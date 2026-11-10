<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

final class ToolResult
{
    /**
     * @param array<string, mixed>       $response renvoyé à Gemini (functionResponse)
     * @param list<array<string, mixed>> $blocks   blocs ajoutés au message de l'IA (cartes), dans l'ordre
     */
    private function __construct(
        public readonly array $response,
        public readonly array $blocks,
        public readonly bool $success,
    ) {
    }

    /**
     * @param array<string, mixed>       $response
     * @param list<array<string, mixed>> $blocks
     */
    public static function ok(array $response = ['status' => 'ok'], array $blocks = []): self
    {
        return new self($response, $blocks, true);
    }

    /**
     * Erreur renvoyée à Gemini pour qu'il se corrige (ex. JSON de recette invalide).
     *
     * @param list<string> $details
     */
    public static function error(string $message, array $details = []): self
    {
        return new self(['status' => 'error', 'error' => $message] + ([] === $details ? [] : ['details' => $details]), [], false);
    }
}
