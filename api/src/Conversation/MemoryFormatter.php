<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\MemoryCategory;
use App\Entity\User;
use App\Repository\UserMemoryRepository;

/** Mémoire longue mise en forme pour les prompts (chat, génération de recette, relecture). */
final class MemoryFormatter
{
    public function __construct(private readonly UserMemoryRepository $memory)
    {
    }

    /** @return list<string> */
    public function lines(User $user, bool $withUnknown = true): array
    {
        $byCategory = [];
        foreach ($this->memory->findForUser($user) as $item) {
            $byCategory[$item->getCategory()->value][] = $item->getValue();
        }

        $lines = [];
        $unknown = [];
        foreach (MemoryCategory::cases() as $category) {
            $values = $byCategory[$category->value] ?? [];
            if ([] === $values) {
                $unknown[] = mb_strtolower($category->contextLabel());
                continue;
            }
            $label = $category->contextLabel().($category->isHardConstraint() ? ' (CONTRAINTE ABSOLUE)' : '');
            $lines[] = \sprintf('- %s [%s] : %s', $label, $category->value, '« '.implode(' », « ', $values).' »');
        }
        if ([] === $byCategory) {
            $lines[] = 'Rien pour l\'instant.';
        }
        if ($withUnknown && [] !== $unknown) {
            $lines[] = 'Pas encore connu : '.implode(', ', $unknown).'.';
        }

        return $lines;
    }
}
