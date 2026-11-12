<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class RecipeFixtures
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function chili(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Chili sin carne',
            'description' => 'Gros plat mijoté, parfait pour les gamelles.',
            'servings' => 2,
            'meals_covered' => 3,
            'meal_tags' => ['batch_cooking', 'gamelle'],
            'prep_time_min' => 20,
            'cook_time_min' => 40,
            'difficulty' => 'easy',
            'uses_inventory' => ['riz'],
            'ingredients' => [
                ['name' => 'haricots rouges', 'quantity' => 800, 'unit' => 'g', 'category' => 'epicerie', 'optional' => false],
                ['name' => 'oignon', 'quantity' => 300, 'unit' => 'g', 'category' => 'fruits_legumes', 'optional' => false],
                ['name' => 'crème fraîche', 'quantity' => 20, 'unit' => 'cl', 'category' => 'cremerie', 'optional' => true],
                ['name' => 'sel', 'quantity' => null, 'unit' => null, 'category' => 'epicerie', 'optional' => false],
            ],
            'steps' => ['Émincer les oignons.', 'Mijoter 30 min.'],
            'tips' => ['Encore meilleur réchauffé le lendemain.', 'Se congèle très bien en portions.'],
            'equipment' => ['cocotte'],
            'suggested_day' => 1,
            'storage_tip' => 'Se garde 3 jours au frigo.',
        ];
    }
}
