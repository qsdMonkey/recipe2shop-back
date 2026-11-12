<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\Turn;

/**
 * Ce que l'utilisateur envoie dans un tour : un message texte (avec une photo éventuelle), ou une action (bouton ✅ / 🔄).
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
        /** photo envoyée à Gemini avec ce message, à ce tour seulement (jamais stockée) */
        public readonly ?Photo $photo = null,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text, [['type' => 'text', 'text' => $text]]);
    }

    /**
     * Message avec photo : bloc photo (référence au fichier gardé sur le téléphone, par client_id) puis texte éventuel.
     * L'historique Gemini étant en texte seul, le contenu signale la photo pour les tours suivants.
     */
    public static function withPhoto(string $text, Photo $photo, string $clientId): self
    {
        $blocks = [['type' => 'photo', 'client_id' => $clientId]];
        if ('' !== $text) {
            $blocks[] = ['type' => 'text', 'text' => $text];
        }

        return new self('' === $text ? '[Photo envoyée]' : '[Photo envoyée] '.$text, $blocks, photo: $photo);
    }
}
