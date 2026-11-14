<?php

declare(strict_types=1);

namespace App\Recipe;

/** Quantité d'une famille de portion dans une recette, rapportée à une portion. */
final class PortionMeasure
{
    /** @param list<int> $indexes positions des ingrédients de la famille dans la recette */
    public function __construct(
        public readonly string $family,
        public readonly float $perPortion,
        public readonly int $portions,
        public readonly array $indexes,
    ) {
    }

    public function isTooMuch(): bool
    {
        return $this->perPortion > PortionRules::FAMILIES[$this->family]['max'] * PortionRules::HIGH_TOLERANCE;
    }

    public function isOutOfRange(): bool
    {
        $rule = PortionRules::FAMILIES[$this->family];
        if ($this->isTooMuch()) {
            return true;
        }

        // Trop peu : seulement pour les familles « base » utilisées comme base du plat (pas un simple appoint).
        return $rule['base']
            && $this->perPortion < $rule['min'] * PortionRules::LOW_TOLERANCE
            && $this->perPortion >= $rule['min'] * PortionRules::GARNISH_RATIO;
    }
}
