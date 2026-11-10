<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** « Pour quelle recette » : la part d'une recette dans un article de la liste (quantité d'origine). */
#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_item_source')]
class ShoppingListItemSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sources')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingListItem $item;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipe $recipe;

    #[ORM\Column(nullable: true)]
    private ?float $quantity;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $unit;

    public function __construct(ShoppingListItem $item, Recipe $recipe, ?float $quantity, ?string $unit)
    {
        $this->item = $item;
        $this->recipe = $recipe;
        $this->quantity = $quantity;
        $this->unit = $unit;
    }

    public function getRecipe(): Recipe
    {
        return $this->recipe;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }
}
