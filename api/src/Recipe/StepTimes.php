<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Memory\MemoryManager;

/**
 * Cohérence des temps : la somme des durées citées dans les étapes ≈ prep_time_min + cook_time_min (± 30 %, ± 5 min).
 * Une étape faite en parallèle (« pendant ce temps… ») ne compte pas. Une fourchette (« 8 à 10 min ») compte pour son milieu.
 */
final class StepTimes
{
    public const TOLERANCE = 0.3;
    public const SLACK_MINUTES = 5;
    /** En dessous de 2 étapes chronométrées, pas de contrôle (rien de fiable à comparer). */
    public const MIN_TIMED_STEPS = 2;

    private const PARALLEL = '/\b(pendant ce temps|pendant la cuisson|pendant que|en meme temps|en parallele)\b/';
    private const DURATION = '/(\d+(?:[.,]\d+)?)\s*(?:(?:a|-)\s*(\d+(?:[.,]\d+)?)\s*)?(h|heures?|min|minutes?|mn)\b(?:\s*(\d{1,2})\b(?!\s*(?:°|min|g|kg|ml|cl)))?/';

    /**
     * @param list<string> $steps
     *
     * @return array{minutes: int, timed_steps: int}
     */
    public static function cited(array $steps): array
    {
        $total = 0.0;
        $timed = 0;
        foreach ($steps as $step) {
            $text = MemoryManager::normalize($step);
            if (1 === preg_match(self::PARALLEL, $text) || !preg_match_all(self::DURATION, $text, $matches, \PREG_SET_ORDER)) {
                continue;
            }
            ++$timed;
            foreach ($matches as $m) {
                $low = (float) str_replace(',', '.', $m[1]);
                $high = '' !== ($m[2] ?? '') ? (float) str_replace(',', '.', $m[2]) : $low;
                $value = ($low + $high) / 2;
                if (str_starts_with($m[3], 'h')) {
                    $value = $value * 60 + (int) ($m[4] ?? 0); // « 1 h 30 »
                }
                $total += $value;
            }
        }

        return ['minutes' => (int) round($total), 'timed_steps' => $timed];
    }

    /** @param array<string, mixed> $data */
    public static function error(array $data): ?string
    {
        $cited = self::cited($data['steps'] ?? []);
        $announced = (int) $data['prep_time_min'] + (int) $data['cook_time_min'];
        if ($cited['timed_steps'] < self::MIN_TIMED_STEPS || self::isConsistent($cited['minutes'], $announced)) {
            return null;
        }

        return \sprintf(
            'Temps : les étapes citent environ %d min au total, mais prep_time_min + cook_time_min = %d min. Donne des temps réalistes et cohérents : une durée par étape (repos et marinade compris dans prep_time_min ; une étape faite en parallèle commence par « Pendant ce temps »). Ne raccourcis pas les cuissons pour tenir une contrainte de temps : propose plutôt un autre plat.',
            $cited['minutes'],
            $announced,
        );
    }

    /**
     * Correction déterministe : prep et cook mis à l'échelle de la durée des étapes (source la plus concrète).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function correct(array $data): array
    {
        $cited = self::cited($data['steps'] ?? [])['minutes'];
        $announced = (int) $data['prep_time_min'] + (int) $data['cook_time_min'];
        if (0 === $announced) {
            $data['cook_time_min'] = $cited;

            return $data;
        }
        $factor = $cited / $announced;
        $data['prep_time_min'] = (int) round($data['prep_time_min'] * $factor);
        $data['cook_time_min'] = max(0, $cited - $data['prep_time_min']); // total exact

        return $data;
    }

    private static function isConsistent(int $cited, int $announced): bool
    {
        return $cited <= $announced * (1 + self::TOLERANCE) + self::SLACK_MINUTES
            && $cited >= $announced * (1 - self::TOLERANCE) - self::SLACK_MINUTES;
    }
}
