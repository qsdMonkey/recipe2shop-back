<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\Recipe;
use App\Entity\SessionRecipe;
use App\Ingredient\IngredientMatcher;
use App\Recipe\RecipeGenerationException;
use App\Recipe\RecipeGenerator;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * propose_recipe({brief, meals_covered?, suggested_day?}) : le chat donne une consigne courte, la recette est générée
 * par un appel Gemini dédié (JSON strict), validée, enregistrée, et affichée en carte (bloc recipe).
 */
final class ProposeRecipeTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(
        private readonly RecipeGenerator $generator,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly EntityManagerInterface $em,
        private readonly IngredientMatcher $matcher,
    ) {
    }

    public function name(): string
    {
        return 'propose_recipe';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Propose UNE recette à l\'utilisateur : elle est générée à partir de ta consigne, enregistrée, puis affichée sous forme de carte '
                .'(titre, ingrédients, étapes) avec des boutons ✅ / 🔄. Une seule recette à la fois : attends la réponse avant la suivante.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'brief' => ['type' => 'string', 'description' => 'Consigne courte et précise : type de plat, contraintes de la semaine, ingrédients à utiliser, pour qui. Ex. « plat mijoté végétarien pour 2 dîners + 1 gamelle, sans four, avec les courgettes et le riz de l\'utilisateur ».'],
                    'meals_covered' => ['type' => 'integer', 'description' => 'Nombre de repas que la recette doit couvrir.'],
                    'suggested_day' => ['type' => 'integer', 'description' => 'Jour de la période où la cuisiner (1 = premier jour), selon la péremption des produits frais.'],
                ],
                'required' => ['brief'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $brief = \is_string($args['brief'] ?? null) ? trim($args['brief']) : '';
        if ('' === $brief) {
            return ToolResult::error('"brief" est obligatoire.');
        }

        $pending = $this->sessionRecipes->findPending($context->session);
        if (null !== $pending) {
            return ToolResult::error(\sprintf(
                'La recette « %s » (recipe_id %d) attend encore la réponse de l\'utilisateur. Une seule recette à la fois : si l\'utilisateur a répondu par écrit, enregistre sa décision avec record_recipe_decision, sinon attends sa réponse.',
                $pending->getRecipe()->getTitle(),
                $pending->getRecipe()->getId(),
            ));
        }

        $mealsCovered = \is_int($args['meals_covered'] ?? null) ? $args['meals_covered'] : null;
        $suggestedDay = \is_int($args['suggested_day'] ?? null) ? $args['suggested_day'] : null;

        try {
            $generated = $this->generator->generate($context->session, $brief, $mealsCovered, $suggestedDay);
        } catch (RecipeGenerationException $e) {
            return ToolResult::error('La génération de la recette a échoué. Reformule une consigne plus simple ou propose autre chose.', array_slice($e->errors, 0, 5));
        }

        $recipe = new Recipe($generated->data, $context->user, $this->generator->promptVersion());
        foreach ($recipe->getIngredients() as $ingredient) {
            $ingredient->linkIngredient($this->matcher->findOrCreate($ingredient->getName(), $ingredient->getCategory()));
        }
        $sessionRecipe = new SessionRecipe($context->session, $recipe, $generated->data['suggested_day'], $this->sessionRecipes->nextPosition($context->session), $generated->warnings);
        $this->em->persist($recipe);
        $this->em->persist($sessionRecipe);
        $this->em->flush();

        return ToolResult::ok(
            [
                'status' => 'ok',
                'recipe_id' => $recipe->getId(),
                'title' => $recipe->getTitle(),
                'meals_covered' => $recipe->getMealsCovered(),
                'servings' => $recipe->getServings(),
                'suggested_day' => $sessionRecipe->getSuggestedDay(),
                'ingredients' => array_map(static fn ($i): string => $i->getName(), $recipe->getIngredients()->toArray()),
                'warnings' => $generated->warnings,
                'note' => 'La carte de la recette (ingrédients, étapes, boutons ✅ / 🔄) est affichée sous ton message : présente-la en une ou deux phrases et demande son avis, sans recopier la recette.',
            ],
            [['type' => 'recipe', 'session_recipe_id' => $sessionRecipe->getId()]],
        );
    }
}
