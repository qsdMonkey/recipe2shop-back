<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RecipeDecisionRequest
{
    public function __construct(
        #[Assert\Uuid(strict: false)]
        public readonly ?string $clientId = null,

        /** Refus uniquement : raison facultative (« moins épicé »). */
        #[Assert\Length(max: 500)]
        public readonly ?string $comment = null,
    ) {
    }
}
