<?php

declare(strict_types=1);

namespace App\Favorite;

use App\Conversation\TurnResult;
use App\Conversation\TurnRunner;
use App\Entity\FavoriteRecipe;
use App\Entity\Message;
use App\Entity\MessageRole;
use App\Entity\Recipe;
use App\Entity\SessionRecipe;
use App\Entity\SessionRecipeStatus;
use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Http\ApiException;
use App\Recipe\RecipeGenerator;
use App\Repository\FavoriteRecipeRepository;
use App\Repository\SessionRecipeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recettes favorites : on ne peut mettre en favori qu'une recette proposée à l'utilisateur
 * (créée pour lui, ou présente dans une de ses sessions).
 */
final class FavoriteService
{
    public function __construct(
        private readonly FavoriteRecipeRepository $favorites,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly TurnRunner $turnRunner,
        private readonly EntityManagerInterface $em,
        private readonly RecipeGenerator $generator,
    ) {
    }

    /** Idempotent. @throws ApiException 404 si la recette n'a jamais été proposée à cet utilisateur */
    public function add(User $user, int $recipeId): void
    {
        if (null !== $this->favorites->findOneFor($user, $recipeId)) {
            return;
        }
        if (!$this->sessionRecipes->isVisibleTo($recipeId, $user)) {
            throw new ApiException(404, 'not_found', 'Recipe not found.');
        }
        $recipe = $this->em->getReference(Recipe::class, $recipeId);
        $this->em->persist(new FavoriteRecipe($user, $recipe, $this->favorites->countAcceptances($user, $recipeId)));
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // requête concurrente : le favori existe déjà, c'est le résultat voulu
        }
    }

    /** Idempotent. */
    public function remove(User $user, int $recipeId): void
    {
        $this->em->getConnection()->delete('favorite_recipe', ['user_id' => $user->getId(), 'recipe_id' => $recipeId]);
    }

    /**
     * Ajout direct d'un favori aux recettes de la session, en accepted, sans IA. Renvoie un « tour » d'un seul
     * message système, que l'app ajoute au fil.
     *
     * @throws ApiException 404 not_found, 409 session_archived / not_shopping_session / recipe_already_in_session / turn_in_progress
     */
    public function addToSession(ShoppingSession $session, int $recipeId): TurnResult
    {
        if (SessionType::Shopping !== $session->getType()) {
            throw new ApiException(409, 'not_shopping_session', 'Favorites can only be added to a shopping session.');
        }
        $user = $session->getUser();
        $favorite = $this->favorites->findOneFor($user, $recipeId)
            ?? throw new ApiException(404, 'not_found', 'Favorite not found.');

        $message = $this->turnRunner->runLocked($session, function () use ($session, $favorite, $user, $recipeId): Message {
            if (null !== $this->sessionRecipes->findOneInSession($session, $recipeId)) {
                throw new ApiException(409, 'recipe_already_in_session', 'This recipe is already in the session.');
            }
            $recipe = $favorite->getRecipe();
            $sessionRecipe = new SessionRecipe($session, $recipe, null, $this->sessionRecipes->nextPosition($session), $this->generator->equipmentWarnings($session, $recipe->getEquipment()));
            $sessionRecipe->decide(SessionRecipeStatus::Accepted); // passe la liste principale en outdated
            $this->em->persist($sessionRecipe);
            $this->favorites->incrementAcceptances($user, $recipeId);

            $text = \sprintf('❤️ « %s » ajoutée depuis tes favoris.', $recipe->getTitle());
            $message = new Message($session, null, MessageRole::System, $text, [['type' => 'text', 'text' => $text]]);
            $this->em->persist($message);
            $session->touch($message->getCreatedAt());
            $this->em->flush();

            return $message;
        });

        return new TurnResult([$message], $session);
    }
}
