<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Conversation\Tool\ToolContext;
use App\Conversation\Tool\ToolRegistry;
use App\Entity\ShoppingSession;
use App\Entity\Turn;
use App\Gemini\GeminiClient;
use App\Gemini\GeminiException;
use App\Gemini\GeminiPurpose;
use App\Gemini\GeminiRequest;
use App\Gemini\GeminiResponse;
use App\Gemini\ThinkingLevels;
use App\Prompt\PromptLoader;

/**
 * La boucle d'un tour : Gemini → exécution des outils demandés → résultats renvoyés à Gemini → … → réponse texte.
 * N'écrit rien elle-même en base hors des outils : la transaction est gérée par TurnRunner.
 */
final class ConversationOrchestrator
{
    /** Nombre maximal d'appels à Gemini par tour. Le dernier interdit les outils pour forcer une réponse texte. */
    public const MAX_ROUNDS = 5;
    /** Budget total d'un tour (l'app coupe à 60 s). */
    public const TURN_BUDGET_SECONDS = 50;
    /** Message d'accueil : au-delà, l'accueil statique prend le relais (la création de session ne doit pas traîner). */
    public const GREETING_MAX_SECONDS = 8.0;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ContextBuilder $contextBuilder,
        private readonly ToolRegistry $tools,
        private readonly GenerationLogger $logger,
        private readonly PromptLoader $prompts,
        private readonly ThinkingLevels $thinkingLevels,
    ) {
    }

    /**
     * @param string|null $userText   null = message d'accueil
     * @param bool        $allowTools false = Gemini ne reçoit aucun outil (ex. message d'accueil)
     *
     * @throws GeminiException
     */
    public function run(ShoppingSession $session, Turn $turn, ?string $userText, bool $allowTools = true): TurnOutcome
    {
        $context = $this->contextBuilder->build($session, $userText);
        $declarations = $allowTools ? $this->tools->declarationsFor($session) : [];
        $toolContext = new ToolContext($session, $session->getUser(), $turn);
        $purpose = null === $userText ? 'greeting' : 'conversation';

        $contents = $context->contents;
        $blocks = [];
        $toolCalls = [];
        $deadline = microtime(true) + self::TURN_BUDGET_SECONDS;

        for ($round = 1; $round <= self::MAX_ROUNDS; ++$round) {
            if (microtime(true) > $deadline) {
                throw new GeminiException('Turn time budget exceeded.');
            }

            $response = $this->call($session, $purpose, $context->promptVersion, new GeminiRequest(
                systemInstruction: $context->systemInstruction,
                contents: $contents,
                functionDeclarations: $declarations,
                allowToolCalls: $round < self::MAX_ROUNDS,
                thinkingLevel: $this->thinkingLevels->for(GeminiPurpose::Chat),
                maxDurationSeconds: null === $userText ? self::GREETING_MAX_SECONDS : null,
            ));

            $text = $response->text();
            if ('' !== $text) {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }

            $calls = $response->functionCalls();
            if ([] === $calls) {
                break;
            }

            $contents[] = ['role' => 'model', 'parts' => $response->parts];
            $responseParts = [];
            foreach ($calls as $call) {
                $result = $this->tools->execute($call['name'], $call['args'], $toolContext);
                array_push($blocks, ...$result->blocks);
                $toolCalls[] = ['name' => $call['name'], 'args' => $call['args'], 'response' => $result->response];

                $functionResponse = ['name' => $call['name'], 'response' => $result->response];
                if (null !== $call['id']) {
                    $functionResponse['id'] = $call['id'];
                }
                $responseParts[] = ['functionResponse' => $functionResponse];
            }
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        $blocks = self::mergeTextBlocks(self::dedupeListCards($blocks));
        if (!\in_array('text', array_column($blocks, 'type'), true)) {
            // Réponse vide (filtrage, outils seuls…) : on ne laisse jamais l'utilisateur sans réponse.
            $blocks[] = ['type' => 'text', 'text' => $this->prompts->load('empty_answer_fallback')];
        }

        $content = implode("\n\n", array_column(array_filter($blocks, static fn (array $b): bool => 'text' === $b['type']), 'text'));

        return new TurnOutcome($blocks, $content, $toolCalls);
    }

    private function call(ShoppingSession $session, string $purpose, string $promptVersion, GeminiRequest $request): GeminiResponse
    {
        $start = hrtime(true);
        try {
            $response = $this->gemini->generate($request);
        } catch (GeminiException $e) {
            $this->logger->record($session, $purpose, $this->gemini->model(), $promptVersion, null, (int) ((hrtime(true) - $start) / 1_000_000), $e->getMessage());
            throw $e;
        }
        $this->logger->record($session, $purpose, $this->gemini->model(), $promptVersion, $response, $response->durationMs);

        return $response;
    }

    /**
     * Une seule carte par liste dans un message : on garde la dernière (après tous les ajouts du tour).
     *
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function dedupeListCards(array $blocks): array
    {
        $last = [];
        foreach ($blocks as $i => $block) {
            if ('shopping_list' === $block['type'] && isset($block['list_id'])) {
                $last[$block['list_id']] = $i;
            }
        }

        return array_values(array_filter($blocks, static fn (array $b, int $i): bool => !('shopping_list' === $b['type'] && isset($b['list_id'])) || $last[$b['list_id']] === $i, \ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Fusionne les blocs texte consécutifs (texte avant et après un appel d'outil sans carte entre les deux).
     *
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function mergeTextBlocks(array $blocks): array
    {
        $merged = [];
        foreach ($blocks as $block) {
            $last = array_key_last($merged);
            if ('text' === $block['type'] && null !== $last && 'text' === $merged[$last]['type']) {
                $merged[$last]['text'] .= "\n\n".$block['text'];
                continue;
            }
            $merged[] = $block;
        }

        return $merged;
    }
}
