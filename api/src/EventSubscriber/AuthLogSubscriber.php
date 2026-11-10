<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Journal des connexions (firewall « login » uniquement : le firewall JWT « authentifie » chaque requête).
 * Emails masqués par SensitiveDataProcessor ; jamais de mot de passe ni de token.
 */
final class AuthLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onSuccess',
            LoginFailureEvent::class => 'onFailure',
        ];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        if ('login' !== $event->getFirewallName()) {
            return;
        }
        $user = $event->getUser();
        $this->logger->info('auth.login_success', [
            'user_id' => $user instanceof User ? $user->getId() : null,
            'email' => $user->getUserIdentifier(),
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        if ('login' !== $event->getFirewallName()) {
            return;
        }
        $passport = $event->getPassport();
        $this->logger->notice('auth.login_failure', [
            'email' => $passport?->hasBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)
                ? $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class)->getUserIdentifier()
                : null,
            'reason' => $event->getException()::class,
            'ip' => $event->getRequest()->getClientIp(),
        ]);
    }
}
