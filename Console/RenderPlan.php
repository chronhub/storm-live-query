<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use Storm\LiveQuery\Output\DocumentMeta;

/**
 * The resolved read-and-emit plan of one inspection run, what `execute()` decided, handed to the
 * read path as one value:
 *
 * - The write-guard mode, `rawSql`
 * - The page shaping, `wallclock`
 * - The truncation-signal inputs, `effectiveLimit` and the mode-aware hint
 * - The emission targets: the out file, the export-port flag, `pretty`, the document meta
 *
 * @see InspectEventsCommand
 */
final readonly class RenderPlan
{
    public function __construct(
        public bool $rawSql,
        public bool $wallclock,
        public ?int $effectiveLimit,
        public string $truncationHint,
        public ?string $out,
        public bool $export,
        public bool $pretty,
        public DocumentMeta $meta,
    ) {}

    /**
     * Whether the run emits a document besides stdout; the read is then materialized once and
     * shared by every target.
     */
    public function emitsDocument(): bool
    {
        return $this->out !== null || $this->export;
    }
}
