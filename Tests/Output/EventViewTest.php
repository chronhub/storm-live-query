<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Output;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\LiveQuery\Output\EventView;
use Storm\LiveQuery\Tests\Fixture\SampleEvent;
use Storm\Message\Message;

final class EventViewTest extends TestCase
{
    #[Test]
    public function the_row_type_is_the_portable_alias_and_the_fqcn_rides_as_class(): void
    {
        // the document leaves the process through --out, the export port: `type` must be the store's own
        // stable alias, since a class name is rename-fragile and not portable, while the FQCN stays at
        // hand for the dev reading a trace.
        $mapper = new class() implements EventTypeMapper
        {
            public function toType(string $class): string
            {
                return 'sample.happened';
            }

            public function toClass(string $type): string
            {
                return SampleEvent::class;
            }

            public function versionOf(string $class): int
            {
                return 1;
            }

            public function storedTypesOf(string $class): array
            {
                return ['sample.happened'];
            }
        };

        $row = EventView::toArray(
            new EventRecord(
                new Message(new SampleEvent),
                SequencePosition::fromInt(7),
                PointInTime::from('2026-05-21T10:00:00.000000+00:00'),
            ),
            $mapper,
            new PersonalDataVeil,
        );

        self::assertSame('sample.happened', $row['type']);
        self::assertSame(SampleEvent::class, $row['class']);
        self::assertSame(7, $row['position']);
    }
}
