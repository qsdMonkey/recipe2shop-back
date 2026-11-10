<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Ce que l'utilisateur a déjà (frigo, placards), déclaré dans la session. Déduit de la liste à l'étape 6. */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_item')]
class InventoryItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingSession $session;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ingredient $ingredient = null;

    #[ORM\Column(nullable: true)]
    private ?float $quantity;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $unit;

    public function __construct(ShoppingSession $session, string $label, ?float $quantity, ?string $unit)
    {
        $this->session = $session;
        $this->label = $label;
        $this->quantity = $quantity;
        $this->unit = $unit;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function linkIngredient(?Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
    }

    public function getLabel(): string
    {
        return $this->label;
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
