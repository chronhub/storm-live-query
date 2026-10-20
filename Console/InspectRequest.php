<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use DateTimeZone;
use InvalidArgumentException;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Filter\JsonCondition;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;
use Storm\Support\Console\PositiveIntOption;
use Storm\Text\Str;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The options of one `storm:events:inspect` run, parsed and checked without any service.
 *
 * Every value comes out typed, and every combination the command refuses is refused here by one
 * exception, `InvalidInspectOption`, never by a sentinel a caller could mistake for an absent option.
 * What needs the store or a registry, an alias, a recipe or the safe head, is left to
 * `InspectFilterFactory`, so an option error is always reported before a resolution error.
 *
 * The checks run in the order the command reports them: the first refusal of a run with one bad
 * option is the one the command reports.
 */
final readonly class InspectRequest
{
    /**
     * The largest `--timeout` in whole seconds: PostgreSQL holds `statement_timeout` as an integer
     * of milliseconds up to 2147483647, and refuses a larger `SET` outside this command's reporting.
     */
    public const int MAX_TIMEOUT_SECONDS = 2_147_483;

    /**
     * The strict set of accepted `--since` and `--until` shapes; see `time()`. The
     * micro-plus-offset shape is the round trip: it is exactly what the tool itself prints as
     * `recorded_at`, so a copied timestamp pastes back in.
     */
    private const array TIME_FORMATS = ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d H:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s.u', 'Y-m-d\TH:i:s.uP'];

    /** The typed selectors a whole-query mode excludes. */
    private const array SELECTORS = ['stream', 'category', 'type', 'after', 'to', 'since', 'until', 'where', 'sql'];

    /**
     * @param  positive-int  $limit
     * @param  array<string, string>  $vars  the `--var` bag, each key a `:placeholder` name
     * @param  list<string>  $typeTokens  the `--type` tokens, aliases or FQCNs, still unresolved
     * @param  list<JsonCondition>  $conditions
     * @param  array<string, string>  $typedSelectors  the selector options as the operator typed them,
     *                                                 the provenance a document replays
     * @param  list<string>|null  $typedWheres  the `--where` expressions as typed, null when none
     */
    private function __construct(
        public string $order,
        public int $limit,
        public ?string $out,
        public bool $export,
        public string $format,
        public bool $pretty,
        public bool $dumpSql,
        public bool $explain,
        public array $vars,
        public int $timeout,
        public bool $safeHead,
        public ?string $recipe,
        public ?string $derivedStream,
        public ?StreamName $derivedTarget,
        public array $typeTokens,
        public ?string $stream,
        public ?string $category,
        public ?int $afterSequence,
        public ?int $toSequence,
        public ?PointInTime $afterTime,
        public ?PointInTime $beforeTime,
        public array $conditions,
        public ?string $sqlFragment,
        public array $typedSelectors,
        public ?array $typedWheres,
    ) {}

    /**
     * Parse and check every option of the run, in the order the command reports a refusal.
     *
     * @throws InvalidInspectOption when an option is malformed or out of range, or when two options
     *                              exclude each other
     */
    public static function fromInput(InputInterface $input): self
    {
        $order = strtolower((string) $input->getOption('order'));

        if (! in_array($order, ['asc', 'desc', 'wallclock'], true)) {
            throw new InvalidInspectOption(sprintf('Unknown --order "%s" (use asc | desc | wallclock).', $order));
        }

        self::requireSequenceDigits($input);
        $limit = self::limit($input->getOption('limit'));

        $out = Str::nonEmptyOrNull($input->getOption('out'));
        $export = (bool) $input->getOption('export');
        $format = (string) $input->getOption('output');
        $dumpSql = $input->getOption('dump-sql') === true;
        $explain = $input->getOption('explain') === true;

        if (($out !== null || $export) && ($dumpSql || $explain)) {
            throw new InvalidInspectOption('--out/--export require a read — they cannot be combined with --dump-sql or --explain.');
        }

        if ($input->getOption('pretty') === true && $format !== 'json' && $out === null) {
            // the module refuses silently ignored flags; --pretty shapes the json document only
            throw new InvalidInspectOption('--pretty shapes the json document only — combine it with --output=json or --out.');
        }

        $vars = self::vars($input->getOption('var'));
        $timeout = self::timeout($input->getOption('timeout'));

        $derivedStream = Str::nonEmptyOrNull($input->getOption('derived-stream'));
        $recipe = Str::nonEmptyOrNull($input->getOption('recipe'));
        $derivedTarget = self::wholeQueryMode($input, $derivedStream, $recipe);

        // a whole-query mode has refused every selector above, so these read as absent there
        $typeTokens = self::typeTokens($input->getOption('type'));
        $afterTime = self::time($input->getOption('since'), 'since');
        $beforeTime = self::time($input->getOption('until'), 'until');

        if ($afterTime !== null && $beforeTime !== null && $afterTime->isAfter($beforeTime)) {
            throw new InvalidInspectOption('--since is after --until — the window is empty; check the bounds.');
        }

        $where = $input->getOption('where');

        return new self(
            order: $order,
            limit: $limit,
            out: $out,
            export: $export,
            format: $format,
            pretty: (bool) $input->getOption('pretty'),
            dumpSql: $dumpSql,
            explain: $explain,
            vars: $vars,
            timeout: $timeout,
            safeHead: (bool) $input->getOption('safe-head'),
            recipe: $recipe,
            derivedStream: $derivedStream,
            derivedTarget: $derivedTarget,
            typeTokens: $typeTokens,
            stream: self::canonicalStream(Str::nonEmptyOrNull($input->getOption('stream'))),
            category: self::canonicalCategory(Str::nonEmptyOrNull($input->getOption('category'))),
            afterSequence: self::intOrNull($input->getOption('after')),
            toSequence: self::intOrNull($input->getOption('to')),
            afterTime: $afterTime,
            beforeTime: $beforeTime,
            conditions: self::wheres($where),
            sqlFragment: Str::nonEmptyOrNull($input->getOption('sql')),
            typedSelectors: self::typedSelectors($input),
            typedWheres: is_array($where) && $where !== [] ? array_values(array_filter($where, is_string(...))) : null,
        );
    }

    /**
     * Whether the run reads through the typed selectors rather than a whole-query mode.
     */
    public function readsSelectors(): bool
    {
        return $this->recipe === null && $this->derivedStream === null;
    }

    /**
     * Reject a non-numeric `--after`/`--to` loudly, since a silent `(int)` cast answers a different
     * question than the one asked:
     *
     * - `--after abc` becomes 0, from the start
     * - `--to 10x` becomes 10, the suffix dropped
     *
     * Both accept 0 as a legitimate sequence bound.
     *
     * @throws InvalidInspectOption
     */
    private static function requireSequenceDigits(InputInterface $input): void
    {
        foreach (['after', 'to'] as $option) {
            $value = $input->getOption($option);

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (! ctype_digit($value)) {
                throw new InvalidInspectOption(sprintf('Invalid --%s "%s" — it must be a non-negative integer.', $option, $value));
            }
        }
    }

    /**
     * Read `--limit` once, before any mode is resolved: the typed selectors and `--derived-stream`
     * apply it, and a recipe, which owns its limit, refuses a typed one. `MAX_LIMIT` caps both modes
     * that apply it, since `DerivedStreamFilter` has no ceiling of its own and `pdo_pgsql` buffers the
     * whole result set client-side.
     *
     * @return positive-int
     *
     * @throws InvalidInspectOption
     */
    private static function limit(mixed $value): int
    {
        $limit = PositiveIntOption::parse($value);

        if ($limit === null) {
            throw new InvalidInspectOption(sprintf('Invalid --limit "%s" — it must be a positive integer up to %d.', is_string($value) ? $value : '', InspectFilter::MAX_LIMIT));
        }

        if ($limit > InspectFilter::MAX_LIMIT) {
            throw new InvalidInspectOption(sprintf('--limit %d exceeds the maximum of %d — pdo_pgsql buffers the whole result set client-side; narrow the window instead.', $limit, InspectFilter::MAX_LIMIT));
        }

        return $limit;
    }

    /**
     * Parse each `--var key=value` into the bound bag for the `--sql` fragment's `:key`
     * placeholders.
     *
     * @return array<string, string>
     *
     * @throws InvalidInspectOption
     */
    private static function vars(mixed $option): array
    {
        if (! is_array($option) || $option === []) {
            return [];
        }

        $vars = [];

        foreach ($option as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                throw new InvalidInspectOption(sprintf('Invalid --var "%s" — expected key=value.', is_string($pair) ? $pair : ''));
            }

            [$key, $value] = explode('=', $pair, 2);

            if (preg_match('/^\w+$/', $key) !== 1) {
                throw new InvalidInspectOption(sprintf('Invalid --var key "%s" — a :placeholder name is letters, digits and underscores only.', $key));
            }

            if (array_key_exists($key, $vars)) {
                throw new InvalidInspectOption(sprintf('Duplicate --var "%s" — the last value would win silently; pass each variable once.', $key));
            }

            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * Bound the read's statement time from `--timeout`, session-level so it covers the autocommit
     * paths and the watermark probe alike. An ad-hoc jsonb scan over a production store competes
     * with the write path for I/O, and `LIMIT` bounds the output, never the work.
     *
     * @return int seconds to apply, 0 disabling the bound
     *
     * @throws InvalidInspectOption when malformed or above `MAX_TIMEOUT_SECONDS`
     */
    private static function timeout(mixed $value): int
    {
        $seconds = is_string($value) && ctype_digit($value) ? (int) $value : null;

        // an over-long digit string saturates at PHP_INT_MAX on the cast, which still lands above the
        // ceiling and is refused here rather than by PostgreSQL at the SET
        if ($seconds === null || $seconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidInspectOption(sprintf('Invalid --timeout "%s" — whole seconds up to %d, 0 to disable.', is_string($value) ? $value : '', self::MAX_TIMEOUT_SECONDS));
        }

        return $seconds;
    }

    /**
     * Check a whole-query mode against the rest of the input: `--derived-stream` and `--recipe`
     * exclude each other and the typed selectors.
     *
     * @return StreamName|null the derived stream's target, null outside that mode
     *
     * @throws InvalidInspectOption
     */
    private static function wholeQueryMode(InputInterface $input, ?string $derivedStream, ?string $recipe): ?StreamName
    {
        if ($derivedStream !== null && $recipe !== null) {
            throw new InvalidInspectOption('--derived-stream cannot be combined with --recipe.');
        }

        if ($recipe !== null) {
            self::guardWholeQueryMode($input, '--recipe');
            self::refuseSelectors($input, '--recipe');
        }

        if ($derivedStream === null) {
            return null;
        }

        self::guardWholeQueryMode($input, '--derived-stream');
        self::refuseSelectors($input, '--derived-stream');

        try {
            return new StreamName($derivedStream);
        } catch (InvalidStreamException $e) {
            throw new InvalidInspectOption(sprintf('Invalid --derived-stream "%s": %s', $derivedStream, $e->getMessage()), previous: $e);
        }
    }

    /**
     * A whole-query mode owns its order and its bounds: `--order asc/desc` would be silently
     * ignored, `wallclock` would silently override the mode's documented order, `--safe-head`
     * would be silently ignored; a recipe also owns its limit, so a typed `--limit` would be
     * silently dropped there, though `--derived-stream` honors it. Reject what the user actually
     * typed; the defaults do not count, since `hasParameterOption` reads the raw input, not the
     * resolved option with its default.
     *
     * @throws InvalidInspectOption
     */
    private static function guardWholeQueryMode(InputInterface $input, string $mode): void
    {
        if ($input->hasParameterOption('--order', true)) {
            throw new InvalidInspectOption(sprintf('--order cannot be combined with %s — %s.', $mode, $mode === '--derived-stream'
                ? 'the derived stream owns its order (target_position)'
                : 'the recipe owns its whole query'));
        }

        if ($input->getOption('safe-head') === true) {
            throw new InvalidInspectOption(sprintf('--safe-head cannot be combined with %s — %s.', $mode, $mode === '--derived-stream'
                ? 'a derived stream is gap-free by construction, no watermark applies'
                : 'the recipe owns its whole query'));
        }

        if ($mode === '--recipe' && $input->hasParameterOption('--limit', true)) {
            throw new InvalidInspectOption('--limit cannot be combined with --recipe — the recipe owns its limit.');
        }
    }

    /**
     * Refuse a typed selector next to a whole-query mode, which would answer a different question
     * than the one the selectors ask.
     *
     * @throws InvalidInspectOption
     */
    private static function refuseSelectors(InputInterface $input, string $mode): void
    {
        $given = array_any(self::SELECTORS, static function (string $option) use ($input): bool {
            $value = $input->getOption($option);

            return is_array($value) ? $value !== [] : ($value !== null && $value !== '');
        });

        if ($given) {
            throw new InvalidInspectOption(sprintf('%s cannot be combined with filter options (--stream/--category/--type/--after/--to/--since/--until/--where/--sql).', $mode));
        }
    }

    /**
     * Split `--type` into its tokens, aliases or FQCNs, left for `InspectFilterFactory` to resolve.
     *
     * @return list<string>
     *
     * @throws InvalidInspectOption when the option holds no usable token
     */
    private static function typeTokens(mixed $option): array
    {
        if (! is_string($option) || $option === '') {
            return [];
        }

        $tokens = [];

        foreach (explode(',', $option) as $token) {
            $token = trim($token);

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        // ',,,' or whitespace would yield zero predicate, a silent full dump where a subset was asked.
        if ($tokens === []) {
            throw new InvalidInspectOption(sprintf('Invalid --type "%s" — it contains no usable token (CSV of aliases or FQCNs).', $option));
        }

        return $tokens;
    }

    /**
     * Parse a time bound strictly against the listed formats, interpreted as UTC.
     *
     * The free-form `DateTimeImmutable` parser accepts what an operator would call a typo:
     * `2026-2-30` rolls over to March 2nd instead of erroring, and relative words such as
     * `yesterday` parse to a value that depends on the execution day, while an inspection window must
     * mean the same rows tomorrow. Rollovers are refused through the parser's own warnings.
     *
     * A date-only `--until` means the end of that day: an operator asking `--until 2026-5-1` wants
     * May 1st included, not excluded by a midnight-start bound.
     *
     * @return PointInTime|null the parsed time, null when absent
     *
     * @throws InvalidInspectOption when the value matches no accepted shape
     */
    private static function time(mixed $option, string $name): ?PointInTime
    {
        if (! is_string($option) || $option === '') {
            return null;
        }

        foreach (self::TIME_FORMATS as $format) {
            try {
                $parsed = PointInTime::createFromFormat('!'.$format, $option, new DateTimeZone('UTC'));

                if ($parsed === false) {
                    continue; // wrong shape for this format, or a rollover the parser flagged
                }

                return $format === 'Y-m-d' && $name === 'until' ? $parsed->setTime(23, 59, 59, 999999) : $parsed;
            } catch (InvalidDateTimeException) {
                break;
            }
        }

        throw new InvalidInspectOption(sprintf(
            'Invalid --%s datetime: "%s". Accepted shapes: %s (UTC; no relative words, no rolled-over dates).',
            $name,
            $option,
            implode(', ', ['2026-05-01', '2026-05-01 10:30', '2026-05-01 10:30:00', '2026-05-01T10:30:00', '2026-05-01T10:30:00+02:00', '2026-05-01 10:30:00.123456', '2026-05-01T10:30:00.123456+00:00']),
        ));
    }

    /**
     * Parse each `--where col.path<op>value` into a `JsonCondition`. The regex is the operator
     * allowlist `=` `!=` `>` `>=` `<` `<=` and pins the column to `header` or `content`; the path
     * and value stay raw, bound later. Multi-char operators are listed first, so `>=` does not read
     * as `>`.
     *
     * @return list<JsonCondition>
     *
     * @throws InvalidInspectOption when a clause is malformed
     */
    private static function wheres(mixed $option): array
    {
        if (! is_array($option) || $option === []) {
            return [];
        }

        $conditions = [];

        foreach ($option as $expr) {
            $raw = is_string($expr) ? trim($expr) : '';

            if ($raw === '' || preg_match('/^(header|content)\.(.+?)(>=|<=|!=|=|>|<)(.*)$/s', $raw, $m) !== 1) {
                throw new InvalidInspectOption(sprintf('Invalid --where "%s" — expected header|content.path<op>value, op in = != > >= < <=.', is_string($expr) ? $expr : ''));
            }

            try {
                $column = trim($m[1]);
                $path = array_map(trim(...), explode('.', trim($m[2])));
                // equivalent: the operator group admits no whitespace, so there is nothing to trim
                // @infection-ignore-all
                $operator = trim($m[3]);
                $value = trim($m[4]);
                $conditions[] = new JsonCondition($column, $path, $operator, $value);
            } catch (InvalidArgumentException $e) {
                throw new InvalidInspectOption(sprintf('Invalid --where "%s" — %s', $expr, $e->getMessage()), previous: $e);
            }
        }

        return $conditions;
    }

    /**
     * The read and the empty-result diagnostic must see one value: the diagnostic probes through
     * `StreamName`, so the read compares the same canonical form, or `Order-1` reads nothing and is
     * then reported as existing. A malformed value is kept raw: the diagnostic names the
     * malformation, and a raw value matches no row.
     */
    private static function canonicalStream(?string $stream): ?string
    {
        if ($stream === null) {
            return null;
        }

        try {
            return new StreamName($stream)->toString();
        } catch (InvalidStreamException) {
            return $stream;
        }
    }

    /**
     * The category twin of `canonicalStream()`; a qualified name is not a category and stays raw
     * for the diagnostic to refuse.
     */
    private static function canonicalCategory(?string $category): ?string
    {
        if ($category === null) {
            return null;
        }

        try {
            $name = new StreamName($category);
        } catch (InvalidStreamException) {
            return $category;
        }

        return $name->qualifier === null ? $name->category->value : $category;
    }

    /**
     * The cast cannot fail-open here: `requireSequenceDigits()` already rejected non-digits.
     */
    private static function intOrNull(mixed $value): ?int
    {
        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    /**
     * The selector options as typed, the raw values the operator would retype, so a document's
     * provenance replays the query and the alias expansion stays an implementation detail.
     *
     * @return array<string, string>
     */
    private static function typedSelectors(InputInterface $input): array
    {
        $selectors = [];

        foreach (['stream', 'category', 'type', 'after', 'to', 'since', 'until', 'sql'] as $option) {
            $value = Str::nonEmptyOrNull($input->getOption($option));

            if ($value !== null) {
                $selectors[$option] = $value;
            }
        }

        return $selectors;
    }
}
