<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use InvalidArgumentException;
use JsonException;
use Override;
use RuntimeException;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Store\Direction;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\Exception\InvalidDateTimeException;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Chronicler\UnknownEventType;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\LiveQuery\Export\LiveQueryExport;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Filter\JsonCondition;
use Storm\LiveQuery\Output\DocumentMeta;
use Storm\LiveQuery\Output\EventRenderer;
use Storm\LiveQuery\Output\JsonRenderer;
use Storm\LiveQuery\Output\LiveQueryDocument;
use Storm\LiveQuery\Output\NdjsonRenderer;
use Storm\LiveQuery\Output\TableRenderer;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamName;
use Storm\Support\Console\PositiveIntOption;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Ad-hoc inspection of the event store from the CLI.
 *
 * A thin skin over `StreamReader::retrieveByFilter()`: it maps options to a typed, parameter-bound
 * `InspectFilter`, streams the matching `EventRecord` values, and renders them as table, json, or
 * ndjson. Snapshot semantics: it reads everything committed, no checkpoint, and runs read-only.
 *
 * Selectors: `--stream`, `--category`, alias-aware `--type`, the `--after`/`--to` sequence window,
 * the `--since`/`--until` time window, and a json `--where col.path<op>value` predicate. Two escape
 * tiers sit above them: a raw read-only `--sql` fragment with `:k` placeholders bound from `--var`,
 * and `--where`. Two whole-query modes own the entire query and exclude the selectors: `--recipe`
 * runs a `LiveQueryRecipe`; `--derived-stream` reads a `LinkProjection` target stream through a
 * `DerivedStreamFilter`, in link order.
 *
 * Shaping and output: `--limit`, `--order`, `--output`, `--pretty`, `--safe-head`. `--out` writes
 * the self-describing json document to a file, independent of `--output`. `--export` emits that
 * same document through the `LiveQueryExport` port, the app-bound outbound adapter, opt-in per run.
 * `--dump-sql` and `--explain` print the plan and run nothing.
 *
 * Examples:
 * ```bash
 * # The full causal trace of one operation, in human-time order
 * storm:events:inspect --recipe=correlation_trace --var id=<correlation-id> --output=ndjson
 *
 * # A typed window on one category, capped at the safe head
 * storm:events:inspect --category=order --since "2026-05-01" --safe-head --limit 500
 *
 * # A json predicate, written to a self-describing document
 * storm:events:inspect --where "content.amount>1000" --output=json --out=trace.json
 *
 * # Inspect the plan without reading
 * storm:events:inspect --category=order --explain
 * ```
 *
 * @see LiveQueryRecipe
 */
