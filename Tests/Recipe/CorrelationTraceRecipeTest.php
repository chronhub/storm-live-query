<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Recipe;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Query\CorrelationFeedFilter;
use Storm\Chronicler\Query\QueryFilter;
use Storm\LiveQuery\Recipe\CorrelationTraceRecipe;

final class CorrelationTraceRecipeTest extends TestCase
{
    #[Test]
    public function it_declares_a_required_id_param(): void
    {
        $recipe = new CorrelationTraceRecipe;

        $this->assertSame('correlation_trace', $recipe->name());
        $this->assertCount(1, $recipe->params());
        $this->assertSame('id', $recipe->params()[0]->name);
        $this->assertTrue($recipe->params()[0]->required);
    }

    #[Test]
    public function the_predicate_is_delegated_to_the_store_layer(): void
    {
        // the recipe owns the parsing and nothing else: what the ids MEAN as a query, and the
        // index-matching spelling that serves them, live in Chronicler with the index itself.
        $filter = new CorrelationTraceRecipe()->filter(['id' => 'corr-9']);

        $this->assertInstanceOf(CorrelationFeedFilter::class, $filter);
    }

    #[Test]
    public function several_ids_reach_the_filter_trimmed_and_in_order(): void
    {
        // the lineage form: a saga child carries its own correlation id, so tracing a whole lineage
        // is tracing a SET the caller resolved elsewhere, never a prefix this recipe could guess
        $filter = new CorrelationTraceRecipe()->filter(['id' => 'corr-9, corr-9\x1fkyc ,corr-9\x1fkyc\x1fdocs']);

        $this->assertSame(['corr-9', 'corr-9\x1fkyc', 'corr-9\x1fkyc\x1fdocs'], $this->boundIds($filter));
    }

    #[Test]
    public function a_blank_segment_between_two_ids_is_dropped_not_traced(): void
    {
        // a trailing or doubled comma is a typing accident, and an empty id would widen the trace to
        // rows whose header is absent rather than narrowing it
        $filter = new CorrelationTraceRecipe()->filter(['id' => 'corr-9,,corr-4,']);

        $this->assertSame(['corr-9', 'corr-4'], $this->boundIds($filter));
    }

    #[Test]
    public function a_list_of_nothing_but_separators_is_refused_by_the_recipes_own_guard(): void
    {
        // the guard the required-param check upstream cannot make: presence is proven, content is not.
        // The MESSAGE is the assertion, not the type: the filter refuses an empty set too, and with
        // the same exception class, so a type-only expectation passes whichever of the two fired.
        // Only this one can name the option an operator typed.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/--var id/');

        new CorrelationTraceRecipe()->filter(['id' => ' , , ']);
    }

    #[Test]
    public function the_recipe_owns_its_bound_and_it_is_the_one_the_provenance_reports(): void
    {
        // the exported document reads this number back as the query's replayable limit, so it is a
        // contract and not an implementation detail
        $filter = new CorrelationTraceRecipe()->filter(['id' => 'corr-9']);

        self::assertInstanceOf(CorrelationFeedFilter::class, $filter);
        self::assertSame(1000, $filter->limit);
    }

    /**
     * @return list<string>
     */
    private function boundIds(QueryFilter $filter): array
    {
        $qb = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'serverVersion' => '16',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
        ])->createQueryBuilder()->select('e.*')->from('event_store', 'e');

        $filter->apply($qb);

        /** @var list<string> $ids */
        $ids = $qb->getParameter('correlationIds');

        return $ids;
    }
}
