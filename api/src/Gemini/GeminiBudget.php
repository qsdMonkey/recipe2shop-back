<?php

declare(strict_types=1);

namespace App\Gemini;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Plafond GLOBAL de dépense Gemini par jour (UTC), tous utilisateurs confondus, calculé depuis generation_log.
 * Protection contre l'abus des coûts d'une API publique : au-delà, plus aucun appel (→ 503 ai_unavailable).
 */
final class GeminiBudget
{
    private ?float $cachedSpent = null;
    private int $cachedAt = 0;

    public function __construct(
        private readonly Connection $db,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(float:GEMINI_DAILY_BUDGET_USD)%')]
        private readonly float $dailyBudgetUsd,
    ) {
    }

    /** @throws GeminiException si le plafond du jour est atteint */
    public function assertAvailable(): void
    {
        if ($this->dailyBudgetUsd <= 0) {
            return;
        }
        $spent = $this->spentToday();
        if ($spent >= $this->dailyBudgetUsd) {
            $this->logger->warning('gemini.daily_budget_exceeded', ['spent_usd' => round($spent, 4), 'budget_usd' => $this->dailyBudgetUsd]);
            throw new GeminiException(\sprintf('Daily Gemini budget reached (%.2f / %.2f USD).', $spent, $this->dailyBudgetUsd));
        }
    }

    public function spentToday(): float
    {
        // Petit cache (5 s) : un tour fait plusieurs appels d'affilée.
        if (null !== $this->cachedSpent && time() - $this->cachedAt < 5) {
            return $this->cachedSpent;
        }
        $this->cachedSpent = (float) $this->db->fetchOne(
            'SELECT COALESCE(SUM(cost_usd), 0) FROM generation_log WHERE created_at >= ?',
            [(new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
        );
        $this->cachedAt = time();

        return $this->cachedSpent;
    }
}
