<?php

declare(strict_types=1);

namespace App\Gemini;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appel à l'API Gemini (generateContent, REST) avec function calling.
 * La clé API est portée par le client HTTP « gemini.client » (header x-goog-api-key, config/packages/framework.yaml).
 */
final class GeminiClient
{
    private const RETRYABLE_STATUSES = [429, 500, 503];

    public function __construct(
        #[Autowire(service: 'gemini.client')]
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(GEMINI_MODEL)%')]
        private readonly string $model,
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(int:GEMINI_MAX_OUTPUT_TOKENS)%')]
        private readonly int $maxOutputTokens = 8192,
        private readonly ?GeminiBudget $budget = null,
        private readonly int $retryDelayMs = 1000,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generate(GeminiRequest $request): GeminiResponse
    {
        if ('' === $this->apiKey || 'changeme' === $this->apiKey) {
            throw new GeminiException('GEMINI_API_KEY is not configured.');
        }
        $this->budget?->assertAvailable();

        $start = hrtime(true);
        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                $response = $this->httpClient->request('POST', \sprintf('v1beta/models/%s:generateContent', rawurlencode($this->model)), [
                    'json' => $this->buildBody($request),
                ] + (null === $request->maxDurationSeconds ? [] : ['timeout' => $request->maxDurationSeconds, 'max_duration' => $request->maxDurationSeconds]));
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (HttpClientException $e) {
                throw new GeminiException('Gemini transport error: '.$e->getMessage(), 0, $e);
            }

            if (\in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < 2 && null === $request->maxDurationSeconds) {
                usleep($this->retryDelayMs * 1000);
                continue;
            }
            if ($status >= 400) {
                throw new GeminiException(\sprintf('Gemini HTTP %d: %s', $status, $data['error']['message'] ?? 'unknown error'));
            }
            break;
        }

        $candidate = $data['candidates'][0] ?? null;
        if (!\is_array($candidate)) {
            $reason = $data['promptFeedback']['blockReason'] ?? 'no candidate';
            throw new GeminiException('Gemini returned no answer: '.$reason);
        }

        $usage = $data['usageMetadata'] ?? [];

        return new GeminiResponse(
            parts: array_map(self::preserveEmptyArgs(...), array_values(array_filter($candidate['content']['parts'] ?? [], 'is_array'))),
            finishReason: $candidate['finishReason'] ?? null,
            inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
            outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            thinkingTokens: (int) ($usage['thoughtsTokenCount'] ?? 0),
            model: (string) ($data['modelVersion'] ?? $this->model),
            durationMs: (int) ((hrtime(true) - $start) / 1_000_000),
        );
    }

    /**
     * Les parts sont renvoyées telles quelles à Gemini dans la suite du tour. Un appel d'outil sans argument
     * ("args": {}) serait réencodé en liste vide ([]) par PHP, ce que Gemini refuse : on garde un objet.
     *
     * @param array<string, mixed> $part
     *
     * @return array<string, mixed>
     */
    private static function preserveEmptyArgs(array $part): array
    {
        if (isset($part['functionCall']) && \is_array($part['functionCall']) && [] === ($part['functionCall']['args'] ?? [])) {
            $part['functionCall']['args'] = new \stdClass();
        }

        return $part;
    }

    /** @return array<string, mixed> */
    private function buildBody(GeminiRequest $request): array
    {
        $body = [
            'systemInstruction' => ['parts' => [['text' => $request->systemInstruction]]],
            'contents' => $request->contents,
            'generationConfig' => ['maxOutputTokens' => $this->maxOutputTokens],
        ];
        if (null !== $request->responseSchema) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $request->responseSchema;
        }
        if (null !== $request->thinkingLevel) {
            $body['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $request->thinkingLevel];
        }

        if ([] !== $request->functionDeclarations) {
            $body['tools'] = [['functionDeclarations' => $request->functionDeclarations]];
            $body['toolConfig'] = ['functionCallingConfig' => ['mode' => $request->allowToolCalls ? 'AUTO' : 'NONE']];
        }

        return $body;
    }
}
