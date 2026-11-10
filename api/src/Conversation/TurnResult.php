<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\Message;
use App\Entity\ShoppingSession;

final class TurnResult
{
    /** @param list<Message> $messages */
    public function __construct(
        public readonly array $messages,
        public readonly ShoppingSession $session,
    ) {
    }
}
