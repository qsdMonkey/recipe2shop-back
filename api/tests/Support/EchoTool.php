<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Conversation\Tool\ToolContext;
use App\Conversation\Tool\ToolHandlerInterface;
use App\Conversation\Tool\ToolResult;
use App\Entity\ShoppingSession;

/** Outil de test (env test uniquement) : vérifie la boucle de function calling et l'ordre des blocs. */
final class EchoTool implements ToolHandlerInterface
{
    public function name(): string
    {
        return 'echo_test';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Test tool: echoes a text.',
            'parameters' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
        ];
    }

    public function supports(ShoppingSession $session): bool
    {
        return true;
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        if (!\is_string($args['text'] ?? null) || '' === $args['text']) {
            return ToolResult::error('Argument "text" is required.');
        }

        return ToolResult::ok(['status' => 'ok', 'echoed' => $args['text']], [['type' => 'test_card', 'value' => $args['text']]]);
    }
}
