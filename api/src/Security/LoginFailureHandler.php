<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Échec de POST /auth/login : 429 si trop de tentatives (login_throttling), sinon 401 standard de LexikJWT.
 */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_failure')]
        private readonly AuthenticationFailureHandlerInterface $jwtFailureHandler,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $minutes = (int) ($exception->getMessageData()['%minutes%'] ?? 1);

            return new JsonResponse(
                ['code' => Response::HTTP_TOO_MANY_REQUESTS, 'error' => 'rate_limited', 'message' => strtr($exception->getMessageKey(), $exception->getMessageData())],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) ($minutes * 60)],
            );
        }

        return $this->jwtFailureHandler->onAuthenticationFailure($request, $exception);
    }
}
