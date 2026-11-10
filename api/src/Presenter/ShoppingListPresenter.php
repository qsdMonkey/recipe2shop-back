<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ShoppingList;
use App\Entity\ShoppingListItem;
use App\ShoppingList\ShoppingListService;

final class ShoppingListPresenter
{
    /** Ordre des rayons (magasin type) — contrat de l'étape 6. */
    public const AISLES = ['fruits_legumes', 'boulangerie', 'boucherie_poissonnerie', 'cremerie', 'epicerie', 'epicerie_sucree', 'surgeles', 'boissons', 'hygiene_entretien', 'autre'];

    public function __construct(private readonly ShoppingListService $service)
    {
    }

    /** @return array<string, mixed> carte du chat / SessionDetail.shopping_lists */
    public function summary(ShoppingList $list): array
    {
        return ['id' => $list->getId(), 'type' => $list->getType()->value, 'name' => $list->getName()] + $this->service->counts($list);
    }

    /** @return array<string, mixed> ShoppingList complète */
    public function detail(ShoppingList $list): array
    {
        $sections = array_fill_keys(self::AISLES, []);
        $basics = $alreadyHave = [];
        foreach ($list->getItems() as $item) {
            if ($item->isCoveredByInventory()) {
                $alreadyHave[] = ['label' => $item->getLabel(), 'sources' => $this->sources($item)];
            } elseif ($item->isBasic()) {
                $basics[] = $item;
            } else {
                $sections[\in_array($item->getCategory(), self::AISLES, true) ? $item->getCategory() : 'autre'][] = $item;
            }
        }

        $result = [];
        foreach ($sections as $category => $items) {
            if ([] !== $items) {
                $result[] = ['category' => $category, 'items' => array_map($this->item(...), self::sort($items))];
            }
        }
        usort($alreadyHave, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return [
            'id' => $list->getId(),
            'session_id' => $list->getSession()->getId(),
            'type' => $list->getType()->value,
            'name' => $list->getName(),
            'created_at' => $list->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'generated_at' => $list->getGeneratedAt()?->format(\DateTimeInterface::ATOM),
            'outdated' => $this->service->isOutdated($list),
            'read_only' => !$list->getSession()->isActive(),
        ] + $this->service->counts($list) + [
            'sections' => $result,
            'basics' => array_map($this->item(...), self::sort($basics)),
            'already_have' => $alreadyHave,
        ];
    }

    /** @return array<string, mixed> */
    public function item(ShoppingListItem $item): array
    {
        return [
            'id' => $item->getId(),
            'label' => $item->getLabel(),
            'quantity' => self::number($item->getQuantity()),
            'unit' => $item->getUnit(),
            'category' => $item->getCategory(),
            'checked' => $item->isChecked(),
            'origin' => $item->getOrigin()->value,
            'optional' => $item->isOptional(),
            'sources' => $this->sources($item),
            'inventory_deducted' => null === $item->getInventoryDeducted() ? null : [
                'quantity' => self::number($item->getInventoryDeducted()['quantity']),
                'unit' => $item->getInventoryDeducted()['unit'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sources(ShoppingListItem $item): array
    {
        $sources = [];
        foreach ($item->getSources() as $source) {
            $sources[] = [
                'recipe_id' => $source->getRecipe()->getId(),
                'recipe_title' => $source->getRecipe()->getTitle(),
                'quantity' => self::number($source->getQuantity()),
                'unit' => $source->getUnit(),
            ];
        }

        return $sources;
    }

    /**
     * Non cochés (ordre alphabétique) puis cochés (ordre alphabétique).
     *
     * @param list<ShoppingListItem> $items
     *
     * @return list<ShoppingListItem>
     */
    private static function sort(array $items): array
    {
        $collator = new \Collator('fr_FR');
        usort($items, static fn (ShoppingListItem $a, ShoppingListItem $b): int => [$a->isChecked(), 0] <=> [$b->isChecked(), 0] ?: $collator->compare($a->getLabel(), $b->getLabel()));

        return $items;
    }

    private static function number(?float $value): int|float|null
    {
        if (null === $value) {
            return null;
        }

        return floor($value) === $value ? (int) $value : round($value, 2);
    }
}
