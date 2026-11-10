<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IngredientRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Référentiel d'ingrédients (data/ingredients.csv, synchronisé par app:ingredients:sync).
 * Un nom inconnu rencontré dans une recette crée une entrée « to_review » (à vérifier puis à ajouter au CSV).
 */
#[ORM\Entity(repositoryClass: IngredientRepository::class)]
#[ORM\Table(name: 'ingredient')]
#[ORM\UniqueConstraint(name: 'uniq_ingredient_normalized', columns: ['normalized_name'])]
class Ingredient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 100)]
    private string $normalizedName;

    /** Rayon (liste fermée des catégories). */
    #[ORM\Column(length: 30)]
    private string $category;

    /** Unité d'affichage préférée dans la liste de courses (null = choisie selon les quantités). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $defaultUnit;

    #[ORM\Column(nullable: true)]
    private ?int $shelfLifeDays;

    #[ORM\Column(nullable: true)]
    private ?float $avgWeightPerPiece;

    /** Produit de base (sel, poivre, huile…) : affiché à part dans la liste. */
    #[ORM\Column]
    private bool $basic;

    #[ORM\Column]
    private bool $toReview;

    /** @var list<string> alias normalisés */
    #[ORM\Column(type: Types::JSON)]
    private array $aliases;

    /** @param list<string> $aliases */
    public function __construct(string $name, string $normalizedName, string $category, ?string $defaultUnit, ?int $shelfLifeDays, ?float $avgWeightPerPiece, bool $basic, bool $toReview, array $aliases = [])
    {
        $this->name = $name;
        $this->normalizedName = $normalizedName;
        $this->category = $category;
        $this->defaultUnit = $defaultUnit;
        $this->shelfLifeDays = $shelfLifeDays;
        $this->avgWeightPerPiece = $avgWeightPerPiece;
        $this->basic = $basic;
        $this->toReview = $toReview;
        $this->aliases = $aliases;
    }

    /** @param list<string> $aliases */
    public function updateFromReference(string $name, string $category, ?string $defaultUnit, ?int $shelfLifeDays, ?float $avgWeightPerPiece, bool $basic, array $aliases): void
    {
        $this->name = $name;
        $this->category = $category;
        $this->defaultUnit = $defaultUnit;
        $this->shelfLifeDays = $shelfLifeDays;
        $this->avgWeightPerPiece = $avgWeightPerPiece;
        $this->basic = $basic;
        $this->toReview = false;
        $this->aliases = $aliases;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** Nom lisible corrigé (ex. accents restaurés sur un ingrédient « to_review »). La clé normalisée ne change pas. */
    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getDefaultUnit(): ?string
    {
        return $this->defaultUnit;
    }

    public function getShelfLifeDays(): ?int
    {
        return $this->shelfLifeDays;
    }

    public function getAvgWeightPerPiece(): ?float
    {
        return $this->avgWeightPerPiece;
    }

    public function isBasic(): bool
    {
        return $this->basic;
    }

    public function isToReview(): bool
    {
        return $this->toReview;
    }

    /** @return list<string> */
    public function getAliases(): array
    {
        return $this->aliases;
    }
}
