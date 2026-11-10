<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** En-têtes de sécurité de base pour une API JSON exposée publiquement. */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        if ($event->getRequest()->isSecure()) { // HTTPS vu à travers le proxy de confiance (X-Forwarded-Proto)
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        // Pas de cache des réponses de l'API (données personnelles), sauf si une route l'a explicitement réglé.
        if (!$headers->hasCacheControlDirective('public')) {
            $headers->set('Cache-Control', 'no-store, private');
        }
        $headers->remove('X-Powered-By');
    }
}
