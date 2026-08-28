<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Output;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Evolution\IdentityEventTypeMapper;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Clock\PointInTime;
use Storm\LiveQuery\Output\TableRenderer;
use Storm\LiveQuery\Tests\Fixture\SampleEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Symfony\Component\Console\Output\BufferedOutput;

final class TableRendererTest extends TestCase
{
    #[Test]
    public function renders_a_row_per_record_and_returns_the_count(): void
    {
        $output = new BufferedOutput;

        $count = new TableRenderer(new IdentityEventTypeMapper, new PersonalDataVeil)->render(
            [$this->record(42, 'account-7'), $this->record(43, null)],
            $output,
        );

        self::assertSame(2, $count);

        $rendered = $output->fetch();
        // the column headers + the short row values; long cells are wrapped by the table, so not asserted
        foreach (['position', 'type', 'aggregate_id', 'recorded_at', 'content'] as $header) {
            self::assertStringContainsString($header, $rendered);
        }
        self::assertStringContainsString('account-7', $rendered);
        self::assertStringContainsString('42', $rendered);
    }

    #[Test]
    public function renders_an_empty_table_and_returns_zero(): void
    {
        $count = new TableRenderer(new IdentityEventTypeMapper, new PersonalDataVeil)->render([], new BufferedOutput);

        self::assertSame(0, $count);
    }

    /**
     * @param  positive-int  $position
     */
    private function record(int $position, ?string $aggregateId): EventRecord
    {
        $headers = $aggregateId !== null ? [Header::AggregateId->value => $aggregateId] : [];

        return new EventRecord(
            new Message(new SampleEvent, $headers),
            SequencePosition::fromInt($position),
            PointInTime::from('2026-05-21T10:00:00.000000+00:00'),
        );
    }
}
