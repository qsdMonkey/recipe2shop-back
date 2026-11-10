<?php

declare(strict_types=1);

namespace App\Tests\Unit\Memory;

use App\Memory\MemoryManager;
use PHPUnit\Framework\TestCase;

final class MemoryManagerTest extends TestCase
{
    public function testNormalize(): void
    {
        self::assertSame('poele', MemoryManager::normalize('  Poêle. '));
        self::assertSame('cuisine thai', MemoryManager::normalize('Cuisine   THAÏ'));
        self::assertSame('l airfryer', MemoryManager::normalize("l'Airfryer"));
        self::assertSame('oeufs', MemoryManager::normalize('Œufs'));
    }

    public function testNoneValues(): void
    {
        self::assertTrue(MemoryManager::isNoneValue('Aucune'));
        self::assertTrue(MemoryManager::isNoneValue('rien.'));
        self::assertFalse(MemoryManager::isNoneValue('arachides'));
    }
}
