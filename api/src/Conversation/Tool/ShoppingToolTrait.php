<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\SessionType;
use App\Entity\ShoppingSession;

/** Outils réservés aux sessions de courses actives. */
trait ShoppingToolTrait
{
    public function supports(ShoppingSession $session): bool
    {
        return SessionType::Shopping === $session->getType() && $session->isActive();
    }
}
