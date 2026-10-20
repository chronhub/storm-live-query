<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use Closure;
use JsonException;
use RuntimeException;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Storm\Contracts\Clock\ClockExceptionContract;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\LiveQuery\Export\LiveQueryExport;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Output\DocumentMeta;
use Storm\LiveQuery\Output\LiveQueryDocument;

/**
 * The self-describing document of a `storm:events:inspect` read, for its export: the provenance
 * that replays the page, and its emission to the `--out` file and the `LiveQueryExport` port.
 */
final readonly class InspectDocument
{
    /**
     * @param  Clock<PointInTime>  $clock
     * @param  LiveQueryExport|null  $export  the app-bound outbound adapter; null when none is wired
     */
    public function __construct(
        private Clock $clock,
        private PersonalDataVeil $veil,
        private ?LiveQueryExport $export,
    ) {}

    /**
     * The limit the chosen filter applies, readable on every storm-owned filter shape, a recipe's
     * own included, for example `correlation_trace`'s 1000 on its `CorrelationFeedFilter`. A recipe
     * returning a shape from outside that set reads as null: the bound is unknown, so no truncation
     * signal is possible.
     */
    public static function limitOf(QueryFilter $filter): ?int
    {
        return match (true) {
            $filter instanceof InspectFilter,
            $filter instanceof CorrelationFeedFilter,
            $filter instanceof DerivedStreamFilter => $filter->limit,
            default => null,
        };
    }

    /**
     * The provenance the json document carries, built before the read; the renderer and the writer
     * complete it with the count. Order is meaningful for the typed selectors only, since a
     * whole-query mode owns its order; `safe_head` is the applied upper bound, the min of `--to` and
     * the watermark, when `--safe-head` is set.
     *
     * @throws ClockExceptionContract when the system clock yields a non-canonical datetime
     */
    public function meta(InspectRequest $request, QueryFilter $filter): DocumentMeta
    {
        return new DocumentMeta(
            source: $this->source($request),
            limit: self::limitOf($filter),
            generatedAt: $this->clock->now(),
            order: $request->readsSelectors() ? $request->order : null,
            safeHead: $request->safeHead && $filter instanceof InspectFilter ? $filter->maxSequence : null,
        );
    }

    /**
     * Emit the document to the plan's targets: the `--out` file, a local convenience where the
     * operator reads a table on screen and keeps the document, and/or the `LiveQueryExport` port,
     * the app-bound adapter whose failure throws since emission is part of the asked gesture. Each
     * emission is confirmed through `$confirm` as soon as it lands.
     *
     * @param  Closure(string): void  $confirm
     *
     * @throws RuntimeException when the `--out` file cannot be written
     * @throws JsonException on a JSON encoding failure
     */
    public function emit(LiveQueryDocument $document, RenderPlan $plan, Closure $confirm): void
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

            $confirm(sprintf('Document written to %s (%d rows).', $plan->out, $document->count()));
        }

        if ($plan->export && $this->export instanceof LiveQueryExport) {
            $this->export->export($document);

            $confirm(sprintf('Document exported through %s (%d rows).', $this->export::class, $document->count()));
        }
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
     * @return array<string, mixed>
     */
    private function source(InspectRequest $request): array
    {
        if ($request->recipe !== null) {
            return ['mode' => 'recipe', 'recipe' => $request->recipe, 'vars' => $request->vars];
        }

        if ($request->derivedStream !== null) {
            return ['mode' => 'derived_stream', 'derived_stream' => $request->derivedStream];
        }

        $selectors = $request->typedSelectors;

        if ($request->typedWheres !== null) {
            $selectors['where'] = $this->redactedWheres($request->typedWheres);
        }

        if ($request->vars !== []) {
            $selectors['vars'] = $request->vars;
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
            if (preg_match('/^(content)\.(.+?)(>=|<=|!=|=|>|<)(.*)$/s', trim($expr), $m) !== 1) {
                return $expr;
            }

            $head = trim(explode('.', $m[2])[0]);

            return $this->veil->isDeclaredKey($head) ? $m[1].'.'.$m[2].$m[3].'<redacted>' : $expr;
        }, $wheres);
    }
}
