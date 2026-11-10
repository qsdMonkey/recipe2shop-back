<?php

declare(strict_types=1);

namespace App\Tests\Unit\ShoppingList;

use App\ShoppingList\Units;
use PHPUnit\Framework\TestCase;

final class UnitsTest extends TestCase
{
    public function testDimensionsAndBase(): void
    {
        self::assertSame('mass', Units::dimension('kg'));
        self::assertSame('volume', Units::dimension('cas'));
        self::assertSame('piece', Units::dimension('piece'));
        self::assertSame(1500.0, Units::toBase(1.5, 'kg'));
        self::assertSame(200.0, Units::toBase(20, 'cl'));
        self::assertSame(30.0, Units::toBase(2, 'cas'));
    }

    public function testShoppingQuantities(): void
    {
        self::assertSame([400.0, 'g'], Units::forShopping('mass', 400));
        self::assertSame([1.25, 'kg'], Units::forShopping('mass', 1234));
        self::assertSame([35.0, 'g'], Units::forShopping('mass', 34.2));
        self::assertSame([30.0, 'cl'], Units::forShopping('volume', 300, ['cl']));
        self::assertSame([2.0, 'cas'], Units::forShopping('volume', 30, ['cas']));
        self::assertSame([4.0, 'cac'], Units::forShopping('volume', 20, ['cas', 'cac']));
        self::assertSame([1.5, 'l'], Units::forShopping('volume', 1500, ['ml']));
        self::assertSame([3.0, 'piece'], Units::forShopping('piece', 2.2));
        self::assertSame([1.0, 'botte'], Units::forShopping('botte', 0.5));
    }
}
