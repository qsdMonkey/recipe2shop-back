<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'shopping_list_item')]
class ShoppingListItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ShoppingList $list;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ingredient $ingredient;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(nullable: true)]
    private ?float $quantity;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $unit;

    #[ORM\Column(length: 30)]
    private string $category;

    #[ORM\Column]
    private bool $checked = false;

    #[ORM\Column(length: 10, enumType: ShoppingItemOrigin::class)]
    private ShoppingItemOrigin $origin;

    #[ORM\Column]
    private bool $optional = false;

    /** Produit de base (rubrique « À vérifier dans tes placards »). */
    #[ORM\Column]
    private bool $basic = false;

    /** Entièrement couvert par l'inventaire déclaré (rubrique « Déjà chez toi »). */
    #[ORM\Column]
    private bool $coveredByInventory = false;

    /** @var array{quantity: float, unit: string}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $inventoryDeducted = null;

    /** @var Collection<int, ShoppingListItemSource> */
    #[ORM\OneToMany(targetEntity: ShoppingListItemSource::class, mappedBy: 'item', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $sources;

    public function __construct(ShoppingList $list, ShoppingItemOrigin $origin, string $label, ?float $quantity, ?string $unit, string $category, ?Ingredient $ingredient = null)
    {
        $this->list = $list;
        $this->origin = $origin;
        $this->label = $label;
        $this->quantity = $quantity;
        $this->unit = null === $quantity ? null : $unit;
        $this->category = $category;
        $this->ingredient = $ingredient;
        $this->sources = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getList(): ShoppingList
    {
        return $this->list;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function setChecked(bool $checked): void
    {
        $this->checked = $checked;
    }

    public function getOrigin(): ShoppingItemOrigin
    {
        return $this->origin;
    }

    public function isFromRecipes(): bool
    {
        return ShoppingItemOrigin::Recipe === $this->origin;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function isBasic(): bool
    {
        return $this->basic;
    }

    public function isCoveredByInventory(): bool
    {
        return $this->coveredByInventory;
    }

    /** @return array{quantity: float, unit: string}|null */
    public function getInventoryDeducted(): ?array
    {
        return $this->inventoryDeducted;
    }

    /** @param array{quantity: float, unit: string}|null $inventoryDeducted */
    public function setComputed(bool $optional, bool $basic, bool $coveredByInventory, ?array $inventoryDeducted): void
    {
        $this->optional = $optional;
        $this->basic = $basic;
        $this->coveredByInventory = $coveredByInventory;
        $this->inventoryDeducted = $inventoryDeducted;
    }

    public function edit(string $label, ?float $quantity, ?string $unit, string $category): void
    {
        $this->label = $label;
        $this->quantity = $quantity;
        $this->unit = null === $quantity ? null : $unit;
        $this->category = $category;
    }

    public function markBasic(bool $basic): void
    {
        $this->basic = $basic;
    }

    /** @return Collection<int, ShoppingListItemSource> */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    public function addSource(Recipe $recipe, ?float $quantity, ?string $unit): void
    {
        $this->sources->add(new ShoppingListItemSource($this, $recipe, $quantity, $unit));
    }
}
