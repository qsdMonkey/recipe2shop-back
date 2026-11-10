<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\SessionRecipe;
use App\Entity\SessionRecipeStatus;
use App\Recipe\RecipeGenerator;
use App\Repository\FavoriteRecipeRepository;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * propose_favorite({recipe_id, suggested_day?}) : repropose une recette favorite de l'utilisateur. Même carte que
 * propose_recipe (bloc recipe, statut proposed), mais pointe vers la recette existante : pas d'appel Gemini dédié.
 */
final class ProposeFavoriteTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(
        private readonly FavoriteRecipeRepository $favorites,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly RecipeGenerator $generator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'propose_favorite';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Repropose UNE recette favorite de l\'utilisateur (liste « Recettes favorites » du contexte), telle quelle : '
                .'la carte avec les boutons ✅ / 🔄 s\'affiche comme pour propose_recipe, sans la régénérer. Une seule recette à la fois.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'recipe_id' => ['type' => 'integer', 'description' => 'recipe_id d\'une recette de la liste des favoris.'],
                    'suggested_day' => ['type' => 'integer', 'description' => 'Jour de la période où la cuisiner (1 = premier jour), selon la péremption des produits frais.'],
                ],
                'required' => ['recipe_id'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $recipeId = $args['recipe_id'] ?? null;
        $favorite = \is_int($recipeId) ? $this->favorites->findOneFor($context->user, $recipeId) : null;
        if (null === $favorite) {
            return ToolResult::error('Ce recipe_id n\'est pas une recette favorite de l\'utilisateur. Utilise un id de la liste des favoris, ou propose_recipe pour une nouvelle recette.');
        }
        $recipe = $favorite->getRecipe();

        $pending = $this->sessionRecipes->findPending($context->session);
        if (null !== $pending) {
            return ToolResult::error(\sprintf(
                'La recette « %s » (recipe_id %d) attend encore la réponse de l\'utilisateur. Une seule recette à la fois : si l\'utilisateur a répondu par écrit, enregistre sa décision avec record_recipe_decision, sinon attends sa réponse.',
                $pending->getRecipe()->getTitle(),
                $pending->getRecipe()->getId(),
            ));
        }

        $existing = $this->sessionRecipes->findOneInSession($context->session, (int) $recipe->getId());
        if (null !== $existing) {
            return ToolResult::error(SessionRecipeStatus::Rejected === $existing->getStatus()
                ? 'L\'utilisateur a refusé cette recette dans cette session : ne la repropose pas.'
                : 'Cette recette est déjà acceptée dans cette session.');
        }

        $suggestedDay = $args['suggested_day'] ?? null;
        $suggestedDay = \is_int($suggestedDay) && $suggestedDay >= 1 && $suggestedDay <= $context->session->getPeriodDays() ? $suggestedDay : null;
        $warnings = $this->generator->equipmentWarnings($context->session, $recipe->getEquipment());

        $sessionRecipe = new SessionRecipe($context->session, $recipe, $suggestedDay, $this->sessionRecipes->nextPosition($context->session), $warnings);
        $this->em->persist($sessionRecipe);
        $this->em->flush();

        return ToolResult::ok(
            [
                'status' => 'ok',
                'recipe_id' => $recipe->getId(),
                'title' => $recipe->getTitle(),
                'meals_covered' => $recipe->getMealsCovered(),
                'servings' => $recipe->getServings(),
                'suggested_day' => $suggestedDay,
                'ingredients' => array_map(static fn ($i): string => $i->getName(), $recipe->getIngredients()->toArray()),
                'warnings' => $warnings,
                'note' => 'La carte de cette recette favorite (boutons ✅ / 🔄) est affichée sous ton message : présente-la en une ou deux phrases (tu peux dire que c\'est une de ses favorites) et demande son avis, sans recopier la recette.',
            ],
            [['type' => 'recipe', 'session_recipe_id' => $sessionRecipe->getId()]],
        );
    }
}
