<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Recipe;

use InvalidArgumentException;
use Storm\Chronicler\Query\QueryFilter;

/**
 * A named, parameterized fetch preset for `storm:events:inspect --recipe=<name>`.
 *
 * A recipe is a plain DI-discovered class with no config DSL and no scaffolder: it declares its
 * `--var` parameters and builds a `QueryFilter` from them. It shapes a fetch only; it never reduces
 * events into a value, which is a `QueryProjection`, folded by a dev-written command on the query
 * projection runner. Apps add their own by implementing this interface; the framework ships
 * `correlation_trace` as the reference.
 *
 * Contract: a recipe owns its whole query, limit and order included, so the command rejects
 * `--limit`/`--order`/`--safe-head` in recipe mode; that limit MUST be bounded: a recipe built on
 * `InspectFilter` is bounded by construction, since the filter refuses beyond its MAX_LIMIT; one
 * returning a custom `QueryFilter` owns the same obligation, unverifiable here, so an unbounded
 * custom filter is a bug. `RecipeParam` declares presence only, required or optional; value
 * VALIDATION belongs in `filter()`, and a refusal throws `InvalidArgumentException`, which the
 * command renders as an operator error, a message plus FAILURE, like the native selectors' own.
 */
interface LiveQueryRecipe
{
    /**
     * The recipe name used on the CLI as `--recipe=<name>`; unique across registered recipes.
     */
    public function name(): string;

    /**
     * A one-line description for `storm:events:recipes`.
     */
    public function description(): string;

    /**
     * The `--var` parameters this recipe accepts.
     *
     * @return list<RecipeParam>
     */
    public function params(): array;

    /**
     * Build the read filter from the validated `--var` values; required params are checked by the
     * command before this is called.
     *
     * @param  array<string, string>  $vars
     *
     * @throws InvalidArgumentException when a variable value is rejected; the command renders it as an
     *                                  operator error rather than a stack trace
     */
    public function filter(array $vars): QueryFilter;
}
