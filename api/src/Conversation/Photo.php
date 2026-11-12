<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Dto\PhotoPayload;
use App\Http\ApiException;

/**
 * Photo jointe à un message, validée. Elle n'est jamais écrite sur disque ni en base : seulement transmise à Gemini
 * (inline_data) au tour où elle est postée. Le base64 ne doit jamais apparaître dans les logs (SensitiveDataProcessor).
 */
final class Photo
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Signatures des formats acceptés (le mime annoncé doit correspondre au contenu). */
    private const MAGIC = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1A\n"],
        'image/webp' => ['RIFF'],
    ];

    private function __construct(
        public readonly string $mime,
        public readonly string $base64,
    ) {
    }

    /** @throws ApiException 422 invalid_photo / photo_too_large */
    public static function fromPayload(PhotoPayload $payload): self
    {
        $mime = strtolower(trim($payload->mime));
        if (!isset(self::MAGIC[$mime])) {
            throw self::invalid('mime must be image/jpeg, image/png or image/webp.');
        }
        $base64 = (string) preg_replace('/\s+/', '', $payload->data);
        // Taille estimée avant décodage : pas de gros décodage inutile.
        if ((int) floor(\strlen($base64) * 3 / 4) - substr_count(substr($base64, -2), '=') > self::MAX_BYTES) {
            throw new ApiException(422, 'photo_too_large', 'The photo must not exceed 2 MB.');
        }
        $bytes = '' === $base64 ? false : base64_decode($base64, true);
        if (false === $bytes || '' === $bytes) {
            throw self::invalid('data must be valid base64.');
        }
        if (\strlen($bytes) > self::MAX_BYTES) {
            throw new ApiException(422, 'photo_too_large', 'The photo must not exceed 2 MB.');
        }
        $matches = false;
        foreach (self::MAGIC[$mime] as $magic) {
            $matches = $matches || str_starts_with($bytes, $magic);
        }
        if ('image/webp' === $mime) {
            $matches = $matches && 'WEBP' === substr($bytes, 8, 4);
        }
        if (!$matches) {
            throw self::invalid('data is not a '.$mime.' image.');
        }

        return new self($mime, base64_encode($bytes));
    }

    /** @return array<string, mixed> part Gemini */
    public function geminiPart(): array
    {
        return ['inlineData' => ['mimeType' => $this->mime, 'data' => $this->base64]];
    }

    private static function invalid(string $message): ApiException
    {
        return new ApiException(422, 'invalid_photo', 'Invalid photo: '.$message);
    }
}
