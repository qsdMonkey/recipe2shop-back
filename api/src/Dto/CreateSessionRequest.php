<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateSessionRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['shopping', 'onboarding'])]
        public readonly string $type = 'shopping',
    ) {
    }
}
