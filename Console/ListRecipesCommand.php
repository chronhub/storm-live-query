<?php

declare(strict_types=1);

namespace Storm\LiveQuery\Console;

use Override;
use Storm\LiveQuery\Recipe\LiveQueryRecipe;
use Storm\LiveQuery\Recipe\RecipeParam;
use Storm\LiveQuery\Recipe\RecipeRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List the registered LiveQuery recipes, with their name, description, and `--var` parameters, so
 * an operator can discover what `storm:events:inspect --recipe=<name>` accepts before running it.
 *
 * Examples:
 * ```bash
 * storm:events:recipes
 * ```
 *
 * @see LiveQueryRecipe
 */
#[AsCommand(name: 'storm:events:recipes', description: 'List available LiveQuery recipes.')]
final class ListRecipesCommand extends Command
{
    public function __construct(
        private readonly RecipeRegistry $recipes,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the machine-readable recipes and their declared params');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $recipes = $this->recipes->all();

        if ($input->getOption('json') === true) {
            $output->writeln(json_encode([
                'recipes' => array_map(static fn (LiveQueryRecipe $r): array => [
                    'name' => $r->name(),
                    'description' => $r->description(),
                    // RecipeParam exposes exactly the public JSON fields this channel promises.
                    'params' => $r->params(),
                ], $recipes),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($recipes === []) {
            $io->writeln('No LiveQuery recipes registered.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($recipes as $recipe) {
            // RecipeParam declares a description no other surface displays; ride it inline so
            // `--var` discovery does not require opening the recipe class
            $params = implode(', ', array_map(
                static fn (RecipeParam $p): string => ($p->required ? $p->name : $p->name.'?')
                    .($p->description !== '' ? ' — '.$p->description : ''),
                $recipe->params(),
            ));

            $rows[] = [$recipe->name(), $params, $recipe->description()];
        }

        $io->table(['recipe', 'params (? = optional)', 'description'], $rows);

        return Command::SUCCESS;
    }
}
