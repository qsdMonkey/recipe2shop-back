<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\ShoppingSession;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Un outil que Gemini peut appeler (function calling). Un handler par outil : propose_recipe, update_memory…
 * L'outil valide ses arguments, agit (enregistre) et renvoie un résultat à Gemini + d'éventuels blocs pour l'app.
 */
#[AutoconfigureTag('app.conversation_tool')]
interface ToolHandlerInterface
{
    public function name(): string;

    /**
     * Déclaration Gemini, sans le nom : ['description' => ..., 'parameters' => <schéma OpenAPI>].
     *
     * @return array<string, mixed>
     */
    public function declaration(): array;

    /** L'outil est-il proposé dans cette session (ex. finish_onboarding uniquement en onboarding) ? */
    public function supports(ShoppingSession $session): bool;

    /**
     * @param array<string, mixed> $args arguments fournis par Gemini (non fiables : à valider)
     */
    public function handle(array $args, ToolContext $context): ToolResult;
}
