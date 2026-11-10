<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeGemini;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        FakeGemini::reset();
    }

    /** Crée un compte et renvoie son access token. */
    protected function registerUser(string $email = 'alice@example.com'): string
    {
        $body = $this->request('POST', '/auth/register', ['email' => $email, 'password' => 'correct-horse-battery']);
        self::assertResponseStatusCodeSame(201);

        return $body['token'];
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    protected function request(string $method, string $uri, ?array $payload = null, ?string $token = null): array
    {
        $server = [];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $payload) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $this->client->request($method, $uri, server: $server, content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();

        return '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
    }
}
