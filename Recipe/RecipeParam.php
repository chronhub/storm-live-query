<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Recipe;

use InvalidArgumentException;

/**
 * One declared parameter of a `LiveQueryRecipe`: its `--var` key, whether it is required, and a
 * short help line shown by `storm:events:recipes`. A plain value object, never a service.
 *
 * @see LiveQueryRecipe
 */
final readonly class RecipeParam
{
    /**
     * @throws InvalidArgumentException when the name is not a valid `--var` key; it must be
     *                                  expressible as `--var <name>=<value>` to be usable at all
     */
    public function __construct(
        public string $name,
        public bool $required = true,
        public string $description = '',
    ) {
        if (preg_match('/^\w+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid recipe param name "%s" — a --var key is letters, digits and underscores only.',
                $name,
            ));
        }
    }
}
