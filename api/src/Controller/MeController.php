<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DeleteAccountRequest;
use App\Entity\RefreshToken;
use App\Entity\User;
use App\Http\ApiException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Gesdinet\JWTRefreshTokenBundle\Doctrine\DeleteRefreshTokenRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class MeController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/me', name: 'me_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'created_at' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'onboarding_completed' => $user->isOnboardingCompleted(),
        ]);
    }

    /**
     * Suppression du compte et de toutes ses données (RGPD). Corps : {"password": "..."}.
     * Mot de passe absent → 422 ; incorrect → 403 (pas 401, pour que l'app ne tente pas un refresh).
     * Les recettes créées par l'utilisateur seront conservées, anonymisées (recipe.created_by → NULL, FK ON DELETE SET NULL).
     * Les tables liées à l'utilisateur (sessions, mémoire…) devront être supprimées en cascade (FK ON DELETE CASCADE).
     */
    #[Route('/me', name: 'me_delete', methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, #[MapRequestPayload] ?DeleteAccountRequest $payload = null): Response
    {
        // Corps absent : même réponse 422 qu'un mot de passe vide.
        $payload ??= new DeleteAccountRequest();
        $violations = $this->validator->validate($payload);
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException('', new ValidationFailedException($payload, $violations));
        }

        if (!$this->passwordHasher->isPasswordValid($user, $payload->password)) {
            throw new ApiException(403, 'invalid_password', 'Invalid password.');
        }

        $this->logger->info('auth.account_deleted', ['user_id' => $user->getId()]);
        $this->em->wrapInTransaction(function () use ($user): void {
            $refreshTokens = $this->em->getRepository(RefreshToken::class);
            \assert($refreshTokens instanceof DeleteRefreshTokenRepositoryInterface);
            $refreshTokens->deleteByUser($user);

            $this->em->remove($user);
        });

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
