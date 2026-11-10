<?php

declare(strict_types=1);

namespace App\Dto;

use App\Recipe\RecipeVocabulary;
use Symfony\Component\Validator\Constraints as Assert;

final class ShoppingItemCreateRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 100, normalizer: 'trim')]
        public readonly string $label = '',

        #[Assert\Positive]
        #[Assert\LessThanOrEqual(100000)]
        public readonly int|float|null $quantity = null,

        #[Assert\Choice(choices: RecipeVocabulary::UNITS)]
        public readonly ?string $unit = null,

        #[Assert\Choice(choices: RecipeVocabulary::CATEGORIES)]
        public readonly ?string $category = null,
    ) {
    }
}
