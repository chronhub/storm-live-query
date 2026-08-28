<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Recipe;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every `LiveQueryRecipe` the bundle tagged `storm.live_query_recipe`, applied to each
 * class implementing the interface, and indexes them by name. The source of truth for available
 * recipes; no config list.
 */
final class RecipeRegistry
{
    /** @var array<string, LiveQueryRecipe> */
    private array $recipes = [];

    /**
     * @param  iterable<LiveQueryRecipe>  $recipes
     *
     * @throws LogicException when a recipe name is blank or two recipes claim the same name; a
     *                        wiring bug where last-wins would silently shadow one preset behind another
     */
    public function __construct(
        #[AutowireIterator('storm.live_query_recipe')]
        iterable $recipes,
    ) {
        foreach ($recipes as $recipe) {
            $name = trim($recipe->name());

            if ($name === '') {
                throw new LogicException(sprintf('Recipe %s has a blank name.', $recipe::class));
            }

            if (isset($this->recipes[$name])) {
                throw new LogicException(sprintf(
                    'Duplicate recipe name "%s": %s and %s both claim it — rename one.',
                    $name,
                    $this->recipes[$name]::class,
                    $recipe::class,
                ));
            }

            $this->recipes[$name] = $recipe;
        }
    }

    public function get(string $name): ?LiveQueryRecipe
    {
        return $this->recipes[$name] ?? null;
    }

    /**
     * @return list<LiveQueryRecipe>
     */
    public function all(): array
    {
        return array_values($this->recipes);
    }
}
