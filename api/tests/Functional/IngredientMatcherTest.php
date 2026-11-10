<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Ingredient\IngredientMatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IngredientMatcherTest extends KernelTestCase
{
    public function testMatchesNamesAliasesAndPlurals(): void
    {
        $matcher = self::getContainer()->get(IngredientMatcher::class);
        $name = static fn (string $n): ?string => $matcher->find($n)?->getName();

        self::assertSame('oignon', $name('Oignons'));
        self::assertSame('œuf', $name('œufs'));
        self::assertSame('œuf', $name('oeuf'));
        self::assertSame('poulet', $name('Filet de poulet'));
        self::assertSame('crème fraîche', $name('Crème fraîche épaisse'));
        self::assertSame('tomates concassées', $name('tomate concassée'));
        self::assertSame('pâtes', $name('coquillettes'));
        self::assertSame('papier toilette', $name('PQ'));
        self::assertSame('huile neutre', $name('huile de tournesol'));
        self::assertSame('gnocchis', $name('gnocchis à poêler'));
        self::assertSame('tomates concassées', $name('pulpe de tomate en boîte'));
        self::assertSame('thon en boîte', $name('thon en boite'));
        self::assertNull($name('jus de citron'));
    }

    public function testUnknownNameCreatesIngredientToReview(): void
    {
        $matcher = self::getContainer()->get(IngredientMatcher::class);

        $ingredient = $matcher->findOrCreate('Sauce Worcestershire', 'epicerie');

        self::assertTrue($ingredient->isToReview());
        self::assertSame('epicerie', $ingredient->getCategory());
        self::assertSame($ingredient, $matcher->findOrCreate('sauce worcestershire', 'autre'));
    }
}
