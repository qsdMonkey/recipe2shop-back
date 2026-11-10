<?php

declare(strict_types=1);

namespace App\Recipe;

/**
 * Listes fermées des recettes (validées dans le contrat API). L'app a les libellés correspondants.
 */
final class RecipeVocabulary
{
    public const UNITS = ['g', 'kg', 'ml', 'cl', 'l', 'piece', 'cas', 'cac', 'pincee', 'gousse', 'botte', 'tranche', 'boite', 'sachet'];
    public const CATEGORIES = ['fruits_legumes', 'boucherie_poissonnerie', 'cremerie', 'boulangerie', 'epicerie', 'epicerie_sucree', 'surgeles', 'boissons', 'hygiene_entretien', 'autre'];
    public const MEAL_TAGS = ['batch_cooking', 'gamelle', 'one_pot', 'rapide', 'congelable'];
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /** Ustensiles de base : jamais signalés comme « absents du profil ». */
    public const BASIC_EQUIPMENT = [
        'poele', 'casserole', 'couteau', 'planche', 'planche a decouper', 'saladier', 'cuillere', 'cuillere en bois',
        'spatule', 'fouet', 'passoire', 'egouttoir', 'plaque', 'plaque de cuisson', 'feux', 'plaques de cuisson',
        'bol', 'assiette', 'fourchette', 'rape', 'econome', 'louche', 'ecumoire', 'film alimentaire', 'papier cuisson',
        'boite hermetique', 'boites hermetiques', 'tupperware',
    ];
}
