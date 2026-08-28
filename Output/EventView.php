<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Output;

use Storm\Chronicler\Exception\NotADomainEvent;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Contracts\Chronicler\EventTypeMapper;

/**
 * Flattens a stored `EventRecord` into a render-friendly row, shared by every output format.
 *
 * The value LiveQuery adds over raw `psql`: the event is deserialized, so `content` is the domain
 * event's payload, not a jsonb blob.
 *
 * `type` is the CURRENT canonical alias, computed from the hydrated class via the mapper, NOT the
 * raw value of the store's `type` column: after an alias rename via `replaces` an old row displays
 * the new alias, the logical view, and the persisted spelling is not surfaced. Auditing the stored
 * bytes is `psql`'s job; exposing a separate `stored_type` would need `EventRecord` to carry the
 * raw alias, deferred until a consumer needs it. The document leaves the process through `--out`
 * files and the `LiveQueryExport` port, and the house rule holds on every outward surface: the
 * alias is the stable type, a class name is not, being rename-fragile and not portable
 * cross-language. The FQCN still rides along as `class`, the in-process hydration detail a dev
 * reading a trace wants at hand.
 *
 * `content` passes through the {@see \Storm\Chronicler\Record\PersonalDataVeil}: a `#[Personal]` class's declared keys show
 * what the store holds, either the ciphered envelope or a pre-marking row's cleartext, never the
 * rebuilt event's decrypted values. Inspection reads without decrypting, by doctrine.
 */
final class EventView
{
    /**
     * @return array{
     *     position: int,
     *     type: string,
     *     class: string,
     *     aggregate_id: string|null,
     *     recorded_at: string,
     *     content: array<string, mixed>,
     *     headers: array<string, scalar|array<mixed>|null>
     * }
     *
     * @throws NotADomainEvent when the record's envelope does not wrap a domain event
     */
    public static function toArray(EventRecord $record, EventTypeMapper $types, PersonalDataVeil $veil): array
    {
        $message = $record->message;
        $event = $record->event();

        return [
            'position' => $record->position->toOrdinal(),
            'type' => $types->toType($event::class),
            'class' => $event::class,
            'aggregate_id' => $message->aggregateId(),
            'recorded_at' => $record->recordedAt->toString(),
            'content' => $veil->veil($record),
            'headers' => $message->headers(),
        ];
    }
}
