<?php

declare(strict_types=1);

namespace App\Gemini;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Niveau de « réflexion » Gemini par type d'appel (.env : GEMINI_THINKING_LEVEL_*). Vide = défaut du modèle.
 */
final class ThinkingLevels
{
    public function __construct(
        #[Autowire('%env(GEMINI_THINKING_LEVEL_CHAT)%')]
        private readonly string $chat,
        #[Autowire('%env(GEMINI_THINKING_LEVEL_PROPOSE_RECIPE)%')]
        private readonly string $proposeRecipe,
        #[Autowire('%env(GEMINI_THINKING_LEVEL_MEMORY_REVIEW)%')]
        private readonly string $memoryReview,
    ) {
    }

    public function for(GeminiPurpose $purpose): ?string
    {
        $level = match ($purpose) {
            GeminiPurpose::Chat => $this->chat,
            GeminiPurpose::ProposeRecipe => $this->proposeRecipe,
            GeminiPurpose::MemoryReview => $this->memoryReview,
        };

        return '' === trim($level) ? null : trim($level);
    }
}
