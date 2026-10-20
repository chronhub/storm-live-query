<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Clock\Clock;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\LiveQuery\Console\InspectDocument;
use Storm\LiveQuery\Console\InspectEventsCommand;
use Storm\LiveQuery\Console\InspectRequest;
use Storm\LiveQuery\Console\RenderPlan;
use Storm\LiveQuery\Export\LiveQueryExport;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Output\DocumentMeta;
use Storm\LiveQuery\Output\LiveQueryDocument;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Storm\Stream\StreamName;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The document of an inspection without a console: the provenance that replays the page, the limit
 * each filter shape applies, and the emission to the `--out` file and the export port, each target
 * confirmed as it lands.
 */
final class InspectDocumentTest extends TestCase
{
    private const string NOW = '2026-10-16T12:00:00.000000+00:00';

    /** @var list<string> */
    private array $confirmations = [];

    #[Test]
    public function each_storm_filter_shape_reads_its_own_limit(): void
    {
        self::assertSame(7, InspectDocument::limitOf(new InspectFilter(limit: 7)));
        self::assertSame(8, InspectDocument::limitOf(new DerivedStreamFilter(new StreamName('large'), limit: 8)));
        self::assertSame(9, InspectDocument::limitOf(new CorrelationFeedFilter(['c-1'], limit: 9)));
        self::assertNull(InspectDocument::limitOf($this->createStub(QueryFilter::class)));
    }

    #[Test]
    public function a_selector_read_replays_the_selectors_as_typed_with_its_order(): void
    {
        $request = $this->request(['--stream' => 'Order-1', '--to' => '9', '--sql' => 'x = :k', '--var' => ['k=1'], '--order' => 'DESC', '--limit' => '5']);

        $meta = $this->document()->meta($request, new InspectFilter(limit: 5));

        self::assertSame(['mode' => 'selectors', 'selectors' => ['stream' => 'Order-1', 'to' => '9', 'sql' => 'x = :k', 'vars' => ['k' => '1']]], $meta->source);
        self::assertSame(5, $meta->limit);
        self::assertSame('desc', $meta->order);
        self::assertSame(self::NOW, $meta->generatedAt->toString());
        self::assertNull($meta->safeHead);
    }

    #[Test]
    public function a_where_on_a_declared_personal_key_travels_redacted(): void
    {
        $request = $this->request(['--where' => ['content.email.domain=acme.test', 'header.email=x', 'content.amount>=10']]);

        $meta = $this->document()->meta($request, new InspectFilter);

        self::assertSame(['content.email.domain=<redacted>', 'header.email=x', 'content.amount>=10'], $meta->source['selectors']['where']);
    }

    #[Test]
    public function the_safe_head_is_the_bound_the_filter_applied(): void
    {
        $request = $this->request(['--safe-head' => true]);

        self::assertSame(4, $this->document()->meta($request, new InspectFilter(maxSequence: 4))->safeHead);
        self::assertNull($this->document()->meta($request, $this->createStub(QueryFilter::class))->safeHead);
    }

    #[Test]
    public function a_whole_query_mode_replays_its_name_and_owns_its_order(): void
    {
        $recipe = $this->document()->meta($this->request(['--recipe' => 'trace', '--var' => ['id=c-1']]), new CorrelationFeedFilter(['c-1'], limit: 1000));
        $derived = $this->document()->meta($this->request(['--derived-stream' => 'large']), new DerivedStreamFilter(new StreamName('large'), limit: 100));

        self::assertSame(['mode' => 'recipe', 'recipe' => 'trace', 'vars' => ['id' => 'c-1']], $recipe->source);
        self::assertNull($recipe->order);
        self::assertSame(1000, $recipe->limit);
        self::assertSame(['mode' => 'derived_stream', 'derived_stream' => 'large'], $derived->source);
        self::assertNull($derived->order);
    }

    #[Test]
    public function the_out_file_receives_the_document_and_is_confirmed(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'inspect');
        self::assertIsString($out);

        try {
            $this->document()->emit($this->emptyDocument(), $this->plan(out: $out), $this->confirm(...));

            self::assertSame(["Document written to {$out} (0 rows)."], $this->confirmations);
            $written = (string) file_get_contents($out);
            self::assertStringEndsWith(PHP_EOL, $written);
            self::assertStringNotContainsString("\n    ", $written);
            self::assertSame($this->emptyDocument()->toArray(), json_decode($written, true, 512, JSON_THROW_ON_ERROR));
        } finally {
            @unlink($out);
        }
    }

    #[Test]
    public function a_pretty_out_file_is_indented(): void
    {
        $out = tempnam(sys_get_temp_dir(), 'inspect');
        self::assertIsString($out);

        try {
            $this->document()->emit($this->emptyDocument(), $this->plan(out: $out, pretty: true), $this->confirm(...));

            self::assertStringContainsString("\n    ", (string) file_get_contents($out));
        } finally {
            @unlink($out);
        }
    }

    #[Test]
    public function an_unwritable_out_file_is_refused_with_its_reason(): void
    {
        $out = sys_get_temp_dir().'/no-such-directory-'.bin2hex(random_bytes(4)).'/page.json';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Could not write the document to "%s": ', $out));

        $this->document()->emit($this->emptyDocument(), $this->plan(out: $out), $this->confirm(...));
    }

    #[Test]
    public function the_export_port_receives_the_document_and_is_confirmed(): void
    {
        $export = new class() implements LiveQueryExport
        {
            public ?LiveQueryDocument $received = null;

            public function export(LiveQueryDocument $document): void
            {
                $this->received = $document;
            }
        };
        $document = $this->emptyDocument();

        $this->document($export)->emit($document, $this->plan(export: true), $this->confirm(...));

        self::assertSame($document, $export->received);
        self::assertSame([sprintf('Document exported through %s (0 rows).', $export::class)], $this->confirmations);
    }

    #[Test]
    public function a_plan_without_a_target_emits_nothing(): void
    {
        $export = $this->createMock(LiveQueryExport::class);
        $export->expects($this->never())->method('export');

        $this->document($export)->emit($this->emptyDocument(), $this->plan(), $this->confirm(...));

        self::assertSame([], $this->confirmations);
    }

    private function document(?LiveQueryExport $export = null): InspectDocument
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(PointInTime::from(self::NOW));

        return new InspectDocument(
            $clock,
            new PersonalDataVeil([stdClass::class => ['subject' => 'id', 'keys' => ['email'], 'fallbacks' => ['email' => null]]]),
            $export,
        );
    }

    private function emptyDocument(): LiveQueryDocument
    {
        return LiveQueryDocument::fromRecords(
            new DocumentMeta(['mode' => 'selectors', 'selectors' => []], 100, PointInTime::from(self::NOW), 'asc'),
            [],
            $this->createStub(EventTypeMapper::class),
            new PersonalDataVeil,
        );
    }

    private function plan(?string $out = null, bool $export = false, bool $pretty = false): RenderPlan
    {
        return new RenderPlan(
            rawSql: false,
            wallclock: false,
            effectiveLimit: 100,
            truncationHint: '',
            out: $out,
            export: $export,
            pretty: $pretty,
            meta: new DocumentMeta([], 100, PointInTime::from(self::NOW)),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function request(array $options): InspectRequest
    {
        $definition = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $this->createStub(Connection::class),
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->createStub(Clock::class),
            $this->createStub(StreamExistence::class),
        )->getDefinition();

        return InspectRequest::fromInput(new ArrayInput($options, $definition));
    }

    private function confirm(string $confirmation): void
    {
        $this->confirmations[] = $confirmation;
    }
}
