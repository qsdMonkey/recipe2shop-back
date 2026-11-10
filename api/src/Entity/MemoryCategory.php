<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Catégories de la mémoire longue (liste fermée, ordre d'affichage de la page « Ce que l'IA sait de moi »).
 */
enum MemoryCategory: string
{
    case Foyer = 'foyer';
    case Allergie = 'allergie';
    case Intolerance = 'intolerance';
    case Regime = 'regime';
    case Aime = 'aime';
    case NaimePas = 'naime_pas';
    case Ustensile = 'ustensile';
    case Habitude = 'habitude';
    case FrequenceCourses = 'frequence_courses';
    case Contrainte = 'contrainte';
    case NiveauCuisine = 'niveau_cuisine';

    /** Une seule valeur possible : un ajout remplace la valeur existante. */
    public function isSingleValued(): bool
    {
        return \in_array($this, [self::Foyer, self::FrequenceCourses, self::NiveauCuisine], true);
    }

    /** Catégories où « aucune » a un sens (la question a été posée) et s'efface devant une vraie valeur. */
    public function acceptsNone(): bool
    {
        return \in_array($this, [self::Allergie, self::Intolerance, self::Regime], true);
    }

    /** Allergies et intolérances : contraintes absolues pour l'IA. */
    public function isHardConstraint(): bool
    {
        return \in_array($this, [self::Allergie, self::Intolerance], true);
    }

    /** Libellé utilisé dans le contexte envoyé à l'IA. */
    public function contextLabel(): string
    {
        return match ($this) {
            self::Foyer => 'Foyer',
            self::Allergie => 'Allergies',
            self::Intolerance => 'Intolérances',
            self::Regime => 'Régime',
            self::Aime => 'Aime',
            self::NaimePas => 'N\'aime pas',
            self::Ustensile => 'Ustensiles',
            self::Habitude => 'Habitudes',
            self::FrequenceCourses => 'Fréquence des courses',
            self::Contrainte => 'Contraintes (budget, temps, calories)',
            self::NiveauCuisine => 'Niveau en cuisine',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
