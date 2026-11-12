<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Memory\MemoryManager;

/**
 * Règle des poids (demande de Pierre, 2026-11-12) : on n'achète pas « 3 carottes » mais « 300 g de carottes ».
 * L'unité « piece » est refusée dans les rayons où le poids compte, sauf pour ce qui n'a pas de poids pertinent
 * (œufs, feuille de laurier, cube de bouillon, gousse de vanille).
 */
final class WeightRule
{
    public const PIECE_FORBIDDEN_CATEGORIES = ['fruits_legumes', 'boucherie_poissonnerie', 'cremerie', 'epicerie'];

    /** Mots (normalisés) qui autorisent « piece » dans ces rayons. */
    private const PIECE_EXCEPTIONS = ['oeuf', 'oeufs', 'laurier', 'bouillon', 'vanille'];

    public static function pieceAllowed(string $name, string $category): bool
    {
        if (!\in_array($category, self::PIECE_FORBIDDEN_CATEGORIES, true)) {
            return true;
        }
        $words = preg_split('/[^a-z0-9]+/', MemoryManager::normalize($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return [] !== array_intersect($words, self::PIECE_EXCEPTIONS);
    }
}
