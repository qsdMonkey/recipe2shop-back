<?php

declare(strict_types=1);

namespace App\Gemini;

final class GeminiResponse
{
    /**
     * @param list<array<string, mixed>> $parts brutes, renvoyées telles quelles à Gemini dans la suite du tour
     *                                          (indispensable pour les « thought signatures » et ids d'appels)
     */
    public function __construct(
        public readonly array $parts,
        public readonly ?string $finishReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $thinkingTokens,
        public readonly string $model,
        public readonly int $durationMs,
    ) {
    }

    /** Texte visible (hors « pensées » du modèle). */
    public function text(): string
    {
        $texts = [];
        foreach ($this->parts as $part) {
            if (isset($part['text']) && \is_string($part['text']) && true !== ($part['thought'] ?? false)) {
                $texts[] = $part['text'];
            }
        }

        return trim(implode('', $texts));
    }

    /**
     * Réponse JSON (appel avec responseSchema).
     *
     * @return array<string, mixed>
     *
     * @throws GeminiException
     */
    public function json(): array
    {
        $text = $this->text();
        // Tolère un éventuel bloc ```json … ``` autour du JSON.
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $data = json_decode($text, true);
        if (!\is_array($data)) {
            throw new GeminiException('Gemini did not return valid JSON (finish reason: '.($this->finishReason ?? 'unknown').').');
        }

        return $data;
    }

    /** @return list<array{name: string, args: array<string, mixed>, id: string|null}> */
    public function functionCalls(): array
    {
        $calls = [];
        foreach ($this->parts as $part) {
            if (isset($part['functionCall']['name'])) {
                $calls[] = [
                    'name' => (string) $part['functionCall']['name'],
                    'args' => \is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : (array) ($part['functionCall']['args'] ?? []),
                    'id' => isset($part['functionCall']['id']) ? (string) $part['functionCall']['id'] : null,
                ];
            }
        }

        return $calls;
    }
}
