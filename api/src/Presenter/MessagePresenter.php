<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\Message;
use App\Repository\FavoriteRecipeRepository;
use App\Repository\MemoryEventRepository;
use App\Repository\SessionRecipeRepository;
use App\Repository\ShoppingListRepository;

/**
 * Message → JSON du contrat API. Les cartes sont stockées sous forme de référence (ex. {"type":"memory","event_id":3})
 * et hydratées ici, pour toujours refléter l'état actuel (ex. puce annulée).
 */
final class MessagePresenter
{
    /**
     * Ordre des clés des blocs stockés tels quels (MySQL réordonne les clés des objets JSON stockés) :
     * garantit une sortie identique entre la réponse d'un tour et son rejeu (idempotence).
     */
    private const KEY_ORDER = [
        'text' => ['type', 'text'],
        'action' => ['type', 'action', 'recipe_id', 'recipe_title', 'comment'],
        'new_session' => ['type', 'reason'],
        'finish_onboarding' => ['type'],
        'cooking_guide' => ['type', 'recipe_id', 'title', 'servings'],
    ];

    public function __construct(
        private readonly MemoryEventRepository $memoryEvents,
        private readonly MemoryPresenter $memoryPresenter,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly RecipePresenter $recipePresenter,
        private readonly ShoppingListRepository $shoppingLists,
        private readonly ShoppingListPresenter $shoppingListPresenter,
        private readonly FavoriteRecipeRepository $favorites,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(Message $message): array
    {
        return $this->presentMany([$message])[0];
    }

    /**
     * @param iterable<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $messages): array
    {
        $messages = [...$messages];

        // Chargement groupé des références (une requête pour tous les messages).
        $eventIds = $sessionRecipeIds = $listIds = [];
        foreach ($messages as $message) {
            foreach ($message->getBlocks() as $block) {
                if ('memory' === ($block['type'] ?? null) && isset($block['event_id'])) {
                    $eventIds[] = (int) $block['event_id'];
                }
                if ('recipe' === ($block['type'] ?? null) && isset($block['session_recipe_id'])) {
                    $sessionRecipeIds[] = (int) $block['session_recipe_id'];
                }
                if ('shopping_list' === ($block['type'] ?? null) && isset($block['list_id'])) {
                    $listIds[] = (int) $block['list_id'];
                }
            }
        }
        $refs = [
            'events' => $this->memoryEvents->findByIds(array_values(array_unique($eventIds))),
            'session_recipes' => $this->sessionRecipes->findByIds(array_values(array_unique($sessionRecipeIds))),
            'lists' => $this->shoppingLists->findByIds(array_values(array_unique($listIds))),
            'favorites' => [],
        ];
        // Favoris des propriétaires des cartes (en pratique un seul utilisateur).
        foreach ($refs['session_recipes'] as $sr) {
            $userId = (int) $sr->getSession()->getUser()->getId();
            $refs['favorites'][$userId] ??= $this->favorites->recipeIdsFor($sr->getSession()->getUser());
        }

        $result = [];
        foreach ($messages as $message) {
            $blocks = [];
            foreach ($message->getBlocks() as $block) {
                $block = $this->hydrate($block, $refs);
                if (null !== $block) {
                    $blocks[] = $block;
                }
            }
            $result[] = [
                'id' => $message->getId(),
                'role' => $message->getRole()->value,
                'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'blocks' => $blocks,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed>                                                                                            $block
     * @param array{events: array<int, \App\Entity\MemoryEvent>, session_recipes: array<int, \App\Entity\SessionRecipe>, lists: array<int, \App\Entity\ShoppingList>, favorites: array<int, array<int, true>>} $refs
     *
     * @return array<string, mixed>|null null = bloc à ne plus afficher (référence disparue)
     */
    private function hydrate(array $block, array $refs): ?array
    {
        if ('memory' === $block['type'] && isset($block['event_id'])) {
            $event = $refs['events'][(int) $block['event_id']] ?? null;

            return null === $event ? null : ['type' => 'memory', 'event' => $this->memoryPresenter->event($event)];
        }
        if ('shopping_list' === $block['type'] && isset($block['list_id'])) {
            $list = $refs['lists'][(int) $block['list_id']] ?? null;

            return null === $list ? null : ['type' => 'shopping_list', 'list' => $this->shoppingListPresenter->summary($list)];
        }
        if ('recipe' === $block['type'] && isset($block['session_recipe_id'])) {
            $sessionRecipe = $refs['session_recipes'][(int) $block['session_recipe_id']] ?? null;

            if (null === $sessionRecipe) {
                return null;
            }
            $favorite = isset($refs['favorites'][(int) $sessionRecipe->getSession()->getUser()->getId()][(int) $sessionRecipe->getRecipe()->getId()]);

            return ['type' => 'recipe'] + $this->recipePresenter->sessionRecipe($sessionRecipe, $favorite);
        }

        $order = self::KEY_ORDER[$block['type']] ?? ['type'];

        return array_merge(array_intersect_key(array_fill_keys($order, null), $block), $block);
    }
}
