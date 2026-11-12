<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SendMessageRequest
{
    public function __construct(
        /** Facultatif quand une photo est jointe. La limite de 2000 caractères ne porte que sur le texte. */
        #[Assert\Length(max: 2000, normalizer: 'trim')]
        public readonly string $text = '',

        /** UUID généré par l'app, réutilisé tel quel pour réessayer le même tour (idempotence). Obligatoire avec une photo. */
        #[Assert\Uuid(strict: false)]
        public readonly ?string $clientId = null,

        public readonly ?PhotoPayload $photo = null,
    ) {
    }

    #[Assert\Callback]
    public function validateContent(ExecutionContextInterface $context): void
    {
        if (null === $this->photo && '' === trim($this->text)) {
            $context->buildViolation('This value should not be blank.')->atPath('text')->addViolation();
        }
        if (null !== $this->photo && null === $this->clientId) {
            $context->buildViolation('client_id is required with a photo.')->atPath('client_id')->addViolation();
        }
    }
}
