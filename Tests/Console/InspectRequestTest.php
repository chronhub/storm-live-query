<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Clock\Clock;
use Storm\LiveQuery\Console\InspectEventsCommand;
use Storm\LiveQuery\Console\InspectRequest;
use Storm\LiveQuery\Console\InvalidInspectOption;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * The parse of `storm:events:inspect` without a console: every refusal is one `InvalidInspectOption`
 * carrying the operator's message, never a `false` or a `null` a caller could take for an absent
 * option.
 */
final class InspectRequestTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $options
     */
    #[Test]
    #[DataProvider('refusals')]
    public function a_refused_option_throws_the_one_option_exception(array $options, string $message): void
    {
        $this->expectException(InvalidInspectOption::class);
        $this->expectExceptionMessage($message);

        InspectRequest::fromInput($this->input($options));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        // one case per parser that answered a sentinel, then the combinations the request refuses
        yield 'an unknown order' => [['--order' => 'sideways'], 'Unknown --order "sideways" (use asc | desc | wallclock).'];
        yield 'a non-digit after' => [['--after' => '1x'], 'Invalid --after "1x" — it must be a non-negative integer.'];
        yield 'a non-digit to' => [['--to' => '-3'], 'Invalid --to "-3" — it must be a non-negative integer.'];
        yield 'a zero limit' => [['--limit' => '0'], 'Invalid --limit "0" — it must be a positive integer up to 50000.'];
        yield 'a limit above the cap' => [['--limit' => '50001'], '--limit 50001 exceeds the maximum of 50000'];
        yield 'out with dump-sql' => [['--out' => 'x.json', '--dump-sql' => true], '--out/--export require a read'];
        yield 'export with explain' => [['--export' => true, '--explain' => true], '--out/--export require a read'];
        yield 'pretty on a table' => [['--pretty' => true], '--pretty shapes the json document only'];
        yield 'a var without a key' => [['--var' => ['novalue']], 'Invalid --var "novalue" — expected key=value.'];
        yield 'a var key out of the placeholder alphabet' => [['--var' => ['a-b=1']], 'Invalid --var key "a-b"'];
        yield 'a var given twice' => [['--var' => ['k=1', 'k=2']], 'Duplicate --var "k"'];
        yield 'a non-digit timeout' => [['--timeout' => '1s'], 'Invalid --timeout "1s" — whole seconds up to 2147483, 0 to disable.'];
        yield 'a timeout past the postgres range' => [['--timeout' => '2147484'], 'Invalid --timeout "2147484"'];
        yield 'both whole-query modes' => [['--derived-stream' => 'large', '--recipe' => 'trace'], '--derived-stream cannot be combined with --recipe.'];
        yield 'an order on a derived stream' => [['--derived-stream' => 'large', '--order' => 'asc'], '--order cannot be combined with --derived-stream — the derived stream owns its order (target_position).'];
        yield 'an order on a recipe' => [['--recipe' => 'trace', '--order' => 'desc'], '--order cannot be combined with --recipe — the recipe owns its whole query.'];
        yield 'a safe head on a derived stream' => [['--derived-stream' => 'large', '--safe-head' => true], '--safe-head cannot be combined with --derived-stream — a derived stream is gap-free by construction, no watermark applies.'];
        yield 'a safe head on a recipe' => [['--recipe' => 'trace', '--safe-head' => true], '--safe-head cannot be combined with --recipe — the recipe owns its whole query.'];
        yield 'a limit on a recipe' => [['--recipe' => 'trace', '--limit' => '10'], '--limit cannot be combined with --recipe — the recipe owns its limit.'];
        yield 'a selector on a recipe' => [['--recipe' => 'trace', '--where' => ['content.a=1']], '--recipe cannot be combined with filter options'];
        yield 'a selector on a derived stream' => [['--derived-stream' => 'large', '--stream' => 'order-1'], '--derived-stream cannot be combined with filter options'];
        yield 'a malformed derived stream' => [['--derived-stream' => '-bad'], 'Invalid --derived-stream "-bad": '];
        yield 'a type with no usable token' => [['--type' => ' , ,'], 'Invalid --type " , ," — it contains no usable token'];
        yield 'an unparseable since' => [['--since' => 'yesterday'], 'Invalid --since datetime: "yesterday".'];
        yield 'a rolled-over until' => [['--until' => '2026-02-30'], 'Invalid --until datetime: "2026-02-30".'];
        yield 'a since after the until' => [['--since' => '2026-05-02', '--until' => '2026-05-01'], '--since is after --until — the window is empty; check the bounds.'];
        yield 'a where outside the grammar' => [['--where' => ['amount>1']], 'Invalid --where "amount>1" — expected header|content.path<op>value'];
        yield 'a where with an empty path segment' => [['--where' => ['content..a=1']], 'Invalid --where "content..a=1" — '];
    }

    #[Test]
    public function the_selectors_come_out_typed(): void
    {
        $request = InspectRequest::fromInput($this->input([
            '--stream' => 'Order-1',
            '--category' => 'Order',
            '--type' => ' a , b ',
            '--after' => '3',
            '--to' => '0',
            '--since' => '2026-05-01 10:30',
            '--until' => '2026-05-01',
            '--where' => ['content.amount >= 10'],
            '--sql' => 'e.version > :v',
            '--var' => ['v=2'],
            '--order' => 'DESC',
            '--limit' => '00042',
            '--timeout' => '7',
            '--safe-head' => true,
        ]));

        self::assertSame('desc', $request->order);
        self::assertSame(42, $request->limit);
        self::assertSame(7, $request->timeout);
        self::assertTrue($request->safeHead);
        self::assertSame('order-1', $request->stream);
        self::assertSame('order', $request->category);
        self::assertSame(['a', 'b'], $request->typeTokens);
        self::assertSame(3, $request->afterSequence);
        self::assertSame(0, $request->toSequence);
        self::assertSame('2026-05-01T10:30:00.000000+00:00', $request->afterTime?->toString());
        // a date-only until means the end of that day
        self::assertSame('2026-05-01T23:59:59.999999+00:00', $request->beforeTime?->toString());
        self::assertCount(1, $request->conditions);
        self::assertSame(['content', ['amount'], '>=', '10'], [$request->conditions[0]->column, $request->conditions[0]->path, $request->conditions[0]->operator, $request->conditions[0]->value]);
        self::assertSame('e.version > :v', $request->sqlFragment);
        self::assertSame(['v' => '2'], $request->vars);
        self::assertTrue($request->readsSelectors());
    }

    #[Test]
    public function the_typed_selectors_keep_what_the_operator_typed(): void
    {
        $request = InspectRequest::fromInput($this->input([
            '--stream' => 'Order-1',
            '--type' => 'a,b',
            '--to' => '9',
            '--sql' => '1 = 1',
            '--where' => ['content.amount>10'],
        ]));

        self::assertSame(['stream' => 'Order-1', 'type' => 'a,b', 'to' => '9', 'sql' => '1 = 1'], $request->typedSelectors);
        self::assertSame(['content.amount>10'], $request->typedWheres);
        self::assertNull(InspectRequest::fromInput($this->input([]))->typedWheres);
    }

    #[Test]
    public function an_absent_option_reads_as_absent(): void
    {
        $request = InspectRequest::fromInput($this->input([]));

        self::assertSame('asc', $request->order);
        self::assertSame(100, $request->limit);
        self::assertSame(30, $request->timeout);
        self::assertSame('table', $request->format);
        self::assertNull($request->out);
        self::assertFalse($request->export);
        self::assertFalse($request->pretty);
        self::assertFalse($request->dumpSql);
        self::assertFalse($request->explain);
        self::assertFalse($request->safeHead);
        self::assertSame([], $request->vars);
        self::assertSame([], $request->typeTokens);
        self::assertSame([], $request->conditions);
        self::assertSame([], $request->typedSelectors);
        self::assertNull($request->stream);
        self::assertNull($request->category);
        self::assertNull($request->afterSequence);
        self::assertNull($request->toSequence);
        self::assertNull($request->afterTime);
        self::assertNull($request->beforeTime);
        self::assertNull($request->sqlFragment);
        self::assertNull($request->recipe);
        self::assertNull($request->derivedStream);
        self::assertNull($request->derivedTarget);
    }

    #[Test]
    public function a_whole_query_mode_carries_its_name_and_the_limit_it_honors(): void
    {
        $derived = InspectRequest::fromInput($this->input(['--derived-stream' => 'large_withdrawals', '--limit' => '5']));
        $recipe = InspectRequest::fromInput($this->input(['--recipe' => 'correlation_trace', '--var' => ['id=c-1']]));

        self::assertSame('large_withdrawals', $derived->derivedStream);
        self::assertSame('large_withdrawals', $derived->derivedTarget?->toString());
        self::assertSame(5, $derived->limit);
        self::assertFalse($derived->readsSelectors());
        self::assertSame('correlation_trace', $recipe->recipe);
        self::assertSame(['id' => 'c-1'], $recipe->vars);
        self::assertFalse($recipe->readsSelectors());
    }

    #[Test]
    public function the_largest_timeout_postgres_holds_and_zero_are_accepted(): void
    {
        self::assertSame(InspectRequest::MAX_TIMEOUT_SECONDS, InspectRequest::fromInput($this->input(['--timeout' => '2147483']))->timeout);
        self::assertSame(0, InspectRequest::fromInput($this->input(['--timeout' => '0']))->timeout);
        self::assertSame(InspectFilter::MAX_LIMIT, InspectRequest::fromInput($this->input(['--limit' => '50000']))->limit);
    }

    #[Test]
    public function a_malformed_stream_or_category_stays_raw_for_the_diagnostic(): void
    {
        $request = InspectRequest::fromInput($this->input(['--stream' => '-bad', '--category' => 'order-1']));

        self::assertSame('-bad', $request->stream);
        self::assertSame('order-1', $request->category); // a qualified name is no category
        self::assertSame('-bad', InspectRequest::fromInput($this->input(['--category' => '-bad']))->category);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function input(array $options): ArrayInput
    {
        return new ArrayInput($options, $this->definition());
    }

    private function definition(): InputDefinition
    {
        // the command owns its options; every dependency is an unreached stub
        return new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $this->createStub(Connection::class),
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->createStub(Clock::class),
            $this->createStub(StreamExistence::class),
        )->getDefinition();
    }
}
