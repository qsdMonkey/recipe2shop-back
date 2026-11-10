<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Repository\FavoriteRecipeRepository;
use App\Repository\MessageRepository;
use App\Repository\SessionRecipeRepository;
use App\Repository\ShoppingListRepository;

final class SessionPresenter
{
    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessagePresenter $messagePresenter,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly RecipePresenter $recipePresenter,
        private readonly ShoppingListRepository $shoppingLists,
        private readonly ShoppingListPresenter $shoppingListPresenter,
        private readonly FavoriteRecipeRepository $favorites,
    ) {
    }

    /** @return array<string, mixed> SessionSummary */
    public function summary(ShoppingSession $session): array
    {
        return [
            'id' => $session->getId(),
            'type' => $session->getType()->value,
            'status' => $session->getStatus()->value,
            'started_at' => $session->getStartedAt()->format(\DateTimeInterface::ATOM),
            'closed_at' => $session->getClosedAt()?->format(\DateTimeInterface::ATOM),
            'period_days' => $session->getPeriodDays(),
            'last_message_at' => $session->getLastMessageAt()?->format(\DateTimeInterface::ATOM),
            // Repas prévus (set_meal_plan) / couverts (somme des meals_covered des recettes acceptées).
            'progress' => SessionType::Shopping === $session->getType()
                ? ['meals_planned' => $session->getMealsPlanned(), 'meals_covered' => $this->sessionRecipes->mealsCovered($session)]
                : null,
        ];
    }

    /** @return array<string, mixed> SessionDetail */
    public function detail(ShoppingSession $session): array
    {
        $favoriteIds = $this->favorites->recipeIdsFor($session->getUser());

        return $this->summary($session) + [
            'read_only' => !$session->isActive(),
            'messages' => $this->messagePresenter->presentMany($this->messages->findForSession($session)),
            'recipes' => array_map(
                fn ($sr): array => $this->recipePresenter->sessionRecipe($sr, isset($favoriteIds[(int) $sr->getRecipe()->getId()])),
                $this->sessionRecipes->findForSession($session),
            ),
            'shopping_lists' => array_map($this->shoppingListPresenter->summary(...), $this->shoppingLists->findForSession($session)),
        ];
    }
}
