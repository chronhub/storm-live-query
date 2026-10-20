<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Directory\StreamExistence;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Contracts\Clock\Clock;
use Storm\LiveQuery\Console\InspectEventsCommand;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The selector-validation guards of storm:events:inspect: a malformed selector is rejected before
 * any read and turned into a reported error plus `Command::FAILURE`. The guards covered:
 *
 * - A malformed --derived-stream, where StreamName validation throws on resolve
 * - A malformed --where, where JsonCondition rejects an empty path segment
 * - The two whole-query modes named together
 * - A non-digit numeric option sitting behind an absent one, which the scan must still reach
 * - An opted-in --export with no adapter bound, the port storm ships no implementation for
 *
 * A pure unit; every collaborator is a stub that is never reached.
 */
final class InspectEventsCommandTest extends TestCase
{
    #[Test]
    #[DataProvider('validTimeBounds')]
    public function accepted_time_bounds_are_bound_as_exact_utc_instants(string $option, string $value, string $parameter, string $expected): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );
        $tester = new CommandTester($command);
        $this->assertSame(Command::SUCCESS, $tester->execute([$option => $value, '--timeout' => '0', '--dump-sql' => true]));
        $this->assertStringContainsString($parameter.' = '.$expected, $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function validTimeBounds(): iterable
    {
        yield 'start of day' => ['--since', '2026-05-01', ':afterTime', '2026-05-01T00:00:00.000000+00:00'];
        yield 'end of day' => ['--until', '2026-05-01', ':beforeTime', '2026-05-01T23:59:59.999999+00:00'];
        yield 'minutes' => ['--since', '2026-05-01 10:30', ':afterTime', '2026-05-01T10:30:00.000000+00:00'];
        yield 'seconds' => ['--since', '2026-05-01 10:30:12', ':afterTime', '2026-05-01T10:30:12.000000+00:00'];
        yield 'space and offset' => ['--since', '2026-05-01 10:30:12+02:00', ':afterTime', '2026-05-01T08:30:12.000000+00:00'];
        yield 'ISO seconds' => ['--since', '2026-05-01T10:30:12', ':afterTime', '2026-05-01T10:30:12.000000+00:00'];
        yield 'ISO offset' => ['--since', '2026-05-01T10:30:12+02:00', ':afterTime', '2026-05-01T08:30:12.000000+00:00'];
        yield 'microseconds' => ['--since', '2026-05-01 10:30:12.123456', ':afterTime', '2026-05-01T10:30:12.123456+00:00'];
        yield 'ISO microseconds' => ['--since', '2026-05-01T10:30:12.123456+02:00', ':afterTime', '2026-05-01T08:30:12.123456+00:00'];
    }

    /**
     * @param  array<string, string|bool>  $options
     */
    #[Test]
    #[DataProvider('invalidOptionCombinations')]
    public function conflicting_or_invalid_options_fail_before_a_read(array $options, string $diagnostic): void
    {
        $tester = new CommandTester($this->command());
        $this->assertSame(Command::INVALID, $tester->execute($options));
        $this->assertStringContainsString($diagnostic, $tester->getDisplay());
        $this->assertStringNotContainsString('Unknown recipe', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, string|bool>, string}>
     */
    public static function invalidOptionCombinations(): iterable
    {
        yield 'out and SQL' => [['--out' => '/tmp/unwritten-inspection.json', '--dump-sql' => true], '--out/--export require a read'];
        yield 'export and explain' => [['--export' => true, '--explain' => true], '--out/--export require a read'];
        yield 'stream and recipe' => [['--stream' => 'orders', '--recipe' => 'trace'], 'cannot be combined'];
        yield 'rolled-over day' => [['--since' => '2026-02-30'], 'Invalid --since'];
        yield 'year zero' => [['--since' => '0000-01-01'], 'Invalid --since'];
        yield 'invalid since' => [['--since' => 'not a date'], '--since'];
        yield 'invalid until' => [['--until' => 'not a date'], '--until'];
        yield 'non numeric limit' => [['--limit' => 'abc'], 'Invalid --limit "abc"'];
        yield 'non numeric after' => [['--after' => 'abc'], 'Invalid --after "abc"'];
        yield 'excessive limit' => [['--limit' => '1000000000'], 'exceeds the maximum'];
        // DerivedStreamFilter has no ceiling of its own, so the command's cap is the only one
        yield 'excessive derived-stream limit' => [['--derived-stream' => 'feed', '--limit' => '50001'], 'exceeds the maximum'];
    }

    #[Test]
    public function a_malformed_derived_stream_name_is_reported_and_fails_before_any_read(): void
    {
        $tester = new CommandTester($this->command());

        // a whitespace-only name trims to empty, so StreamName::from throws InvalidStreamException
        $exit = $tester->execute(['--derived-stream' => '   ']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid --derived-stream', $tester->getDisplay());
    }

    #[Test]
    public function derived_stream_and_recipe_are_mutually_exclusive(): void
    {
        $tester = new CommandTester($this->command());

        // both whole-query modes named at once, rejected before either is resolved; the empty
        // RecipeRegistry and StreamName are never reached, proving this guard runs first
        $exit = $tester->execute(['--derived-stream' => 'feed', '--recipe' => 'correlation_trace']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('--derived-stream cannot be combined with --recipe', $tester->getDisplay());
    }

    #[Test]
    public function a_malformed_where_clause_is_reported_and_fails_softly(): void
    {
        $tester = new CommandTester($this->command());

        // a doubled dot passes the shape regex but makes JsonCondition reject the empty path segment;
        // it must be reported like every other validation, not escape as a raw exception.
        $exit = $tester->execute(['--where' => ['header..x=1']]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid --where', $tester->getDisplay());
    }

    #[Test]
    public function a_non_digit_to_is_reported_even_when_after_is_absent(): void
    {
        $tester = new CommandTester($this->command());

        // the numeric scan walks after, then to. An absent --after is skipped, and a scan that ended
        // on that skip would carry a non-digit --to past the guard and into the read path
        $exit = $tester->execute(['--to' => 'abc']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid --to', $tester->getDisplay());
        // a sequence bound admits zero, and the sentence tells the operator so
        $this->assertStringContainsString('must be a non-negative integer', $tester->getDisplay());
    }

    #[Test]
    public function a_zero_padded_limit_within_the_cap_is_accepted(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        // fourteen characters spelling 100: the page size is the number, and a check on the
        // spelling's length would refuse it as beyond the cap
        $tester = new CommandTester($command);
        $exit = $tester->execute(['--stream' => 'order-1', '--limit' => '00000000000100', '--timeout' => '0', '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('LIMIT 100', $tester->getDisplay());
    }

    #[Test]
    public function the_limit_cap_itself_is_accepted(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--stream' => 'order-1', '--limit' => '50000', '--timeout' => '0', '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('LIMIT 50000', $tester->getDisplay());
    }

    #[Test]
    public function export_without_a_bound_adapter_is_reported_and_fails(): void
    {
        $tester = new CommandTester($this->command());

        // the port is optional and storm ships no adapter, so this guard is the only thing between an
        // opted-in run and a silent no-op; it answers `Command::FAILURE` and names what the app must
        // bind
        $exit = $tester->execute(['--export' => true]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('--export has no adapter', $tester->getDisplay());
    }

    #[Test]
    public function applies_and_resets_the_statement_timeout_on_the_connection(): void
    {
        $executed = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 1;
        });

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--stream' => 'order-1', '--timeout' => '10', '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertContains('SET statement_timeout = 10000', $executed);
        $this->assertContains('RESET statement_timeout', $executed);
    }

    #[Test]
    public function restores_the_prior_non_zero_statement_timeout(): void
    {
        $executed = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn('5000ms');
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 1;
        });

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--stream' => 'order-1', '--timeout' => '10', '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertContains('SET statement_timeout = 10000', $executed);
        $this->assertContains("SET statement_timeout = '5000ms'", $executed);
    }

    #[Test]
    public function reports_and_rejects_a_malformed_timeout_before_touching_the_connection(): void
    {
        $executed = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 1;
        });

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--stream' => 'order-1', '--timeout' => 'invalid-timeout', '--dump-sql' => true]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid --timeout "invalid-timeout"', $tester->getDisplay());
        $this->assertEmpty($executed);
    }

    #[Test]
    #[DataProvider('timeoutsBeyondThePostgresRange')]
    public function a_timeout_beyond_the_postgres_range_is_refused_before_touching_the_connection(string $timeout): void
    {
        $executed = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 1;
        });

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--stream' => 'order-1', '--timeout' => $timeout, '--dump-sql' => true]);

        // PostgreSQL refuses the SET with a raw driver error, so the refusal has to come first
        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString(sprintf('Invalid --timeout "%s"', $timeout), $tester->getDisplay());
        $this->assertEmpty($executed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timeoutsBeyondThePostgresRange(): iterable
    {
        // 2147484 seconds is 2147484000 ms, one second past the integer `statement_timeout` holds
        yield 'one second past the ceiling' => ['2147484'];
        yield 'saturating at PHP_INT_MAX' => ['99999999999999999999'];
    }

    #[Test]
    public function the_largest_timeout_postgres_holds_is_applied(): void
    {
        $executed = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql) use (&$executed): int {
            $executed[] = $sql;

            return 1;
        });

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--stream' => 'order-1', '--timeout' => '2147483', '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertContains('SET statement_timeout = 2147483000', $executed);
    }

    #[Test]
    public function normalizes_the_order_options_case(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--stream' => 'order-1', '--order' => 'DESC', '--timeout' => '0', '--dump-sql' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desc', strtolower($tester->getDisplay()));
    }

    #[Test]
    public function trims_whitespace_around_where_tokens(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $command = new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $connection,
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--where' => [' content.amount > 1000 '], '--timeout' => '0', '--dump-sql' => true]);

        // untrimmed, the path segment carries a trailing space and the value a leading one; a
        // whitespace-padded value binding would still contain "amount" as a substring, so the
        // proof has to read the exact bound value rather than merely that it appears somewhere
        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString(':wval0 = 1000', $tester->getDisplay());
    }

    #[Test]
    public function an_unbalanced_sql_fragment_is_refused_as_invalid(): void
    {
        // a fragment whose parentheses could close the generated wrapper is an operator error like any
        // other refusal, reported with its reason, never an exception out of the console
        $tester = new CommandTester($this->command());

        $exit = $tester->execute(['--sql' => '(1 = 1', '--timeout' => '0', '--dump-sql' => true]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('InspectFilter --sql fragment has unbalanced parentheses', $tester->getDisplay());
    }

    private function command(): InspectEventsCommand
    {
        // every dependency is an unreached stub; the parse failure short-circuits before the read path
        return new InspectEventsCommand(
            $this->createStub(StreamReader::class),
            $this->createStub(Connection::class),
            $this->createStub(EventTypeMapper::class),
            new RecipeRegistry([]),
            $this->clock(),
            $this->createStub(StreamExistence::class),
        );
    }

    /**
     * @return Clock<PointInTime>
     */
    private function clock(): Clock
    {
        return $this->createStub(Clock::class);
    }
}
