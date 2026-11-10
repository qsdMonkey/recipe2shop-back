<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Ingredient\AccentRestorer;
use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class AccentsTest extends ApiTestCase
{
    public function testRestorer(): void
    {
        $restorer = self::getContainer()->get(AccentRestorer::class);

        self::assertSame('tortilla de blé', $restorer->restore('tortilla de ble'));
        self::assertSame('épices à fajitas', $restorer->restore('epices a fajitas'));
        self::assertSame('crème fraîche', $restorer->restore('creme fraiche'));
        self::assertSame('crème fraîche épaisse', $restorer->restore('creme fraiche epaisse'));
        self::assertSame('Bœuf haché', $restorer->restore('Boeuf hache'));
        self::assertSame('sucre en poudre', $restorer->restore('sucre en poudre'));          // mot correct sans accent
        self::assertSame('pâte a pizza maison é', $restorer->restore('pâte a pizza maison é')); // déjà accentué : intact
        self::assertSame('riz basmati', $restorer->restore('riz basmati'));
    }

    public function testRecipeCardAndListShowAccentedNamesWhenGeminiDropsThem(): void
    {
        $token = $this->registerUser();
        FakeGemini::queueText('Salut !');
        $session = $this->request('POST', '/sessions', ['type' => 'shopping'], $token);
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'fajitas']);
        FakeGemini::queueJson(RecipeFixtures::chili(['title' => 'Fajitas', 'ingredients' => [
            ['name' => 'tortilla de ble', 'quantity' => 6, 'unit' => 'piece', 'category' => 'boulangerie', 'optional' => false],
            ['name' => 'epices a fajitas', 'quantity' => 1, 'unit' => 'sachet', 'category' => 'epicerie', 'optional' => false],
            ['name' => 'creme fraiche', 'quantity' => 20, 'unit' => 'cl', 'category' => 'cremerie', 'optional' => false],
            ['name' => 'poivron rouge', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes', 'optional' => false],
        ]]));
        FakeGemini::queueText('Des fajitas ?');
        $turn = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'Une idée'], $token);

        $card = $turn['messages'][1]['blocks'][0];
        self::assertSame(['tortilla de blé', 'épices à fajitas', 'crème fraîche', 'poivron rouge'], array_column($card['recipe']['ingredients'], 'name'));

        FakeGemini::queueText('Validé.');
        $this->request('POST', \sprintf('/sessions/%d/recipes/%d/accept', $session['id'], $card['recipe']['id']), null, $token);
        FakeGemini::queueFunctionCall('generate_shopping_list', ['type' => 'main']);
        FakeGemini::queueText('Voici la liste.');
        $listId = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'La liste'], $token)['messages'][1]['blocks'][0]['list']['id'];

        $labels = [];
        foreach ($this->request('GET', '/shopping-lists/'.$listId, null, $token)['sections'] as $section) {
            array_push($labels, ...array_column($section['items'], 'label'));
        }
        self::assertContains('tortilla de blé', $labels);
        self::assertContains('épices à fajitas', $labels);
        self::assertContains('crème fraîche', $labels);
        self::assertNotContains('epices a fajitas', $labels);
    }

    public function testRepairCommandFixesExistingDataAndIsIdempotent(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $tortilla = (int) $db->fetchOne("SELECT id FROM ingredient WHERE name = 'tortilla de blé'");
        $db->executeStatement("INSERT INTO ingredient (name, normalized_name, category, basic, to_review, aliases) VALUES ('epices a fajitas', 'epices a fajitas test', 'epicerie', 0, 1, '[]')");
        $spices = (int) $db->lastInsertId();
        $db->executeStatement("INSERT INTO recipe (title, servings, meals_covered, meal_tags, prep_time_min, cook_time_min, difficulty, uses_inventory, steps, equipment, prompt_version, created_at) VALUES ('Fajitas', 2, 1, '[]', 10, 10, 'easy', '[]', '[\"x\"]', '[]', 'recipe_v1', NOW())");
        $recipe = (int) $db->lastInsertId();
        $curry = (int) $db->fetchOne("SELECT id FROM ingredient WHERE normalized_name = 'pate de curry'");
        $db->executeStatement("INSERT INTO recipe_ingredient (recipe_id, position, name, quantity, unit, category, optional, ingredient_id) VALUES (?, 0, 'tortilla de ble', 6, 'piece', 'boulangerie', 0, ?), (?, 1, 'epices a fajitas', 1, 'sachet', 'epicerie', 0, ?), (?, 2, 'pâte de curry rouge', 1, 'cas', 'epicerie', 0, ?)", [$recipe, $tortilla, $recipe, $spices, $recipe, $curry]);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:ingredients:fix-accents'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 ingrédient(s), 2 ingrédient(s) de recette', $tester->getDisplay());

        // Un nom déjà accentué n'est jamais remplacé (ici par le nom du référentiel).
        self::assertSame(['tortilla de blé', 'épices à fajitas', 'pâte de curry rouge'], $db->fetchFirstColumn('SELECT name FROM recipe_ingredient WHERE recipe_id = ? ORDER BY position', [$recipe]));
        self::assertSame('épices à fajitas', $db->fetchOne('SELECT name FROM ingredient WHERE id = ?', [$spices]));

        $tester->execute([]);
        self::assertStringContainsString('0 ingrédient(s), 0 ingrédient(s) de recette, 0 article(s)', $tester->getDisplay());
    }
}
