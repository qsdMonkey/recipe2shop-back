<?php

declare(strict_types=1);

namespace App\Tests\Unit\Memory;

use App\Memory\HouseholdSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HouseholdSizeTest extends TestCase
{
    /** @return iterable<array{string, ?int}> */
    public static function values(): iterable
    {
        yield ['2 adultes et 2 enfants de 6 et 9 ans', 4];
        yield ['2 adultes', 2];
        yield ['1 adulte (étudiant)', 1];
        yield ['2 enfants', 2];
        yield ['couple', 2];
        yield ['un couple avec deux enfants', 4];
        yield ['Je vis seule', 1];
        yield ['on est 3', 3];
        yield ['famille de 5', 5];
        yield ['3 personnes', 3];
        yield ['grande tablée', null];
    }

    #[DataProvider('values')]
    public function testParse(string $value, ?int $expected): void
    {
        self::assertSame($expected, HouseholdSize::parse($value));
    }
}
