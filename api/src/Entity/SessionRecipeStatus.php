<?php

declare(strict_types=1);

namespace App\Entity;

enum SessionRecipeStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
