<?php

declare(strict_types=1);

namespace App\Memory;

use App\Entity\MemoryCategory;
use App\Entity\User;
use App\Repository\UserMemoryRepository;

/**
 * Minimum pour terminer l'onboarding (fiche 01) : foyer, fréquence des courses, et régime ou allergies (même « aucune »).
 */
final class OnboardingChecklist
{
    public function __construct(private readonly UserMemoryRepository $items)
    {
    }

    /** @return list<string> ce qui manque, en clair (vide = minimum atteint) */
    public function missing(User $user): array
    {
        $known = [];
        foreach ($this->items->findForUser($user) as $item) {
            $known[$item->getCategory()->value] = true;
        }

        $missing = [];
        if (!isset($known[MemoryCategory::Foyer->value])) {
            $missing[] = 'le foyer';
        }
        if (!isset($known[MemoryCategory::FrequenceCourses->value])) {
            $missing[] = 'la fréquence des courses';
        }
        if (!isset($known[MemoryCategory::Regime->value]) && !isset($known[MemoryCategory::Allergie->value]) && !isset($known[MemoryCategory::Intolerance->value])) {
            $missing[] = 'le régime ou les allergies (même « aucune »)';
        }

        return $missing;
    }
}
