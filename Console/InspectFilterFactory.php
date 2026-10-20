<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use Closure;
use InvalidArgumentException;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Store\Direction;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Storm\Stream\StreamName;
use Throwable;

/**
 * Turns an `InspectRequest` into the filter the read applies: a `DerivedStreamFilter`, a recipe's
 * own filter, or an `InspectFilter` over the typed selectors.
 *
 * It resolves what the request cannot without a service: the stored types an alias stands for, the
 * recipe and its variables, the safe head. A selector the store or a registry cannot resolve is
 * refused by `InvalidInspectOption`, like a malformed option. An advisory, a selector the veil makes
 * unmatchable or a `--var` with nothing to bind, goes to the caller's `$warn` in the order the
 * resolution meets it.
 */
final readonly class InspectFilterFactory
{
    public function __construct(
        private EventTypeMapper $eventTypeMapper,
        private RecipeRegistry $recipes,
        private StreamReader $streamReader,
        private PersonalDataVeil $veil,
    ) {}

    /**
     * The filter the request reads through.
     *
     * @param  Closure(string): void  $warn  receives each advisory, never an error
     *
     * @throws InvalidInspectOption when a type, a recipe or its variables cannot be resolved, when the
     *                              safe head cannot be read, or when the selectors form no valid filter
     */
    public function filterFor(InspectRequest $request, Closure $warn): QueryFilter
    {
        if ($request->derivedTarget instanceof StreamName) {
            return new DerivedStreamFilter($request->derivedTarget, limit: $request->limit);
        }

        if ($request->recipe !== null) {
            return $this->recipeFilter($request->recipe, $request->vars);
        }

        return $this->selectorFilter($request, $warn);
    }

    /**
     * Resolve a `--recipe`: look it up, validate its required `--var` params, and build its filter.
     *
     * @param  array<string, string>  $vars  the `--var` bag
     *
     * @throws InvalidInspectOption
     */
    private function recipeFilter(string $name, array $vars): QueryFilter
    {
        $recipe = $this->recipes->get($name);

        if ($recipe === null) {
            throw new InvalidInspectOption(sprintf('Unknown recipe "%s". List them with storm:events:recipes.', $name));
        }

        $declared = [];

        foreach ($recipe->params() as $param) {
            $declared[] = $param->name;

            if ($param->required && ! array_key_exists($param->name, $vars)) {
                throw new InvalidInspectOption(sprintf('Recipe "%s" requires --var %s.', $name, $param->name));
            }
        }

        // typo protection: an unknown variable would be silently ignored and the recipe would
        // answer a broader question than the operator asked
        $unknown = array_diff(array_keys($vars), $declared);

        if ($unknown !== []) {
            throw new InvalidInspectOption(sprintf('Unknown --var "%s" for recipe "%s" — it declares: %s.', implode('", "', $unknown), $name, $declared === [] ? '(none)' : implode(', ', $declared)));
        }

        try {
            // the contract puts value validation in filter(): its refusals are operator errors,
            // rendered like the native selectors', never a raw stack trace out of the console
            return $recipe->filter($vars);
        } catch (InvalidArgumentException $e) {
            throw new InvalidInspectOption(sprintf('Recipe "%s" rejected its variables: %s', $name, $e->getMessage()), previous: $e);
        }
    }

    /**
     * Build the `InspectFilter` from the typed selectors.
     *
     * @param  Closure(string): void  $warn
     *
     * @throws InvalidInspectOption
     */
    private function selectorFilter(InspectRequest $request, Closure $warn): InspectFilter
    {
        $types = $this->storedTypes($request->typeTokens);
        $to = $this->maxSequence($request, $warn);

        if ($request->afterSequence !== null && $to !== null && $request->afterSequence >= $to) {
            throw new InvalidInspectOption('--after must be strictly below --to — the sequence window is empty.');
        }

        foreach ($request->conditions as $condition) {
            if ($condition->column === 'content' && $this->veil->isDeclaredKey($condition->path[0])) {
                // The store holds this key's ciphered envelope, so a cleartext needle never matches,
                // and the needle itself is personal data; the provenance redacts it, this warning
                // explains the empty page.
                $warn(sprintf(
                    '--where targets "%s", a declared personal key: the store holds its CIPHERED envelope, so a cleartext value cannot match, and the value is redacted from any exported provenance.',
                    implode('.', $condition->path),
                ));
            }
        }

        $sqlFragment = $request->sqlFragment;

        if ($request->vars !== [] && $sqlFragment === null) {
            $warn('--var is only bound into a --sql fragment; without --sql it is ignored (the typed selectors --stream/--category/--type/--where take no vars).');
        }

        if ($sqlFragment !== null) {
            // The same notice owed to --where, on the escape hatch that bypasses its parser. Read by
            // token rather than by matching an accessor shape: a raw fragment can reach a key through
            // ->>, #>>, jsonb_extract_path_text or a cast, and every one of them has to name it, so a
            // word scan cannot be walked around while a grammar could
            preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $sqlFragment, $tokens);
            $declared = array_values(array_unique(array_filter($tokens[0], $this->veil->isDeclaredKey(...))));

            foreach ($declared as $key) {
                $warn(sprintf(
                    '--sql names "%s", a declared personal key: the store holds its CIPHERED envelope, so a cleartext comparison never matches. Unlike a --where on the same key, neither the fragment nor its --var values are redacted from an exported provenance.',
                    $key,
                ));
            }
        }

        try {
            return new InspectFilter(
                stream: $request->stream,
                category: $request->category,
                types: $types,
                afterSequence: $request->afterSequence,
                maxSequence: $to,
                afterTime: $request->afterTime,
                beforeTime: $request->beforeTime,
                conditions: $request->conditions,
                sqlFragment: $sqlFragment,
                vars: $request->vars,
                limit: $request->limit,
                direction: $request->order === 'desc' ? Direction::Backward : Direction::Forward,
            );
        } catch (InvalidArgumentException $e) {
            // a `--sql` fragment whose parentheses could close the generated wrapper is an operator
            // error like any other refusal, never a raw exception out of the console
            throw new InvalidInspectOption($e->getMessage(), previous: $e);
        }
    }

    /**
     * Expand each `--type` token, alias or FQCN, to its stored type set where the alias union
     * replaces the union FQCN, so a filter on the current name still matches rows written under an
     * old alias or the bare FQCN.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     *
     * @throws InvalidInspectOption when a token names no registered event type
     */
    private function storedTypes(array $tokens): array
    {
        $stored = [];

        foreach ($tokens as $token) {
            try {
                $class = $this->eventTypeMapper->toClass($token);
            } catch (UnknownEventType $e) {
                throw new InvalidInspectOption($e->getMessage(), previous: $e);
            }

            foreach ($this->eventTypeMapper->storedTypesOf($class) as $type) {
                $stored[$type] = true;
            }
        }

        return array_keys($stored);
    }

    /**
     * The upper sequence bound: `--to` if given, capped at `safeHeadPosition()` when `--safe-head`
     * is set, the more restrictive winning. A null safe head, an empty store or the lowest position
     * in-flight, becomes 0 and selects nothing; the warning keeps that empty page from masquerading
     * as "nothing matched".
     *
     * @param  Closure(string): void  $warn
     * @return int|null the bound, null when unbounded
     *
     * @throws InvalidInspectOption when the watermark probe fails
     */
    private function maxSequence(InspectRequest $request, Closure $warn): ?int
    {
        $to = $request->toSequence;

        if (! $request->safeHead) {
            return $to;
        }

        try {
            $safe = $this->streamReader->safeHeadPosition();
        } catch (Throwable $e) {
            // A refusal is an operator error, never a raw stack trace.
            throw new InvalidInspectOption(sprintf('--safe-head watermark probe failed: %s', $e->getMessage()), previous: $e);
        }

        if ($safe === null) {
            $warn('--safe-head: no position is safe to read yet (empty store, or the lowest position still in flight); the read is bounded at 0 and returns nothing.');
        }

        $safeMax = $safe?->toOrdinal() ?? 0;

        return $to === null ? $safeMax : min($to, $safeMax);
    }
}
