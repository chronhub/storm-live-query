<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Recipe;

use InvalidArgumentException;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;

/**
 * The reference recipe: every event carrying one of the given `__correlation_id`s, in `sequence_no`
 * order, across all streams. A named, documented preset over the same bound JSON mechanism every
 * selector uses.
 *
 * It traces the ids it is GIVEN, and claims nothing beyond them. A saga that spawns a child gives
 * that child its own correlation id, so the child's footprint is a different id and this recipe
 * will not reach it from the parent's. That is not a gap to paper over here: coordination is an
 * opt-in module this one does not depend on, and a preset that quietly meant a whole lineage
 * wherever sagas happen to be installed would mean two different things in two applications.
 *
 * The set form is how a lineage is traced: resolve it where it is known, `storm:saga:children`
 * walking a parent's tree, then pass the ids together.
 *
 * The recipe owns the PARSING and nothing else. What the ids mean as a query, the predicate, its
 * index-matching spelling and its order, belongs to `CorrelationFeedFilter` in the store's own
 * layer, where the index that serves it also lives; this class turns one operator-typed option into
 * that filter's set.
 */
final class CorrelationTraceRecipe implements LiveQueryRecipe
{
    public function name(): string
    {
        return 'correlation_trace';
    }

    public function description(): string
    {
        return 'Every event carrying one of the given __correlation_ids, in order. Pass several, comma-separated, to trace a saga lineage resolved elsewhere.';
    }

    public function params(): array
    {
        return [new RecipeParam('id', true, 'The __correlation_id to trace; several, comma-separated, are traced together')];
    }

    /**
     * {@inheritDoc}
     *
     * One id and several render the same set predicate: a set of one is still a set, and a second
     * spelling for the common case would be a second thing to keep matching the store's index.
     *
     * @throws InvalidArgumentException when `id` is blank or holds nothing but separators; the
     *                                  required-param check upstream proves presence only, and an
     *                                  empty id would trace nothing while exiting clean
     */
    public function filter(array $vars): QueryFilter
    {
        $ids = explode(',', (string) ($vars['id'] ?? ''))
                |> (static fn ($x) => array_map(trim(...), $x))
                |> array_filter(...)
                |> array_values(...);

        if ($ids === []) {
            throw new InvalidArgumentException('--var id cannot be blank — pass the __correlation_id to trace, or several comma-separated.');
        }

        return new CorrelationFeedFilter($ids, 1000);
    }
}
