<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SendMessageRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 2000, normalizer: 'trim')]
        public readonly string $text = '',

        /** UUID généré par l'app, réutilisé tel quel pour réessayer le même tour (idempotence). */
        #[Assert\Uuid(strict: false)]
        public readonly ?string $clientId = null,
    ) {
    }
}
