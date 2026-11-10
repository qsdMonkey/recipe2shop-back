<?php

declare(strict_types=1);

namespace App\Message;

/** Relecture mémoire de fin de session (asynchrone, transport « async »). */
final class MemoryReviewMessage
{
    public function __construct(public readonly int $sessionId)
    {
    }
}
