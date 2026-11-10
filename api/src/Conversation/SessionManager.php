<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\SessionType;
use App\Entity\ShoppingSession;
use App\Entity\User;
use App\Http\ApiException;
use App\Repository\ShoppingSessionRepository;
use App\Memory\ShoppingPeriod;
use App\Message\MemoryReviewMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cycle de vie des sessions. Règle des 3 et relecture mémoire à la clôture : étape 5.
 */
final class SessionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShoppingSessionRepository $sessions,
        private readonly TurnRunner $turnRunner,
        private readonly AiRateLimiter $rateLimiter,
        private readonly MessageBusInterface $bus,
        private readonly SessionRetention $retention,
        private readonly ShoppingPeriod $period,
    ) {
    }

    /**
     * @return array{0: ShoppingSession, 1: bool} la session et true si elle vient d'être créée
     */
    public function start(User $user, SessionType $type): array
    {
        if (SessionType::Onboarding === $type) {
            if ($user->isOnboardingCompleted()) {
                throw new ApiException(409, 'onboarding_completed', 'Onboarding is already completed.');
            }
            if (null !== $existing = $this->sessions->findActive($user, SessionType::Onboarding)) {
                return [$existing, false];
            }
        }

        $this->rateLimiter->consume($user);

        $closed = null;
        $session = $this->em->wrapInTransaction(function () use ($user, $type, &$closed): ShoppingSession {
            if (SessionType::Shopping === $type) {
                // Une seule session de courses active : la précédente passe en lecture seule.
                $closed = $this->sessions->findActive($user, SessionType::Shopping);
                $closed?->archive();
            }
            $session = new ShoppingSession($user, $type, SessionType::Shopping === $type ? $this->period->forUser($user) : ShoppingSession::DEFAULT_PERIOD_DAYS);
            $this->em->persist($session);

            return $session;
        });

        // Clôture : relecture mémoire en arrière-plan, puis règle des 3 (sessions déjà relues).
        if (null !== $closed) {
            $this->bus->dispatch(new MemoryReviewMessage((int) $closed->getId()));
        }
        $this->retention->apply($user);

        $this->turnRunner->greet($session);

        return [$session, true];
    }

    /**
     * Bouton « Terminer » de l'onboarding (ou « passer ») : idempotent.
     */
    public function finishOnboarding(User $user, ShoppingSession $session): void
    {
        if (SessionType::Onboarding !== $session->getType()) {
            throw new ApiException(409, 'not_onboarding', 'This session is not an onboarding session.');
        }

        $user->completeOnboarding();
        $session->archive();
        $this->em->flush();
    }
}
