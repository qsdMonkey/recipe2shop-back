<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\Recipe;
use App\Entity\SessionRecipe;

final class RecipePresenter
{
    /**
     * Carte recette (bloc recipe sans "type", et éléments de SessionDetail.recipes) : état actuel.
     * $favorite : la recette est dans les favoris de l'utilisateur (état actuel, comme status).
     *
     * @return array<string, mixed>
     */
    public function sessionRecipe(SessionRecipe $sessionRecipe, bool $favorite = false): array
    {
        return [
            'status' => $sessionRecipe->getStatus()->value,
            'favorite' => $favorite,
            'suggested_day' => $sessionRecipe->getSuggestedDay(),
            'cooked' => $sessionRecipe->isCooked(),
            'warnings' => $sessionRecipe->getWarnings(),
            'recipe' => $this->recipe($sessionRecipe->getRecipe()),
        ];
    }

    /**
     * @param int|null $servings portions demandées : quantités recalculées par le code (null = portions d'origine)
     *
     * @return array<string, mixed>
     */
    public function recipe(Recipe $recipe, ?int $servings = null): array
    {
        $factor = null === $servings ? 1.0 : $servings / $recipe->getServings();
        $ingredients = [];
        foreach ($recipe->getIngredients() as $ingredient) {
            $ingredients[] = [
                'name' => $ingredient->getName(),
                'quantity' => null === $ingredient->getQuantity() ? null : self::quantity($ingredient->getQuantity() * $factor, $ingredient->getUnit(), 1.0 !== $factor),
                'unit' => $ingredient->getUnit(),
                'category' => $ingredient->getCategory(),
                'optional' => $ingredient->isOptional(),
            ];
        }

        return [
            'id' => $recipe->getId(),
            'title' => $recipe->getTitle(),
            'description' => $recipe->getDescription(),
            'servings' => $servings ?? $recipe->getServings(),
            'meals_covered' => $recipe->getMealsCovered(),
            'meal_tags' => $recipe->getMealTags(),
            'prep_time_min' => $recipe->getPrepTimeMin(),
            'cook_time_min' => $recipe->getCookTimeMin(),
            'difficulty' => $recipe->getDifficulty(),
            'uses_inventory' => $recipe->getUsesInventory(),
            'ingredients' => $ingredients,
            'steps' => $recipe->getSteps(),
            'equipment' => $recipe->getEquipment(),
            'storage_tip' => $recipe->getStorageTip(),
        ];
    }

    /**
     * Quantité lisible : entier si possible ; après recalcul des portions, arrondi adapté à l'unité.
     */
    private static function quantity(float $value, ?string $unit, bool $scaled): int|float
    {
        if ($scaled) {
            $value = match (true) {
                \in_array($unit, ['g', 'ml'], true) => $value >= 50 ? round($value / 5) * 5 : round($value),
                \in_array($unit, ['kg', 'l'], true) => round($value, 2),
                'cl' === $unit => round($value),
                default => max(0.5, round($value * 2) / 2), // pièces, cuillères, gousses… : à la demi-unité
            };
        }

        return floor($value) === $value ? (int) $value : round($value, 2);
    }
}
