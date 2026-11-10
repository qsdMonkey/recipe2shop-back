<?php

declare(strict_types=1);

namespace App\Gemini;

final class GeminiRequest
{
    /**
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents             format Gemini (role user|model)
     * @param list<array<string, mixed>>                                    $functionDeclarations
     * @param bool                                                          $allowToolCalls       false = l'IA doit répondre en texte (dernier tour de boucle)
     * @param string|null                                                   $thinkingLevel        low, medium, high… (null = défaut du modèle)
     * @param array<string, mixed>|null                                     $responseSchema       réponse JSON imposée (responseSchema Gemini)
     * @param float|null                                                    $maxDurationSeconds   durée max de l'appel, sans nouvel essai (null = réglages par défaut du client)
     */
    public function __construct(
        public readonly string $systemInstruction,
        public readonly array $contents,
        public readonly array $functionDeclarations = [],
        public readonly bool $allowToolCalls = true,
        public readonly ?string $thinkingLevel = null,
        public readonly ?array $responseSchema = null,
        public readonly ?float $maxDurationSeconds = null,
    ) {
    }
}
