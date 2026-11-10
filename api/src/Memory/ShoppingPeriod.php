<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemoryCategory;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Repository\UserMemoryRepository;

/**
 * Durée d'une session de courses déduite de la fréquence des courses en mémoire (fiche 02) :
 * « toutes les semaines » → 7 j, « toutes les 2 semaines » / « tous les 15 jours » → 14 j, « une fois par mois » → 30 j.
 */
final class ShoppingPeriod
{
    public function __construct(private readonly UserMemoryRepository $memory)
    {
    }

    public function forUser(User $user): int
    {
        $value = $this->memory->findInCategory($user, MemoryCategory::FrequenceCourses)[0] ?? null;

        return null === $value ? ShoppingSession::DEFAULT_PERIOD_DAYS : self::fromText($value->getValue());
    }

    public static function fromText(string $frequency): int
    {
        $text = MemoryManager::normalize($frequency);

        return match (true) {
            (bool) preg_match('/\b(mois|mensuel|mensuelle|4 semaines|30 jours)\b/u', $text) => 30,
            (bool) preg_match('/\b(3 semaines|21 jours|trois semaines)\b/u', $text) => 21,
            (bool) preg_match('/\b(2 semaines|deux semaines|15 jours|quinze jours|quinzaine|14 jours)\b/u', $text) => 14,
            (bool) preg_match('/\b(2 fois par semaine|deux fois par semaine)\b/u', $text) => 4,
            default => ShoppingSession::DEFAULT_PERIOD_DAYS,
        };
    }
}
