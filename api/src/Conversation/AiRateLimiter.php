<?php

declare(strict_types=1);

namespace App\Conversation;

use App\Entity\User;
use App\Http\ApiException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limite les tours qui appellent Gemini (payant), par utilisateur : 20 / minute et 300 / jour.
 */
final class AiRateLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.ai_messages')]
        private readonly RateLimiterFactoryInterface $perMinute,
        #[Autowire(service: 'limiter.ai_messages_daily')]
        private readonly RateLimiterFactoryInterface $perDay,
    ) {
    }

    public function consume(User $user): void
    {
        $key = 'user-'.$user->getId();
        foreach ([$this->perMinute, $this->perDay] as $factory) {
            $limit = $factory->create($key)->consume();
            if (!$limit->isAccepted()) {
                throw new ApiException(429, 'rate_limited', 'Too many messages, try again later.', [
                    'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                ]);
            }
        }
    }
}
