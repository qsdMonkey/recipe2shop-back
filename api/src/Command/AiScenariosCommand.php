<?php

declare(strict_types=1);

namespace App\Command;

use App\Scenario\ScenarioResult;
use App\Scenario\ScenarioRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Scénarios de test IA (POC.md, « Critères de réussite ») : conversations scriptées rejouées contre le VRAI Gemini,
 * à relancer à chaque changement de prompt. Coûte quelques centimes par passage (affiché dans le rapport).
 */
#[AsCommand(name: 'app:ai:scenarios', description: 'Rejoue les scénarios de test IA (scenarios/*.yaml) contre Gemini et écrit un rapport')]
final class AiScenariosCommand extends Command
{
    public function __construct(
        private readonly ScenarioRunner $runner,
        #[Autowire('%kernel.project_dir%/scenarios')]
        private readonly string $scenarioDir,
        #[Autowire('%kernel.project_dir%/var/scenarios')]
        private readonly string $reportDir,
        #[Autowire('%env(GEMINI_MODEL)%')]
        private readonly string $model,
        #[Autowire('%app.prompt.shopping%')]
        private readonly string $shoppingPrompt,
        #[Autowire('%app.prompt.onboarding%')]
        private readonly string $onboardingPrompt,
        #[Autowire('%app.prompt.recipe%')]
        private readonly string $recipePrompt,
        #[Autowire('%app.prompt.memory_review%')]
        private readonly string $reviewPrompt,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('names', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Scénarios à jouer (par défaut : tous)')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Garder les comptes de test créés (sinon supprimés à la fin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $files = glob($this->scenarioDir.'/*.yaml') ?: [];
        sort($files);
        $wanted = $input->getArgument('names');

        $results = [];
        foreach ($files as $file) {
            $scenario = Yaml::parseFile($file);
            if ([] !== $wanted && !\in_array($scenario['name'], $wanted, true)) {
                continue;
            }
            $io->section($scenario['name'].' — '.($scenario['description'] ?? ''));
            $result = $this->runner->run($scenario, (bool) $input->getOption('keep'));
            foreach ($result->transcript as $line) {
                $io->writeln('  '.$line);
            }
            foreach ($result->checks as $check) {
                $io->writeln(\sprintf('  %s %s%s', $check['ok'] ? '<info>✔</info>' : ($check['soft'] ? '<comment>⚠</comment>' : '<error>✘</error>'), $check['check'], $check['ok'] || '' === $check['detail'] ? '' : ' — '.$check['detail']));
            }
            if (null !== $result->error) {
                $io->error($result->error);
            }
            $io->writeln(\sprintf('  → <options=bold>%s</> · %d tours · %.0f s d\'IA · %d appels Gemini · %.4f $', strtoupper($result->status()), $result->turns, $result->aiSeconds, $result->geminiCalls, $result->costUsd));
            $results[] = $result;
        }

        if ([] === $results) {
            $io->warning('Aucun scénario trouvé.');

            return Command::INVALID;
        }

        $path = $this->writeReport($results);
        $failed = \count(array_filter($results, static fn (ScenarioResult $r): bool => 'fail' === $r->status()));
        $io->table(['Scénario', 'Statut', 'Tours', 'IA (s)', 'Appels', 'Coût ($)'], array_map(static fn (ScenarioResult $r): array => [$r->name, $r->status(), $r->turns, round($r->aiSeconds), $r->geminiCalls, number_format($r->costUsd, 4)], $results));
        $io->writeln('Rapport : '.$path);
        0 === $failed ? $io->success('Tous les scénarios passent (⚠ = critère souple non tenu).') : $io->error($failed.' scénario(s) en échec.');

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<ScenarioResult> $results */
    private function writeReport(array $results): string
    {
        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0775, true);
        }
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d_H-i');
        $md = [\sprintf('# Scénarios IA — %s', str_replace('_', ' ', $date)), '', \sprintf('Modèle `%s` · prompts `%s`, `%s`, `%s`, `%s`', $this->model, $this->onboardingPrompt, $this->shoppingPrompt, $this->recipePrompt, $this->reviewPrompt), '', '| Scénario | Statut | Tours | Temps IA | Appels Gemini | Coût |', '|---|---|---|---|---|---|'];
        foreach ($results as $r) {
            $md[] = \sprintf('| %s | %s | %d | %.0f s | %d | %.4f $ |', $r->name, ['pass' => '✅', 'warn' => '⚠️', 'fail' => '❌'][$r->status()], $r->turns, $r->aiSeconds, $r->geminiCalls, $r->costUsd);
        }
        foreach ($results as $r) {
            $md[] = '';
            $md[] = '## '.$r->name.' — '.$r->description;
            $md[] = '';
            foreach ($r->checks as $c) {
                $md[] = \sprintf('- %s %s%s', $c['ok'] ? '✅' : ($c['soft'] ? '⚠️' : '❌'), $c['check'], '' === $c['detail'] ? '' : ' — '.$c['detail']);
            }
            if (null !== $r->error) {
                $md[] = '- ❌ Erreur : '.$r->error;
            }
            $md[] = '';
            $md[] = '<details><summary>Déroulé</summary>';
            $md[] = '';
            $md[] = '```';
            array_push($md, ...$r->transcript);
            $md[] = '```';
            $md[] = '</details>';
        }

        $path = $this->reportDir.'/report-'.$date.'.md';
        file_put_contents($path, implode("\n", $md)."\n");
        file_put_contents($this->reportDir.'/report-'.$date.'.json', json_encode(array_map(static fn (ScenarioResult $r): array => [
            'name' => $r->name, 'status' => $r->status(), 'turns' => $r->turns, 'ai_seconds' => round($r->aiSeconds, 1), 'gemini_calls' => $r->geminiCalls,
            'cost_usd' => round($r->costUsd, 5), 'input_tokens' => $r->inputTokens, 'output_tokens' => $r->outputTokens, 'checks' => $r->checks, 'error' => $r->error, 'transcript' => $r->transcript,
        ], $results), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
