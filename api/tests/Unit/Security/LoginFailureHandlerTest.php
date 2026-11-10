<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\LoginFailureHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Le seuil de login_throttling est relevé en env test : on vérifie ici le format de la réponse 429.
 */
final class LoginFailureHandlerTest extends TestCase
{
    public function testTooManyAttemptsReturns429(): void
    {
        $response = $this->handler()->onAuthenticationFailure(new Request(), new TooManyLoginAttemptsAuthenticationException(2));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('120', $response->headers->get('Retry-After'));
        self::assertSame(
            ['code' => 429, 'error' => 'rate_limited', 'message' => 'Too many failed login attempts, please try again in 2 minutes.'],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testOtherFailuresAreDelegatedToLexik(): void
    {
        self::assertSame(401, $this->handler()->onAuthenticationFailure(new Request(), new BadCredentialsException())->getStatusCode());
    }

    private function handler(): LoginFailureHandler
    {
        $lexik = $this->createStub(AuthenticationFailureHandlerInterface::class);
        $lexik->method('onAuthenticationFailure')->willReturn(new JsonResponse(['code' => 401, 'message' => 'Invalid credentials.'], 401));

        return new LoginFailureHandler($lexik);
    }
}
