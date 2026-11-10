<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Recette générée par l'IA. Toujours conservée : survit à la suppression des conversations
 * et, anonymisée (created_by = NULL), à la suppression du compte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'recipe')]
class Recipe
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column]
    private int $servings;

    #[ORM\Column]
    private int $mealsCovered;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $mealTags;

    #[ORM\Column]
    private int $prepTimeMin;

    #[ORM\Column]
    private int $cookTimeMin;

    #[ORM\Column(length: 10)]
    private string $difficulty;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $usesInventory;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $equipment;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $storageTip;

    /** @var Collection<int, RecipeIngredient> */
    #[ORM\OneToMany(targetEntity: RecipeIngredient::class, mappedBy: 'recipe', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $ingredients;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(length: 50)]
    private string $promptVersion;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array{title: string, description: ?string, servings: int, meals_covered: int, meal_tags: list<string>, prep_time_min: int, cook_time_min: int, difficulty: string, uses_inventory: list<string>, ingredients: list<array{name: string, quantity: float|int|null, unit: ?string, category: string, optional: bool}>, steps: list<string>, equipment: list<string>, storage_tip: ?string} $data
     */
    public function __construct(array $data, ?User $createdBy, string $promptVersion)
    {
        $this->title = $data['title'];
        $this->description = $data['description'];
        $this->servings = $data['servings'];
        $this->mealsCovered = $data['meals_covered'];
        $this->mealTags = array_values($data['meal_tags']);
        $this->prepTimeMin = $data['prep_time_min'];
        $this->cookTimeMin = $data['cook_time_min'];
        $this->difficulty = $data['difficulty'];
        $this->usesInventory = array_values($data['uses_inventory']);
        $this->steps = array_values($data['steps']);
        $this->equipment = array_values($data['equipment']);
        $this->storageTip = $data['storage_tip'];
        $this->createdBy = $createdBy;
        $this->promptVersion = $promptVersion;
        $this->createdAt = new \DateTimeImmutable();
        $this->ingredients = new ArrayCollection();
        foreach (array_values($data['ingredients']) as $position => $ingredient) {
            $this->ingredients->add(new RecipeIngredient($this, $position, $ingredient['name'], $ingredient['quantity'], $ingredient['unit'], $ingredient['category'], $ingredient['optional']));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getServings(): int
    {
        return $this->servings;
    }

    public function getMealsCovered(): int
    {
        return $this->mealsCovered;
    }

    /** @return list<string> */
    public function getMealTags(): array
    {
        return $this->mealTags;
    }

    public function getPrepTimeMin(): int
    {
        return $this->prepTimeMin;
    }

    public function getCookTimeMin(): int
    {
        return $this->cookTimeMin;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    /** @return list<string> */
    public function getUsesInventory(): array
    {
        return $this->usesInventory;
    }

    /** @return list<string> */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /** @return list<string> */
    public function getEquipment(): array
    {
        return $this->equipment;
    }

    public function getStorageTip(): ?string
    {
        return $this->storageTip;
    }

    /** @return Collection<int, RecipeIngredient> */
    public function getIngredients(): Collection
    {
        return $this->ingredients;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }
}
