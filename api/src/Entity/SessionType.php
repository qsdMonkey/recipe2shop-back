<?php

declare(strict_types=1);

namespace App\Entity;

enum SessionType: string
{
    case Onboarding = 'onboarding';
    case Shopping = 'shopping';
}
