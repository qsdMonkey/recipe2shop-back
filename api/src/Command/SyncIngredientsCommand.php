<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Ingredient;
use App\Ingredient\IngredientMatcher;
use App\Recipe\RecipeVocabulary;
use App\Repository\IngredientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Synchronise le référentiel d'ingrédients depuis data/ingredients.csv (idempotent : crée ou met à jour, ne supprime rien).
 * Lancé au démarrage du conteneur php et avant les tests.
 */
#[AsCommand(name: 'app:ingredients:sync', description: 'Synchronise le référentiel d\'ingrédients depuis data/ingredients.csv')]
final class SyncIngredientsCommand extends Command
{
    public function __construct(
        private readonly IngredientRepository $ingredients,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/data/ingredients.csv')]
        private readonly string $csvPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $existing = [];
        foreach ($this->ingredients->findAll() as $ingredient) {
            $existing[$ingredient->getNormalizedName()] = $ingredient;
        }

        $created = $updated = 0;
        $handle = fopen($this->csvPath, 'r');
        if (false === $handle) {
            $io->error('Cannot read '.$this->csvPath);

            return Command::FAILURE;
        }
        while (false !== ($line = fgets($handle))) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $cols = array_map('trim', explode(';', $line));
            if (\count($cols) < 6) {
                $io->warning('Ignored line: '.$line);
                continue;
            }
            [$name, $category, $unit, $shelf, $weight, $basic] = $cols;
            $aliases = array_values(array_filter(array_map(IngredientMatcher::normalize(...), explode('|', $cols[6] ?? ''))));
            if (!\in_array($category, RecipeVocabulary::CATEGORIES, true) || ('' !== $unit && !\in_array($unit, RecipeVocabulary::UNITS, true))) {
                $io->warning('Invalid category or unit: '.$line);
                continue;
            }

            $normalized = IngredientMatcher::normalize($name);
            $args = [$name, $category, '' === $unit ? null : $unit, '' === $shelf ? null : (int) $shelf, '' === $weight ? null : (float) $weight, '1' === $basic, $aliases];
            if (isset($existing[$normalized])) {
                $existing[$normalized]->updateFromReference(...$args);
                ++$updated;
            } else {
                $ingredient = new Ingredient($args[0], $normalized, $args[1], $args[2], $args[3], $args[4], $args[5], false, $args[6]);
                $this->em->persist($ingredient);
                $existing[$normalized] = $ingredient;
                ++$created;
            }
        }
        fclose($handle);
        $this->em->flush();

        $io->success(\sprintf('Ingredients: %d created, %d updated.', $created, $updated));

        return Command::SUCCESS;
    }
}
