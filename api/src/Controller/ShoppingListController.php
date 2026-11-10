<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ShoppingItemCreateRequest;
use App\Entity\ShoppingItemOrigin;
use App\Entity\ShoppingList;
use App\Entity\ShoppingListItem;
use App\Entity\User;
use App\Http\ApiException;
use App\Presenter\ShoppingListPresenter;
use App\Recipe\RecipeVocabulary;
use App\Repository\ShoppingListRepository;
use App\ShoppingList\ShoppingListExporter;
use App\ShoppingList\ShoppingListService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ShoppingListController
{
    public function __construct(
        private readonly ShoppingListRepository $lists,
        private readonly ShoppingListService $service,
        private readonly ShoppingListPresenter $presenter,
        private readonly ShoppingListExporter $exporter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/shopping-lists/{id}', name: 'shopping_list_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(#[CurrentUser] User $user, int $id): JsonResponse
    {
        return new JsonResponse($this->presenter->detail($this->findList($user, $id)));
    }

    #[Route('/shopping-lists/{id}/items', name: 'shopping_list_item_create', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function addItem(#[CurrentUser] User $user, int $id, #[MapRequestPayload] ShoppingItemCreateRequest $payload): JsonResponse
    {
        $list = $this->writableList($user, $id);
        [$item, $merged] = $this->service->addItem($list, ShoppingItemOrigin::Manual, $payload->label, null === $payload->quantity ? null : (float) $payload->quantity, $payload->unit, $payload->category);
        $this->em->flush();

        return $this->itemResponse($item, $merged ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    #[Route('/shopping-lists/{id}/items/{itemId}', name: 'shopping_list_item_update', requirements: ['id' => Requirement::DIGITS, 'itemId' => Requirement::DIGITS], methods: ['PATCH'])]
    public function updateItem(#[CurrentUser] User $user, int $id, int $itemId, Request $request): JsonResponse
    {
        $item = $this->findItem($this->writableList($user, $id), $itemId);
        $this->service->updateItem($item, $this->validatedChanges($request));
        $this->em->flush();

        return $this->itemResponse($item, Response::HTTP_OK);
    }

    #[Route('/shopping-lists/{id}/items/{itemId}', name: 'shopping_list_item_delete', requirements: ['id' => Requirement::DIGITS, 'itemId' => Requirement::DIGITS], methods: ['DELETE'])]
    public function deleteItem(#[CurrentUser] User $user, int $id, int $itemId): Response
    {
        $this->service->deleteItem($this->findItem($this->writableList($user, $id), $itemId));
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/shopping-lists/{id}/regenerate', name: 'shopping_list_regenerate', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function regenerate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $list = $this->writableList($user, $id);
        $this->service->regenerate($list);
        $this->em->flush();

        return new JsonResponse($this->presenter->detail($list));
    }

    #[Route('/shopping-lists/{id}/export', name: 'shopping_list_export', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function export(#[CurrentUser] User $user, int $id, #[MapQueryParameter] string $format = 'text'): Response
    {
        if ('text' !== $format) {
            throw new ApiException(422, 'validation_failed', 'Only format=text is supported.');
        }

        return new Response($this->exporter->export($this->findList($user, $id)), Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * PATCH partiel : seuls les champs présents sont modifiés (validation à la main, pour distinguer « absent » de null).
     *
     * @return array{checked?: bool, label?: string, quantity?: ?float, unit?: ?string, category?: string}
     */
    private function validatedChanges(Request $request): array
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            throw new ApiException(400, 'bad_request', 'Invalid JSON body.');
        }

        $changes = [];
        $errors = [];
        if (\array_key_exists('checked', $data)) {
            \is_bool($data['checked']) ? $changes['checked'] = $data['checked'] : $errors[] = 'checked must be a boolean.';
        }
        if (\array_key_exists('label', $data)) {
            \is_string($data['label']) && '' !== trim($data['label']) && mb_strlen(trim($data['label'])) <= 100 ? $changes['label'] = $data['label'] : $errors[] = 'label must be 1 to 100 characters.';
        }
        if (\array_key_exists('quantity', $data)) {
            null === $data['quantity'] || (is_numeric($data['quantity']) && $data['quantity'] > 0 && $data['quantity'] <= 100000) ? $changes['quantity'] = null === $data['quantity'] ? null : (float) $data['quantity'] : $errors[] = 'quantity must be a positive number or null.';
        }
        if (\array_key_exists('unit', $data)) {
            null === $data['unit'] || \in_array($data['unit'], RecipeVocabulary::UNITS, true) ? $changes['unit'] = $data['unit'] : $errors[] = 'unit is not valid.';
        }
        if (\array_key_exists('category', $data)) {
            \in_array($data['category'], RecipeVocabulary::CATEGORIES, true) ? $changes['category'] = $data['category'] : $errors[] = 'category is not valid.';
        }
        if ([] !== $errors) {
            throw new ApiException(422, 'validation_failed', implode(' ', $errors));
        }

        return $changes;
    }

    private function itemResponse(ShoppingListItem $item, int $status): JsonResponse
    {
        return new JsonResponse(['item' => $this->presenter->item($item), 'list' => $this->service->counts($item->getList())], $status);
    }

    private function findList(User $user, int $id): ShoppingList
    {
        return $this->lists->findOneOwnedBy($id, $user) ?? throw new ApiException(404, 'not_found', 'Shopping list not found.');
    }

    private function writableList(User $user, int $id): ShoppingList
    {
        $list = $this->findList($user, $id);
        if (!$list->getSession()->isActive()) {
            throw new ApiException(409, 'session_archived', 'This session is archived (read-only).');
        }

        return $list;
    }

    private function findItem(ShoppingList $list, int $itemId): ShoppingListItem
    {
        foreach ($list->getItems() as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }

        throw new ApiException(404, 'not_found', 'Item not found.');
    }
}
