<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Favorite\FavoriteService;
use App\Http\ApiException;
use App\Presenter\MessagePresenter;
use App\Presenter\RecipePresenter;
use App\Presenter\SessionPresenter;
use App\Repository\FavoriteRecipeRepository;
use App\Repository\ShoppingSessionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class FavoriteController
{
    public function __construct(
        private readonly FavoriteService $favoriteService,
        private readonly FavoriteRecipeRepository $favorites,
        private readonly RecipePresenter $recipePresenter,
        private readonly ShoppingSessionRepository $sessions,
        private readonly MessagePresenter $messagePresenter,
        private readonly SessionPresenter $sessionPresenter,
    ) {
    }

    #[Route('/me/favorites', name: 'favorites_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $favorites = [];
        foreach ($this->favorites->findForUser($user) as $favorite) {
            $favorites[] = [
                'recipe' => $this->recipePresenter->recipe($favorite->getRecipe()),
                'favorited_at' => $favorite->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'times_accepted' => $favorite->getTimesAccepted(),
            ];
        }

        return new JsonResponse(['favorites' => $favorites]);
    }

    #[Route('/me/favorites/{recipeId}', name: 'favorites_add', requirements: ['recipeId' => Requirement::DIGITS], methods: ['PUT'])]
    public function add(#[CurrentUser] User $user, int $recipeId): JsonResponse
    {
        $this->favoriteService->add($user, $recipeId);

        return new JsonResponse(['recipe_id' => $recipeId, 'favorite' => true]);
    }

    #[Route('/me/favorites/{recipeId}', name: 'favorites_remove', requirements: ['recipeId' => Requirement::DIGITS], methods: ['DELETE'])]
    public function remove(#[CurrentUser] User $user, int $recipeId): JsonResponse
    {
        $this->favoriteService->remove($user, $recipeId);

        return new JsonResponse(['recipe_id' => $recipeId, 'favorite' => false]);
    }

    #[Route('/sessions/{id}/favorites/{recipeId}', name: 'sessions_add_favorite', requirements: ['id' => Requirement::DIGITS, 'recipeId' => Requirement::DIGITS], methods: ['POST'])]
    public function addToSession(#[CurrentUser] User $user, int $id, int $recipeId): JsonResponse
    {
        $session = $this->sessions->findOneOwnedBy($id, $user)
            ?? throw new ApiException(404, 'not_found', 'Session not found.');
        $result = $this->favoriteService->addToSession($session, $recipeId);

        return new JsonResponse([
            'messages' => $this->messagePresenter->presentMany($result->messages),
            'session' => $this->sessionPresenter->summary($result->session),
        ]);
    }
}
