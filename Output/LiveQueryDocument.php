<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\LiveQuery\Export\LiveQueryExport;

use function count;

/**
 * The self-describing result document, one value for every emission target: the JSON stdout
 * renderer, the `--out` file, and the `LiveQueryExport` port all carry this same shape, so a result
 * can never lose its provenance or its truncation truth on the way out.
 *
 * Rows are `EventView`-flattened primitives, render-friendly and serialization-free; meta completes
 * itself with the count at `toArray()` time.
 *
 * @see LiveQueryExport
 */
final readonly class LiveQueryDocument
{
    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function __construct(
        public DocumentMeta $meta,
        public array $results,
    ) {}

    /**
     * @param  iterable<EventRecord>  $records
     *
     * @throws NotADomainEvent when a record's envelope does not wrap a domain event
     */
    public static function fromRecords(DocumentMeta $meta, iterable $records, EventTypeMapper $types, PersonalDataVeil $veil): self
    {
        $rows = [];

        foreach ($records as $record) {
            $rows[] = EventView::toArray($record, $types, $veil);
        }

        return new self($meta, $rows);
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * @return array{meta: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray($this->count()),
            'results' => $this->results,
        ];
    }
}
