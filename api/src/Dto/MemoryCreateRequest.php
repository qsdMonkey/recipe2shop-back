<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\MemoryCategory;
use Symfony\Component\Validator\Constraints as Assert;

final class MemoryCreateRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [MemoryCategory::class, 'values'])]
        public readonly string $category = '',

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 200, normalizer: 'trim')]
        public readonly string $value = '',
    ) {
    }
}
