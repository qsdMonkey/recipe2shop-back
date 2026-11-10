<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Http\ApiException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /auth/login et POST /auth/refresh sont gérés par le firewall (json_login / refresh_jwt),
 * voir config/packages/security.yaml.
 */
final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private readonly AuthenticationSuccessHandler $authenticationSuccess,
        #[Autowire(service: 'limiter.register')]
        private readonly RateLimiterFactoryInterface $registerLimiter,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/auth/register', name: 'auth_register', methods: ['POST'])]
    public function register(#[MapRequestPayload] RegisterRequest $payload, Request $request): Response
    {
        $limit = $this->registerLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            throw new ApiException(429, 'rate_limited', 'Too many registrations, try again later.', ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }

        if (null !== $this->users->findOneByEmail($payload->email)) {
            throw new ApiException(409, 'email_taken', 'An account already exists with this email.');
        }

        $user = new User($payload->email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $payload->password));

        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux inscriptions simultanées avec le même email.
            throw new ApiException(409, 'email_taken', 'An account already exists with this email.');
        }

        $this->logger->info('auth.registered', ['user_id' => $user->getId(), 'email' => $user->getEmail(), 'ip' => $request->getClientIp()]);

        // Même réponse que /auth/login (token + refresh_token) : l'app enchaîne directement sur l'onboarding.
        $response = $this->authenticationSuccess->handleAuthenticationSuccess($user);
        $response->setStatusCode(Response::HTTP_CREATED);

        return $response;
    }
}
