<?php

declare(strict_types=1);

namespace App\Entity;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    /** Information affichée discrètement (ex. puces de la relecture mémoire). Jamais envoyée à Gemini. */
    case System = 'system';
}
