<?php

declare(strict_types=1);

namespace App\Entity;

enum TurnStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
