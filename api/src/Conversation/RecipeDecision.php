<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\SessionRecipeStatus;
use App\Entity\ShoppingSession;
use App\Entity\Turn;
use App\Http\ApiException;
use App\Repository\FavoriteRecipeRepository;
use App\Repository\SessionRecipeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Boutons ✅ / 🔄 d'une carte recette : un tour complet (action affichée, décision enregistrée dans la transaction
 * du tour, réponse de l'IA). Utilisé par l'API et par les scénarios de test IA.
 */
final class RecipeDecision
{
    public function __construct(
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly TurnRunner $turnRunner,
        private readonly EntityManagerInterface $em,
        private readonly FavoriteRecipeRepository $favorites,
    ) {
    }

    /** @throws ApiException 404 not_found, 409 recipe_not_proposed… */
    public function decide(ShoppingSession $session, int $recipeId, bool $accept, ?string $comment, ?string $clientId): TurnResult
    {
        $sessionRecipe = $this->sessionRecipes->findOneInSession($session, $recipeId)
            ?? throw new ApiException(404, 'not_found', 'Recipe not found in this session.');
        $title = $sessionRecipe->getRecipe()->getTitle();
        $comment = $accept || null === $comment || '' === trim($comment) ? null : trim($comment);

        // Ce que l'IA lit dans l'historique : l'action de l'utilisateur, en clair.
        $content = $accept
            ? \sprintf('[Bouton ✅] J\'accepte la recette « %s » (recipe_id %d).', $title, $recipeId)
            : \sprintf('[Bouton 🔄] Je ne veux pas de « %s » (recipe_id %d), propose autre chose.%s', $title, $recipeId, null === $comment ? '' : ' Commentaire : '.$comment);

        $input = new UserInput(
            content: $content,
            blocks: [['type' => 'action', 'action' => $accept ? 'accept_recipe' : 'reject_recipe', 'recipe_id' => $recipeId, 'recipe_title' => $title, 'comment' => $comment]],
            kind: $accept ? Turn::KIND_ACCEPT_RECIPE : Turn::KIND_REJECT_RECIPE,
            // Vérifié dans la transaction du tour (après le verrou) : pas de double décision.
            inTransaction: function () use ($sessionRecipe, $accept, $comment, $session, $recipeId): void {
                $this->em->refresh($sessionRecipe);
                if (!$sessionRecipe->isProposed()) {
                    throw new ApiException(409, 'recipe_not_proposed', 'This recipe has already been accepted or rejected.');
                }
                $sessionRecipe->decide($accept ? SessionRecipeStatus::Accepted : SessionRecipeStatus::Rejected, $comment);
                if ($accept) {
                    $this->favorites->incrementAcceptances($session->getUser(), $recipeId);
                }
            },
        );

        return $this->turnRunner->send($session, $input, $clientId);
    }
}
