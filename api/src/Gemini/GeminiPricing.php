<?php

declare(strict_types=1);

namespace App\Gemini;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Coût d'un appel en USD, à partir des prix configurés pour le modèle (.env : prix par million de tokens).
 * Les tokens de « réflexion » sont facturés comme des tokens de sortie.
 */
final class GeminiPricing
{
    public function __construct(
        #[Autowire('%env(float:GEMINI_PRICE_INPUT_PER_MTOK)%')]
        private readonly float $inputPerMillion,
        #[Autowire('%env(float:GEMINI_PRICE_OUTPUT_PER_MTOK)%')]
        private readonly float $outputPerMillion,
    ) {
    }

    public function costUsd(int $inputTokens, int $outputTokens, int $thinkingTokens): string
    {
        $cost = ($inputTokens * $this->inputPerMillion + ($outputTokens + $thinkingTokens) * $this->outputPerMillion) / 1_000_000;

        return number_format($cost, 6, '.', '');
    }
}
