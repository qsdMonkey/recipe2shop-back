<?php

declare(strict_types=1);

namespace App\Entity;

enum ShoppingItemOrigin: string
{
    /** Calculé depuis les recettes acceptées (recalculé à chaque régénération). */
    case Recipe = 'recipe';
    /** Ajouté par l'IA (add_list_items). */
    case Assistant = 'assistant';
    /** Ajouté par l'utilisateur dans l'app. */
    case Manual = 'manual';
}
