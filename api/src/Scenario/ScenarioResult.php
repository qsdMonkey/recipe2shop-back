<?php

declare(strict_types=1);

namespace App\Scenario;

final class ScenarioResult
{
    /** @var list<array{check: string, ok: bool, soft: bool, detail: string}> */
    public array $checks = [];

    /** @var list<string> déroulé lisible (tours, outils appelés, durées) */
    public array $transcript = [];

    public float $aiSeconds = 0.0;
    public int $turns = 0;
    public int $geminiCalls = 0;
    public float $costUsd = 0.0;
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public ?string $error = null;

    public function __construct(public readonly string $name, public readonly string $description)
    {
    }

    public function check(string $check, bool $ok, string $detail = '', bool $soft = false): void
    {
        $this->checks[] = ['check' => $check, 'ok' => $ok, 'soft' => $soft, 'detail' => $detail];
    }

    /** pass | warn | fail */
    public function status(): string
    {
        if (null !== $this->error) {
            return 'fail';
        }
        foreach ($this->checks as $c) {
            if (!$c['ok'] && !$c['soft']) {
                return 'fail';
            }
        }
        foreach ($this->checks as $c) {
            if (!$c['ok']) {
                return 'warn';
            }
        }

        return 'pass';
    }
}
