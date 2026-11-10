<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ingrédient d'une recette. Le lien vers le référentiel d'ingrédients (ingredient_id) arrive à l'étape 6 (listes).
 */
#[ORM\Entity]
#[ORM\Table(name: 'recipe_ingredient')]
class RecipeIngredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'ingredients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recipe $recipe;

    #[ORM\Column]
    private int $position;

    /** Lien vers le référentiel (agrégation de la liste de courses). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Ingredient $ingredient = null;

    #[ORM\Column(length: 100)]
    private string $name;

    /** null = « à volonté » (sel, poivre…). */
    #[ORM\Column(nullable: true)]
    private ?float $quantity;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $unit;

    #[ORM\Column(length: 30)]
    private string $category;

    #[ORM\Column]
    private bool $optional;

    public function __construct(Recipe $recipe, int $position, string $name, float|int|null $quantity, ?string $unit, string $category, bool $optional)
    {
        $this->recipe = $recipe;
        $this->position = $position;
        $this->name = $name;
        $this->quantity = null === $quantity ? null : (float) $quantity;
        $this->unit = $unit;
        $this->category = $category;
        $this->optional = $optional;
    }

    public function getRecipe(): Recipe
    {
        return $this->recipe;
    }

    public function getIngredient(): ?Ingredient
    {
        return $this->ingredient;
    }

    public function linkIngredient(Ingredient $ingredient): void
    {
        $this->ingredient = $ingredient;
    }

    public function getName(): string
    {
        return $this->name;
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

    public function isOptional(): bool
    {
        return $this->optional;
    }
}
