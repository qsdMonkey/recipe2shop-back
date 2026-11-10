<?php

declare(strict_types=1);

namespace App\Entity;

enum ShoppingListType: string
{
    case Main = 'main';
    case TopUp = 'top_up';
    case Event = 'event';
}
