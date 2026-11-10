<?php

declare(strict_types=1);

namespace App\Conversation;

final class ConversationContext
{
    /**
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents
     */
    public function __construct(
        public readonly string $systemInstruction,
        public readonly array $contents,
        public readonly string $promptVersion,
    ) {
    }
}
