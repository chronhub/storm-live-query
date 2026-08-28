<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Tests\Recipe;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Chronicler\Query\QueryFilter;
use Storm\LiveQuery\Filter\InspectFilter;
use Storm\LiveQuery\Recipe\CorrelationTraceRecipe;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\LiveQuery\Recipe\RecipeParam;
use Storm\LiveQuery\Recipe\RecipeRegistry;

final class RecipeRegistryTest extends TestCase
{
    #[Test]
    public function it_indexes_recipes_by_name_and_resolves_them(): void
    {
        $recipe = new CorrelationTraceRecipe;
        $registry = new RecipeRegistry([$recipe]);

        $this->assertSame($recipe, $registry->get('correlation_trace'));
        $this->assertNull($registry->get('nope'));
        $this->assertSame([$recipe], $registry->all());
    }

    #[Test]
    public function a_duplicate_recipe_name_is_refused_naming_both_classes(): void
    {
        // last-wins would silently shadow one preset behind another, a wiring bug, surfaced loud
        try {
            new RecipeRegistry([$this->named('correlation_trace'), new CorrelationTraceRecipe]);
            $this->fail('a duplicate recipe name must be refused');
        } catch (LogicException $e) {
            $this->assertStringContainsString('correlation_trace', $e->getMessage());
            $this->assertStringContainsString(CorrelationTraceRecipe::class, $e->getMessage());
        }
    }

    #[Test]
    public function a_blank_recipe_name_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('blank name');

        new RecipeRegistry([$this->named('  ')]);
    }

    #[Test]
    public function a_recipe_param_name_must_be_a_valid_var_key(): void
    {
        // a param inexpressible as `--var name=value` could never be supplied at all
        $this->expectException(InvalidArgumentException::class);

        new RecipeParam('not a key');
    }

    private function named(string $name): LiveQueryRecipe
    {
        return new readonly class($name) implements LiveQueryRecipe
        {
            public function __construct(private string $recipeName) {}

            public function name(): string
            {
                return $this->recipeName;
            }

            public function description(): string
            {
                return 'probe';
            }

            public function params(): array
            {
                return [];
            }

            public function filter(array $vars): QueryFilter
            {
                return new InspectFilter(limit: 1);
            }
        };
    }
}
