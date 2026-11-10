<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

/** suggest_new_session({reason}) : nouvelle grosse course détectée → bouton « Nouvelle liste de courses » dans le chat. */
final class SuggestNewSessionTool implements ToolHandlerInterface
{
    use ShoppingToolTrait;

    public function name(): string
    {
        return 'suggest_new_session';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Affiche le bouton « Démarrer une nouvelle liste de courses » quand l\'utilisateur prépare une NOUVELLE grosse course '
                .'(nouvelle semaine, « je refais mes courses »). Pas pour une course d\'appoint ou une fête.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['reason' => ['type' => 'string', 'description' => 'Pourquoi, en une phrase courte.']],
                'required' => ['reason'],
            ],
        ];
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $reason = \is_string($args['reason'] ?? null) ? mb_substr(trim($args['reason']), 0, 300) : '';

        return ToolResult::ok(
            ['status' => 'ok', 'note' => 'Le bouton est affiché sous ton message.'],
            [['type' => 'new_session', 'reason' => '' === $reason ? null : $reason]],
        );
    }
}
