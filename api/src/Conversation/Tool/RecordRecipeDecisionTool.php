<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\SessionRecipeStatus;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * record_recipe_decision({recipe_id, decision, comment?}) : l'utilisateur a accepté ou refusé une recette PAR ÉCRIT
 * (« oui parfait », « non, pas de poisson »). Les boutons ✅ / 🔄 passent par les routes accept / reject.
 */
final class RecordRecipeDecisionTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function __construct(
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function name(): string
    {
        return 'record_recipe_decision';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Enregistre la décision de l\'utilisateur sur une recette proposée quand il répond par écrit au lieu des boutons '
                .'(« oui ça me va » → accepted ; « non », « autre chose », « pas de poisson » → rejected).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'recipe_id' => ['type' => 'integer'],
                    'decision' => ['type' => 'string', 'enum' => ['accepted', 'rejected']],
                    'comment' => ['type' => 'string', 'description' => 'Raison du refus en quelques mots, si l\'utilisateur l\'a donnée.'],
                ],
                'required' => ['recipe_id', 'decision'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $decision = SessionRecipeStatus::tryFrom(\is_string($args['decision'] ?? null) ? $args['decision'] : '');
        if (!\in_array($decision, [SessionRecipeStatus::Accepted, SessionRecipeStatus::Rejected], true)) {
            return ToolResult::error('"decision" doit valoir accepted ou rejected.');
        }

        $sessionRecipe = \is_int($args['recipe_id'] ?? null) ? $this->sessionRecipes->findOneInSession($context->session, $args['recipe_id']) : null;
        if (null === $sessionRecipe) {
            return ToolResult::error('Recette introuvable dans cette session (vérifie recipe_id dans l\'état de la session).');
        }
        if (!$sessionRecipe->isProposed()) {
            return ToolResult::ok(['status' => 'already_decided', 'decision' => $sessionRecipe->getStatus()->value]);
        }

        $sessionRecipe->decide($decision, \is_string($args['comment'] ?? null) ? $args['comment'] : null);
        $this->em->flush();

        return ToolResult::ok(['status' => 'ok', 'recipe_id' => $sessionRecipe->getRecipe()->getId(), 'decision' => $decision->value]);
    }
}
