<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemoryEvent;
use App\Entity\UserMemory;

final class MemoryChange
{
    public function __construct(
        public readonly ?UserMemory $item,
        public readonly MemoryEvent $event,
        /** true si un nouvel élément a été créé (false : valeur remplacée / supprimée). */
        public readonly bool $created,
    ) {
    }
}
