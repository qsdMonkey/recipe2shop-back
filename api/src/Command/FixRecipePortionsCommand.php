<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recipe;
use App\Entity\SessionStatus;
use App\Ingredient\IngredientMatcher;
use App\Recipe\PortionChecker;
use App\Recipe\PortionRules;
use App\Recipe\WeightRule;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applique aux recettes déjà en base les règles des recettes générées : pièces converties en grammes (poids moyen du
 * référentiel), puis portions des familles « base » ramenées dans la plage (PortionRules). Idempotente.
 * Rapport : id et titre des recettes seulement (aucune donnée personnelle). Lancée à la main, pas par deploy.sh.
 */
#[AsCommand(name: 'app:recipes:fix-portions', description: 'Corrige les portions et les pièces des recettes existantes (--dry-run pour simuler)')]
final class FixRecipePortionsCommand extends Command
{
    private const BATCH = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PortionChecker $portions,
        private readonly IngredientMatcher $matcher,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les corrections sans rien enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $connection = $this->em->getConnection();

        $fixed = $checked = 0;
        $lastId = 0;
        do {
            /** @var list<Recipe> $recipes */
            $recipes = $this->em->createQueryBuilder()
                ->select('r')->from(Recipe::class, 'r') // pas de jointure : la limite porte sur les recettes
                ->where('r.id > :last')->setParameter('last', $lastId)
                ->orderBy('r.id')->setMaxResults(self::BATCH)
                ->getQuery()->getResult();
            foreach ($recipes as $recipe) {
                $lastId = (int) $recipe->getId();
                ++$checked;
                $report = $this->fix($recipe, $dryRun);
                if ([] === $report) {
                    continue;
                }
                ++$fixed;
                $io->writeln(\sprintf('#%d « %s » : %s', $recipe->getId(), $recipe->getTitle(), implode(' ; ', $report)));
                if (!$dryRun) {
                    // Listes de courses des sessions actives qui utilisent la recette : signalées « pas à jour ».
                    $connection->executeStatement(
                        'UPDATE shopping_session s JOIN session_recipe sr ON sr.session_id = s.id SET s.list_inputs_version = s.list_inputs_version + 1 WHERE sr.recipe_id = ? AND s.status = ?',
                        [$recipe->getId(), SessionStatus::Active->value],
                    );
                    $this->logger->info('recipe.portions_fixed', ['recipe_id' => $recipe->getId(), 'changes' => \count($report)]);
                }
            }
            if (!$dryRun) {
                $this->em->flush();
            }
            $this->em->clear();
            $this->matcher->reset();
        } while (self::BATCH === \count($recipes));

        $io->success(\sprintf('%s : %d recette(s) à corriger sur %d.', $dryRun ? 'Simulation' : 'Terminé', $fixed, $checked));

        return Command::SUCCESS;
    }

    /** @return list<string> lignes du rapport (vide = rien à corriger) */
    private function fix(Recipe $recipe, bool $dryRun): array
    {
        $report = [];
        $ingredients = array_values($recipe->getIngredients()->toArray());

        // 1. Pièces → grammes (règle des poids), quand le poids moyen est connu.
        $data = [
            'servings' => $recipe->getServings(),
            'meals_covered' => $recipe->getMealsCovered(),
            'steps' => $recipe->getSteps(),
            'ingredients' => [],
        ];
        foreach ($ingredients as $ri) {
            $quantity = $ri->getQuantity();
            $unit = $ri->getUnit();
            $reference = $ri->getIngredient() ?? $this->matcher->find($ri->getName());
            $category = null !== $reference && !$reference->isToReview() ? $reference->getCategory() : $ri->getCategory();
            if ('piece' === $unit && null !== $quantity && 'gousse' === $reference?->getDefaultUnit()) {
                $report[] = \sprintf('%s %s pièce(s) → %s gousse(s)', $ri->getName(), PortionChecker::format($quantity), PortionChecker::format($quantity));
                $unit = 'gousse'; // ail : en gousses, pas en grammes
            } elseif ('piece' === $unit && null !== $quantity && !WeightRule::pieceAllowed($ri->getName(), $category)) {
                $weight = $reference?->getAvgWeightPerPiece();
                if (null !== $weight && $weight > 0) {
                    [$grams, $g] = PortionChecker::roundForPurchase($quantity * $weight, 'g');
                    $report[] = \sprintf('%s %s pièce(s) → %s %s', $ri->getName(), PortionChecker::format($quantity), PortionChecker::format((float) $grams), $g);
                    [$quantity, $unit] = [$grams, $g];
                }
            }
            $data['ingredients'][] = ['name' => $ri->getName(), 'quantity' => $quantity, 'unit' => $unit, 'category' => $ri->getCategory(), 'optional' => $ri->isOptional()];
        }

        // 2. Portions des familles « base ».
        [$data, $changes] = $this->portions->correct($data);
        foreach ($changes as $change) {
            $unit = PortionRules::FAMILIES[$change['family']]['unit'];
            $report[] = \sprintf('%s %s → %s %s/portion', $change['family'], PortionChecker::format($change['before']), PortionChecker::format($change['after']), $unit);
        }

        if ([] !== $report && !$dryRun) {
            foreach ($ingredients as $i => $ri) {
                $ri->changeQuantity($data['ingredients'][$i]['quantity'], $data['ingredients'][$i]['unit']);
            }
            $recipe->replaceSteps($data['steps']);
        }

        return $report;
    }
}
