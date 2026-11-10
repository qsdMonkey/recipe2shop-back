<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\Turn;

/**
 * Ce que l'utilisateur envoie dans un tour : un message texte, ou une action (bouton ✅ / 🔄).
 */
final class UserInput
{
    /**
     * @param string                          $content       texte envoyé à Gemini (historique)
     * @param list<array<string, mixed>>      $blocks        blocs affichés dans la bulle de l'utilisateur
     * @param (\Closure(): void)|null          $inTransaction effet à appliquer dans la transaction du tour, avant l'appel à l'IA
     *                                                       (ex. passer la recette en accepted ; peut lever une ApiException)
     */
    public function __construct(
        public readonly string $content,
        public readonly array $blocks,
        public readonly string $kind = Turn::KIND_MESSAGE,
        public readonly ?\Closure $inTransaction = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text, [['type' => 'text', 'text' => $text]]);
    }
}
