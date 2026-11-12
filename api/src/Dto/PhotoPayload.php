<?php

declare(strict_types=1);

namespace App\Dto;

/** Photo jointe à un message : {"mime": "image/jpeg", "data": "<base64>"}. Validée par App\Conversation\Photo. */
final class PhotoPayload
{
    public function __construct(
        public readonly string $mime = '',
        public readonly string $data = '',
    ) {
    }
}
