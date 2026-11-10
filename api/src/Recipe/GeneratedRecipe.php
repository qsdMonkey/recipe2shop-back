<?php

declare(strict_types=1);

namespace App\Recipe;

final class GeneratedRecipe
{
    /**
     * @param array<string, mixed> $data     recette validée et normalisée (format de la fiche 03)
     * @param list<string>         $warnings avertissements affichés sur la carte
     */
    public function __construct(
        public readonly array $data,
        public readonly array $warnings,
        public readonly int $attempts,
    ) {
    }
}
