<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\SessionRecipeStatus;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/** mark_recipe_cooked({recipe_id}) : « j'ai fait le chili » → suivi de la semaine (fiche 06). */
final class MarkRecipeCookedTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'mark_recipe_cooked';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Marque une recette acceptée comme cuisinée quand l\'utilisateur dit l\'avoir faite (« j\'ai fait le chili »).',
            'parameters' => [
                'type' => 'object',
                'properties' => ['recipe_id' => ['type' => 'integer']],
                'required' => ['recipe_id'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $sessionRecipe = \is_int($args['recipe_id'] ?? null) ? $this->sessionRecipes->findOneInSession($context->session, $args['recipe_id']) : null;
        if (null === $sessionRecipe || SessionRecipeStatus::Accepted !== $sessionRecipe->getStatus()) {
            return ToolResult::error('Recette acceptée introuvable dans cette session (vérifie recipe_id dans l\'état de la session).');
        }

        $sessionRecipe->markCooked();
        $this->em->flush();

        return ToolResult::ok(['status' => 'ok', 'recipe_id' => $sessionRecipe->getRecipe()->getId(), 'title' => $sessionRecipe->getRecipe()->getTitle()]);
    }
}
