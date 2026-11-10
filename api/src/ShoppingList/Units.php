<?php

declare(strict_types=1);

namespace App\ShoppingList;

/**
 * Conversions d'unités (« le code calcule ») : masse en g, volume en ml, pièces ; les autres unités
 * (pincée, gousse, botte, tranche, boîte, sachet) restent chacune dans leur propre dimension.
 */
final class Units
{
    public const MASS = ['g' => 1.0, 'kg' => 1000.0];
    public const VOLUME = ['ml' => 1.0, 'cl' => 10.0, 'l' => 1000.0, 'cas' => 15.0, 'cac' => 5.0];

    /** Dimension d'une unité : mass, volume, ou l'unité elle-même (piece, gousse…). */
    public static function dimension(string $unit): string
    {
        return match (true) {
            isset(self::MASS[$unit]) => 'mass',
            isset(self::VOLUME[$unit]) => 'volume',
            default => $unit,
        };
    }

    /** Quantité dans l'unité de base de sa dimension (g, ml, ou l'unité elle-même). */
    public static function toBase(float $quantity, string $unit): float
    {
        return $quantity * (self::MASS[$unit] ?? self::VOLUME[$unit] ?? 1.0);
    }

    /**
     * Valeur de base → quantité lisible pour les courses (arrondie à l'achat : toujours vers le haut).
     *
     * @param list<string> $sourceUnits unités d'origine (pour garder c. à s. / c. à c. sur les petites quantités)
     *
     * @return array{0: float, 1: string}
     */
    public static function forShopping(string $dimension, float $value, array $sourceUnits = []): array
    {
        if ('mass' === $dimension) {
            return $value >= 1000 ? [self::ceilTo($value / 1000, 0.05), 'kg'] : [self::ceilTo($value, $value >= 100 ? 5 : 1), 'g'];
        }
        if ('volume' === $dimension) {
            $spoonsOnly = [] !== $sourceUnits && [] === array_diff($sourceUnits, ['cas', 'cac']);
            if ($spoonsOnly && $value <= 75) {
                return 0.0 === fmod(round($value, 6), 15.0) ? [$value / 15, 'cas'] : [ceil($value / 5), 'cac'];
            }

            return match (true) {
                $value >= 1000 => [self::ceilTo($value / 1000, 0.05), 'l'],
                $value >= 50 => [ceil($value / 10), 'cl'],
                default => [ceil($value), 'ml'],
            };
        }

        return [max(1.0, ceil(round($value, 6))), $dimension]; // pièces, gousses, bottes… : entier supérieur
    }

    private static function ceilTo(float $value, float $step): float
    {
        return round(ceil(round($value / $step, 6)) * $step, 2);
    }
}
