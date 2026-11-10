<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\MemoryAction;
use App\Entity\MemoryCategory;
use App\Entity\MemoryEvent;
use App\Entity\UserMemory;

final class MemoryPresenter
{
    /** @return array<string, mixed> MemoryItem */
    public function item(UserMemory $item): array
    {
        return [
            'id' => $item->getId(),
            'category' => $item->getCategory()->value,
            'value' => $item->getValue(),
            'created_at' => $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $item->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> MemoryEvent (état actuel : undone) */
    public function event(MemoryEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'action' => $event->getAction()->value,
            'category' => $event->getCategory()->value,
            'value' => MemoryAction::Delete === $event->getAction() ? null : $event->getNewValue(),
            'old_value' => $event->getOldValue(),
            'origin' => $event->getOrigin()->value,
            'undone' => $event->isUndone(),
        ];
    }

    /**
     * Les 11 catégories, dans l'ordre d'affichage, y compris les vides.
     *
     * @param list<UserMemory> $items
     *
     * @return list<array{category: string, items: list<array<string, mixed>>}>
     */
    public function grouped(array $items): array
    {
        $groups = [];
        foreach (MemoryCategory::cases() as $category) {
            $groups[$category->value] = ['category' => $category->value, 'items' => []];
        }
        foreach ($items as $item) {
            $groups[$item->getCategory()->value]['items'][] = $this->item($item);
        }

        return array_values($groups);
    }
}
