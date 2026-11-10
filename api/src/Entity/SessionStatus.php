<?php

declare(strict_types=1);

namespace App\Entity;

enum SessionStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
