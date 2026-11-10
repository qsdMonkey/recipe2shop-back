<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Conversation\GenerationLogger;
use App\Conversation\MemoryFormatter;
use App\Conversation\SessionRetention;
use App\Entity\MemoryCategory;
use App\Entity\MemoryOrigin;
use App\Entity\Message;
use App\Entity\MessageRole;
use App\Entity\SessionRecipeStatus;
use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Gemini\GeminiClient;
use App\Gemini\GeminiException;
use App\Gemini\GeminiPurpose;
use App\Gemini\GeminiRequest;
use App\Gemini\ThinkingLevels;
use App\Memory\MemoryException;
use App\Memory\MemoryManager;
use App\Message\MemoryReviewMessage;
use App\Prompt\PromptLoader;
use App\Repository\MessageRepository;
use App\Repository\SessionRecipeRepository;
use App\Repository\ShoppingSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Relecture de fin de conversation (architecture-ia.md) : Gemini relit toute la session clôturée et liste les
 * opérations de mémoire à faire ; le code les contrôle et les applique (origin = batch). Les changements sont
 * affichés en puces dans la nouvelle session (message system). En cas d'erreur Gemini, l'exception remonte et
 * Messenger réessaie ; la session n'est supprimée (règle des 3) qu'après une relecture réussie.
 */
#[AsMessageHandler]
final class MemoryReviewHandler
{
    private const MAX_MESSAGES = 300;

    public function __construct(
        private readonly ShoppingSessionRepository $sessions,
        private readonly MessageRepository $messages,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly MemoryManager $memory,
        private readonly MemoryFormatter $memoryFormatter,
        private readonly SessionRetention $retention,
        private readonly GeminiClient $gemini,
        private readonly ThinkingLevels $thinkingLevels,
        private readonly GenerationLogger $generationLogger,
        private readonly PromptLoader $prompts,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
        #[Autowire('%app.prompt.memory_review%')]
        private readonly string $promptVersion,
    ) {
    }

