<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Filter;

use InvalidArgumentException;

/**
 * A parsed `--where col.path<op>value` predicate on a jsonb column, `header` or `content`.
 *
 * A pure data carrier: the command parses the CLI string into one of these, as column plus dotted
 * path plus operator plus value, and `InspectFilter` turns it into parameter-bound SQL. The column
 * and operator are constrained to closed allowlists here, belt-and-suspenders since the command's
 * regex already guarantees them, because those two are the only parts that reach the SQL string.
 * The path and value are always bound, never interpolated.
 *
 * @see InspectFilter
 */
final readonly class JsonCondition
{
    public const array COLUMNS = ['header', 'content'];

    public const array OPERATORS = ['=', '!=', '>', '>=', '<', '<='];

    /**
     * @param  list<string>  $path
     *
     * @throws InvalidArgumentException when the column or operator is outside its allow-list, or the
     *                                  path is empty, has an empty segment, or has a segment
     *                                  containing a comma
     */
    public function __construct(
        public string $column,
        public array $path,
        public string $operator,
        public string $value,
    ) {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException(sprintf('JSON column must be one of %s, got "%s".', implode('|', self::COLUMNS), $column));
        }

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Operator must be one of %s, got "%s".', implode(' ', self::OPERATORS), $operator));
        }

        if ($path === []) {
            throw new InvalidArgumentException('JSON path cannot be empty.');
        }

        if (in_array('', $path, true)) {
            throw new InvalidArgumentException('JSON path cannot contain an empty segment (a leading, trailing, or doubled "." in the path).');
        }

        foreach ($path as $segment) {
            // the wire format joins segments with a comma for string_to_array, so a comma INSIDE a
            // segment would silently split it into a different, deeper path and match nothing
            if (str_contains($segment, ',')) {
                throw new InvalidArgumentException(sprintf('JSON path segment "%s" cannot contain a comma.', $segment));
            }
        }
    }

    public function isNumeric(): bool
    {
        return is_numeric($this->value);
    }

    /**
     * The Postgres path argument for the `#>>` operator, as a comma-joined string bound and
     * rebuilt with `string_to_array(:param, ',')`, so the path is a bound value, not interpolated
     * SQL.
     */
    public function pathArgument(): string
    {
        return implode(',', $this->path);
    }
}