#[AsCommand(name: 'storm:events:inspect', description: 'Ad-hoc inspection of the event store (read-only).')]
final class InspectEventsCommand extends Command
{
    /**
     * @param  Clock<PointInTime>  $clock
     * @param  LiveQueryExport|null  $export  the app-bound outbound adapter; null when none is wired
     */
    public function __construct(
        private readonly StreamReader $streamReader,
        private readonly Connection $connection,
        private readonly EventTypeMapper $eventTypeMapper,
        private readonly RecipeRegistry $recipes,
        private readonly Clock $clock,
        private readonly StreamExistence $existence,
        private readonly ?LiveQueryExport $export = null,
        private readonly PersonalDataVeil $veil = new PersonalDataVeil,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('stream', null, InputOption::VALUE_REQUIRED, 'Exact stream name, e.g. order-7f3a…')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Stream category, e.g. order')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Event type(s), CSV — alias or FQCN (alias-aware)')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Only events with sequence_no > N')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Only events with sequence_no <= N')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only events recorded at or after this datetime')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Only events recorded at or before this datetime')
            ->addOption('where', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'json predicate, e.g. header.__correlation_id=<id> or "content.amount>1000" (repeatable, AND); path segments cannot contain < > = — the first operator char ends the path; != also matches events missing the path')
            ->addOption('sql', null, InputOption::VALUE_REQUIRED, 'Raw read-only WHERE fragment (your risk); use :k placeholders bound via --var')
            ->addOption('var', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'k=value — feeds a --recipe param or a --sql :k placeholder (repeatable)')
            ->addOption('recipe', null, InputOption::VALUE_REQUIRED, 'Run a named recipe (see storm:events:recipes); pass its params via --var')
            ->addOption('derived-stream', null, InputOption::VALUE_REQUIRED, 'Read a derived stream (a LinkProjection target) by name, ordered by link position — e.g. large_withdrawals')
            ->addOption('safe-head', null, InputOption::VALUE_NONE, 'Bound the read at the safe head (the projector consistency watermark)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max events returned', '100')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'asc | desc (by sequence_no) | wallclock (re-sort the --limit page by recorded_at — buffers it)', 'asc')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'table | json | ndjson (json = the self-describing {meta, results} document)', 'table')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Also write the self-describing json document ({meta, results}) to this file — independent of --output (table on screen, document in the file)')
            ->addOption('export', null, InputOption::VALUE_NONE, 'Also emit the document through the LiveQueryExport port (requires an adapter bound by the app; opt-in per run)')
            ->addOption('pretty', null, InputOption::VALUE_NONE, 'Pretty-print json output (stdout and --out)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'statement_timeout in seconds for the read (0 disables) — an ad-hoc scan must not compete with the write path unbounded', '30')
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the generated SQL + bindings and run nothing')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Print the EXPLAIN plan of the generated query and run nothing');
    }

    /**
     * {@inheritDoc}
     *
     * @throws ClockExceptionContract when the system clock yields a non-canonical datetime
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $order = strtolower((string) $input->getOption('order'));
        if (! in_array($order, ['asc', 'desc', 'wallclock'], true)) {
            $io->getErrorStyle()->error(sprintf('Unknown --order "%s" (use asc | desc | wallclock).', $order));

            return Command::INVALID;
        }

        if (! $this->validateNumericOptions($input, $io)) {
            return Command::INVALID;
        }

        $out = $this->stringOrNull($input->getOption('out'));
        $export = (bool) $input->getOption('export');
        $format = (string) $input->getOption('output');

        if (($out !== null || $export) && ($input->getOption('dump-sql') || $input->getOption('explain'))) {
            $io->getErrorStyle()->error('--out/--export require a read — they cannot be combined with --dump-sql or --explain.');

            return Command::INVALID;
        }

        if ($export && ! $this->export instanceof LiveQueryExport) {
            $io->getErrorStyle()->error('--export has no adapter: bind a LiveQueryExport implementation in the app (storm ships none — the destination is yours).');

            return Command::FAILURE;
        }

        if ($input->getOption('pretty') === true && $format !== 'json' && $out === null) {
            // the module refuses silently ignored flags; --pretty shapes the json document only
            $io->getErrorStyle()->error('--pretty shapes the json document only — combine it with --output=json or --out.');

            return Command::INVALID;
        }

        $vars = $this->parseVars($input->getOption('var'), $io);

        if ($vars === null) {
            return Command::INVALID; // a malformed --var was already reported
        }

        $timeout = $this->parseStatementTimeout($input, $io);

        if ($timeout === false) {
            return Command::INVALID;
        }

        $priorTimeout = null;

        if ($timeout > 0) {
            $prior = $this->connection->fetchOne('SHOW statement_timeout');
            $priorTimeout = is_string($prior) ? $prior : '0';
            $this->connection->executeStatement('SET statement_timeout = '.($timeout * 1000));
        }

        try {
            $filter = $this->resolveFilter($input, $io, $vars);

            if (! $filter instanceof QueryFilter) {
                return Command::INVALID; // a validation error was already reported
            }

            if ($input->getOption('dump-sql')) {
                return $this->dumpSql($filter, $io);
            }

            if ($input->getOption('explain')) {
                return $this->explain($filter, $io, rawSql: $this->stringOrNull($input->getOption('sql')) !== null);
            }

            $meta = $this->documentMeta($input, $filter, $order, $vars);

            $renderer = $this->renderer($format, (bool) $input->getOption('pretty'), $meta);

            if (! $renderer instanceof EventRenderer) {
                $io->getErrorStyle()->error(sprintf('Unknown --output "%s" (use table | json | ndjson).', $format));

                return Command::INVALID;
            }

            return $this->renderRead($filter, $renderer, $output, $io, new RenderPlan(
                rawSql: $this->stringOrNull($input->getOption('sql')) !== null,
                wallclock: $order === 'wallclock',
                effectiveLimit: $this->effectiveLimit($filter),
                truncationHint: $this->truncationHint($input),
                out: $out,
                export: $export,
                pretty: (bool) $input->getOption('pretty'),
                meta: $meta,
            ));
        } finally {
            if ($priorTimeout !== null) {
                if ($priorTimeout === '0') {
                    $this->connection->executeStatement('RESET statement_timeout');
                } else {
                    $this->connection->executeStatement("SET statement_timeout = '{$priorTimeout}'");
                }
            }
        }
    }

    /**
     * Stream the read to the renderer. Any failure on the read path is caught and reported as a
     * FAILURE exit; nothing escapes.
     *
     * A typed-selector, recipe, or derived-stream read is a single SELECT, a consistent snapshot
     * under autocommit and structurally read-only since it only builds a WHERE, JOIN, or ORDER, so
     * it runs directly with no transaction. Only a user-supplied raw `--sql` fragment, flagged by
     * `rawSql`, is wrapped in a `SET TRANSACTION READ ONLY` transaction: that string is untrusted,
     * so Postgres rejects any writer such as DML or a writable CTE inside it. This is a writer
     * guard, not a sandbox; `READ ONLY` does not block a row lock `SELECT ... FOR UPDATE` or a
     * side-effecting function such as `pg_advisory_lock`, `dblink`, `pg_read_file`, which the
     * fragment author owns. Recipes are DI-discovered trusted PHP, not raw CLI input, and reject
     * `--sql` anyway, so they never need the guard.
     *
     * A page that fills the limit is indistinguishable from a complete result, so the renderer's
     * count is the truncation signal: a count equal to `effectiveLimit` warns on STDERR, never on
     * STDOUT, so piped json/ndjson stay parseable. It false-positives when the result sits exactly
     * at the limit, the price of not over-fetching. A null `effectiveLimit`, a recipe's own filter
     * shape, means no signal is possible.
     *
     * When the plan emits a document through `--out` and/or `--export`, or renders json to stdout,
     * the read is materialized once and ONE `LiveQueryDocument` feeds every target, screen
     * included, so no two of them can disagree and no row array is built twice. The table and
     * ndjson renderers flatten their own copy, bounded by `--limit` in a one-shot CLI.
     *
     * @throws Exception on a DBAL transaction failure
     */
    private function renderRead(QueryFilter $filter, EventRenderer $renderer, OutputInterface $output, SymfonyStyle $io, RenderPlan $plan): int
    {
        try {
            if ($plan->rawSql) {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('SET TRANSACTION READ ONLY');
            }

            $events = $this->streamReader->retrieveByFilter($filter);

            if ($plan->wallclock) {
                $events = $this->byWallClock($events); // re-sorted ONCE: screen and document show the same order
            }

            $document = null;

            if ($plan->emitsDocument() || $renderer instanceof JsonRenderer) {
                $events = is_array($events) ? $events : iterator_to_array($events, preserve_keys: false);

                /** @var list<EventRecord> $events */
                $document = LiveQueryDocument::fromRecords($plan->meta, $events, $this->eventTypeMapper, $this->veil);
            }

            $count = $document !== null && $renderer instanceof JsonRenderer
                ? $renderer->renderDocument($document, $output)
                : $renderer->render($events, $output);

            if ($plan->rawSql) {
                $this->connection->rollBack(); // read-only, nothing to commit
            }

            if ($document !== null && $plan->emitsDocument()) {
                $this->emitDocument($document, $plan, $io);
            }

            if ($count === 0) {
                $this->explainEmptyResult($filter, $io);
            }

            if ($plan->effectiveLimit !== null && $count === $plan->effectiveLimit) {
                $io->getErrorStyle()->warning(sprintf($plan->truncationHint, $plan->effectiveLimit));
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Bound the read's statement time from `--timeout`, session-level so it covers the autocommit
     * paths and the watermark probe alike. An ad-hoc jsonb scan over a production store competes
     * with the write path for I/O, and `LIMIT` bounds the output, never the work.
     *
     * @return int|false seconds to apply; false when malformed and reported
     */
    private function parseStatementTimeout(InputInterface $input, SymfonyStyle $io): int|false
    {
        $value = $input->getOption('timeout');

        if (! is_string($value) || ! ctype_digit($value)) {
            $io->getErrorStyle()->error(sprintf('Invalid --timeout "%s" — whole seconds, 0 to disable.', is_string($value) ? $value : ''));

            return false;
        }

        return (int) $value;
    }

    /**
     * Say WHY a zero-row selector result is empty: a typo'd `--stream` is otherwise
     * indistinguishable from a stream on which nothing happened, and the operator's natural but
     * possibly false conclusion is the latter. One existence probe, reported on STDERR so piped
     * output stays parseable; a malformed name is reported as such rather than probed.
     */
    private function explainEmptyResult(QueryFilter $filter, SymfonyStyle $io): void
    {
        if (! $filter instanceof InspectFilter) {
            return;
        }

        $err = $io->getErrorStyle();

        if ($filter->stream !== null) {
            try {
                $exists = $this->existence->hasStream(new StreamName($filter->stream));
            } catch (InvalidStreamException) {
                $err->note(sprintf('"%s" is not a well-formed stream name, so no event can match.', $filter->stream));

                return;
            }

            $err->note($exists
                ? sprintf('Stream "%s" exists; no event matched the other filters.', $filter->stream)
                : sprintf('No such stream "%s" — a bare category is queried with --category.', $filter->stream));

            return;
        }

        if ($filter->category !== null) {
            try {
                $exists = $this->existence->hasCategory($filter->category);
            } catch (InvalidStreamException) {
                $err->note(sprintf('"%s" is not a well-formed category, so no event can match.', $filter->category));

                return;
            }

            $err->note($exists
                ? sprintf('Category "%s" exists; no event matched the other filters.', $filter->category)
                : sprintf('No such category "%s".', $filter->category));
        }
    }

    /**
     * Emit the document to the plan's targets: the `--out` file, a local convenience where the
     * operator reads a table on screen and keeps the document, and/or the `LiveQueryExport` port,
     * the app-bound adapter whose failure throws since emission is part of the asked gesture. Each
     * emission is confirmed on STDERR, visible at the terminal and absent from pipes.
     *
     * @throws RuntimeException when the `--out` file cannot be written
     * @throws JsonException on a JSON encoding failure
     */
    private function emitDocument(LiveQueryDocument $document, RenderPlan $plan, SymfonyStyle $io): void
    {
        if ($plan->out !== null) {
            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

            if ($plan->pretty) {
                $flags |= JSON_PRETTY_PRINT;
            }

            $json = json_encode($document->toArray(), $flags | JSON_THROW_ON_ERROR);

            if (@file_put_contents($plan->out, $json.PHP_EOL) === false) {
                // the suppression keeps the warning off the console; the reason, missing directory
                // versus permission denied, is exactly what the operator needs, so carry it
                throw new RuntimeException(sprintf(
                    'Could not write the document to "%s": %s',
                    $plan->out,
                    error_get_last()['message'] ?? 'unknown reason',
                ));
            }

            $io->getErrorStyle()->writeln(sprintf('Document written to %s (%d rows).', $plan->out, $document->count()));
        }

        if ($plan->export && $this->export instanceof LiveQueryExport) {
            $this->export->export($document);

            $io->getErrorStyle()->writeln(sprintf('Document exported through %s (%d rows).', $this->export::class, $document->count()));
        }
    }

    /**
     * Materialize the already `--limit`-bounded read and re-sort it by wall-clock `recorded_at`;
     * the saga inspector view, a cherry-picked set of correlated streams in human-time order, not
     * commit order. Page-level only: it sorts the bounded set; a true global streaming merge, a
     * k-way min-heap, is parked behind a volume trigger.
     *
     * @param  iterable<EventRecord>  $events
     * @return list<EventRecord>
     */
    private function byWallClock(iterable $events): array
    {
        $events = iterator_to_array($events, preserve_keys: false);
        usort($events, static fn (EventRecord $a, EventRecord $b): int => $a->recordedAt <=> $b->recordedAt);

        return $events;
    }

    /**
     * The LIMIT the chosen filter applies, readable on every storm-owned filter shape, a recipe's
     * own included, for example `correlation_trace`'s 1000 on its `CorrelationFeedFilter`. A recipe
     * returning a shape from outside that set reads as null: the bound is unknown, so no truncation
     * signal is possible.
     */
    private function effectiveLimit(QueryFilter $filter): ?int
    {
        return match (true) {
            $filter instanceof InspectFilter,
            $filter instanceof CorrelationFeedFilter,
            $filter instanceof DerivedStreamFilter => $filter->limit,
            default => null,
        };
    }

    /**
     * The truncation warning's wording per mode, where the sprintf `%d` is the effective limit.
     * Each mode only advertises the remedy it actually honors: the typed selectors take `--limit`
     * and `--after`; a derived stream takes `--limit` only, rejecting `--after`; a recipe owns its
     * limit, so neither flag is advertised. The paging cursor follows the DIRECTION:
     * a descending page ends on its LOWEST sequence, so the next page needs the upper bound `--to`
     * below it; following the ascending `--after` advice re-reads the same page forever.
     */
    private function truncationHint(InputInterface $input): string
    {
        $descending = strtolower((string) $input->getOption('order')) === 'desc';

        return match (true) {
            $this->stringOrNull($input->getOption('recipe')) !== null => 'Reached the recipe limit %d; more rows may exist.',
            $this->stringOrNull($input->getOption('derived-stream')) !== null => 'Reached --limit %d; more rows may exist — raise --limit.',
            $descending => 'Reached --limit %d; more rows may exist — raise --limit, or page DOWN with --to below the lowest sequence shown.',
            default => 'Reached --limit %d; more rows may exist — raise --limit or page with --after.',
        };
    }

    /**
     * Pick the read filter: a `--derived-stream` or a `--recipe`, each owning the whole query, or
     * the typed selectors. The two whole-query modes are mutually exclusive with each other and
     * with the selectors.
     *
     * @param  array<string, string>  $vars  the `--var` bag, parsed once by `execute()`
     */
    private function resolveFilter(InputInterface $input, SymfonyStyle $io, array $vars): ?QueryFilter
    {
        $derivedStream = $this->stringOrNull($input->getOption('derived-stream'));
        $recipe = $this->stringOrNull($input->getOption('recipe'));

        if ($derivedStream !== null && $recipe !== null) {
            $io->getErrorStyle()->error('--derived-stream cannot be combined with --recipe.');

            return null;
        }

        if ($derivedStream !== null) {
            return $this->guardWholeQueryMode($input, $io, '--derived-stream')
                ? $this->resolveDerivedStream($derivedStream, $input, $io)
                : null;
        }

        if ($recipe !== null) {
            return $this->guardWholeQueryMode($input, $io, '--recipe')
                ? $this->resolveRecipe($recipe, $input, $io, $vars)
                : null;
        }

        return $this->buildFilter($input, $io, $vars);
    }

    /**
     * Resolve `--derived-stream`: a `DerivedStreamFilter` owns the whole query over a
     * `LinkProjection` target stream, ordered by `target_position`; reject combining it with the
     * typed selectors. Honors `--limit` only, the read cap; the derived stream's order is its link
     * order, not `--order`.
     */
    private function resolveDerivedStream(string $name, InputInterface $input, SymfonyStyle $io): ?QueryFilter
    {
        if ($this->hasFilterOptions($input)) {
            $io->getErrorStyle()->error('--derived-stream cannot be combined with filter options (--stream/--category/--type/--after/--to/--since/--until/--where/--sql).');

            return null;
        }

        try {
            $target = new StreamName($name);
        } catch (InvalidStreamException $e) {
            $io->getErrorStyle()->error(sprintf('Invalid --derived-stream "%s": %s', $name, $e->getMessage()));

            return null;
        }

        $limit = PositiveIntOption::parse($input->getOption('limit'));
        if ($limit === null) {
            $io->getErrorStyle()->error('Invalid --limit: expected a positive integer; a zero or garbage value would read as LIMIT 0, an empty page posing as an empty stream.');

            return null;
        }

        return new DerivedStreamFilter($target, limit: $limit);
    }

    /**
     * Resolve a `--recipe`: reject combining it with the typed selectors, look it up, validate its
     * required `--var` params, and build its filter. Null on any failure, the error already
     * reported.
     *
     * @param  array<string, string>  $vars  the `--var` bag, parsed once by `execute()`
     */
    private function resolveRecipe(string $name, InputInterface $input, SymfonyStyle $io, array $vars): ?QueryFilter
    {
        if ($this->hasFilterOptions($input)) {
            $io->getErrorStyle()->error('--recipe cannot be combined with filter options (--stream/--category/--type/--after/--to/--since/--until/--where/--sql).');

            return null;
        }

        $recipe = $this->recipes->get($name);

        if ($recipe === null) {
            $io->getErrorStyle()->error(sprintf('Unknown recipe "%s". List them with storm:events:recipes.', $name));

            return null;
        }

        $declared = [];

        foreach ($recipe->params() as $param) {
            $declared[] = $param->name;

            if ($param->required && ! array_key_exists($param->name, $vars)) {
                $io->getErrorStyle()->error(sprintf('Recipe "%s" requires --var %s.', $name, $param->name));

                return null;
            }
        }

        // typo protection: an unknown variable would be silently ignored and the recipe would
        // answer a broader question than the operator asked
        $unknown = array_diff(array_keys($vars), $declared);

        if ($unknown !== []) {
            $io->getErrorStyle()->error(sprintf('Unknown --var "%s" for recipe "%s" — it declares: %s.', implode('", "', $unknown), $name, $declared === [] ? '(none)' : implode(', ', $declared)));

            return null;
        }

        try {
            // the contract puts value VALIDATION in filter(): its refusals are operator errors,
            // rendered like the native selectors', never a raw stack trace out of the console
            return $recipe->filter($vars);
        } catch (InvalidArgumentException $e) {
            $io->getErrorStyle()->error(sprintf('Recipe "%s" rejected its variables: %s', $name, $e->getMessage()));

            return null;
        }
    }

    /**
     * Whether any typed filter selector was supplied; used to forbid mixing `--recipe` with
     * selectors.
     */
    private function hasFilterOptions(InputInterface $input): bool
    {
        foreach (['stream', 'category', 'type', 'after', 'to', 'since', 'until', 'where', 'sql'] as $option) {
            $value = $input->getOption($option);

            if (is_array($value) ? $value !== [] : ($value !== null && $value !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A whole-query mode owns its order and its bounds: `--order asc/desc` would be silently
     * ignored, `wallclock` would silently override the mode's documented order, `--safe-head`
     * would be silently ignored; a recipe also owns its limit, so a typed `--limit` would be
     * silently dropped there, though `--derived-stream` honors it. Reject what the user actually
     * typed; the defaults do not count, since `hasParameterOption` reads the raw input, not the
     * resolved option with its default.
     */
    private function guardWholeQueryMode(InputInterface $input, SymfonyStyle $io, string $mode): bool
    {
        if ($input->hasParameterOption('--order', true)) {
            $io->getErrorStyle()->error(sprintf('--order cannot be combined with %s — %s.', $mode, $mode === '--derived-stream'
                ? 'the derived stream owns its order (target_position)'
                : 'the recipe owns its whole query'));

            return false;
        }

        if ($input->getOption('safe-head') === true) {
            $io->getErrorStyle()->error(sprintf('--safe-head cannot be combined with %s — %s.', $mode, $mode === '--derived-stream'
                ? 'a derived stream is gap-free by construction, no watermark applies'
                : 'the recipe owns its whole query'));

            return false;
        }

        if ($mode === '--recipe' && $input->hasParameterOption('--limit', true)) {
            $io->getErrorStyle()->error('--limit cannot be combined with --recipe — the recipe owns its limit.');

            return false;
        }

        return true;
    }

    /**
     * Build the `InspectFilter` from the typed selector options, or null when one is invalid, the
     * error already reported to `$io`.
     *
     * @param  array<string, string>  $vars  the `--var` bag, parsed once by `execute()`
     */
    private function buildFilter(InputInterface $input, SymfonyStyle $io, array $vars): ?InspectFilter
    {
        $types = $this->resolveTypes($input->getOption('type'), $io);

        if ($types === null) {
            return null; // unknown type reported
        }

        $afterTime = $this->parseTime($input->getOption('since'), 'since', $io);
        $beforeTime = $this->parseTime($input->getOption('until'), 'until', $io);

        if ($afterTime === false || $beforeTime === false) {
            return null; // bad datetime reported
        }

        if ($afterTime !== null && $beforeTime !== null && $afterTime->isAfter($beforeTime)) {
            $io->getErrorStyle()->error('--since is after --until — the window is empty; check the bounds.');

            return null;
        }

        $after = $this->intOrNull($input->getOption('after'));
        $to = $this->maxSequence($input, $io);

        if ($to === false) {
            return null; // the watermark probe failed; reported
        }

        if ($after !== null && $to !== null && $after >= $to) {
            $io->getErrorStyle()->error('--after must be strictly below --to — the sequence window is empty.');

            return null;
        }

        $conditions = $this->parseWheres($input->getOption('where'), $io);

        if ($conditions === null) {
            return null; // bad --where reported
        }

        foreach ($conditions as $condition) {
            if ($condition->column === 'content' && $this->veil->isDeclaredKey($condition->path[0])) {
                // the honest half of the veil: the store holds this key's ciphered envelope, so a
                // cleartext needle never matches, and the needle itself is personal data; the
                // provenance redacts it, this warning explains the empty page
                $io->getErrorStyle()->warning(sprintf(
                    '--where targets "%s", a declared personal key: the store holds its CIPHERED envelope, so a cleartext value cannot match, and the value is redacted from any exported provenance.',
                    implode('.', $condition->path),
                ));
            }
        }

        $sqlFragment = $this->stringOrNull($input->getOption('sql'));

        if ($vars !== [] && $sqlFragment === null) {
            // STDERR like every advisory, so piped json/ndjson stdout stays parseable
            $io->getErrorStyle()->warning('--var is only bound into a --sql fragment; without --sql it is ignored (the typed selectors --stream/--category/--type/--where take no vars).');
        }

        if ($sqlFragment !== null) {
            // the same honesty owed to --where, on the escape hatch that bypasses its parser. Read by
            // TOKEN rather than by matching an accessor shape: a raw fragment can reach a key through
            // ->>, #>>, jsonb_extract_path_text or a cast, and every one of them has to NAME it, so a
            // word scan cannot be walked around while a grammar could
            preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]*/', $sqlFragment, $tokens);
            $declared = array_values(array_unique(array_filter($tokens[0], $this->veil->isDeclaredKey(...))));

            foreach ($declared as $key) {
                $io->getErrorStyle()->warning(sprintf(
                    '--sql names "%s", a declared personal key: the store holds its CIPHERED envelope, so a cleartext comparison never matches. Unlike a --where on the same key, neither the fragment nor its --var values are redacted from an exported provenance.',
                    $key,
                ));
            }
        }

        $limit = PositiveIntOption::parse($input->getOption('limit'));
        if ($limit === null) {
            $io->getErrorStyle()->error('Invalid --limit: expected a positive integer; a zero or garbage value would read as LIMIT 0, an empty page posing as an empty stream.');

            return null;
        }

        return new InspectFilter(
            stream: $this->stringOrNull($input->getOption('stream')),
            category: $this->stringOrNull($input->getOption('category')),
            types: $types,
            afterSequence: $after,
            maxSequence: $to,
            afterTime: $afterTime,
            beforeTime: $beforeTime,
            conditions: $conditions,
            sqlFragment: $sqlFragment,
            vars: $vars,
            limit: $limit,
            direction: strtolower((string) $input->getOption('order')) === 'desc' ? Direction::Backward : Direction::Forward,
        );
    }

    /**
     * Expand each `--type` token, alias or FQCN, to its stored type set where the alias union
     * replaces the union FQCN, so a filter on the current name still matches rows written under an
     * old alias or the bare FQCN.
     *
     * @return list<string>|null the stored types, or null when a token cannot be resolved or the
     *                           option holds no usable token; error reported
     */
    private function resolveTypes(mixed $option, SymfonyStyle $io): ?array
    {
        if (! is_string($option) || $option === '') {
            return [];
        }

        $tokens = explode(',', $option)
                |> (static fn ($x) => array_map(trim(...), $x))
                |> (static fn ($x) => array_filter($x, static fn (string $t): bool => $t !== ''));

        // ',,,' or whitespace would yield zero predicate, a silent FULL dump where a subset was asked.
        if ($tokens === []) {
            $io->getErrorStyle()->error(sprintf('Invalid --type "%s" — it contains no usable token (CSV of aliases or FQCNs).', $option));

            return null;
        }

        $stored = [];

        foreach ($tokens as $token) {
            try {
                $class = $this->eventTypeMapper->toClass($token);
            } catch (UnknownEventType $e) {
                $io->getErrorStyle()->error($e->getMessage());

                return null;
            }

            foreach ($this->eventTypeMapper->storedTypesOf($class) as $type) {
                $stored[$type] = true;
            }
        }

        return array_keys($stored);
    }

    /**
     * Parse each `--where col.path<op>value` into a `JsonCondition`. The regex is the operator
     * allowlist `=` `!=` `>` `>=` `<` `<=` and pins the column to `header` or `content`; the path
     * and value stay raw, bound later. Multi-char operators are listed first, so `>=` does not read
     * as `>`.
     *
     * @return list<JsonCondition>|null null when a clause is malformed; error reported
     */
    private function parseWheres(mixed $option, SymfonyStyle $io): ?array
    {
        if (! is_array($option) || $option === []) {
            return [];
        }

        $conditions = [];

        foreach ($option as $expr) {
            $raw = is_string($expr) ? trim($expr) : '';

            if ($raw === '' || preg_match('/^(header|content)\.(.+?)(>=|<=|!=|=|>|<)(.*)$/s', $raw, $m) !== 1) {
                $io->getErrorStyle()->error(sprintf('Invalid --where "%s" — expected header|content.path<op>value, op in = != > >= < <=.', is_string($expr) ? $expr : ''));

                return null;
            }

            try {
                $column = trim($m[1]);
                $path = array_map(trim(...), explode('.', trim($m[2])));
                $operator = trim($m[3]);
                $value = trim($m[4]);
                $conditions[] = new JsonCondition($column, $path, $operator, $value);
            } catch (InvalidArgumentException $e) {
                $io->getErrorStyle()->error(sprintf('Invalid --where "%s" — %s', $expr, $e->getMessage()));

                return null;
            }
        }

        return $conditions;
    }

    /**
     * Parse each `--var key=value` into the bound bag for the `--sql` fragment's `:key`
     * placeholders.
     *
     * @return array<string, string>|null null when a pair is malformed; error reported
     */
    private function parseVars(mixed $option, SymfonyStyle $io): ?array
    {
        if (! is_array($option) || $option === []) {
            return [];
        }

        $vars = [];

        foreach ($option as $pair) {
            if (! is_string($pair) || ! str_contains($pair, '=')) {
                $io->getErrorStyle()->error(sprintf('Invalid --var "%s" — expected key=value.', is_string($pair) ? $pair : ''));

                return null;
            }

            [$key, $value] = explode('=', $pair, 2);

            if (preg_match('/^\w+$/', $key) !== 1) {
                $io->getErrorStyle()->error(sprintf('Invalid --var key "%s" — a :placeholder name is letters, digits and underscores only.', $key));

                return null;
            }

            if (array_key_exists($key, $vars)) {
                $io->getErrorStyle()->error(sprintf('Duplicate --var "%s" — the last value would win silently; pass each variable once.', $key));

                return null;
            }

            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * The accepted `--since`/`--until` shapes, strict on purpose; see `parseTime()`. The
     * micro-plus-offset shape is the round trip: it is exactly what the tool itself prints as
     * `recorded_at`, so a copied timestamp pastes back in.
     */
    private const array TIME_FORMATS = ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d H:i:sP', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s.u', 'Y-m-d\TH:i:s.uP'];

    /**
     * Parse a time bound STRICTLY against the listed formats, interpreted as UTC.
     *
     * Strict on purpose, where the free-form `DateTimeImmutable` parser silently accepted what an
     * operator would call a typo: `2026-02-30` rolled over to March 2nd instead of erroring, and
     * relative words such as `yesterday` parsed to a value depending on the execution day; an
     * inspection window must mean the same rows tomorrow. Rollovers are refused via the parser's
     * own warnings.
     *
     * A date-only `--until` means END of that day: an operator asking `--until 2026-05-01` wants
     * May 1st included, not excluded by a midnight-start bound.
     *
     * @return PointInTime|false|null the parsed time, null when absent, false when invalid; error reported
     */
    private function parseTime(mixed $option, string $name, SymfonyStyle $io): PointInTime|null|false
    {
        if (! is_string($option) || $option === '') {
            return null;
        }

        foreach (self::TIME_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $option, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue; // wrong shape for this format, or a rollover the parser flagged
            }

            if ($format === 'Y-m-d' && $name === 'until') {
                $parsed = $parsed->setTime(23, 59, 59, 999999);
            }

            try {
                return PointInTime::fromDateTime($parsed);
            } catch (InvalidDateTimeException) {
                break;
            }
        }

        $io->getErrorStyle()->error(sprintf(
            'Invalid --%s datetime: "%s". Accepted shapes: %s (UTC; no relative words, no rolled-over dates).',
            $name,
            $option,
            implode(', ', ['2026-05-01', '2026-05-01 10:30', '2026-05-01 10:30:00', '2026-05-01T10:30:00', '2026-05-01T10:30:00+02:00', '2026-05-01 10:30:00.123456', '2026-05-01T10:30:00.123456+00:00']),
        ));

        return false;
    }

    /**
     * Reject a non-numeric `--limit`/`--after`/`--to` loudly, since a silent `(int)` cast either
     * answers a different question than the one asked or dies far from the operator:
     *
     * - `--limit abc` becomes 0, which the filter refuses as an out-of-range page size
     * - `--after abc` becomes 0, from the start
     * - `--to 10x` becomes 10, the suffix dropped
     *
     * `--limit` also rejects 0, a positive page size being the only meaningful one; `--after`/`--to`
     * accept 0 as legitimate sequence bounds.
     */
    private function validateNumericOptions(InputInterface $input, SymfonyStyle $io): bool
    {
        foreach (['limit' => true, 'after' => false, 'to' => false] as $option => $positive) {
            $value = $input->getOption($option);

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (($positive && (int) $value === 0) || ! ctype_digit($value)) {
                $io->getErrorStyle()->error(sprintf('Invalid --%s "%s" — it must be a %s integer.', $option, $value, $positive ? 'positive' : 'non-negative'));

                return false;
            }

            // anti-overflow BEFORE the int cast: a 36-digit --limit would silently saturate at
            // PHP_INT_MAX, LIMIT 9223372036854775807, while the filter claimed "capped"
            if ($option === 'limit' && (strlen($value) > 10 || (int) $value > InspectFilter::MAX_LIMIT)) {
                $io->getErrorStyle()->error(sprintf('--limit %s exceeds the maximum of %d — pdo_pgsql buffers the whole result set client-side; narrow the window instead.', $value, InspectFilter::MAX_LIMIT));

                return false;
            }
        }

        return true;
    }

    private function renderer(string $format, bool $pretty, DocumentMeta $meta): ?EventRenderer
    {
        return match ($format) {
            'table' => new TableRenderer($this->eventTypeMapper, $this->veil),
            'json' => new JsonRenderer($meta, $this->eventTypeMapper, $this->veil, $pretty),
            'ndjson' => new NdjsonRenderer($this->eventTypeMapper, $this->veil),
            default => null,
        };
    }

    /**
     * The provenance the json document carries, built before the read; the renderer/writer complete
     * it with the count. Order is meaningful for the typed selectors only, since a whole-query mode
     * owns its order; `safe_head` is the applied upper bound, the min of `--to` and the watermark,
     * when `--safe-head` is set.
     *
     * @param  array<string, string>  $vars  the `--var` bag, parsed once by `execute()`
     *
     * @throws ClockExceptionContract when the system clock yields a non-canonical datetime
     */
    private function documentMeta(InputInterface $input, QueryFilter $filter, string $order, array $vars): DocumentMeta
    {
        $selectors = $this->stringOrNull($input->getOption('recipe')) === null
            && $this->stringOrNull($input->getOption('derived-stream')) === null;

        return new DocumentMeta(
            source: $this->documentSource($input, $vars),
            limit: $this->effectiveLimit($filter),
            generatedAt: $this->clock->now(),
            order: $selectors ? $order : null,
            safeHead: $input->getOption('safe-head') && $filter instanceof InspectFilter ? $filter->maxSequence : null,
        );
    }

    /**
     * The replayable origin of the page: the recipe plus its vars, the derived stream, or the typed
     * selectors as given; the raw option values the operator would retype, not the resolved
     * internals, so the alias expansion stays an implementation detail. The one exception to
     * "as given": a `--where` value on a declared personal key is `<redacted>`, since the document
     * is what leaves the process through `--out` and the export port, and a cleartext needle next
     * to the veiled rows would undo the veil.
     *
     * The exception stops at the parsed selectors. A `--sql` fragment and its `--var` bag travel
     * verbatim, because redaction there would have to guess which half of an arbitrary expression is
     * the needle, and a provenance edited on a guess is no longer the query that produced the page.
     * The escape hatch keeps its cost visible instead: a fragment naming a declared key is warned
     * about at parse time, and the warning says this is what it does not do.
     *
     * @param  array<string, string>  $vars  the `--var` bag, parsed once by `execute()`
     * @return array<string, mixed>
     */
    private function documentSource(InputInterface $input, array $vars): array
    {
        $recipe = $this->stringOrNull($input->getOption('recipe'));

        if ($recipe !== null) {
            return ['mode' => 'recipe', 'recipe' => $recipe, 'vars' => $vars];
        }

        $derived = $this->stringOrNull($input->getOption('derived-stream'));

        if ($derived !== null) {
            return ['mode' => 'derived_stream', 'derived_stream' => $derived];
        }

        $selectors = [];

        foreach (['stream', 'category', 'type', 'after', 'to', 'since', 'until', 'sql'] as $option) {
            $value = $this->stringOrNull($input->getOption($option));

            if ($value !== null) {
                $selectors[$option] = $value;
            }
        }

        $where = $input->getOption('where');

        if (is_array($where) && $where !== []) {
            $selectors['where'] = $this->redactedWheres(array_values(array_filter($where, is_string(...))));
        }

        if ($vars !== []) {
            $selectors['vars'] = $vars;
        }

        return ['mode' => 'selectors', 'selectors' => $selectors];
    }

    /**
     * Redact the value of every `--where` expression whose content path opens on a declared
     * personal key, keeping the predicate's shape replayable; every other expression passes
     * verbatim, a malformed one having already failed the run upstream.
     *
     * @param  list<string>  $wheres
     * @return list<string>
     */
    private function redactedWheres(array $wheres): array
    {
        return array_map(function (string $expr): string {
            if (preg_match('/^(content)\.(.+?)(>=|<=|!=|=|>|<)(.*)$/s', $expr, $m) !== 1) {
                return $expr;
            }

            $head = explode('.', $m[2])[0];

            return $this->veil->isDeclaredKey($head) ? $m[1].'.'.$m[2].$m[3].'<redacted>' : $expr;
        }, $wheres);
    }

    /**
     * The upper sequence bound: `--to` if given, capped at `safeHeadPosition()` when `--safe-head`
     * is set, the more restrictive winning. A null safe head, an empty store or the lowest position
     * in-flight, becomes 0 and selects nothing; the warning below keeps that empty page from
     * masquerading as "nothing matched".
     *
     * @return int|null|false the bound, null when unbounded, false when the watermark probe failed;
     *                        error reported
     */
    private function maxSequence(InputInterface $input, SymfonyStyle $io): int|null|false
    {
        $to = $this->intOrNull($input->getOption('to'));

        if (! $input->getOption('safe-head')) {
            return $to;
        }

        try {
            $safe = $this->streamReader->safeHeadPosition();
        } catch (Throwable $e) {
            // the module's doctrine: a refusal is an operator error, never a raw stack trace
            $io->getErrorStyle()->error(sprintf('--safe-head watermark probe failed: %s', $e->getMessage()));

            return false;
        }

        if ($safe === null) {
            $io->getErrorStyle()->warning('--safe-head: no position is safe to read yet (empty store, or the lowest position still in flight); the read is bounded at 0 and returns nothing.');
        }

        $safeMax = $safe?->toOrdinal() ?? 0;

        return $to === null ? $safeMax : min($to, $safeMax);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The cast cannot fail-open here: `validateNumericOptions()` already rejected non-digits.
     */
    private function intOrNull(mixed $value): ?int
    {
        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    /**
     * Print the WHERE, ORDER, and LIMIT the filter generates plus its bound values. The SELECT here
     * is illustrative, `e.*`; the real read selects the store's own columns. `apply()` runs inside
     * the try: a `--var` collision refuses there, and the refusal is an operator error here exactly
     * as it is on the real read path, never a raw stack trace.
     */
    private function dumpSql(QueryFilter $filter, SymfonyStyle $io): int
    {
        try {
            $qb = $this->connection->createQueryBuilder()->select('e.*')->from('event_store', 'e');
            $filter->apply($qb);

            $io->section('Generated SQL (illustrative SELECT)');
            $io->writeln($qb->getSQL());

            $bindings = [];
            foreach ($qb->getParameters() as $name => $value) {
                $bindings[] = sprintf(':%s = %s', $name, is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR));
            }

            if ($bindings !== []) {
                $io->section('Bindings');
                $io->listing($bindings);
            }

            return Command::SUCCESS;
        } catch (Throwable $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Print the Postgres EXPLAIN plan of the generated query; plain EXPLAIN plans, it does not run
     * the query. The SELECT is illustrative, `e.*`; the WHERE, ORDER, and LIMIT are the real read
     * shape.
     *
     * A raw `--sql` fragment gets the same `READ ONLY` transaction as the real read in
     * `renderRead()`: plain EXPLAIN cannot execute it today, but the guard is the module's declared
     * boundary; keeping both paths symmetric means a future `EXPLAIN ANALYZE`, which does run the
     * query, cannot turn this one into the unguarded writer vector.
     *
     * @throws Exception on a DBAL transaction failure rollback
     */
    private function explain(QueryFilter $filter, SymfonyStyle $io, bool $rawSql): int
    {
        try {
            // inside the try: apply() itself refuses a --var collision, an operator error like any
            $qb = $this->connection->createQueryBuilder()->select('e.*')->from('event_store', 'e');
            $filter->apply($qb);

            if ($rawSql) {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('SET TRANSACTION READ ONLY');
            }

            $plan = $this->connection
                ->executeQuery('EXPLAIN '.$qb->getSQL(), $qb->getParameters(), $qb->getParameterTypes())
                ->fetchFirstColumn();

            if ($rawSql) {
                $this->connection->rollBack(); // read-only, nothing to commit
            }
        } catch (Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            $io->getErrorStyle()->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->section('EXPLAIN');
        $io->writeln(array_map(strval(...), $plan));

        return Command::SUCCESS;
    }
}
