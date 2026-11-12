<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Faux Gemini branché sur le client HTTP en env test (framework.http_client.mock_response_factory).
 * Les tests programment les réponses à l'avance et inspectent les requêtes envoyées.
 * État statique : il survit au reboot du kernel entre deux requêtes du client de test.
 */
final class FakeGemini
{
    /** @var list<array<string, mixed>|int> */
    private static array $queue = [];

    /** @var list<array{url: string, body: array<string, mixed>, raw: string, headers: list<string>}> */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$requests = [];
    }

    public static function queueText(string $text): void
    {
        self::$queue[] = self::candidate([['text' => $text]]);
    }

    /** @param array<string, mixed> $args */
    public static function queueFunctionCall(string $name, array $args, ?string $text = null, ?string $id = null): void
    {
        $call = ['name' => $name, 'args' => [] === $args ? new \stdClass() : $args] + (null === $id ? [] : ['id' => $id]);
        $parts = null === $text ? [] : [['text' => $text]];
        $parts[] = ['functionCall' => $call, 'thoughtSignature' => 'sig-'.$name];
        self::$queue[] = self::candidate($parts);
    }

    /** Réponse JSON (appel avec responseSchema). @param array<string, mixed> $data */
    public static function queueJson(array $data): void
    {
        self::$queue[] = self::candidate([['text' => json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)]]);
    }

    public static function queueHttpError(int $status, ?string $message = null): void
    {
        self::$queue[] = null === $message ? $status : ['__error' => $status, 'message' => $message];
    }

    /** @param array<string, mixed> $data */
    public static function queueRaw(array $data): void
    {
        self::$queue[] = $data;
    }

    public static function remaining(): int
    {
        return \count(self::$queue);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        if (!str_contains($url, 'generativelanguage.googleapis.com')) {
            throw new \LogicException('Unexpected HTTP call in tests: '.$url);
        }

        self::$requests[] = [
            'url' => $url,
            'body' => json_decode((string) ($options['body'] ?? '{}'), true, flags: \JSON_THROW_ON_ERROR),
            'raw' => (string) ($options['body'] ?? ''),
            'headers' => $options['headers'] ?? [],
        ];

        $next = array_shift(self::$queue) ?? throw new \LogicException('FakeGemini: no response queued for '.$url);
        if (\is_array($next) && isset($next['__error'])) {
            return new MockResponse(json_encode(['error' => ['code' => $next['__error'], 'message' => $next['message']]]), ['http_code' => $next['__error'], 'response_headers' => ['content-type' => 'application/json']]);
        }
        if (\is_int($next)) {
            return new MockResponse(json_encode(['error' => ['code' => $next, 'message' => 'fake error']]), ['http_code' => $next, 'response_headers' => ['content-type' => 'application/json']]);
        }

        return new MockResponse(json_encode($next, \JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param list<array<string, mixed>> $parts
     *
     * @return array<string, mixed>
     */
    private static function candidate(array $parts): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 100, 'thoughtsTokenCount' => 50, 'totalTokenCount' => 1150],
            'modelVersion' => 'gemini-test-001',
        ];
    }
}
