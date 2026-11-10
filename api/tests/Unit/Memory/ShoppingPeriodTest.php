<?php

declare(strict_types=1);

namespace App\Tests\Unit\Memory;

use App\Memory\ShoppingPeriod;
use PHPUnit\Framework\TestCase;

final class ShoppingPeriodTest extends TestCase
{
    public function testFromText(): void
    {
        self::assertSame(7, ShoppingPeriod::fromText('toutes les semaines'));
        self::assertSame(7, ShoppingPeriod::fromText('1 fois par semaine'));
        self::assertSame(14, ShoppingPeriod::fromText('toutes les 2 semaines'));
        self::assertSame(14, ShoppingPeriod::fromText('tous les 15 jours'));
        self::assertSame(30, ShoppingPeriod::fromText('une fois par mois'));
        self::assertSame(4, ShoppingPeriod::fromText('deux fois par semaine'));
        self::assertSame(7, ShoppingPeriod::fromText('quand le frigo est vide'));
    }
}
