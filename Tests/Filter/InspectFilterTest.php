<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Filter;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Store\Direction;
use Storm\Clock\PointInTime;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Filter\JsonAnyCondition;
use Storm\LiveQuery\Filter\JsonCondition;

final class InspectFilterTest extends TestCase
{
    #[Test]
    public function it_filters_by_stream_with_order_and_a_bound_value(): void
    {
        $qb = $this->queryBuilder();

        new InspectFilter(stream: 'order-1', limit: 50, direction: Direction::Backward)->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('e.stream = :stream', $sql);
        $this->assertStringContainsStringIgnoringCase('ORDER BY e.sequence_no desc', $sql);
        $this->assertStringContainsString('LIMIT 50', $sql);
        $this->assertSame('order-1', $qb->getParameter('stream'));
    }

    #[Test]
    public function it_omits_the_where_when_no_stream_and_defaults_to_asc_and_100(): void
    {
        $qb = $this->queryBuilder();

        new InspectFilter()->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringNotContainsStringIgnoringCase('WHERE', $sql);
        $this->assertStringContainsStringIgnoringCase('ORDER BY e.sequence_no asc', $sql);
        $this->assertStringContainsString('LIMIT 100', $sql);
    }

    #[Test]
    public function it_combines_category_types_and_windows_all_bound(): void
    {
        $qb = $this->queryBuilder();

        new InspectFilter(
            category: 'order',
            types: ['order.placed', 'order.paid'],
            afterSequence: 100,
            maxSequence: 200,
            afterTime: new PointInTime('2026-05-01T00:00:00.000000+00:00'),
            beforeTime: new PointInTime('2026-05-31T00:00:00.000000+00:00'),
            limit: 25,
        )->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString('e.category = :category', $sql);
        $this->assertStringContainsString('e.type IN (:types)', $sql);
        $this->assertStringContainsString('e.sequence_no > :afterSequence', $sql);
        $this->assertStringContainsString('e.sequence_no <= :maxSequence', $sql);
        $this->assertStringContainsString('CAST(:afterTime AS timestamptz)', $sql);
        $this->assertStringContainsString('CAST(:beforeTime AS timestamptz)', $sql);
        $this->assertStringContainsString('LIMIT 25', $sql);

        $this->assertSame('order', $qb->getParameter('category'));
        $this->assertSame(['order.placed', 'order.paid'], $qb->getParameter('types'));
        $this->assertSame(100, $qb->getParameter('afterSequence'));
    }

    #[Test]
    public function it_builds_text_equality_and_numeric_comparator_where_clauses_bound(): void
    {
        $qb = $this->queryBuilder();

        new InspectFilter(conditions: [
            new JsonCondition('header', ['__correlation_id'], '=', 'abc-123'),
            new JsonCondition('content', ['amount'], '>', '1000'),
        ])->apply($qb);

        $sql = $qb->getSQL();
        // text equality, so no numeric cast, value bound
        $this->assertStringContainsString("e.header #>> string_to_array(:wpath0, ',') = :wval0", $sql);
        // numeric comparator, so GUARDED cast: a non-numeric row yields NULL, a non-match, never a
        // 22P02 killing the whole query including its sane rows
        $this->assertStringContainsString("(CASE WHEN (e.content #>> string_to_array(:wpath1, ',')) ~ '^-?[0-9]+(\.[0-9]+)?$' THEN (e.content #>> string_to_array(:wpath1, ','))::numeric END) > CAST(:wval1 AS numeric)", $sql);

        $this->assertSame('__correlation_id', $qb->getParameter('wpath0'));
        $this->assertSame('abc-123', $qb->getParameter('wval0'));
        $this->assertSame('amount', $qb->getParameter('wpath1'));
        $this->assertSame('1000', $qb->getParameter('wval1'));
    }

    #[Test]
    public function a_not_equal_compares_with_is_distinct_from_on_both_branches(): void
    {
        // the SQL != yields NULL on a missing path and silently narrows the answer; IS DISTINCT
        // FROM makes a missing or non-numeric value a MATCH, which is what "not equal" means
        $qb = $this->queryBuilder();

        new InspectFilter(conditions: [
            new JsonCondition('content', ['status'], '!=', 'shipped'),
            new JsonCondition('content', ['amount'], '!=', '1500'),
        ])->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString("e.content #>> string_to_array(:wpath0, ',') IS DISTINCT FROM :wval0", $sql);
        $this->assertStringContainsString('END) IS DISTINCT FROM CAST(:wval1 AS numeric)', $sql);
        $this->assertStringNotContainsString('!=', $sql);
    }

