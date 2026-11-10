<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthTest extends WebTestCase
{
    private const EMAIL = 'alice@example.com';
    private const PASSWORD = 'correct-horse-battery';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRegisterReturnsTokens(): void
    {
        $body = $this->postJson('/auth/register', ['email' => '  Alice@Example.com ', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(201);
        self::assertIsString($body['token'] ?? null);
        self::assertIsString($body['refresh_token'] ?? null);
        self::assertIsInt($body['refresh_token_expiration'] ?? null);

        // L'email est normalisé (trim + minuscules).
        $me = $this->getJson('/me', $body['token']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $me['email']);
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->register();
        $body = $this->postJson('/auth/register', ['email' => 'ALICE@example.com', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(409, $body['code']);
        self::assertSame('email_taken', $body['error']);
    }

    public function testRegisterValidatesPayload(): void
    {
        $body = $this->postJson('/auth/register', ['email' => 'not-an-email', 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(422, $body['code']);
        self::assertSame('validation_failed', $body['error']);
        self::assertEqualsCanonicalizing(['email', 'password'], array_column($body['violations'], 'field'));
    }

    public function testLoginReturnsTokens(): void
    {
        $this->register();
        $body = $this->postJson('/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        self::assertResponseIsSuccessful();
        self::assertIsString($body['token'] ?? null);
        self::assertIsString($body['refresh_token'] ?? null);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->register();
        $body = $this->postJson('/auth/login', ['email' => self::EMAIL, 'password' => 'wrong-password']);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(401, $body['code']);
        self::assertArrayNotHasKey('token', $body);
    }

    public function testFailedLoginsDoNotBlockJwtRequests(): void
    {
        $tokens = $this->register();
        for ($i = 0; $i < 3; ++$i) {
            $this->postJson('/auth/login', ['email' => self::EMAIL, 'password' => 'wrong-password']);
        }

        $this->getJson('/me', $tokens['token']);
        self::assertResponseIsSuccessful();
    }

    public function testAccessDeniedWithoutToken(): void
    {
        $body = $this->getJson('/me');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(401, $body['code']);
        self::assertSame('unauthorized', $body['error']);
    }

    public function testAccessDeniedWithInvalidToken(): void
    {
        $this->getJson('/me', 'not.a.jwt');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshIssuesNewTokensAndIsSingleUse(): void
    {
        $tokens = $this->register();

        $refreshed = $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        self::assertResponseIsSuccessful();
        self::assertIsString($refreshed['token'] ?? null);
        self::assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);

        $this->getJson('/me', $refreshed['token']);
        self::assertResponseIsSuccessful();

        // Le refresh token est à usage unique.
        $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshRejectsUnknownToken(): void
    {
        $this->postJson('/auth/refresh', ['refresh_token' => 'unknown']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteMeRemovesAccountAndTokens(): void
    {
        $tokens = $this->register();

        $this->deleteMe($tokens['token'], ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(204);

        $this->postJson('/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(401);

        $this->postJson('/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
        self::assertResponseStatusCodeSame(401);

        // Le JWT encore valide ne donne plus accès : l'utilisateur n'existe plus.
        $this->getJson('/me', $tokens['token']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteMeRequiresPassword(): void
    {
        $tokens = $this->register();

        $body = $this->deleteMe($tokens['token'], null);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['password'], array_column($body['violations'], 'field'));

        $body = $this->deleteMe($tokens['token'], []);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['password'], array_column($body['violations'], 'field'));
    }

    public function testDeleteMeRejectsWrongPasswordWith403(): void
    {
        $tokens = $this->register();

        $body = $this->deleteMe($tokens['token'], ['password' => 'wrong-password']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(403, $body['code']);
        self::assertSame('invalid_password', $body['error']);

        // Le compte existe toujours.
        $this->getJson('/me', $tokens['token']);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $payload null = pas de corps du tout
     *
     * @return array<string, mixed>
     */
    private function deleteMe(string $token, ?array $payload): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
        if (null !== $payload) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request('DELETE', '/me', server: $server, content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function register(): array
    {
        $body = $this->postJson('/auth/register', ['email' => self::EMAIL, 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(201);

        return $body;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postJson(string $uri, array $payload): array
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri, ?string $token = null): array
    {
        $this->client->request('GET', $uri, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        return $this->decode();
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }
}
