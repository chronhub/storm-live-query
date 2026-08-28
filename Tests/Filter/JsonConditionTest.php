<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Filter;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\LiveQuery\Filter\JsonCondition;

final class JsonConditionTest extends TestCase
{
    #[Test]
    public function rejects_a_column_outside_the_allow_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonCondition('payload', ['x'], '=', 'v');
    }

    #[Test]
    public function rejects_an_operator_outside_the_allow_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonCondition('header', ['x'], '~~', 'v');
    }

    #[Test]
    public function rejects_an_empty_path(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonCondition('content', [], '=', 'v');
    }

    #[Test]
    public function rejects_a_path_with_an_empty_segment(): void
    {
        // a leading / trailing / doubled "." in --where, for example `header..a`, explodes to an empty segment;
        // reject it so the inspect tool errors instead of silently matching nothing.
        $this->expectException(InvalidArgumentException::class);

        new JsonCondition('header', ['', 'a'], '=', 'v');
    }

    #[Test]
    public function rejects_a_path_segment_containing_a_comma(): void
    {
        // the wire format joins segments with a comma for string_to_array, so a comma INSIDE a
        // segment would silently rewrite `a,b` into the deeper path a.b and match nothing
        $this->expectException(InvalidArgumentException::class);

        new JsonCondition('content', ['a,b'], '=', 'v');
    }

    #[Test]
    public function joins_a_nested_path_for_postgres(): void
    {
        $condition = new JsonCondition('content', ['meta', 'ref'], '=', 'x');

        $this->assertSame('meta,ref', $condition->pathArgument());
    }

    #[Test]
    public function detects_numeric_values(): void
    {
        $this->assertTrue(new JsonCondition('content', ['amount'], '>', '1000')->isNumeric());
        $this->assertFalse(new JsonCondition('content', ['status'], '=', 'shipped')->isNumeric());
    }
}
