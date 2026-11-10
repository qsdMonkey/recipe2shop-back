<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\ShoppingSession;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ToolRegistry
{
    /** @var array<string, ToolHandlerInterface> */
    private array $handlers = [];

    /** @var list<string> outils désactivés (scénarios de test uniquement, ex. tester la relecture sans update_memory) */
    private array $disabled = [];

    /** @param iterable<ToolHandlerInterface> $handlers */
    public function __construct(#[AutowireIterator('app.conversation_tool')] iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->name()] = $handler;
        }
    }

    /** @param list<string> $names */
    public function disableForTesting(array $names): void
    {
        $this->disabled = $names;
    }

    /** @return list<array<string, mixed>> déclarations Gemini des outils disponibles dans cette session */
    public function declarationsFor(ShoppingSession $session): array
    {
        $declarations = [];
        foreach ($this->handlers as $name => $handler) {
            if ($handler->supports($session) && !\in_array($name, $this->disabled, true)) {
                $declarations[] = ['name' => $name] + $handler->declaration();
            }
        }

        return $declarations;
    }

    /** @param array<string, mixed> $args */
    public function execute(string $name, array $args, ToolContext $context): ToolResult
    {
        $handler = $this->handlers[$name] ?? null;
        if (null === $handler || !$handler->supports($context->session) || \in_array($name, $this->disabled, true)) {
            return ToolResult::error(\sprintf('Unknown tool "%s".', $name));
        }

        return $handler->handle($args, $context);
    }
}
