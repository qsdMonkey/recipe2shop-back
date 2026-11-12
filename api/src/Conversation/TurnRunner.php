<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\Message;
use App\Entity\MessageRole;
use App\Entity\ShoppingSession;
use App\Entity\Turn;
use App\Entity\TurnStatus;
use App\Gemini\GeminiException;
use App\Http\ApiException;
use App\Prompt\PromptLoader;
use App\Repository\MemoryEventRepository;
use App\Repository\MessageRepository;
use App\Repository\TurnRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Exécute un tour de conversation de bout en bout :
 * - idempotence par client_id (tour terminé → même réponse, tour en cours → 409) ;
 * - un seul tour à la fois par session (verrou SQL, abandonné après STALE_AFTER_SECONDS) ;
 * - tour atomique : message utilisateur + réponse + effets des outils enregistrés ensemble, ou rien (503) ;
 * - traces Gemini (generation_log) enregistrées dans tous les cas.
 */
final class TurnRunner
{
    public const STALE_AFTER_SECONDS = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationOrchestrator $orchestrator,
        private readonly TurnRepository $turns,
        private readonly MessageRepository $messages,
        private readonly MemoryEventRepository $memoryEvents,
        private readonly GenerationLogger $generationLogger,
        private readonly AiRateLimiter $rateLimiter,
        private readonly PromptLoader $prompts,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Message d'accueil de l'IA, à la création d'une session. Sans outils.
     * Si Gemini échoue, on enregistre un accueil statique : la session reste utilisable.
     */
    public function greet(ShoppingSession $session): Message
    {
        $turn = new Turn($session, Turn::KIND_GREETING);
        $this->em->persist($turn);

        try {
            $outcome = $this->orchestrator->run($session, $turn, null, allowTools: false);
            $message = new Message($session, $turn, MessageRole::Assistant, $outcome->content, $outcome->blocks, $outcome->toolCalls ?: null);
        } catch (GeminiException $e) {
            $this->logger->warning('conversation.greeting_fallback', ['session_id' => $session->getId(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $text = $this->prompts->load('greeting_fallback_'.$session->getType()->value);
            $message = new Message($session, $turn, MessageRole::Assistant, $text, [['type' => 'text', 'text' => $text]]);
        }

        $this->em->persist($message);
        $turn->complete();
        $session->touch($message->getCreatedAt());
        $this->em->flush();
        $this->generationLogger->flush();

        return $message;
    }

    public function send(ShoppingSession $session, UserInput $input, ?string $clientId): TurnResult
    {
        if (!$session->isActive()) {
            throw new ApiException(409, 'session_archived', 'This session is archived (read-only).');
        }

        if (null !== $clientId && null !== $existing = $this->turns->findByClientId($session, $clientId)) {
            if (TurnStatus::Completed === $existing->getStatus()) {
                return new TurnResult($this->messages->findForTurn($existing), $session);
            }
            if (!$this->isStale($existing->getStartedAt())) {
                throw self::turnInProgress();
            }
            // Tour abandonné (processus tué…) : on libère le client_id.
            $this->em->getConnection()->delete('conversation_turn', ['id' => $existing->getId()]);
            $this->em->detach($existing);
        }

        $this->rateLimiter->consume($session->getUser());
        $this->acquireLock($session);

        $connection = $this->em->getConnection();
        $turnId = null;
        try {
            // Le tour « en cours » est visible tout de suite (hors transaction) pour l'idempotence.
            $turn = new Turn($session, $input->kind, $clientId);
            $this->em->persist($turn);
            $this->em->flush();
            $turnId = $turn->getId();

            $connection->beginTransaction();
            try {
                if (null !== $input->inTransaction) {
                    ($input->inTransaction)();
                    $this->em->flush(); // visible par le ContextBuilder (requêtes SQL) avant l'appel à l'IA
                }
                $userMessage = new Message($session, $turn, MessageRole::User, $input->content, $input->blocks);
                $this->em->persist($userMessage);

                $outcome = $this->orchestrator->run($session, $turn, $input->content, photo: $input->photo);

                $assistantMessage = new Message($session, $turn, MessageRole::Assistant, $outcome->content, $outcome->blocks, $outcome->toolCalls ?: null);
                $this->em->persist($assistantMessage);
                $turn->complete();
                $session->touch($assistantMessage->getCreatedAt());
                $this->em->flush();
                $this->memoryEvents->attachToMessage(self::memoryEventIds($outcome->blocks), $assistantMessage);
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->em->clear();
            if (null !== $turnId) {
                $connection->delete('conversation_turn', ['id' => $turnId]);
            }
            $this->releaseLock($session);
            $this->generationLogger->flush();

            if ($e instanceof GeminiException) {
                $this->logger->error('conversation.turn_failed', ['session_id' => $session->getId(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
                throw new ApiException(503, 'ai_unavailable', 'The assistant is unavailable, please retry.', [], $e);
            }
            throw $e;
        }

        $this->releaseLock($session);
        $this->generationLogger->flush();

        return new TurnResult([$userMessage, $assistantMessage], $session);
    }

    /**
     * Modification de session hors IA (ex. ajout direct d'un favori) : même verrou qu'un tour, dans une transaction,
     * sans appel Gemini ni rate limit.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function runLocked(ShoppingSession $session, callable $operation): mixed
    {
        if (!$session->isActive()) {
            throw new ApiException(409, 'session_archived', 'This session is archived (read-only).');
        }
        $this->acquireLock($session);
        try {
            return $this->em->wrapInTransaction(static fn () => $operation());
        } catch (\Throwable $e) {
            $this->em->clear();
            throw $e;
        } finally {
            $this->releaseLock($session);
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     *
     * @return list<int>
     */
    private static function memoryEventIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if ('memory' === $block['type'] && isset($block['event_id'])) {
                $ids[] = (int) $block['event_id'];
            }
        }

        return $ids;
    }

    private function acquireLock(ShoppingSession $session): void
    {
        $now = new \DateTimeImmutable();
        $acquired = $this->em->getConnection()->executeStatement(
            'UPDATE shopping_session SET turn_locked_at = :now WHERE id = :id AND (turn_locked_at IS NULL OR turn_locked_at < :staleBefore)',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $session->getId(),
                'staleBefore' => $now->modify(\sprintf('-%d seconds', self::STALE_AFTER_SECONDS))->format('Y-m-d H:i:s'),
            ],
        );

        if (1 !== $acquired) {
            throw self::turnInProgress();
        }
    }

    private function releaseLock(ShoppingSession $session): void
    {
        $this->em->getConnection()->executeStatement('UPDATE shopping_session SET turn_locked_at = NULL WHERE id = :id', ['id' => $session->getId()]);
    }

    private function isStale(\DateTimeImmutable $startedAt): bool
    {
        return $startedAt < new \DateTimeImmutable(\sprintf('-%d seconds', self::STALE_AFTER_SECONDS));
    }

    private static function turnInProgress(): ApiException
    {
        return new ApiException(409, 'turn_in_progress', 'A turn is already in progress for this session.');
    }
}
