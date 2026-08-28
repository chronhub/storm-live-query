<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Filter;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Storm\Chronicler\Query\QueryFilter;
use Storm\Chronicler\Record\EventRecord;
use Storm\Chronicler\Store\Direction;
use Storm\Chronicler\Store\StreamReader;
use Storm\Clock\PointInTime;

/**
 * The LiveQuery ad-hoc inspection filter for `StreamReader::retrieveByFilter()`.
 *
 * It only narrows and orders rows against the `e` alias; the store owns the SELECT columns and the
 * row-to-`EventRecord` mapping. All dimensions are optional and AND-combined; the limit is always
 * applied and CAPPED BY THE FILTER ITSELF at `MAX_LIMIT`; `pdo_pgsql` buffers the whole result set
 * client-side, so an unbounded read is an OOM, and bounding here rather than only at the command
 * bounds every recipe built on this filter too. Types must already be the stored type strings.
 *
 * Two escape tiers sit above the typed dimensions. The JSON `--where` predicate, a `JsonCondition`,
 * carries equality and comparators on `header` or `content`, where the column and operator come
 * from closed allowlists and the path and value are bound with `#>> string_to_array(:p, ',')`; a
 * NUMERIC comparison guards its cast, so a heterogeneous row such as an old textual representation
 * or a sentinel is a non-match, never a 22P02 killing the whole query. `!=` alone compares with
 * `IS DISTINCT FROM`: a row missing the path, or failing the numeric cast, IS "not equal", where
 * the SQL `!=` would yield NULL and silently narrow "events that are not shipped" into "events
 * that carry a status and it is not shipped", exactly the anomalous rows an audit hunts. The
 * ordering comparators keep the NULL semantics, since "greater than" is meaningless on a missing
 * value. The raw `--sql` fragment is
 * an opaque boolean expression AND-ed onto the query, with `:k` placeholders bound from `$vars`; a
 * user variable whose name collides with an internal binding is REFUSED, never silently allowed to
 * rebind a selector out from under the SQL text it still displays.
 *
 * @see \Storm\Chronicler\Store\StreamReader::retrieveByFilter()
 * @see \Storm\Chronicler\Record\EventRecord
 */
