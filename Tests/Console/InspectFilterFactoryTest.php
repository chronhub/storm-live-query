<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Console;

use Closure;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Exception\UnknownEventType;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\PersonalDataVeil;
use Storm\Chronicler\Record\SequencePosition;
use Storm\Chronicler\Store\Direction;
use Storm\Chronicler\Store\StreamReader;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Clock\Clock;
use Storm\EventLinks\DerivedStreamFilter;
use Storm\LiveQuery\Console\InspectEventsCommand;
use Storm\LiveQuery\Console\InspectFilterFactory;
use Storm\LiveQuery\Console\InspectRequest;
use Storm\LiveQuery\Console\InvalidInspectOption;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\LiveQuery\Recipe\RecipeParam;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The resolution of an `InspectRequest` without a console: what needs the mapper, a recipe or the
 * safe head is refused by `InvalidInspectOption`, never answered by a `false` the caller must test,
 * and every advisory reaches the caller's callback in the order the resolution meets it.
 */
final class InspectFilterFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    #[Test]
    public function a_derived_stream_reads_its_target_at_the_requested_limit(): void
    {
        $filter = $this->filterFor(['--derived-stream' => 'large_withdrawals', '--limit' => '7']);

        self::assertInstanceOf(DerivedStreamFilter::class, $filter);
        self::assertSame('large_withdrawals', $filter->targetStream->toString());
        self::assertSame(7, $filter->limit);
    }

    #[Test]
    public function a_recipe_answers_its_own_filter_from_its_vars(): void
    {
        $answer = new InspectFilter(stream: 'order-1');
        $recipe = $this->recipe([new RecipeParam('id')], static fn (array $vars): QueryFilter => $vars === ['id' => 'c-1'] ? $answer : new InspectFilter);

        self::assertSame($answer, $this->filterFor(['--recipe' => 'trace', '--var' => ['id=c-1']], recipe: $recipe));
    }

    #[Test]
    public function an_unknown_recipe_is_refused(): void
    {
        $this->expectRefusal('Unknown recipe "nope". List them with storm:events:recipes.');

        $this->filterFor(['--recipe' => 'nope']);
    }

    #[Test]
    public function a_missing_required_recipe_var_is_refused(): void
    {
        $this->expectRefusal('Recipe "trace" requires --var id.');

        $this->filterFor(['--recipe' => 'trace'], recipe: $this->recipe([new RecipeParam('id')]));
    }

    #[Test]
    public function an_optional_recipe_var_may_be_left_out(): void
    {
        $answer = new InspectFilter;

        self::assertSame($answer, $this->filterFor(['--recipe' => 'trace'], recipe: $this->recipe([new RecipeParam('id', required: false)], static fn (): QueryFilter => $answer)));
    }

    #[Test]
    public function an_unknown_recipe_var_is_refused_with_the_declared_ones(): void
    {
        $this->expectRefusal('Unknown --var "idd" for recipe "trace" — it declares: id, at.');

        $this->filterFor(['--recipe' => 'trace', '--var' => ['id=1', 'idd=2']], recipe: $this->recipe([new RecipeParam('id'), new RecipeParam('at', required: false)]));
    }

    #[Test]
    public function a_recipe_declaring_no_var_says_so(): void
    {
        $this->expectRefusal('Unknown --var "id" for recipe "trace" — it declares: (none).');

        $this->filterFor(['--recipe' => 'trace', '--var' => ['id=1']], recipe: $this->recipe([]));
    }

    #[Test]
    public function a_recipe_rejecting_its_vars_is_refused_with_its_reason(): void
    {
        $this->expectRefusal('Recipe "trace" rejected its variables: not a correlation id');

        $this->filterFor(['--recipe' => 'trace', '--var' => ['id=x']], recipe: $this->recipe([new RecipeParam('id')], static fn (): never => throw new InvalidArgumentException('not a correlation id')));
    }

    #[Test]
    public function each_type_token_expands_to_its_stored_types_once(): void
    {
        $filter = $this->filterFor(['--type' => 'placed,PlacedV1']);

        self::assertInstanceOf(InspectFilter::class, $filter);
        self::assertSame(['placed', 'App\\Placed'], $filter->types);
    }

    #[Test]
    public function an_unknown_type_is_refused_with_the_mapper_reason(): void
    {
        $this->expectRefusal(UnknownEventType::cannotResolve('ghost')->getMessage());

        $this->filterFor(['--type' => 'ghost']);
    }

    #[Test]
    public function the_safe_head_caps_the_requested_to(): void
    {
        self::assertSame(5, $this->selectors(['--to' => '9', '--safe-head' => true], safeHead: 5)->maxSequence);
        self::assertSame(4, $this->selectors(['--to' => '4', '--safe-head' => true], safeHead: 5)->maxSequence);
        self::assertSame(5, $this->selectors(['--safe-head' => true], safeHead: 5)->maxSequence);
        self::assertSame([], $this->warnings);
    }

    #[Test]
    public function an_empty_safe_head_bounds_the_read_at_zero_and_says_why(): void
    {
        self::assertSame(0, $this->selectors(['--safe-head' => true], safeHead: null)->maxSequence);
        self::assertSame(['--safe-head: no position is safe to read yet (empty store, or the lowest position still in flight); the read is bounded at 0 and returns nothing.'], $this->warnings);
    }

    #[Test]
    public function a_failed_watermark_probe_is_refused_where_it_answered_false(): void
    {
        $reader = $this->createStub(StreamReader::class);
        $reader->method('safeHeadPosition')->willThrowException(new RuntimeException('connection lost'));

        $this->expectRefusal('--safe-head watermark probe failed: connection lost');

        $this->factory(reader: $reader)->filterFor($this->request(['--safe-head' => true]), $this->warn(...));
    }

    #[Test]
    public function the_to_without_a_safe_head_is_the_bound_as_typed(): void
    {
        self::assertSame(9, $this->selectors(['--to' => '9'])->maxSequence);
        self::assertNull($this->selectors([])->maxSequence);
    }

    #[Test]
    public function an_after_at_the_bound_leaves_an_empty_window_and_is_refused(): void
    {
        $this->expectRefusal('--after must be strictly below --to — the sequence window is empty.');

        $this->filterFor(['--after' => '5', '--to' => '5']);
    }

    #[Test]
    public function an_after_just_below_the_bound_is_read(): void
    {
        $filter = $this->selectors(['--after' => '4', '--to' => '5']);

        self::assertSame(4, $filter->afterSequence);
        self::assertSame(5, $filter->maxSequence);
    }

    #[Test]
    public function a_where_on_a_declared_personal_key_is_warned_about(): void
    {
        $this->selectors(['--where' => ['content.email.domain=x', 'header.email=y', 'content.amount>1']]);

        self::assertSame(['--where targets "email.domain", a declared personal key: the store holds its CIPHERED envelope, so a cleartext value cannot match, and the value is redacted from any exported provenance.'], $this->warnings);
    }

    #[Test]
    public function a_var_without_a_fragment_is_warned_about(): void
    {
        $this->selectors(['--var' => ['k=1']]);

        self::assertSame(['--var is only bound into a --sql fragment; without --sql it is ignored (the typed selectors --stream/--category/--type/--where take no vars).'], $this->warnings);
    }

    #[Test]
    public function a_fragment_naming_a_declared_key_is_warned_about_once(): void
    {
        $filter = $this->selectors(['--sql' => "e.content ->> 'email' = :k OR email IS NULL OR amount > 1", '--var' => ['k=a']]);

        self::assertSame("e.content ->> 'email' = :k OR email IS NULL OR amount > 1", $filter->sqlFragment);
        self::assertSame(['--sql names "email", a declared personal key: the store holds its CIPHERED envelope, so a cleartext comparison never matches. Unlike a --where on the same key, neither the fragment nor its --var values are redacted from an exported provenance.'], $this->warnings);
    }

    #[Test]
    public function an_unbalanced_fragment_is_refused_with_the_filter_reason(): void
    {
        $this->expectRefusal('InspectFilter --sql fragment has unbalanced parentheses outside string literals');

        $this->filterFor(['--sql' => '(1 = 1']);
    }

    #[Test]
    public function the_typed_selectors_reach_the_filter(): void
    {
        $filter = $this->selectors([
            '--stream' => 'Order-1',
            '--category' => 'order',
            '--after' => '2',
            '--since' => '2026-05-01',
            '--until' => '2026-05-02',
            '--where' => ['content.amount>1'],
            '--limit' => '9',
            '--order' => 'desc',
        ]);

        self::assertSame('order-1', $filter->stream);
        self::assertSame('order', $filter->category);
        self::assertSame(2, $filter->afterSequence);
        self::assertSame('2026-05-01T00:00:00.000000+00:00', $filter->afterTime?->toString());
        self::assertSame('2026-05-02T23:59:59.999999+00:00', $filter->beforeTime?->toString());
        self::assertCount(1, $filter->conditions);
        self::assertSame(9, $filter->limit);
        self::assertSame(Direction::Backward, $filter->direction);
        self::assertSame(Direction::Forward, $this->selectors([])->direction);
        self::assertSame(Direction::Forward, $this->selectors(['--order' => 'wallclock'])->direction);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function selectors(array $options, ?int $safeHead = null): InspectFilter
    {
        $reader = $this->createStub(StreamReader::class);
        $reader->method('safeHeadPosition')->willReturn($safeHead === null ? null : SequencePosition::fromInt($safeHead));

        $filter = $this->factory(reader: $reader)->filterFor($this->request($options), $this->warn(...));
        self::assertInstanceOf(InspectFilter::class, $filter);

        return $filter;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function filterFor(array $options, ?LiveQueryRecipe $recipe = null): QueryFilter
    {
        return $this->factory(recipe: $recipe)->filterFor($this->request($options), $this->warn(...));
    }

    private function factory(?StreamReader $reader = null, ?LiveQueryRecipe $recipe = null): InspectFilterFactory
    {
        $mapper = $this->createStub(EventTypeMapper::class);
        $mapper->method('toClass')->willReturnCallback(static fn (string $type): string => match ($type) {
            'placed', 'PlacedV1' => 'App\\Placed',
            default => throw UnknownEventType::cannotResolve($type),
        });
        $mapper->method('storedTypesOf')->willReturn(['placed', 'App\\Placed']);

        return new InspectFilterFactory(
            $mapper,
            new RecipeRegistry($recipe === null ? [] : [$recipe]),
            $reader ?? $this->createStub(StreamReader::class),
            new PersonalDataVeil([stdClass::class => ['subject' => 'id', 'keys' => ['email'], 'fallbacks' => ['email' => null]]]),
        );
    }

    /**
     * @param  list<RecipeParam>  $params
     * @param  (Closure(array<string, string>): QueryFilter)|null  $filter
     */
    private function recipe(array $params, ?Closure $filter = null): LiveQueryRecipe
    {
        return new readonly class($params, $filter ?? static fn (): QueryFilter => new InspectFilter) implements LiveQueryRecipe
        {
            /**
             * @param  list<RecipeParam>  $params
             * @param  Closure(array<string, string>): QueryFilter  $filter
             */
            public function __construct(private array $params, private Closure $filter) {}

            public function name(): string
            {
                return 'trace';
            }

            public function description(): string
            {
                return 'a recipe for the factory';
            }

            public function params(): array
            {
                return $this->params;
            }

            public function filter(array $vars): QueryFilter
            {
                return ($this->filter)($vars);
            }
        };
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

    private function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    private function expectRefusal(string $message): void
    {
        $this->expectException(InvalidInspectOption::class);
        $this->expectExceptionMessage($message);
    }
}
