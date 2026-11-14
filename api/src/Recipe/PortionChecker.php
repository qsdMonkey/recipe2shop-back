<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Ingredient\IngredientMatcher;
use App\ShoppingList\Units;

/**
 * Contrôle des quantités par portion (PortionRules) : mesure de chaque famille, erreurs explicites pour Gemini,
 * et correction déterministe des familles « base » quand l'IA n'y arrive pas.
 *
 * Travaille sur le format tableau d'une recette (servings, meals_covered, ingredients[name, quantity, unit, category,
 * optional], steps) : utilisé à la génération (RecipeGenerator) et sur les recettes existantes (app:recipes:fix-portions).
 */
final class PortionChecker
{
    /** Poids d'une boîte quand le référentiel ne le donne pas (égoutté pour les légumineuses et le thon). */
    private const CAN_WEIGHT = ['legumineuse' => 240.0, 'viande' => 140.0];
    private const DEFAULT_CAN_WEIGHT = 400.0;
    private const EGG_WEIGHT = 60.0;

    public function __construct(private readonly IngredientMatcher $matcher)
    {
    }

    public static function portions(array $data): int
    {
        return max(1, (int) $data['servings']) * max(1, (int) ($data['meals_covered'] ?? 1));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<PortionMeasure> familles présentes dans la recette (ingrédients facultatifs exclus)
     */
    public function measure(array $data): array
    {
        $portions = self::portions($data);
        $totals = $indexes = [];
        foreach ($data['ingredients'] as $i => $ingredient) {
            if (($ingredient['optional'] ?? false) || null === $ingredient['quantity'] || null === $ingredient['unit']) {
                continue;
            }
            [$family, $weight] = $this->familyOf((string) $ingredient['name']);
            if (null === $family) {
                continue;
            }
            $amount = $this->amount($family, (float) $ingredient['quantity'], (string) $ingredient['unit'], $weight);
            if (null === $amount) {
                continue; // unité non convertible (sachet…) : non mesurée
            }
            $totals[$family] = ($totals[$family] ?? 0.0) + $amount;
            $indexes[$family][] = $i;
        }

        $measures = [];
        foreach ($totals as $family => $total) {
            $measures[] = new PortionMeasure($family, $total / $portions, $portions, $indexes[$family]);
        }

        return $measures;
    }

    /**
     * Erreurs à renvoyer à Gemini, avec les chiffres.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public function errors(array $data): array
    {
        $errors = [];
        foreach ($this->measure($data) as $m) {
            if ($m->isOutOfRange()) {
                $rule = PortionRules::FAMILIES[$m->family];
                $names = array_map(static fn (int $i): string => \sprintf('%s %s %s', $data['ingredients'][$i]['name'], self::format((float) $data['ingredients'][$i]['quantity']), $data['ingredients'][$i]['unit']), $m->indexes);
                $errors[] = \sprintf(
                    'Portions : %s = %s %s par portion, attendu %s à %s %s (%d portions = servings %d × meals_covered %d ; ingrédients : %s). Ajuste ces quantités.',
                    $rule['label'],
                    self::format(round($m->perPortion, 1)),
                    $rule['unit'],
                    self::format($rule['min']),
                    self::format($rule['max']),
                    $rule['unit'],
                    $m->portions,
                    $data['servings'],
                    $data['meals_covered'] ?? 1,
                    implode(', ', $names),
                );
            }
        }

        return $errors;
    }

    /**
     * Correction déterministe : les familles « base » en excès sont ramenées au milieu de la plage (arrondi d'achat),
     * et les quantités citées dans les étapes suivent. Les familles « garniture » ne sont jamais modifiées.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: array<string, mixed>, 1: list<array{family: string, before: float, after: float}>}
     */
    public function correct(array $data): array
    {
        $changes = [];
        foreach ($this->measure($data) as $m) {
            $rule = PortionRules::FAMILIES[$m->family];
            // Seulement les excès des familles « base » : une portion trop petite est signalée à l'IA, jamais gonflée
            // par le code (thon en appoint, vermicelles…).
            if (!$rule['base'] || !$m->isTooMuch()) {
                continue;
            }
            $factor = (($rule['min'] + $rule['max']) / 2) / $m->perPortion;
            foreach ($m->indexes as $i) {
                $ingredient = $data['ingredients'][$i];
                [$quantity, $unit] = self::roundForPurchase((float) $ingredient['quantity'] * $factor, (string) $ingredient['unit']);
                $data['steps'] = self::replaceInSteps($data['steps'] ?? [], (float) $ingredient['quantity'], (string) $ingredient['unit'], $quantity, $unit);
                $data['ingredients'][$i]['quantity'] = $quantity;
                $data['ingredients'][$i]['unit'] = $unit;
            }
            $after = array_values(array_filter($this->measure($data), static fn (PortionMeasure $x): bool => $x->family === $m->family))[0]->perPortion ?? 0.0;
            $changes[] = ['family' => $m->family, 'before' => round($m->perPortion, 1), 'after' => round($after, 1)];
        }

        return [$data, $changes];
    }

    /** @return array{0: ?string, 1: ?float} famille et poids moyen d'une pièce (référentiel), d'après le nom */
    private function familyOf(string $name): array
    {
        $reference = $this->matcher->find($name);
        if (null !== $reference && !$reference->isToReview()) {
            return [$reference->getPortionFamily(), $reference->getAvgWeightPerPiece()];
        }

        return [PortionRules::familyFromName($name), $reference?->getAvgWeightPerPiece()];
    }

    /** Quantité dans l'unité de la famille (g, ml ou nombre d'œufs) ; null si non convertible. */
    private function amount(string $family, float $quantity, string $unit, ?float $pieceWeight): ?float
    {
        $familyUnit = PortionRules::FAMILIES[$family]['unit'];
        if ('œuf' === $familyUnit) {
            return match (true) {
                'piece' === $unit => $quantity,
                isset(Units::MASS[$unit]) => Units::toBase($quantity, $unit) / self::EGG_WEIGHT,
                default => null,
            };
        }
        if ('ml' === $familyUnit && isset(Units::VOLUME[$unit])) {
            return Units::toBase($quantity, $unit);
        }
        if (isset(Units::MASS[$unit])) {
            return Units::toBase($quantity, $unit);
        }
        if ('boite' === $unit) {
            return $quantity * ($pieceWeight ?? self::CAN_WEIGHT[$family] ?? self::DEFAULT_CAN_WEIGHT);
        }
        if (\in_array($unit, ['piece', 'tranche', 'gousse'], true) && null !== $pieceWeight && $pieceWeight > 0) {
            return $quantity * $pieceWeight;
        }

        return null;
    }

    /** @return array{0: float|int, 1: string} */
    public static function roundForPurchase(float $quantity, string $unit): array
    {
        if ('kg' === $unit && $quantity < 1) {
            [$quantity, $unit] = [$quantity * 1000, 'g'];
        }
        if ('l' === $unit && $quantity < 1) {
            [$quantity, $unit] = [$quantity * 100, 'cl'];
        }
        $rounded = match ($unit) {
            'g', 'ml' => $quantity >= 100 ? round($quantity / 10) * 10 : ($quantity >= 20 ? round($quantity / 5) * 5 : max(1, round($quantity))),
            'kg', 'l' => round($quantity * 20) / 20,
            'cl' => max(1, round($quantity)),
            default => max(0.5, round($quantity * 2) / 2), // pièces, boîtes, tranches…
        };

        return [floor($rounded) === $rounded ? (int) $rounded : $rounded, $unit];
    }

    /**
     * « Ajoute les 300 g de pâtes » → « Ajoute les 180 g de pâtes » (seulement pour les unités écrites telles quelles).
     *
     * @param list<string> $steps
     *
     * @return list<string>
     */
    private static function replaceInSteps(array $steps, float $before, string $beforeUnit, float|int $after, string $afterUnit): array
    {
        if (!\in_array($beforeUnit, ['g', 'kg', 'ml', 'cl', 'l'], true)) {
            return $steps;
        }
        $pattern = '/(?<![\d,.])'.preg_quote(self::format($before), '/').'\s?'.preg_quote($beforeUnit, '/').'\b/u';

        return array_map(static fn (string $step): string => (string) preg_replace($pattern, self::format((float) $after).' '.$afterUnit, $step), $steps);
    }

    public static function format(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : str_replace('.', ',', (string) round($value, 2));
    }
}
