<?php

declare(strict_types=1);

namespace App\Conversation;

final class TurnOutcome
{
    /**
     * @param list<array<string, mixed>> $blocks    blocs du message de l'IA
     * @param string                     $content   texte brut (historique Gemini)
     * @param list<array<string, mixed>> $toolCalls trace des appels d'outils
     */
    public function __construct(
        public readonly array $blocks,
        public readonly string $content,
        public readonly array $toolCalls,
    ) {
    }
}