    #[Test]
    public function it_appends_the_raw_sql_fragment_with_bound_vars(): void
    {
        $qb = $this->queryBuilder();

        new InspectFilter(
            sqlFragment: "e.header ->> 'kind' = :kind",
            vars: ['kind' => 'shipment'],
        )->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString("(e.header ->> 'kind' = :kind\n)", $sql);
        $this->assertSame('shipment', $qb->getParameter('kind'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_fragment_closing_the_wrapper_itself_is_refused_at_construction(): void
    {
        // the escape this guard exists for: '1=1) --' closes OUR parenthesis and comments out the generated
        // ORDER BY and the hard LIMIT, the exact bounds this filter owns; unbalanced parens
        // outside string literals make the shape unwritable
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('unbalanced parentheses');

        new InspectFilter(sqlFragment: '1 = 1) --');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_trailing_line_comment_cannot_eat_the_order_and_limit(): void
    {
        // balanced fragment, hostile tail: the newline before the wrapper's closing parenthesis
        // ends the line comment, so the parenthesis, and everything DBAL appends after the WHERE,
        // survives on the next line
        $qb = $this->queryBuilder();

        new InspectFilter(sqlFragment: '1 = 1 -- tail')->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString("-- tail\n)", $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT', $sql);
    }

    #[Test]
    public function a_parenthesis_inside_a_string_literal_never_false_refuses(): void
    {
        $qb = $this->queryBuilder();

        new InspectFilter(sqlFragment: "e.content ->> 'note' LIKE '%(a)%' AND (1 = 1)")->apply($qb);

        $this->assertStringContainsString("LIKE '%(a)%'", $qb->getSQL());
    }

    #[Test]
    #[Group('adversarial')]
    public function a_var_colliding_with_an_internal_binding_is_refused(): void
    {
        // the provenance lie: the SQL text keeps displaying the selector's value while the binding
        // underneath is the user's; an exported trace would claim a scope its rows do not have
        $qb = $this->queryBuilder();

        $filter = new InspectFilter(
            stream: 'order-1',
            conditions: [new JsonCondition('content', ['amount'], '>', '1000')],
            sqlFragment: '1 = 1',
            vars: ['stream' => 'order-2'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('collides with an internal binding');

        $filter->apply($qb);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_var_colliding_with_a_where_binding_is_refused_too(): void
    {
        $qb = $this->queryBuilder();

        $filter = new InspectFilter(
            conditions: [new JsonCondition('content', ['amount'], '>', '1000')],
            sqlFragment: '1 = 1',
            vars: ['wval0' => '999999'],
        );

        $this->expectException(InvalidArgumentException::class);

        $filter->apply($qb);
    }

    #[Test]
    public function the_limit_is_bounded_by_the_filter_itself(): void
    {
        // bounding here, not only at the command, bounds every recipe built on this filter too
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('1..'.InspectFilter::MAX_LIMIT);

        new InspectFilter(limit: InspectFilter::MAX_LIMIT + 1);
    }

    #[Test]
    public function a_non_positive_limit_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InspectFilter(limit: 0);
    }

    /**
     * A lazy PG connection: `serverVersion` lets DBAL select the platform without connecting, so
     * `getSQL()` renders PostgreSQL syntax with zero database I/O; a true unit test.
     */
    #[Test]
    public function a_set_condition_renders_the_in_form_on_the_index_matching_spelling(): void
    {
        // the ANY branch, which no recipe composes any more since the correlation predicate moved to
        // the store layer. The rendering is still the carrier's whole reason to exist, and it must
        // stay the `#>> string_to_array(...)` form: the `->>` key form returns identical text and
        // matches the store's expression index NOTHING, scanning while every row still looks right.
        $qb = $this->queryBuilder();

        new InspectFilter(
            conditions: [new JsonAnyCondition('header', ['__correlation_id'], ['corr-9', 'corr-4'])],
            limit: 10,
        )->apply($qb);

        $sql = $qb->getSQL();
        $this->assertStringContainsString("e.header #>> string_to_array(:wpath0, ',') IN (:wval0)", $sql);
        $this->assertStringNotContainsString('->>', $sql);
        $this->assertSame('__correlation_id', $qb->getParameter('wpath0'));
        $this->assertSame(['corr-9', 'corr-4'], $qb->getParameter('wval0'));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_condition_following_a_set_one_is_still_applied(): void
    {
        // the ORDER is the assertion, and it is the whole point of putting the set FIRST. The
        // dispatch loop leaves the set branch by `continue`; were that a `break`, everything after
        // the first set condition would be dropped and the query would narrow to it alone. A
        // fixture with the set LAST cannot tell the two apart.
        $qb = $this->queryBuilder();

        new InspectFilter(
            conditions: [
                new JsonAnyCondition('content', ['kind'], ['a', 'b']),
                new JsonCondition('header', ['__correlation_id'], '=', 'corr-9'),
            ],
            limit: 10,
        )->apply($qb);

        $this->assertSame(['a', 'b'], $qb->getParameter('wval0'));
        $this->assertSame('corr-9', $qb->getParameter('wval1'));
    }

    #[Test]
    public function a_set_condition_following_a_scalar_one_gets_its_own_bindings(): void
    {
        // the mirror, for the binding suffix rather than the order: with one shared name the second
        // condition would silently rebind the first and the query would narrow to it alone
        $qb = $this->queryBuilder();

        new InspectFilter(
            conditions: [
                new JsonCondition('header', ['__correlation_id'], '=', 'corr-9'),
                new JsonAnyCondition('content', ['kind'], ['a', 'b']),
            ],
            limit: 10,
        )->apply($qb);

        $this->assertSame('corr-9', $qb->getParameter('wval0'));
        $this->assertSame(['a', 'b'], $qb->getParameter('wval1'));
    }

    private function queryBuilder(): QueryBuilder
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ]);

        return $connection->createQueryBuilder()->select('e.*')->from('event_store', 'e');
    }
}