final readonly class InspectFilter implements QueryFilter
{
    /**
     * The hard page-size ceiling; see the class docblock. The command validates `--limit` against
     * it up front, and the constructor refuses beyond it so a recipe cannot smuggle one past.
     */
    public const int MAX_LIMIT = 50_000;

    /**
     * @param  list<string>  $types  already-resolved stored type strings; the alias union replaces the union FQCN
     * @param  list<JsonCondition|JsonAnyCondition>  $conditions  json predicates, AND-combined; the
     *                                                            set form comes from a recipe, the
     *                                                            scalar one from `--where` too
     * @param  array<string, string>  $vars  bound values for the `--sql` fragment's `:k` placeholders
     *
     * @throws InvalidArgumentException when the limit is not within 1..MAX_LIMIT
     */
    public function __construct(
        public ?string $stream = null,
        public ?string $category = null,
        public array $types = [],
        public ?int $afterSequence = null,
        public ?int $maxSequence = null,
        public ?PointInTime $afterTime = null,
        public ?PointInTime $beforeTime = null,
        public array $conditions = [],
        public ?string $sqlFragment = null,
        public array $vars = [],
        public int $limit = 100,
        public Direction $direction = Direction::Forward,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf(
                'InspectFilter limit must be within 1..%d, got %d — pdo_pgsql buffers the whole result set client-side, an unbounded read is an OOM.',
                self::MAX_LIMIT,
                $limit,
            ));
        }

        // The wrapper-escape guard: a fragment closing our parenthesis itself and
        // ending in a line comment would swallow the generated ORDER BY and the hard LIMIT, the
        // exact bounds this filter exists to own. Balanced parens, measured OUTSIDE single-quoted
        // literals so a ')' inside a pattern never false-refuses, make that shape unwritable; the
        // newline the apply side appends before its closing parenthesis kills the comment vector
        // for balanced fragments. The literal-stripping regex understands STANDARD single-quoted
        // literals only, not dollar-quoting nor E'' escape strings: a miscount from those lands in
        // the refusing direction or in a Postgres syntax error, never in a swallowed bound, and the
        // fragment is documented operator-owned risk; a guard, not a parser.
        if ($this->sqlFragment !== null) {
            $bare = preg_replace("/'(?:[^']|'')*'/", '', $this->sqlFragment) ?? $this->sqlFragment;

            if (substr_count($bare, '(') !== substr_count($bare, ')')) {
                throw new InvalidArgumentException(
                    'InspectFilter --sql fragment has unbalanced parentheses outside string literals — a fragment closing the wrapper\'s own parenthesis could comment out the generated ORDER BY and the hard LIMIT.',
                );
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException when a `--var` name collides with an internal binding
     */
    public function apply(QueryBuilder $qb): void
    {
        if ($this->stream !== null) {
            $qb->andWhere('e.stream = :stream')->setParameter('stream', $this->stream);
        }

        if ($this->category !== null) {
            $qb->andWhere('e.category = :category')->setParameter('category', $this->category);
        }

        if ($this->types !== []) {
            $qb->andWhere('e.type IN (:types)')->setParameter('types', $this->types, ArrayParameterType::STRING);
        }

        if ($this->afterSequence !== null) {
            $qb->andWhere('e.sequence_no > :afterSequence')->setParameter('afterSequence', $this->afterSequence, ParameterType::INTEGER);
        }

        if ($this->maxSequence !== null) {
            $qb->andWhere('e.sequence_no <= :maxSequence')->setParameter('maxSequence', $this->maxSequence, ParameterType::INTEGER);
        }

        if ($this->afterTime !== null) {
            $qb->andWhere('e.recorded_at >= CAST(:afterTime AS timestamptz)')->setParameter('afterTime', $this->afterTime->toString());
        }

        if ($this->beforeTime !== null) {
            $qb->andWhere('e.recorded_at <= CAST(:beforeTime AS timestamptz)')->setParameter('beforeTime', $this->beforeTime->toString());
        }

        foreach ($this->conditions as $i => $condition) {
            if ($condition instanceof JsonAnyCondition) {
                $this->applyAnyCondition($qb, $condition, $i);

                continue;
            }

            $this->applyCondition($qb, $condition, $i);
        }

        if ($this->sqlFragment !== null) {
            // the newline is load-bearing: a fragment ending in a line comment eats to END OF LINE,
            // so our closing parenthesis, and DBAL's ORDER BY / LIMIT after it, live on the next
            // one and survive; paired with the constructor's balance guard, the wrapper cannot be
            // escaped
            $qb->andWhere('('.$this->sqlFragment."\n)");

            // Drift-proof collision guard: every internal binding, namely the selectors, the time window,
            // and wpathN/wvalN, is already on the builder at this point; a user key that matches one would
            // silently REBIND a selector out from under the SQL text still displaying it, and any
            // exported provenance would then claim a scope the rows do not have.
            $bound = $qb->getParameters();

            foreach ($this->vars as $key => $value) {
                if (array_key_exists($key, $bound)) {
                    throw new InvalidArgumentException(sprintf(
                        '--var "%s" collides with an internal binding of the typed selectors — rename the placeholder in the --sql fragment.',
                        $key,
                    ));
                }

                $qb->setParameter($key, $value);
            }
        }

        $qb->orderBy('e.sequence_no', $this->direction->value)
            ->setMaxResults($this->limit);
    }

    private function applyCondition(QueryBuilder $qb, JsonCondition $condition, int $index): void
    {
        $pathParam = 'wpath'.$index;
        $valueParam = 'wval'.$index;

        // column + operator are closed allowlists, validated by JsonCondition; path + value are bound.
        $extract = sprintf("e.%s #>> string_to_array(:%s, ',')", $condition->column, $pathParam);

        // IS DISTINCT FROM under !=, so a NULL extraction is a MATCH: "not equal" includes the rows
        // that do not carry the value at all; the ordering comparators keep the NULL exclusion.
        $operator = $condition->operator === '!=' ? 'IS DISTINCT FROM' : $condition->operator;

        if ($condition->isNumeric()) {
            // Guarded cast: a row whose extracted text is not strictly numeric, such as an old textual
            // representation, a sentinel, or another event type sharing the path, yields NULL, so the
            // comparison is a NON-MATCH, never a 22P02 that would kill the whole query, sane rows
            // included; under != the NULL matches, the same rule as the text branch.
            $qb->andWhere(sprintf(
                "(CASE WHEN (%s) ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (%s)::numeric END) %s CAST(:%s AS numeric)",
                $extract,
                $extract,
                $operator,
                $valueParam,
            ));
        } else {
            $qb->andWhere(sprintf('%s %s :%s', $extract, $operator, $valueParam));
        }

        $qb->setParameter($pathParam, $condition->pathArgument());
        $qb->setParameter($valueParam, $condition->value);
    }

    /**
     * The set form: one bound list, expanded by DBAL into an `IN` of equalities.
     *
     * Equality is the whole point. It rides the store's correlation expression index exactly as the
     * scalar form does, measured at a bitmap index scan over a bound list, where a prefix or a range
     * would fall back to a full scan and, on a database whose collation is not byte-ordered, would
     * also be plain wrong.
     */
    private function applyAnyCondition(QueryBuilder $qb, JsonAnyCondition $condition, int $index): void
    {
        $pathParam = 'wpath'.$index;
        $valueParam = 'wval'.$index;

        // the column is a closed allow-list, validated by the condition; path and values are bound
        $qb->andWhere(sprintf("e.%s #>> string_to_array(:%s, ',') IN (:%s)", $condition->column, $pathParam, $valueParam));

        $qb->setParameter($pathParam, implode(',', $condition->path));
        $qb->setParameter($valueParam, $condition->values, ArrayParameterType::STRING);
    }
}
