<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\Recipe;
use App\Memory\HouseholdSize;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * start_cooking({recipe_id, servings?}) : l'utilisateur va cuisiner une recette → carte « 👨‍🍳 Pas à pas »
 * (bloc cooking_guide), qui ouvre la recette complète en plein écran dans l'app. L'IA ne recopie jamais la recette.
 */
final class StartCookingTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly HouseholdSize $household,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'start_cooking';
    }

    public function declaration(): array
    {
        return [
            'description' => 'L\'utilisateur va cuisiner une recette (« ce soir je fais le chili », « comment on fait les pâtes ? ») : affiche une carte « Pas à pas » '
                .'qui ouvre la recette complète (ingrédients, étapes détaillées, astuces) en plein écran. N\'écris jamais la recette dans le chat.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'recipe_id' => ['type' => 'integer', 'description' => 'recipe_id d\'une recette de la session ou d\'un favori.'],
                    'servings' => ['type' => 'integer', 'description' => 'Nombre de portions si l\'utilisateur le précise (« on sera 6 ») ; sinon ne le donne pas : le foyer est utilisé.'],
                ],
                'required' => ['recipe_id'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $recipeId = $args['recipe_id'] ?? null;
        $recipe = \is_int($recipeId) && $this->sessionRecipes->isVisibleTo($recipeId, $context->user) ? $this->em->find(Recipe::class, $recipeId) : null;
        if (null === $recipe) {
            return ToolResult::error('Recette introuvable : utilise le recipe_id d\'une recette de la session ou d\'un favori. Pour une recette nouvelle, propose-la d\'abord avec propose_recipe.');
        }

        $servings = $args['servings'] ?? null;
        if (!\is_int($servings) || $servings < 1 || $servings > 50) {
            $servings = $this->household->forUser($context->user) ?? $recipe->getServings();
        }

        return ToolResult::ok(
            [
                'status' => 'ok',
                'recipe_id' => $recipe->getId(),
                'title' => $recipe->getTitle(),
                'servings' => $servings,
                'note' => 'La carte « Pas à pas » (recette complète, quantités pour '.$servings.' portions) est affichée sous ton message : réponds en une phrase courte (« C\'est parti ! Tout est dans le pas à pas 👇 »), sans recopier la recette.',
            ],
            [['type' => 'cooking_guide', 'recipe_id' => $recipe->getId(), 'title' => $recipe->getTitle(), 'servings' => $servings]],
        );
    }
}
