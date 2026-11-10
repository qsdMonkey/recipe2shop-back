<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\GenerationLog;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Gemini\GeminiPricing;
use App\Gemini\GeminiResponse;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Collecte les traces d'appels Gemini pendant un tour et les enregistre APRÈS la transaction du tour :
 * un tour en échec (annulé) garde ainsi la trace de ce qu'il a coûté.
 */
final class GenerationLogger
{
    /** @var list<array<string, mixed>> */
    private array $pending = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GeminiPricing $pricing,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function record(ShoppingSession $session, string $purpose, string $model, string $promptVersion, ?GeminiResponse $response, int $durationMs, ?string $error = null): void
    {
        $this->pending[] = [
            'user_id' => $session->getUser()->getId(),
            'session_id' => $session->getId(),
            'purpose' => $purpose,
            'model' => $response?->model ?? $model,
            'prompt_version' => $promptVersion,
            'input' => $response?->inputTokens ?? 0,
            'output' => $response?->outputTokens ?? 0,
            'thinking' => $response?->thinkingTokens ?? 0,
            'duration_ms' => $response?->durationMs ?? $durationMs,
            'error' => $error,
        ];
    }

    public function flush(): void
    {
        if ([] === $this->pending) {
            return;
        }

        foreach ($this->pending as $log) {
            $cost = $this->pricing->costUsd($log['input'], $log['output'], $log['thinking']);
            $context = [
                'purpose' => $log['purpose'], 'model' => $log['model'], 'prompt_version' => $log['prompt_version'],
                'session_id' => $log['session_id'], 'user_id' => $log['user_id'], 'duration_ms' => $log['duration_ms'],
                'input_tokens' => $log['input'], 'output_tokens' => $log['output'] + $log['thinking'], 'cost_usd' => (float) $cost,
            ];
            null === $log['error']
                ? $this->logger?->info('gemini.call', $context)
                : $this->logger?->warning('gemini.error', $context + ['error' => mb_substr($log['error'], 0, 300)]);

            $this->em->persist(new GenerationLog(
                user: null === $log['user_id'] ? null : $this->em->getReference(User::class, $log['user_id']),
                session: null === $log['session_id'] ? null : $this->em->getReference(ShoppingSession::class, $log['session_id']),
                purpose: $log['purpose'],
                model: mb_substr($log['model'], 0, 100),
                promptVersion: $log['prompt_version'],
                inputTokens: $log['input'],
                outputTokens: $log['output'],
                thinkingTokens: $log['thinking'],
                costUsd: $cost,
                durationMs: $log['duration_ms'],
                success: null === $log['error'],
                error: $log['error'],
            ));
        }
        $this->pending = [];
        $this->em->flush();
    }
}
