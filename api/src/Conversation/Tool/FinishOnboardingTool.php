<?php

declare(strict_types=1);

namespace App\Conversation\Tool;

use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Memory\OnboardingChecklist;
use App\Repository\MessageRepository;

/**
 * finish_onboarding() : l'IA estime avoir le minimum. Le code le vérifie, puis affiche le bouton « Terminer ».
 */
final class FinishOnboardingTool implements ToolHandlerInterface
{
    public function __construct(
        private readonly OnboardingChecklist $checklist,
        private readonly MessageRepository $messages,
    ) {
    }

    public function name(): string
    {
        return 'finish_onboarding';
    }

    public function declaration(): array
    {
        return [
            'description' => 'Affiche le bouton « Terminer » de l\'onboarding. À appeler une seule fois, quand le minimum est enregistré en mémoire '
                .'(foyer, fréquence des courses, et régime ou allergies, même « aucune »).',
            // Pas de paramètres.
        ];
    }

    public function supports(ShoppingSession $session): bool
    {
        return SessionType::Onboarding === $session->getType() && $session->isActive();
    }

    public function handle(array $args, ToolContext $context): ToolResult
    {
        $missing = $this->checklist->missing($context->user);
        if ([] !== $missing) {
            return ToolResult::error('Le minimum n\'est pas encore en mémoire.', ['Manque : '.implode(' ; ', $missing).'. Enregistre-le avec update_memory (ou demande-le).']);
        }

        // Un seul bouton « Terminer » par onboarding, même si l'IA rappelle l'outil.
        if ($this->messages->sessionHasBlockType($context->session, 'finish_onboarding')) {
            return ToolResult::ok(['status' => 'already_shown', 'detail' => 'Le bouton « Terminer » est déjà affiché plus haut dans la conversation : ne rappelle plus cet outil.']);
        }

        return ToolResult::ok(
            ['status' => 'ok', 'detail' => 'Le bouton « Terminer » est affiché. Dis à l\'utilisateur qu\'il peut terminer maintenant ou continuer à compléter son profil.'],
            [['type' => 'finish_onboarding']],
        );
    }
}
