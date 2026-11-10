<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Coût et latence Gemini (generation_log) : par type d'appel et par session. Critère du POC : « coût par session connu ».
 */
#[AsCommand(name: 'app:ai:costs', description: 'Coût et latence des appels Gemini (par type d\'appel et par session)')]
final class AiCostsCommand extends Command
{
    public function __construct(private readonly Connection $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Période analysée, en jours', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $since = (new \DateTimeImmutable(\sprintf('-%d days', max(1, (int) $input->getOption('days')))))->format('Y-m-d H:i:s');

        $byPurpose = $this->db->fetchAllAssociative(
            'SELECT purpose, COUNT(*) calls, SUM(success = 0) failed, ROUND(AVG(duration_ms)) avg_ms, MAX(duration_ms) max_ms,
                    ROUND(AVG(input_tokens)) avg_in, ROUND(AVG(output_tokens + thinking_tokens)) avg_out, SUM(cost_usd) cost, AVG(cost_usd) avg_cost
             FROM generation_log WHERE created_at >= ? GROUP BY purpose ORDER BY cost DESC',
            [$since],
        );
        $io->title('Appels Gemini depuis le '.$since);
        $io->table(
            ['Type', 'Appels', 'Échecs', 'Durée moy. (ms)', 'Durée max (ms)', 'Tokens entrée moy.', 'Tokens sortie moy.', 'Coût total ($)', 'Coût moy. ($)'],
            array_map(static fn (array $r): array => [$r['purpose'], $r['calls'], $r['failed'], $r['avg_ms'], $r['max_ms'], $r['avg_in'], $r['avg_out'], number_format((float) $r['cost'], 4), number_format((float) $r['avg_cost'], 5)], $byPurpose),
        );

        $sessions = $this->db->fetchAssociative(
            "SELECT COUNT(*) sessions, AVG(cost) avg_cost, MAX(cost) max_cost, AVG(calls) avg_calls, AVG(recipes) avg_recipes
             FROM (SELECT s.id, SUM(g.cost_usd) cost, COUNT(g.id) calls,
                          (SELECT COUNT(*) FROM session_recipe sr WHERE sr.session_id = s.id) recipes
                   FROM shopping_session s JOIN generation_log g ON g.session_id = s.id
                   WHERE s.type = 'shopping' AND g.created_at >= ? GROUP BY s.id) t",
            [$since],
        );
        $io->section('Par session de courses (sessions encore en base)');
        $io->definitionList(
            ['Sessions' => $sessions['sessions']],
            ['Coût moyen par session' => number_format((float) $sessions['avg_cost'], 4).' $'],
            ['Coût max d\'une session' => number_format((float) $sessions['max_cost'], 4).' $'],
            ['Appels Gemini par session (moy.)' => number_format((float) $sessions['avg_calls'], 1)],
            ['Recettes proposées par session (moy.)' => number_format((float) $sessions['avg_recipes'], 1)],
        );
        $io->note('Coûts calculés avec GEMINI_PRICE_INPUT_PER_MTOK / GEMINI_PRICE_OUTPUT_PER_MTOK (.env). Les sessions supprimées (règle des 3, comptes supprimés) n\'apparaissent plus dans « par session », mais leurs appels restent comptés dans le premier tableau.');

        return Command::SUCCESS;
    }
}
