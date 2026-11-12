<?php

declare(strict_types=1);

namespace App\Recipe;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Format d'une recette (fiche 03) : schéma imposé à Gemini (responseSchema) + contraintes de validation par le code.
 */
final class RecipeSchema
{
    /** @return array<string, mixed> */
    public static function gemini(): array
    {
        $string = ['type' => 'string'];
        $stringList = ['type' => 'array', 'items' => $string];

        return [
            'type' => 'object',
            'properties' => [
                'title' => $string,
                'description' => $string,
                'servings' => ['type' => 'integer'],
                'meals_covered' => ['type' => 'integer', 'description' => 'Nombre de repas du foyer couverts par la recette (ex. 2 dîners + 1 gamelle = 3).'],
                'meal_tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => RecipeVocabulary::MEAL_TAGS]],
                'prep_time_min' => ['type' => 'integer'],
                'cook_time_min' => ['type' => 'integer'],
                'difficulty' => ['type' => 'string', 'enum' => RecipeVocabulary::DIFFICULTIES],
                'uses_inventory' => $stringList,
                'ingredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'Nom simple, au singulier, sans quantité (« oignon », « haricots rouges »).'],
                            'quantity' => ['type' => 'number', 'nullable' => true],
                            'unit' => ['type' => 'string', 'enum' => RecipeVocabulary::UNITS, 'nullable' => true],
                            'category' => ['type' => 'string', 'enum' => RecipeVocabulary::CATEGORIES],
                            'optional' => ['type' => 'boolean'],
                        ],
                        'required' => ['name', 'quantity', 'unit', 'category', 'optional'],
                    ],
                ],
                'steps' => ['type' => 'array', 'items' => $string, 'description' => '6 à 12 étapes détaillées et autonomes (quantités, température, durée, repères visuels).'],
                'tips' => ['type' => 'array', 'items' => $string, 'description' => '2 à 4 astuces : variantes, erreurs à éviter, conservation.'],
                'equipment' => $stringList,
                'suggested_day' => ['type' => 'integer'],
                'storage_tip' => $string,
            ],
            'required' => ['title', 'description', 'servings', 'meals_covered', 'meal_tags', 'prep_time_min', 'cook_time_min', 'difficulty', 'uses_inventory', 'ingredients', 'steps', 'equipment', 'suggested_day', 'storage_tip'],
        ];
    }

    public static function constraints(int $periodDays): Assert\Collection
    {
        $text = static fn (int $max): array => [new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: $max)];
        $int = static fn (int $min, int $max): array => [new Assert\NotNull(), new Assert\Type('integer'), new Assert\Range(min: $min, max: $max)];
        $stringList = static fn (int $max, int $maxLength): array => [new Assert\Type('array'), new Assert\Count(max: $max), new Assert\All([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: $maxLength)])];

        return new Assert\Collection(
            fields: [
                'title' => $text(150),
                'description' => [new Assert\Type('string'), new Assert\Length(max: 500)],
                'servings' => $int(1, 20),
                'meals_covered' => $int(1, 14),
                'meal_tags' => [new Assert\Type('array'), new Assert\All([new Assert\Choice(choices: RecipeVocabulary::MEAL_TAGS)])],
                'prep_time_min' => $int(0, 600),
                'cook_time_min' => $int(0, 1440),
                'difficulty' => [new Assert\Choice(choices: RecipeVocabulary::DIFFICULTIES)],
                'uses_inventory' => $stringList(30, 100),
                'ingredients' => [
                    new Assert\Type('array'),
                    new Assert\Count(min: 1, max: 40),
                    new Assert\All([new Assert\Collection(
                        fields: [
                            'name' => $text(100),
                            'quantity' => [new Assert\Type('numeric'), new Assert\Positive(), new Assert\LessThanOrEqual(100000)],
                            'unit' => [new Assert\Choice(choices: RecipeVocabulary::UNITS)],
                            'category' => [new Assert\NotNull(), new Assert\Choice(choices: RecipeVocabulary::CATEGORIES)],
                            'optional' => [new Assert\Type('bool')],
                        ],
                        allowMissingFields: false,
                    )]),
                ],
                'steps' => [new Assert\Type('array'), new Assert\Count(min: 1, max: 30), new Assert\All([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim'), new Assert\Length(max: 1000)])],
                'tips' => new Assert\Optional($stringList(6, 400)),
                'equipment' => $stringList(15, 60),
                'suggested_day' => $int(1, max(1, $periodDays)),
                'storage_tip' => [new Assert\Type('string'), new Assert\Length(max: 300)],
            ],
            allowExtraFields: true,
        );
    }
}
