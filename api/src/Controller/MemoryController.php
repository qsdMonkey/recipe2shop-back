<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MemoryCreateRequest;
use App\Dto\MemoryUpdateRequest;
use App\Entity\MemoryCategory;
use App\Entity\MemoryOrigin;
use App\Entity\User;
use App\Entity\UserMemory;
use App\Http\ApiException;
use App\Memory\MemoryChange;
use App\Memory\MemoryException;
use App\Memory\MemoryManager;
use App\Presenter\MemoryPresenter;
use App\Repository\MemoryEventRepository;
use App\Repository\UserMemoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Page « Ce que l'IA sait de moi » + bouton « Annuler » des puces mémoire du chat.
 */
final class MemoryController
{
    public function __construct(
        private readonly MemoryManager $memory,
        private readonly UserMemoryRepository $items,
        private readonly MemoryEventRepository $events,
        private readonly MemoryPresenter $presenter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/me/memory', name: 'memory_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['categories' => $this->presenter->grouped($this->items->findForUser($user))]);
    }

    #[Route('/me/memory', name: 'memory_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] MemoryCreateRequest $payload): JsonResponse
    {
        $change = $this->apply(fn (): MemoryChange => $this->memory->add($user, MemoryCategory::from($payload->category), $payload->value, MemoryOrigin::User));

        return $this->changeResponse($change, $change->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/me/memory/{id}', name: 'memory_update', requirements: ['id' => Requirement::DIGITS], methods: ['PATCH'])]
    public function update(#[CurrentUser] User $user, int $id, #[MapRequestPayload] MemoryUpdateRequest $payload): JsonResponse
    {
        $item = $this->findItem($user, $id);
        $change = $this->apply(fn (): MemoryChange => $this->memory->update($item, $payload->value, MemoryOrigin::User));

        return $this->changeResponse($change, Response::HTTP_OK);
    }

    #[Route('/me/memory/{id}', name: 'memory_delete', requirements: ['id' => Requirement::DIGITS], methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, int $id): Response
    {
        $item = $this->findItem($user, $id);
        $this->apply(fn (): MemoryChange => $this->memory->delete($item, MemoryOrigin::User));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/me/memory/events/{id}/undo', name: 'memory_event_undo', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function undo(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $event = $this->events->findOneOwnedBy($id, $user) ?? throw new ApiException(404, 'not_found', 'Memory event not found.');

        try {
            $this->memory->undo($event);
            $this->em->flush();
        } catch (MemoryException $e) {
            throw new ApiException(409, $e->errorCode, $e->getMessage());
        }

        return new JsonResponse(['event' => $this->presenter->event($event)]);
    }

    /** @param callable(): MemoryChange $operation */
    private function apply(callable $operation): MemoryChange
    {
        try {
            $change = $operation();
            $this->em->flush();
        } catch (MemoryException $e) {
            throw new ApiException('validation_failed' === $e->errorCode ? 422 : 409, $e->errorCode, $e->getMessage());
        }

        return $change;
    }

    private function changeResponse(MemoryChange $change, int $status): JsonResponse
    {
        return new JsonResponse([
            'item' => null === $change->item ? null : $this->presenter->item($change->item),
            'event' => $this->presenter->event($change->event),
        ], $status);
    }

    private function findItem(User $user, int $id): UserMemory
    {
        return $this->items->findOneOwnedBy($id, $user) ?? throw new ApiException(404, 'not_found', 'Memory item not found.');
    }
}
