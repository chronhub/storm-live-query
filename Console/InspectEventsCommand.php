<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Override;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\LiveQuery\Export\LiveQueryExport;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Output\DocumentMeta;
use Storm\LiveQuery\Output\EventRenderer;
use Storm\LiveQuery\Output\JsonRenderer;
use Storm\LiveQuery\Output\LiveQueryDocument;
use Storm\LiveQuery\Output\NdjsonRenderer;
use Storm\LiveQuery\Output\TableRenderer;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Storm\Stream\Exception\InvalidStreamException;
use Storm\Stream\StreamCategory;
use Storm\Stream\StreamName;
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
 * A thin layer over `StreamReader::retrieveByFilter()`: it maps options to a typed, parameter-bound
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
    private readonly InspectFilterFactory $filters;

    private readonly InspectDocument $document;

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

        $this->filters = new InspectFilterFactory($eventTypeMapper, $recipes, $streamReader, $veil);
        $this->document = new InspectDocument($clock, $veil, $export);
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

        try {
            $request = InspectRequest::fromInput($input);

            if ($request->export && ! $this->export instanceof LiveQueryExport) {
                $io->getErrorStyle()->error('--export has no adapter: bind a LiveQueryExport implementation in the app (storm ships none — the destination is yours).');

                return Command::FAILURE;
            }

            return $this->inspect($request, $output, $io);
        } catch (InvalidInspectOption $e) {
            $io->getErrorStyle()->error($e->getMessage());

            return Command::INVALID;
        }
    }

    /**
     * Resolve and run the parsed request under its statement timeout, restored however the run ends.
     *
     * @throws InvalidInspectOption when the request cannot be resolved against the store
     * @throws ClockExceptionContract when the system clock yields a non-canonical datetime
     */
    private function inspect(InspectRequest $request, OutputInterface $output, SymfonyStyle $io): int
    {
        $priorTimeout = null;

        if ($request->timeout > 0) {
            $prior = $this->connection->fetchOne('SHOW statement_timeout');
            $priorTimeout = is_string($prior) ? $prior : '0';
            $this->connection->executeStatement('SET statement_timeout = '.($request->timeout * 1000));
        }

        try {
            // advisories go to stderr like every warning, so piped json/ndjson stdout stays parseable
            $filter = $this->filters->filterFor($request, $io->getErrorStyle()->warning(...));

            if ($request->dumpSql) {
                return $this->dumpSql($filter, $io);
            }

            if ($request->explain) {
                return $this->explain($filter, $io, rawSql: $request->sqlFragment !== null);
            }

            $meta = $this->document->meta($request, $filter);

            $renderer = $this->renderer($request->format, $request->pretty, $meta);

            if (! $renderer instanceof EventRenderer) {
                $io->getErrorStyle()->error(sprintf('Unknown --output "%s" (use table | json | ndjson).', $request->format));

                return Command::INVALID;
            }

            return $this->renderRead($filter, $renderer, $output, $io, new RenderPlan(
                rawSql: $request->sqlFragment !== null,
                wallclock: $request->order === 'wallclock',
                effectiveLimit: InspectDocument::limitOf($filter),
                truncationHint: $this->truncationHint($request),
                out: $request->out,
                export: $request->export,
                pretty: $request->pretty,
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
     * failure exit; nothing escapes.
     *
     * A typed-selector, recipe, or derived-stream read is a single SELECT, a consistent snapshot
     * under autocommit and structurally read-only since it only builds a WHERE, JOIN, or ORDER BY, so
     * it runs directly with no transaction. Only a user-supplied raw `--sql` fragment, flagged by
     * `rawSql`, is wrapped in a `SET TRANSACTION READ ONLY` transaction: that string is untrusted,
     * so Postgres rejects any writer such as DML or a writable CTE inside it. This is a writer
     * guard, not a sandbox; `READ ONLY` does not block a row lock `SELECT ... FOR UPDATE` or a
     * side-effecting function such as `pg_advisory_lock`, `dblink`, `pg_read_file`, which the
     * fragment author owns. Recipes are DI-discovered trusted PHP, not raw CLI input, and reject
     * `--sql` anyway, so they never need the guard. The transaction covers the read alone: the
     * cursor is drained inside it and it is released before the first byte is rendered, so an
     * output that stalls, a slow pipe or a paused pager, never holds it open.
     *
     * A page that fills the limit is indistinguishable from a complete result, so the renderer's
     * count is the truncation signal: a count equal to `effectiveLimit` warns on stderr, never on
     * stdout, so piped json/ndjson stay parseable. It false-positives when the result sits exactly
     * at the limit, the price of not over-fetching. A null `effectiveLimit`, a recipe's own filter
     * shape, means no signal is possible.
     *
     * When the plan emits a document through `--out` and/or `--export`, or renders json to stdout,
     * the read is materialized once and one `LiveQueryDocument` feeds every target, screen
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
                // re-sorted once: screen and document show the same order
                $events = $this->byWallClock($events);
            }

            if ($plan->rawSql) {
                // drained inside the guard: the reader's generator runs its SQL at the first iteration, so
                // releasing the transaction over a still-lazy cursor would run the untrusted fragment
                // outside `READ ONLY`, the reverse of the guard. Released before the render: `pdo_pgsql`
                // buffers the whole result client-side anyway and the hard limit bounds it, so nothing is
                // gained by rendering under the transaction and a stalled output would hold it open
                $events = is_array($events) ? $events : iterator_to_array($events, preserve_keys: false);
                $this->connection->rollBack(); // read-only, nothing to commit
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

            if ($document !== null && $plan->emitsDocument()) {
                // each emission is confirmed on stderr, visible at the terminal and absent from pipes
                $this->document->emit($document, $plan, $io->getErrorStyle()->writeln(...));
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
     * Say why a zero-row selector result is empty: a typo'd `--stream` is otherwise
     * indistinguishable from a stream on which nothing happened, and the operator's natural but
     * possibly false conclusion is the latter. One existence probe, reported on stderr so piped
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
                $exists = $this->existence->hasCategory(new StreamCategory($filter->category));
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
     * The truncation warning's wording per mode, where the sprintf `%d` is the effective limit.
     * Each mode only advertises the remedy it actually honors: the typed selectors take `--limit`
     * and `--after`; a derived stream takes `--limit` only, rejecting `--after`; a recipe owns its
     * limit, so neither flag is advertised. The paging cursor follows the direction:
     * a descending page ends on its lowest sequence, so the next page needs the upper bound `--to`
     * below it; following the ascending `--after` advice re-reads the same page forever.
     */
    private function truncationHint(InspectRequest $request): string
    {
        return match (true) {
            $request->recipe !== null => 'Reached the recipe limit %d; more rows may exist.',
            $request->derivedStream !== null => 'Reached --limit %d; more rows may exist — raise --limit.',
            $request->order === 'desc' => 'Reached --limit %d; more rows may exist — raise --limit, or page DOWN with --to below the lowest sequence shown.',
            default => 'Reached --limit %d; more rows may exist — raise --limit or page with --after.',
        };
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
     * Print the WHERE, ORDER BY, and LIMIT the filter generates plus its bound values. The SELECT
     * here is illustrative, `e.*`; the real read selects the store's own columns. `apply()` runs
     * inside the try: a `--var` collision refuses there, and the refusal is an operator error here
     * exactly as it is on the real read path, never a raw stack trace.
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
     * the query. The SELECT is illustrative, `e.*`; the WHERE, ORDER BY, and LIMIT are the real
     * read shape.
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
