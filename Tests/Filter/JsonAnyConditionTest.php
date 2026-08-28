<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Filter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\LiveQuery\Filter\JsonAnyCondition;

/**
 * The set-form carrier answers for its own contract, whoever composes it.
 *
 * It has no consumer in this repository since the correlation predicate moved to the store layer,
 * and its guards are what a consumer would meet first: a carrier reachable from no grammar is
 * exactly the one whose refusals nobody exercises by accident.
 */
final class JsonAnyConditionTest extends TestCase
{
    #[Test]
    public function it_keeps_the_column_path_and_values_it_was_given(): void
    {
        $condition = new JsonAnyCondition('header', ['__correlation_id'], ['corr-9', 'corr-4']);

        self::assertSame('header', $condition->column);
        self::assertSame(['__correlation_id'], $condition->path);
        self::assertSame(['corr-9', 'corr-4'], $condition->values);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_column_outside_the_allow_list_is_refused_naming_the_set(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/header\|content/');

        new JsonAnyCondition('stream', ['x'], ['v']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_path_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonAnyCondition('header', [], ['v']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_segment_is_refused_because_it_is_a_stray_dot(): void
    {
        // a leading, trailing or doubled "." in the path the operator typed reaches here as an
        // empty segment; passed through it would address a key that cannot exist
        $this->expectException(InvalidArgumentException::class);

        new JsonAnyCondition('header', ['a', '', 'b'], ['v']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_segment_holding_a_comma_is_refused_because_the_path_is_bound_comma_joined(): void
    {
        // the renderer binds the path as one comma-joined string for `string_to_array`, so a comma
        // inside a segment would SPLIT it and address a different key than the caller named
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/comma/');

        new JsonAnyCondition('header', ['a,b'], ['v']);
    }

    #[Test]
    #[Group('adversarial')]
    public function an_empty_value_set_is_refused_rather_than_matching_nothing(): void
    {
        // an empty set renders a predicate matching nothing while reading as a filter that was
        // simply not narrow: the caller gets an empty answer and no reason for it
        $this->expectException(InvalidArgumentException::class);

        new JsonAnyCondition('header', ['__correlation_id'], []);
    }
}
