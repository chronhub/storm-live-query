<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use JsonException;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A single self-describing json document, `{meta, results}`, for tooling that wants one parseable
 * payload. Meta carries the replayable provenance and the truncation truth of source, limit, count,
 * and truncated: a file produced by `--output json > trace.json` still says what it is and whether
 * it is partial.
 *
 * Buffers the result, unlike the streaming `NdjsonRenderer`; prefer ndjson for large dumps and
 * line-oriented pipes. Compact by default; `--pretty` indents. Read the rows with `jq
 * '.results[]'`.
 *
 * @see NdjsonRenderer
 */
final readonly class JsonRenderer implements EventRenderer
{
    public function __construct(
        private DocumentMeta $meta,
        private EventTypeMapper $types,
        private PersonalDataVeil $veil,
        private bool $pretty = false,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws JsonException on a JSON encoding failure
     */
    public function render(iterable $records, OutputInterface $output): int
    {
        return $this->renderDocument(LiveQueryDocument::fromRecords($this->meta, $records, $this->types, $this->veil), $output);
    }

    /**
     * Render a PREBUILT document, so a run that also emits it through `--out` or the export port
     * builds and flattens the rows once instead of once per target.
     *
     * @throws JsonException on a JSON encoding failure
     */
    public function renderDocument(LiveQueryDocument $document, OutputInterface $output): int
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($this->pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $output->writeln(json_encode($document->toArray(), $flags | JSON_THROW_ON_ERROR));

        return $document->count();
    }
}
