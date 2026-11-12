<?php

declare(strict_types=1);

namespace App\Controller;

use App\Conversation\SessionManager;
use App\Conversation\TurnResult;
use App\Conversation\TurnRunner;
use App\Conversation\RecipeDecision;
use App\Conversation\Photo;
use App\Conversation\UserInput;
use App\Dto\RecipeDecisionRequest;
use App\Presenter\RecipePresenter;
use App\Repository\SessionRecipeRepository;
use App\Dto\CreateSessionRequest;
use App\Dto\SendMessageRequest;
use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Http\ApiException;
use App\Presenter\MessagePresenter;
use App\Presenter\SessionPresenter;
use App\Repository\MessageRepository;
use App\Repository\ShoppingSessionRepository;
use App\Entity\Recipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class SessionController
{
    /** Nombre de sessions archivées affichées dans l'historique (règle des 3 : 1 active + 2 archivées). */
    private const ARCHIVED_SHOWN = 2;

    public function __construct(
        private readonly ShoppingSessionRepository $sessions,
        private readonly MessageRepository $messages,
        private readonly SessionManager $sessionManager,
        private readonly TurnRunner $turnRunner,
        private readonly SessionPresenter $sessionPresenter,
        private readonly MessagePresenter $messagePresenter,
        private readonly SessionRecipeRepository $sessionRecipes,
        private readonly RecipePresenter $recipePresenter,
        private readonly EntityManagerInterface $em,
        private readonly RecipeDecision $recipeDecision,
    ) {
    }

    #[Route('/sessions', name: 'sessions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $active = $this->sessions->findActive($user, SessionType::Shopping);

        return new JsonResponse([
            'active' => null === $active ? null : $this->sessionPresenter->summary($active),
            'archived' => array_map($this->sessionPresenter->summary(...), $this->sessions->findArchived($user, SessionType::Shopping, self::ARCHIVED_SHOWN)),
        ]);
    }

    #[Route('/sessions', name: 'sessions_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] ?CreateSessionRequest $payload = null): JsonResponse
    {
        [$session, $created] = $this->sessionManager->start($user, SessionType::from(($payload ?? new CreateSessionRequest())->type));

        return new JsonResponse($this->sessionPresenter->detail($session), $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/sessions/{id}', name: 'sessions_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(#[CurrentUser] User $user, int $id): JsonResponse
    {
        return new JsonResponse($this->sessionPresenter->detail($this->findSession($user, $id)));
    }

    #[Route('/sessions/{id}/messages', name: 'sessions_messages', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function messages(#[CurrentUser] User $user, int $id, #[MapQueryParameter(name: 'after_id')] ?int $afterId = null): JsonResponse
    {
        $session = $this->findSession($user, $id);

        return new JsonResponse(['messages' => $this->messagePresenter->presentMany($this->messages->findForSession($session, $afterId))]);
    }

    #[Route('/sessions/{id}/messages', name: 'sessions_send_message', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function send(#[CurrentUser] User $user, int $id, #[MapRequestPayload] SendMessageRequest $payload): JsonResponse
    {
        $session = $this->findSession($user, $id);
        $text = trim($payload->text);
        if (null === $payload->photo) {
            $input = UserInput::text($text);
        } else {
            if (SessionType::Shopping !== $session->getType()) {
                throw new ApiException(409, 'not_shopping_session', 'Photos are only accepted in shopping sessions.');
            }
            $input = UserInput::withPhoto($text, Photo::fromPayload($payload->photo), (string) $payload->clientId);
        }
        $result = $this->turnRunner->send($session, $input, $payload->clientId);

        return $this->turnResponse($result);
    }

    #[Route('/sessions/{id}/recipes/{recipeId}/{decision}', name: 'sessions_recipe_decision', requirements: ['id' => Requirement::DIGITS, 'recipeId' => Requirement::DIGITS, 'decision' => 'accept|reject'], methods: ['POST'])]
    public function decideRecipe(#[CurrentUser] User $user, int $id, int $recipeId, string $decision, #[MapRequestPayload] ?RecipeDecisionRequest $payload = null): JsonResponse
    {
        $payload ??= new RecipeDecisionRequest();

        return $this->turnResponse($this->recipeDecision->decide($this->findSession($user, $id), $recipeId, 'accept' === $decision, $payload->comment, $payload->clientId));
    }

    #[Route('/recipes/{id}', name: 'recipes_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function recipe(#[CurrentUser] User $user, int $id, #[MapQueryParameter] ?int $servings = null): JsonResponse
    {
        if (!$this->sessionRecipes->isVisibleTo($id, $user)) {
            throw new ApiException(404, 'not_found', 'Recipe not found.');
        }
        if (null !== $servings && ($servings < 1 || $servings > 50)) {
            throw new ApiException(422, 'validation_failed', 'servings must be between 1 and 50.');
        }
        $recipe = $this->em->find(Recipe::class, $id) ?? throw new ApiException(404, 'not_found', 'Recipe not found.');

        return new JsonResponse($this->recipePresenter->recipe($recipe, $servings));
    }

    #[Route('/sessions/{id}/finish-onboarding', name: 'sessions_finish_onboarding', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    public function finishOnboarding(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $session = $this->findSession($user, $id);
        $this->sessionManager->finishOnboarding($user, $session);

        return new JsonResponse($this->sessionPresenter->summary($session));
    }

    private function turnResponse(TurnResult $result): JsonResponse
    {
        return new JsonResponse([
            'messages' => $this->messagePresenter->presentMany($result->messages),
            'session' => $this->sessionPresenter->summary($result->session),
        ]);
    }

    private function findSession(User $user, int $id): ShoppingSession
    {
        return $this->sessions->findOneOwnedBy($id, $user)
            ?? throw new ApiException(404, 'not_found', 'Session not found.');
    }
}
