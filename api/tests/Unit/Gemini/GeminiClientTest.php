<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gemini;

use App\Gemini\GeminiClient;
use App\Gemini\GeminiException;
use App\Gemini\GeminiRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class GeminiClientTest extends TestCase
{
    public function testParsesTextFunctionCallsUsageAndIgnoresThoughts(): void
    {
        $http = new MockHttpClient(new JsonMockResponse([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [
                    ['text' => 'réflexion interne', 'thought' => true],
                    ['text' => 'Bonjour '],
                    ['text' => 'toi'],
                    ['functionCall' => ['name' => 'update_memory', 'args' => ['value' => 'airfryer'], 'id' => 'c1'], 'thoughtSignature' => 'abc'],
                ]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 3],
            'modelVersion' => 'gemini-x-001',
        ]), 'https://example.test/');

        $response = $this->client($http)->generate(new GeminiRequest('system', [['role' => 'user', 'parts' => [['text' => 'hi']]]]));

        self::assertSame('Bonjour toi', $response->text());
        self::assertSame([['name' => 'update_memory', 'args' => ['value' => 'airfryer'], 'id' => 'c1']], $response->functionCalls());
        self::assertSame([10, 5, 3], [$response->inputTokens, $response->outputTokens, $response->thinkingTokens]);
        self::assertSame('gemini-x-001', $response->model);
        self::assertCount(4, $response->parts);
    }

    public function testRequestBody(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return new JsonMockResponse(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]);
        }, 'https://example.test/');

        $this->client($http)->generate(new GeminiRequest('system', [['role' => 'user', 'parts' => [['text' => 'hi']]]], [['name' => 't', 'description' => 'd']], allowToolCalls: false, thinkingLevel: 'low'));

        self::assertSame('https://example.test/v1beta/models/gemini-test:generateContent', $sent['url']);
        self::assertSame('system', $sent['body']['systemInstruction']['parts'][0]['text']);
        self::assertSame([['functionDeclarations' => [['name' => 't', 'description' => 'd']]]], $sent['body']['tools']);
        self::assertSame('NONE', $sent['body']['toolConfig']['functionCallingConfig']['mode']);
        self::assertSame(8192, $sent['body']['generationConfig']['maxOutputTokens']);
        self::assertSame(['thinkingLevel' => 'low'], $sent['body']['generationConfig']['thinkingConfig']);
    }

    public function testNoThinkingConfigByDefault(): void
    {
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent) {
            $sent = json_decode($options['body'], true);

            return new JsonMockResponse(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]);
        }, 'https://example.test/');

        $this->client($http)->generate($this->request());

        self::assertArrayNotHasKey('thinkingConfig', $sent['generationConfig']);
    }

    public function testRetriesOnceOnServerError(): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse(['error' => ['message' => 'overloaded']], ['http_code' => 503]),
            new JsonMockResponse(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]),
        ], 'https://example.test/');

        self::assertSame('ok', $this->client($http)->generate($this->request())->text());
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testThrowsAfterSecondServerError(): void
    {
        $http = new MockHttpClient([
            new JsonMockResponse(['error' => ['message' => 'overloaded']], ['http_code' => 503]),
            new JsonMockResponse(['error' => ['message' => 'overloaded']], ['http_code' => 503]),
        ], 'https://example.test/');

        $this->expectException(GeminiException::class);
        $this->expectExceptionMessage('Gemini HTTP 503: overloaded');
        $this->client($http)->generate($this->request());
    }

    public function testMaxDurationDisablesRetryAndSetsTimeouts(): void
    {
        $options = null;
        $http = new MockHttpClient(function (string $method, string $url, array $opts) use (&$options) {
            $options = $opts;

            return new JsonMockResponse(['error' => ['message' => 'overloaded']], ['http_code' => 503]);
        }, 'https://example.test/');

        try {
            $this->client($http)->generate(new GeminiRequest('system', [['role' => 'user', 'parts' => [['text' => 'hi']]]], maxDurationSeconds: 8.0));
            self::fail('Expected exception');
        } catch (GeminiException) {
        }

        self::assertSame(1, $http->getRequestsCount());
        self::assertSame(8.0, $options['max_duration']);
    }

    public function testThrowsWhenPromptIsBlocked(): void
    {
        $http = new MockHttpClient(new JsonMockResponse(['promptFeedback' => ['blockReason' => 'SAFETY']]), 'https://example.test/');

        $this->expectException(GeminiException::class);
        $this->expectExceptionMessage('SAFETY');
        $this->client($http)->generate($this->request());
    }

    public function testRefusesPlaceholderApiKey(): void
    {
        $http = new MockHttpClient([], 'https://example.test/');

        $this->expectException(GeminiException::class);
        (new GeminiClient($http, 'gemini-test', 'changeme'))->generate($this->request());
    }

    private function client(MockHttpClient $http): GeminiClient
    {
        return new GeminiClient($http, 'gemini-test', 'key', 8192, retryDelayMs: 0);
    }

    private function request(): GeminiRequest
    {
        return new GeminiRequest('system', [['role' => 'user', 'parts' => [['text' => 'hi']]]]);
    }
}