    public function __invoke(MemoryReviewMessage $message): void
    {
        $session = $this->sessions->find($message->sessionId);
        if (null === $session || $session->isActive() || $session->isMemoryReviewed()) {
            return;
        }
        $user = $session->getUser();

        $transcript = $this->transcript($session);
        $eventIds = [];
        if (null !== $transcript) {
            try {
                $operations = $this->askGemini($session, $transcript);
            } catch (GeminiException $e) {
                $this->logger->error('memory_review.failed', ['session_id' => $session->getId(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
                throw $e; // Messenger réessaie
            } finally {
                $this->generationLogger->flush();
            }
            $eventIds = $this->apply($session, $operations);
        }

        if ([] !== $eventIds && null !== $target = $this->sessions->findActive($user, SessionType::Shopping)) {
            $blocks = [['type' => 'text', 'text' => $this->prompts->load('memory_review_intro')]];
            foreach ($eventIds as $id) {
                $blocks[] = ['type' => 'memory', 'event_id' => $id];
            }
            $this->em->persist(new Message($target, null, MessageRole::System, '', $blocks));
        }

        $session->markMemoryReviewed();
        $this->em->flush();
        $this->logger->info('memory_review.done', ['session_id' => $session->getId(), 'operations_applied' => \count($eventIds)]);
        $this->retention->apply($user);
    }

    private function transcript(ShoppingSession $session): ?string
    {
        $lines = [];
        $hasUserMessage = false;
        foreach (\array_slice($this->messages->findForSession($session), -self::MAX_MESSAGES) as $m) {
            if (MessageRole::System === $m->getRole()) {
                continue;
            }
            $hasUserMessage = $hasUserMessage || MessageRole::User === $m->getRole();
            $lines[] = (MessageRole::User === $m->getRole() ? 'UTILISATEUR : ' : 'ASSISTANT : ').$m->getContent();
        }
        if (!$hasUserMessage) {
            return null; // rien à relire
        }

        $recipes = [];
        foreach ($this->sessionRecipes->findForSession($session) as $sr) {
            $recipes[] = \sprintf('- %s : « %s »%s', match ($sr->getStatus()) {
                SessionRecipeStatus::Accepted => 'acceptée',
                SessionRecipeStatus::Rejected => 'refusée',
                SessionRecipeStatus::Proposed => 'sans réponse',
            }, $sr->getRecipe()->getTitle(), null === $sr->getComment() ? '' : ' (commentaire : '.$sr->getComment().')');
        }

        return implode("\n", [
            '# Mémoire actuelle',
            ...$this->memoryFormatter->lines($session->getUser(), withUnknown: false),
            '',
            '# Recettes de la session',
            ...([] === $recipes ? ['Aucune.'] : $recipes),
            '',
            '# Conversation complète',
            ...$lines,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function askGemini(ShoppingSession $session, string $transcript): array
    {
        $start = hrtime(true);
        try {
            $response = $this->gemini->generate(new GeminiRequest(
                systemInstruction: $this->prompts->load($this->promptVersion),
                contents: [['role' => 'user', 'parts' => [['text' => $transcript]]]],
                thinkingLevel: $this->thinkingLevels->for(GeminiPurpose::MemoryReview),
                responseSchema: self::schema(),
            ));
            $data = $response->json();
        } catch (GeminiException $e) {
            $this->generationLogger->record($session, GeminiPurpose::MemoryReview->value, $this->gemini->model(), $this->promptVersion, $response ?? null, (int) ((hrtime(true) - $start) / 1_000_000), $e->getMessage());
            throw $e;
        }
        $this->generationLogger->record($session, GeminiPurpose::MemoryReview->value, $this->gemini->model(), $this->promptVersion, $response, $response->durationMs);

        return array_values(array_filter($data['operations'] ?? [], 'is_array'));
    }

    /**
     * Applique chaque opération avec les contrôles du code ; une opération invalide est ignorée (et tracée).
     *
     * @param list<array<string, mixed>> $operations
     *
     * @return list<int> ids des événements créés
     */
    private function apply(ShoppingSession $session, array $operations): array
    {
        $user = $session->getUser();
        $eventIds = [];
        foreach (\array_slice($operations, 0, 20) as $op) {
            $category = MemoryCategory::tryFrom((string) ($op['category'] ?? ''));
            $value = trim((string) ($op['value'] ?? ''));
            $reason = mb_substr(trim((string) ($op['reason'] ?? '')), 0, 500) ?: null;
            if (null === $category || '' === $value) {
                continue;
            }
            try {
                $change = match ($op['action'] ?? null) {
                    'add' => $this->memory->add($user, $category, $value, MemoryOrigin::Batch, $session, $reason),
                    'update' => null === ($item = $this->memory->find($user, $category, isset($op['old_value']) ? (string) $op['old_value'] : null))
                        ? null : $this->memory->update($item, $value, MemoryOrigin::Batch, $session, $reason),
                    'delete' => null === ($item = $this->memory->find($user, $category, $value))
                        ? null : $this->memory->delete($item, MemoryOrigin::Batch, $session, $reason),
                    default => null,
                };
                if (null === $change) {
                    $this->logger->info('memory_review.operation_skipped', ['reason' => 'target_not_found', 'action' => $op['action'] ?? null, 'category' => $category->value, 'session_id' => $session->getId()]);
                    continue;
                }
                $this->em->flush();
                $eventIds[] = (int) $change->event->getId();
            } catch (MemoryException $e) {
                $this->logger->info('memory_review.operation_skipped', ['reason' => $e->errorCode, 'action' => $op['action'] ?? null, 'category' => $category->value, 'session_id' => $session->getId()]);
            }
        }

        return $eventIds;
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'operations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['add', 'update', 'delete']],
                            'category' => ['type' => 'string', 'enum' => MemoryCategory::values()],
                            'value' => ['type' => 'string'],
                            'old_value' => ['type' => 'string', 'nullable' => true],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['action', 'category', 'value', 'reason'],
                    ],
                ],
            ],
            'required' => ['operations'],
        ];
    }
}
