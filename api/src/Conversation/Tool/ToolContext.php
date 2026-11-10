<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\ShoppingSession;
use App\Entity\Turn;
use App\Entity\User;

final class ToolContext
{
    public function __construct(
        public readonly ShoppingSession $session,
        public readonly User $user,
        public readonly Turn $turn,
    ) {
    }
}
