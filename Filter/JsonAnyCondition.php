<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Filter;

use InvalidArgumentException;

use function count;
use function implode;
use function in_array;
use function sprintf;
use function str_contains;

/**
 * A jsonb predicate matching a path against ANY of several values, the set form of a `JsonCondition`
 * equality.
 *
 * It exists because some questions are a SET of ids rather than one, and a filter that only knows
 * `=` forces the caller into one query per id. The rendered SQL stays a plain equality against a
 * bound list, so it rides the same expression index a single equality does; a range or a prefix
 * match would not, the store's correlation index carrying the database collation rather than a
 * byte-ordered one.
 *
 * Deliberately NOT reachable from the `--where` grammar, whose operators are a closed set the
 * command parses itself: this carrier is for a recipe that composes its own predicate, so widening
 * it here widens no operator surface an operator types.
 *
 * @see InspectFilter the renderer
 * @see JsonCondition the scalar sibling
 */
final readonly class JsonAnyCondition
{
    /**
     * @param  list<string>  $path
     * @param  list<string>  $values  matched by equality; an empty list would render a
     *                                predicate matching nothing while reading as a filter
     *                                that was simply not narrow, so it is refused
     *
     * @throws InvalidArgumentException when the column is outside the allow-list, the path is empty,
     *                                  has an empty segment or a segment containing a comma, or the
     *                                  value list is empty
     */
    public function __construct(
        public string $column,
        public array $path,
        public array $values,
    ) {
        if (! in_array($column, JsonCondition::COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf('JSON column must be one of %s, got "%s".', implode('|', JsonCondition::COLUMNS), $column));
        }

        if ($path === []) {
            throw new InvalidArgumentException('JSON path cannot be empty.');
        }

        foreach ($path as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('JSON path cannot contain an empty segment (a leading, trailing, or doubled "." in the path).');
            }

            if (str_contains($segment, ',')) {
                throw new InvalidArgumentException(sprintf('JSON path segment "%s" cannot contain a comma: the path is bound as a comma-joined string.', $segment));
            }
        }

        if (count($values) === 0) {
            throw new InvalidArgumentException('A value set cannot be empty — an empty set matches nothing, which no caller means.');
        }
    }
}
