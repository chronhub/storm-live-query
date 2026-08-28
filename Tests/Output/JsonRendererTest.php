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
use Storm\LiveQuery\Output\DocumentMeta;
use Storm\LiveQuery\Output\JsonRenderer;
use Storm\LiveQuery\Output\LiveQueryDocument;
use Storm\LiveQuery\Tests\Fixture\SampleEvent;
use Storm\Message\Header;
use Storm\Message\Message;
use Symfony\Component\Console\Output\BufferedOutput;

use function json_decode;

final class JsonRendererTest extends TestCase
{
    #[Test]
    public function rendering_from_records_answers_the_same_document_as_the_prebuilt_path(): void
    {
        // the command prebuilds the document and calls renderDocument, so this renderer's own
        // interface entry point is the one every OTHER consumer of EventRenderer arrives through.
        // The two must not drift: a render() that dropped the veil or the type mapper would leak or
        // mislabel exactly where the command's tests never look
        $records = [$this->record(42, 'account-7'), $this->record(43, null)];
        $meta = $this->meta();

        $streamed = new BufferedOutput;
        $count = $this->renderer($meta)->render($records, $streamed);

        $prebuilt = new BufferedOutput;
        $this->renderer($meta)->renderDocument(
            LiveQueryDocument::fromRecords($meta, $records, new IdentityEventTypeMapper, new PersonalDataVeil),
            $prebuilt,
        );

        self::assertSame(2, $count, 'the count is the command truncation signal, and render() owes it too');

        $rendered = $streamed->fetch();
        self::assertSame($prebuilt->fetch(), $rendered);

        // and the equality is over a real document, not two identical silences
        /** @var array{meta: array<string, mixed>, results: list<array<string, mixed>>} $payload */
        $payload = json_decode($rendered, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['meta']['count']);
        self::assertTrue($payload['meta']['truncated'], 'a page that filled its limit says so');
        self::assertCount(2, $payload['results']);
    }

    private function meta(): DocumentMeta
    {
        return new DocumentMeta(
            source: ['mode' => 'selectors', 'category' => 'account'],
            limit: 2,
            generatedAt: PointInTime::from('2026-05-21T10:00:00.000000+00:00'),
        );
    }

    private function renderer(DocumentMeta $meta): JsonRenderer
    {
        return new JsonRenderer($meta, new IdentityEventTypeMapper, new PersonalDataVeil);
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
