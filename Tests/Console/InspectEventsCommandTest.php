<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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
 * The selector-validation guards of storm:events:inspect: a malformed selector is rejected BEFORE
 * any read and turned into a reported error plus Command::FAILURE. The guards covered:
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

        // the numeric scan walks limit, then after, then to. An absent --after is SKIPPED, and a scan
        // that ended on that skip would carry a non-digit --to past the guard and into the read path
        $exit = $tester->execute(['--to' => 'abc']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Invalid --to', $tester->getDisplay());
        // --to admits zero where --limit does not, and the sentence is what tells the operator which
        $this->assertStringContainsString('must be a non-negative integer', $tester->getDisplay());
    }

    #[Test]
    public function export_without_a_bound_adapter_is_reported_and_fails(): void
    {
        $tester = new CommandTester($this->command());

        // the port is optional and storm ships no adapter, so this guard is the only thing between an
        // opted-in run and a silent no-op; it answers FAILURE and names what the app must bind
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
