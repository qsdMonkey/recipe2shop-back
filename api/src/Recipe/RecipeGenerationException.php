<?php

declare(strict_types=1);

namespace App\Recipe;

/** La recette générée reste invalide après les essais : l'erreur est renvoyée à l'IA du chat. */
final class RecipeGenerationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid recipe: '.implode(' | ', $errors));
    }
}
