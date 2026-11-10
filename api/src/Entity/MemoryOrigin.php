<?php

declare(strict_types=1);

namespace App\Entity;

enum MemoryOrigin: string
{
    /** L'IA, pendant la conversation (outil update_memory). */
    case Live = 'live';
    /** Relecture de fin de session (étape 5). */
    case Batch = 'batch';
    /** L'utilisateur, depuis la page « Ce que l'IA sait de moi ». */
    case User = 'user';
}
