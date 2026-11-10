<?php

declare(strict_types=1);

namespace App\ShoppingList;

use App\Entity\Ingredient;
use App\Entity\Recipe;

/** Article calculé par ShoppingListBuilder, avant enregistrement. */
final class BuiltItem
{
    /**
     * @param list<array{recipe: Recipe, quantity: ?float, unit: ?string}> $sources
     * @param array{quantity: float, unit: string}|null                    $inventoryDeducted
     */
    public function __construct(
        public readonly Ingredient $ingredient,
        public readonly ?float $quantity,
        public readonly ?string $unit,
        public readonly bool $optional,
        public readonly bool $coveredByInventory,
        public readonly ?array $inventoryDeducted,
        public readonly array $sources,
    ) {
    }

    public function key(): string
    {
        return $this->ingredient->getId().'|'.($this->unit ?? '');
    }
}
