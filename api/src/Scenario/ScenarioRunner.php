<?php

declare(strict_types=1);

namespace App\Scenario;

use App\Conversation\RecipeDecision;
use App\Conversation\SessionManager;
use App\Conversation\Tool\ToolRegistry;
use App\Conversation\TurnResult;
use App\Conversation\TurnRunner;
use App\Conversation\UserInput;
use App\Entity\MemoryCategory;
use App\Entity\MemoryOrigin;
use App\Entity\MessageRole;
use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Ingredient\IngredientMatcher;
use App\Memory\MemoryManager;
use App\Message\MemoryReviewMessage;
use App\MessageHandler\MemoryReviewHandler;
use App\Presenter\ShoppingListPresenter;
use App\Repository\SessionRecipeRepository;
use App\Repository\ShoppingListRepository;
use App\Repository\UserMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rejoue un scénario de conversation (scenarios/*.yaml) contre le VRAI Gemini, via les mêmes services que l'API,
 * puis vérifie les critères de réussite du POC : bons outils appelés, JSON valides, mémoire correcte, durées, coût.
 */
final class ScenarioRunner
{
    private const REVIEW_WAIT_SECONDS = 90;

    private User $user;
    private ?ShoppingSession $session = null;
    /** @var list<int> */
    private array $sessionIds = [];
    /** @var list<string> */
    private array $toolsSeen = [];
    /** @var list<string> */
    private array $blocksSeen = [];
    private int $recipeToolErrors = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SessionManager $sessions,
        private readonly TurnRunner $turnRunner,
        private readonly RecipeDecision $recipeDecision,
        private readonly MemoryManager $memory,
        private readonly UserMemoryRepository $memoryItems,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly ShoppingListRepository $lists,
        private readonly ShoppingListPresenter $listPresenter,
        private readonly MemoryReviewHandler $reviewHandler,
        private readonly IngredientMatcher $matcher,
        private readonly ToolRegistry $tools,
    ) {
    }

    /** @param array<string, mixed> $scenario */
    public function run(array $scenario, bool $keepData): ScenarioResult
    {
        $result = new ScenarioResult((string) $scenario['name'], (string) ($scenario['description'] ?? ''));
        // Scénarios indépendants : EntityManager et caches remis à zéro (sinon entités détachées d'un scénario à l'autre).
        $this->em->clear();
        $this->matcher->reset();
        $this->tools->disableForTesting(array_values((array) ($scenario['disable_tools'] ?? [])));
        $this->session = null;
        $this->sessionIds = $this->toolsSeen = $this->blocksSeen = [];
        $this->recipeToolErrors = 0;

        $this->user = new User(\sprintf('scenario-%s-%s@scenarios.local', $scenario['name'], bin2hex(random_bytes(4))));
        $this->user->setPassword('!'); // compte technique, sans connexion possible
        $this->em->persist($this->user);
        foreach ($scenario['memory'] ?? [] as $category => $values) {
            foreach ((array) $values as $value) {
                $this->memory->add($this->user, MemoryCategory::from($category), (string) $value, MemoryOrigin::User);
            }
        }
        if (isset($scenario['memory'])) {
            $this->user->completeOnboarding();
        }
        $this->em->flush();

        try {
            foreach ($scenario['steps'] as $i => $step) {
                $this->step($i + 1, $step, $result);
            }
            $this->finalChecks($scenario['checks'] ?? [], false, $result);
            $this->finalChecks($scenario['soft_checks'] ?? [], true, $result);
        } catch (\Throwable $e) {
            $frames = array_slice(array_values(array_filter($e->getTrace(), static fn (array $f): bool => str_contains($f['file'] ?? '', '/src/'))), 0, 4);
            $result->error = $e::class.': '.$e->getMessage().' @ '.implode(' < ', array_map(static fn (array $f): string => basename($f['file']).':'.$f['line'], $frames));
        }

        $this->tools->disableForTesting([]);
        $this->collectCosts($result);
        if (!$keepData) {
            $id = $this->user->getId();
            $this->em->clear();
            $this->matcher->reset();
            $this->em->getConnection()->delete('app_user', ['id' => $id]); // cascade : sessions, mémoire, listes
        }

        return $result;
    }

    /** @param array<string, mixed> $step */
    private function step(int $n, array $step, ScenarioResult $result): void
    {
        if (isset($step['start'])) {
            [$this->session] = $this->sessions->start($this->user, SessionType::from($step['start']));
            $this->sessionIds[] = (int) $this->session->getId();
            $greeting = $this->lastAssistantText();
            $result->transcript[] = \sprintf('%d. [nouvelle session %s, %d jours] IA : %s', $n, $step['start'], $this->session->getPeriodDays(), self::short($greeting));
            $this->expectations($n, $step['expect'] ?? [], $greeting, [], $result);

            return;
        }

        if (isset($step['say'])) {
            $this->turn($n, 'MOI : '.$step['say'], fn (): TurnResult => $this->turnRunner->send($this->session(), UserInput::text($step['say']), null), $step['expect'] ?? [], $result);

            return;
        }

        if (isset($step['reject_pending'])) {
            $pending = $this->sessionRecipes->findPending($this->session());
            $result->check(\sprintf('étape %d : une recette est en attente', $n), null !== $pending);
            if (null !== $pending) {
                $title = $pending->getRecipe()->getTitle();
                $this->turn($n, \sprintf('🔄 « %s » (%s)', $title, $step['reject_pending']), fn (): TurnResult => $this->recipeDecision->decide($this->session(), (int) $pending->getRecipe()->getId(), false, $step['reject_pending'], null), $step['expect'] ?? [], $result);
            }

            return;
        }

        if (isset($step['accept_until_covered'])) {
            $max = (int) ($step['accept_until_covered']['max'] ?? 6);
            for ($k = 0; $k < $max; ++$k) {
                $pending = $this->sessionRecipes->findPending($this->session());
                if (null === $pending) {
                    $this->turn($n, 'MOI : Propose-moi la recette suivante.', fn (): TurnResult => $this->turnRunner->send($this->session(), UserInput::text('Propose-moi la recette suivante.'), null), [], $result);
                    $pending = $this->sessionRecipes->findPending($this->session());
                    if (null === $pending) {
                        break;
                    }
                }
                $this->turn($n, \sprintf('✅ « %s »', $pending->getRecipe()->getTitle()), fn (): TurnResult => $this->recipeDecision->decide($this->session(), (int) $pending->getRecipe()->getId(), true, null, null), [], $result);
                $planned = $this->session()->getMealsPlanned();
                if (null !== $planned && $this->sessionRecipes->mealsCovered($this->session()) >= min($planned, (int) ($step['accept_until_covered']['meals'] ?? $planned))) {
                    break;
                }
            }

            return;
        }

        if (isset($step['finish_onboarding'])) {
            $this->sessions->finishOnboarding($this->user, $this->session());
            $result->transcript[] = \sprintf('%d. [Terminer l\'onboarding]', $n);

            return;
        }

        if (isset($step['new_session'])) {
            $previous = $this->session();
            [$this->session] = $this->sessions->start($this->user, SessionType::Shopping);
            $this->sessionIds[] = (int) $this->session->getId();
            $reviewed = $this->waitForReview($previous);
            $result->transcript[] = \sprintf('%d. [nouvelle session → relecture de la précédente : %s]', $n, $reviewed);

            return;
        }

        throw new \InvalidArgumentException(\sprintf('Unknown step %d: %s', $n, json_encode($step)));
    }

    /**
     * @param \Closure(): TurnResult $action
     * @param array<string, mixed>   $expect
     */
    private function turn(int $n, string $label, \Closure $action, array $expect, ScenarioResult $result): void
    {
        $start = microtime(true);
        $turn = $action();
        $seconds = microtime(true) - $start;
        $result->aiSeconds += $seconds;
        ++$result->turns;

        $assistant = $turn->messages[1] ?? null;
        $tools = array_column($assistant?->getToolCalls() ?? [], 'name');
        foreach ($assistant?->getToolCalls() ?? [] as $call) {
            if ('propose_recipe' === $call['name'] && 'error' === ($call['response']['status'] ?? null)) {
                ++$this->recipeToolErrors;
            }
        }
        array_push($this->toolsSeen, ...$tools);
        $blocks = array_column($assistant?->getBlocks() ?? [], 'type');
        array_push($this->blocksSeen, ...$blocks);
        $text = $assistant?->getContent() ?? '';

        $result->transcript[] = \sprintf('%d. %s (%.1f s, outils : %s)', $n, $label, $seconds, [] === $tools ? '—' : implode(', ', $tools));
        $result->transcript[] = '   IA : '.self::short($text);
        $this->expectations($n, $expect, $text, $tools, $result);
    }

    /**
     * @param array<string, mixed> $expect
     * @param list<string>         $tools
     */
    private function expectations(int $n, array $expect, string $text, array $tools, ScenarioResult $result): void
    {
        foreach ($expect as $key => $value) {
            $soft = str_starts_with($key, 'soft_');
            $key = $soft ? substr($key, 5) : $key;
            match ($key) {
                'tools' => array_map(static fn (string $t) => $result->check(\sprintf('étape %d : outil %s appelé', $n, $t), \in_array($t, $tools, true), 'appelés : '.implode(', ', $tools), $soft), (array) $value),
                'tools_not' => array_map(static fn (string $t) => $result->check(\sprintf('étape %d : outil %s NON appelé', $n, $t), !\in_array($t, $tools, true), 'appelés : '.implode(', ', $tools), $soft), (array) $value),
                'text_matches' => $result->check(\sprintf('étape %d : réponse contient /%s/', $n, $value), 1 === preg_match('/'.$value.'/iu', $text), self::short($text), $soft),
                'text_not_matches' => $result->check(\sprintf('étape %d : réponse ne contient pas /%s/', $n, $value), 0 === preg_match('/'.$value.'/iu', $text), self::short($text), $soft),
                default => throw new \InvalidArgumentException('Unknown expectation: '.$key),
            };
        }
    }

    /** @param array<string, mixed> $checks */
    private function finalChecks(array $checks, bool $soft, ScenarioResult $result): void
    {
        foreach ($checks as $key => $value) {
            match ($key) {
                'memory_contains' => $this->memoryChecks((array) $value, true, $soft, $result),
                'memory_not_contains' => $this->memoryChecks((array) $value, false, $soft, $result),
                'memory_from_review' => $this->memoryChecks((array) $value, true, $soft, $result, 'batch'),
                'onboarding_completed' => $result->check('onboarding terminé', $this->user->isOnboardingCompleted() === (bool) $value, '', $soft),
                'blocks_seen' => array_map(fn (string $b) => $result->check(\sprintf('bloc %s affiché', $b), \in_array($b, $this->blocksSeen, true), '', $soft), (array) $value),
                'tools_seen' => array_map(fn (string $t) => $result->check(\sprintf('outil %s appelé au moins une fois', $t), \in_array($t, $this->toolsSeen, true), '', $soft), (array) $value),
                'max_ai_seconds' => $result->check(\sprintf('temps IA total ≤ %d s', $value), $result->aiSeconds <= $value, \sprintf('%.0f s', $result->aiSeconds), $soft),
                'period_days' => $result->check(\sprintf('période de %d jours', $value), $this->session()->getPeriodDays() === (int) $value, (string) $this->session()->getPeriodDays(), $soft),
                'recipe_tool_errors_max' => $result->check(\sprintf('erreurs de génération de recette ≤ %d', $value), $this->recipeToolErrors <= $value, (string) $this->recipeToolErrors, $soft),
                'recipes_exclude' => $this->recipesExclude((array) $value, $soft, $result),
                'meals_covered' => $this->mealsCovered($soft, $result),
                'list_generated' => $this->listGenerated($soft, $result),
                'inventory_in_list' => $this->inventoryInList((array) $value, $soft, $result),
                'recipes_difficulty' => $this->recipesDifficulty((array) $value, $soft, $result),
                'recipes_tag_any' => $this->recipesTagAny((array) $value, $soft, $result),
                default => throw new \InvalidArgumentException('Unknown check: '.$key),
            };
        }
    }

    /** @param array<string, string|list<string>> $expected */
    private function memoryChecks(array $expected, bool $present, bool $soft, ScenarioResult $result, ?string $requiredOrigin = null): void
    {
        foreach ($expected as $category => $needles) {
            $values = array_map(static fn ($i): string => $i->getValue(), $this->memoryItems->findInCategory($this->user, MemoryCategory::from($category)));
            foreach ((array) $needles as $needle) {
                $found = [] !== array_filter($values, static fn (string $v): bool => str_contains(MemoryManager::normalize($v), MemoryManager::normalize($needle)));
                $origin = '';
                if ($found) {
                    $origin = (string) $this->em->getConnection()->fetchOne(
                        'SELECT GROUP_CONCAT(DISTINCT origin) FROM memory_event WHERE user_id = ? AND category = ? AND new_value LIKE ?',
                        [$this->user->getId(), $category, '%'.$needle.'%'],
                    );
                }
                $result->check(
                    \sprintf('mémoire %s %s « %s »%s', $category, $present ? 'contient' : 'ne contient pas', $needle, null === $requiredOrigin ? '' : ' (retenu par la relecture : '.$requiredOrigin.')'),
                    $found === $present && (null === $requiredOrigin || str_contains($origin, $requiredOrigin)),
                    'valeurs : '.([] === $values ? '(aucune)' : implode(' | ', $values)).('' === $origin ? '' : ' (origine : '.$origin.')'),
                    $soft,
                );
            }
        }
    }

    /** @param list<string> $banned */
    private function recipesExclude(array $banned, bool $soft, ScenarioResult $result): void
    {
        $hits = [];
        foreach ($this->sessionIds as $id) {
            foreach ($this->sessionRecipes->findForSession($this->em->getReference(ShoppingSession::class, $id)) as $sr) {
                foreach ($sr->getRecipe()->getIngredients() as $ingredient) {
                    foreach ($banned as $word) {
                        if (str_contains(MemoryManager::normalize($ingredient->getName()), MemoryManager::normalize($word))) {
                            $hits[] = $sr->getRecipe()->getTitle().' → '.$ingredient->getName();
                        }
                    }
                }
            }
        }
        $result->check('aucune recette avec : '.implode(', ', $banned), [] === $hits, implode(' ; ', $hits), $soft);
    }

    private function mealsCovered(bool $soft, ScenarioResult $result): void
    {
        $planned = $this->session()->getMealsPlanned();
        $covered = $this->sessionRecipes->mealsCovered($this->session());
        $result->check('repas couverts ≥ repas prévus', null !== $planned && $covered >= $planned, \sprintf('%d / %s', $covered, $planned ?? '?'), $soft);
    }

    private function listGenerated(bool $soft, ScenarioResult $result): void
    {
        $list = $this->lists->findMain($this->session());
        $detail = null === $list ? null : $this->listPresenter->detail($list);
        $result->check('liste principale générée et non vide', null !== $detail && $detail['item_count'] > 0, null === $detail ? 'aucune' : \sprintf('%d articles, %d rayons, déjà chez toi : %s', $detail['item_count'], \count($detail['sections']), implode(', ', array_column($detail['already_have'], 'label'))), $soft);
    }

    /** @param list<string> $labels */
    private function inventoryInList(array $labels, bool $soft, ScenarioResult $result): void
    {
        $list = $this->lists->findMain($this->session());
        $detail = null === $list ? ['sections' => [], 'already_have' => []] : $this->listPresenter->detail($list);
        $deducted = array_column($detail['already_have'], 'label');
        foreach ($detail['sections'] as $section) {
            foreach ($section['items'] as $item) {
                if (null !== $item['inventory_deducted']) {
                    $deducted[] = $item['label'];
                }
            }
        }
        foreach ($labels as $label) {
            $ok = [] !== array_filter($deducted, static fn (string $d): bool => str_contains(MemoryManager::normalize($d), MemoryManager::normalize($label)));
            $result->check(\sprintf('inventaire « %s » déduit de la liste', $label), $ok, 'déduits : '.implode(', ', $deducted), $soft);
        }
    }

    /** @param list<string> $allowed */
    private function recipesDifficulty(array $allowed, bool $soft, ScenarioResult $result): void
    {
        $bad = [];
        foreach ($this->sessionRecipes->findForSession($this->session()) as $sr) {
            if (!\in_array($sr->getRecipe()->getDifficulty(), $allowed, true)) {
                $bad[] = $sr->getRecipe()->getTitle().' ('.$sr->getRecipe()->getDifficulty().')';
            }
        }
        $result->check('difficulté des recettes : '.implode(' ou ', $allowed), [] === $bad, implode(' ; ', $bad), $soft);
    }

    /** @param list<string> $tags */
    private function recipesTagAny(array $tags, bool $soft, ScenarioResult $result): void
    {
        $titles = [];
        foreach ($this->sessionRecipes->findForSession($this->session()) as $sr) {
            if ([] !== array_intersect($tags, $sr->getRecipe()->getMealTags())) {
                $titles[] = $sr->getRecipe()->getTitle();
            }
        }
        $result->check('au moins une recette taguée '.implode(' / ', $tags), [] !== $titles, implode(' ; ', $titles), $soft);
    }

    private function waitForReview(ShoppingSession $previous): string
    {
        $id = $previous->getId();
        $deadline = time() + self::REVIEW_WAIT_SECONDS;
        while (time() < $deadline) {
            if (null !== $this->em->getConnection()->fetchOne('SELECT memory_reviewed_at FROM shopping_session WHERE id = ?', [$id])) {
                $this->em->refresh($previous);

                return 'faite par le worker';
            }
            sleep(2);
        }
        ($this->reviewHandler)(new MemoryReviewMessage((int) $id)); // worker absent : relecture directe

        return 'faite directement (worker non disponible)';
    }

    private function collectCosts(ScenarioResult $result): void
    {
        if ([] === $this->sessionIds) {
            return;
        }
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(*) calls, COALESCE(SUM(cost_usd), 0) cost, COALESCE(SUM(input_tokens), 0) input, COALESCE(SUM(output_tokens + thinking_tokens), 0) output FROM generation_log WHERE session_id IN (?)',
            [$this->sessionIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $result->geminiCalls = (int) $row['calls'];
        $result->costUsd = (float) $row['cost'];
        $result->inputTokens = (int) $row['input'];
        $result->outputTokens = (int) $row['output'];
    }

    private function lastAssistantText(): string
    {
        $messages = $this->em->getRepository(\App\Entity\Message::class)->findBy(['session' => $this->session(), 'role' => MessageRole::Assistant], ['id' => 'DESC'], 1);

        return [] === $messages ? '' : $messages[0]->getContent();
    }

    private function session(): ShoppingSession
    {
        return $this->session ?? throw new \LogicException('No session started: add a "start" step first.');
    }

    private static function short(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 220 ? mb_substr($text, 0, 217).'…' : $text;
    }
}
