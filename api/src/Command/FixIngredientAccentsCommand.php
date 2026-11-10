<?php

declare(strict_types=1);

namespace App\Command;

use App\Ingredient\AccentRestorer;
use App\Memory\MemoryManager;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Répare les noms d'ingrédients sans accents déjà enregistrés (Gemini les omettait parfois) :
 * ingrédients « to_review », ingrédients des recettes, articles des listes. Idempotent (lancé par deploy.sh).
 * Ne touche jamais aux clés normalisées ni aux libellés saisis par l'utilisateur (articles « manual »).
 */
#[AsCommand(name: 'app:ingredients:fix-accents', description: 'Restaure les accents des noms d\'ingrédients enregistrés sans accents')]
final class FixIngredientAccentsCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccentRestorer $accents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Afficher les corrections sans les enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $changes = ['ingredient' => 0, 'recipe_ingredient' => 0, 'shopping_list_item' => 0];
        $update = function (string $table, string $column, int $id, string $old, string $new) use ($dryRun, $io, &$changes): void {
            if ($new === $old) {
                return;
            }
            $io->writeln(\sprintf('  %s #%d : « %s » → « %s »', $table, $id, $old, $new), OutputInterface::VERBOSITY_VERBOSE);
            ++$changes[$table];
            if (!$dryRun) {
                $this->db->update($table, [$column => $new], ['id' => $id]);
            }
        };

        // 1. Ingrédients créés automatiquement (to_review) : accents restaurés.
        foreach ($this->db->fetchAllAssociative('SELECT id, name FROM ingredient WHERE to_review = 1') as $row) {
            $update('ingredient', 'name', (int) $row['id'], $row['name'], $this->accents->restore($row['name']));
        }

        // 2. Ingrédients des recettes : orthographe du référentiel si c'est le même nom, sinon accents restaurés.
        $rows = $this->db->fetchAllAssociative('SELECT ri.id, ri.name, i.name AS ref_name, i.to_review FROM recipe_ingredient ri LEFT JOIN ingredient i ON i.id = ri.ingredient_id');
        foreach ($rows as $row) {
            $new = null !== $row['ref_name'] && !$row['to_review'] && 1 !== preg_match('/[^\x00-\x7F]/', $row['name'])
                && MemoryManager::normalize($row['name']) === MemoryManager::normalize($row['ref_name'])
                ? $row['ref_name']
                : $this->accents->restore($row['name']);
            $update('recipe_ingredient', 'name', (int) $row['id'], $row['name'], $new);
        }

        // 3. Articles des listes : calculés → nom de l'ingrédient (déjà corrigé) ; ajoutés par l'IA → accents restaurés.
        $rows = $this->db->fetchAllAssociative("SELECT s.id, s.label, s.origin, i.name AS ref_name FROM shopping_list_item s LEFT JOIN ingredient i ON i.id = s.ingredient_id WHERE s.origin IN ('recipe', 'assistant')");
        foreach ($rows as $row) {
            $new = 'recipe' === $row['origin'] && null !== $row['ref_name']
                ? $row['ref_name']
                : $this->accents->restore($row['label']);
            $update('shopping_list_item', 'label', (int) $row['id'], $row['label'], $new);
        }

        $io->success(\sprintf('%sCorrections : %d ingrédient(s), %d ingrédient(s) de recette, %d article(s) de liste.', $dryRun ? '[simulation] ' : '', ...array_values($changes)));

        return Command::SUCCESS;
    }
}
