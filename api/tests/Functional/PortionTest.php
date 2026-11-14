<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Recipe;
use App\Entity\SessionRecipe;
use App\Entity\ShoppingSession;
use App\Recipe\PortionChecker;
use App\Recipe\PortionRules;
use App\Recipe\StepTimes;
use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Contrôle des portions (« un plat pour 2, je me retrouve pour 4 ») et des temps. */
final class PortionTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser();
    }

    public function testFamiliesAndRanges(): void
    {
        self::assertSame('feculent', PortionRules::familyFromName('penne rigate'));
        self::assertSame('feculent', PortionRules::familyFromName('Riz basmati'));
        self::assertSame('feculent_frais', PortionRules::familyFromName('gnocchis à poêler'));
        self::assertSame('viande', PortionRules::familyFromName('blanc de poulet'));
        self::assertSame('viande_os', PortionRules::familyFromName('haut de cuisse de poulet'));
        self::assertSame('hache', PortionRules::familyFromName('bœuf haché 5 %'));
        self::assertSame('charcuterie', PortionRules::familyFromName('lardons fumés'));
        self::assertSame('legumineuse', PortionRules::familyFromName('pois chiches cuits'));
        self::assertNull(PortionRules::familyFromName('pâte de curry rouge'));
        self::assertNull(PortionRules::familyFromName('sauce tomate cuisinée'));
        self::assertNull(PortionRules::familyFromName('pâte brisée'));

        $checker = self::getContainer()->get(PortionChecker::class);
        $measures = [];
        foreach ($checker->measure(self::recipe([
            ['linguine', 300, 'g'], ['jambon blanc', 200, 'g'], ['petits pois surgelés', 300, 'g'], ['œuf', 4, 'piece'],
            ['crème fraîche', 20, 'cl'], ['parmesan', 40, 'g', true], // facultatif : non compté
        ])) as $m) {
            $measures[$m->family] = [round($m->perPortion, 1), $m->isOutOfRange()];
        }
        // Recette de la prod (id 12) : 2 portions.
        self::assertSame([
            'feculent' => [150.0, true],       // 150 g > 1,3 × 100
            'charcuterie' => [100.0, false],   // 100 g ≤ 1,3 × 80
            'legume' => [150.0, false],
            'oeuf' => [2.0, false],
            'creme' => [100.0, true],          // 100 ml > 1,3 × 60
        ], $measures);

        // Plat « batch » : 2 personnes × 2 repas = 4 portions → 300 g de linguine = 75 g par portion, dans la plage.
        $batch = self::recipe([['linguine', 300, 'g']], mealsCovered: 2);
        self::assertSame([], $checker->errors($batch));
        // Petit appoint (vermicelles dans une soupe) : pas d'erreur de portion trop petite.
        self::assertSame([], $checker->errors(self::recipe([['vermicelles', 40, 'g']])));
        // Vraie base trop petite : signalée.
        self::assertCount(1, $checker->errors(self::recipe([['riz', 80, 'g']])));
    }

    public function testTooBigPortionsAreSentBackToGeminiWithNumbers(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson(self::pasta(400));
        FakeGemini::queueJson(self::pasta(180));
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée ?')['messages'][1]['blocks'][0];

        $retry = FakeGemini::$requests[3]['body']['contents'][2]['parts'][0]['text'];
        self::assertStringContainsString('Portions : féculents secs (pâtes, riz, semoule, lentilles…) = 200 g par portion, attendu 70 à 100 g (2 portions = servings 2 × meals_covered 1 ; ingrédients : linguine 400 g)', $retry);
        self::assertSame([180, 'g'], [$card['recipe']['ingredients'][0]['quantity'], $card['recipe']['ingredients'][0]['unit']]);
        self::assertSame(2, $card['recipe']['total_portions']);
        // Le prompt (depuis recipe_v5) donne le tableau des portions ; recipe_v7 vise 80 g de féculents.
        self::assertStringContainsString('| Féculents secs (pâtes, riz, semoule, quinoa, boulgour, lentilles sèches) | 70 à 100 g (vise 80 g) |', FakeGemini::$requests[2]['body']['systemInstruction']['parts'][0]['text']);
    }

    public function testCodeCorrectsPortionsAfterTheSecondFailureWithoutBlocking(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson(self::pasta(400));
        FakeGemini::queueJson(self::pasta(500));
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée ?')['messages'][1]['blocks'][0];

        self::assertSame('recipe', $card['type']);
        // 250 g par portion → milieu de la plage (85 g) × 2 portions = 170 g ; l'étape suit.
        self::assertSame([170, 'g'], [$card['recipe']['ingredients'][0]['quantity'], $card['recipe']['ingredients'][0]['unit']]);
        self::assertSame('Fais cuire les 170 g de linguine 10 min dans l\'eau bouillante salée.', $card['recipe']['steps'][1]);
        self::assertSame(0, FakeGemini::remaining());
    }

    public function testSmallPortionIsNeverInflatedByTheCode(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson(self::pasta(60));
        FakeGemini::queueJson(self::pasta(60));
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée ?')['messages'][1]['blocks'][0];

        self::assertStringContainsString('= 30 g par portion, attendu 70 à 100 g', FakeGemini::$requests[3]['body']['contents'][2]['parts'][0]['text']);
        self::assertSame(60, $card['recipe']['ingredients'][0]['quantity']);
    }

    public function testTimesMustMatchTheSteps(): void
    {
        self::assertSame(['minutes' => 43, 'timed_steps' => 3], StepTimes::cited([
            'Épluche et coupe les légumes en dés (10 min).',
            'Fais revenir les oignons 5 min, jusqu\'à ce qu\'ils soient translucides.',
            'Pendant ce temps, prépare la sauce (5 min).',  // en parallèle : non compté
            'Laisse mijoter 25 à 30 min à feu doux.',
            'Préchauffe le four à 180 °C.',
        ]));
        self::assertSame(90, StepTimes::cited(['Mijote 1 h 30.', 'Sers.'])['minutes'] + StepTimes::cited(['Sers chaud.'])['minutes']);

        $session = $this->startSession();
        $quick = self::pasta(180, prep: 5, cook: 5); // les étapes citent 25 min
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson($quick);
        FakeGemini::queueJson($quick);
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée rapide ?')['messages'][1]['blocks'][0];

        self::assertStringContainsString('Temps : les étapes citent environ 25 min au total, mais prep_time_min + cook_time_min = 10 min.', FakeGemini::$requests[3]['body']['contents'][2]['parts'][0]['text']);
        // 2e échec : temps alignés sur les étapes par le code.
        self::assertSame(25, $card['recipe']['prep_time_min'] + $card['recipe']['cook_time_min']);
    }

    public function testOneMealByDefaultAndCodeScalesUnrequestedLeftovers(): void
    {
        $session = $this->startSession();
        $twoMeals = self::pasta(340) + [];
        $twoMeals['meals_covered'] = 2; // 4 portions alors qu'on en demandait 2
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson($twoMeals);
        FakeGemini::queueJson($twoMeals);
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée pour ce soir ?')['messages'][1]['blocks'][0];

        self::assertStringContainsString('Nombre de repas à couvrir : 1.', FakeGemini::$requests[2]['body']['contents'][0]['parts'][0]['text']);
        self::assertStringContainsString('meals_covered vaut 2, mais la consigne demande 1 repas', FakeGemini::$requests[3]['body']['contents'][2]['parts'][0]['text']);
        // 2e échec : le code ramène la recette à 1 repas, quantités et étapes comprises.
        self::assertSame([1, 2], [$card['recipe']['meals_covered'], $card['recipe']['total_portions']]);
        self::assertSame([170, 60], [$card['recipe']['ingredients'][0]['quantity'], $card['recipe']['ingredients'][1]['quantity']]);
        self::assertSame('Fais cuire les 170 g de linguine 10 min dans l\'eau bouillante salée.', $card['recipe']['steps'][1]);

        // Le prompt système ne propose plus de restes par défaut.
        $system = FakeGemini::$requests[1]['body']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('Plats pour plusieurs repas (restes, batch cooking) : PAS par défaut.', $system);
        self::assertStringContainsString('« ce plat fait 2 repas : ce soir et demain midi »', $system);
    }

    public function testServingsFollowTheHouseholdAndCodeScalesTheRecipe(): void
    {
        $this->request('POST', '/me/memory', ['category' => 'foyer', 'value' => '2 adultes et 2 enfants de 6 et 9 ans'], $this->token);
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson(self::pasta(170)); // servings 2 pour un foyer de 4
        FakeGemini::queueJson(self::pasta(170));
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée pour ce soir ?')['messages'][1]['blocks'][0];

        self::assertStringContainsString('Nombre de personnes : 4.', FakeGemini::$requests[2]['body']['contents'][0]['parts'][0]['text']);
        self::assertStringContainsString('servings vaut 2, mais la consigne demande 4 personnes', FakeGemini::$requests[3]['body']['contents'][2]['parts'][0]['text']);
        // 2e échec : le code met la recette à 4 portions, quantités et étapes comprises.
        self::assertSame([4, 4], [$card['recipe']['servings'], $card['recipe']['total_portions']]);
        self::assertSame([340, 240], [$card['recipe']['ingredients'][0]['quantity'], $card['recipe']['ingredients'][1]['quantity']]);
        self::assertSame('Fais cuire les 340 g de linguine 10 min dans l\'eau bouillante salée.', $card['recipe']['steps'][1]);
        self::assertStringContainsString('servings : EXACTEMENT le « Nombre de personnes »', FakeGemini::$requests[2]['body']['systemInstruction']['parts'][0]['text']);
    }

    public function testServingsGivenForTheDishWinOverTheHouseholdAndUnknownHouseholdIsNotForced(): void
    {
        $session = $this->startSession();
        // Foyer inconnu : servings laissé à l'IA, pas de 2e essai.
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon']);
        FakeGemini::queueJson(self::pasta(170));
        FakeGemini::queueText('Voici !');
        $card = $this->say($session, 'Une idée ?')['messages'][1]['blocks'][0];
        self::assertSame(2, $card['recipe']['servings']);
        self::assertStringNotContainsString('Nombre de personnes', FakeGemini::$requests[2]['body']['contents'][0]['parts'][0]['text']);
        self::assertCount(4, FakeGemini::$requests);

        // « On sera 6 » : donné pour ce plat, il passe avant le foyer.
        FakeGemini::queueText('Validé.');
        $this->request('POST', \sprintf('/sessions/%d/recipes/%d/accept', $session['id'], $card['recipe']['id']), null, $this->token);
        $this->request('POST', '/me/memory', ['category' => 'foyer', 'value' => 'couple'], $this->token);
        $six = self::pasta(510);
        $six['servings'] = 6;
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes pour 6', 'servings' => 6]);
        FakeGemini::queueJson($six);
        FakeGemini::queueText('Voici !');
        $calls = \count(FakeGemini::$requests);
        $card = $this->say($session, 'On sera 6 samedi')['messages'][1]['blocks'][0];
        self::assertSame(6, $card['recipe']['servings']);
        self::assertCount($calls + 3, FakeGemini::$requests); // pas de 2e essai
        self::assertStringContainsString('Nombre de personnes : 6.', FakeGemini::$requests[$calls + 1]['body']['contents'][0]['parts'][0]['text']);
    }

    public function testMultiMealDishWhenRequested(): void
    {
        $session = $this->startSession();
        $twoMeals = self::pasta(340);
        $twoMeals['meals_covered'] = 2;
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'pâtes au jambon, ce soir et gamelle demain', 'meals_covered' => 2]);
        FakeGemini::queueJson($twoMeals);
        FakeGemini::queueText('Ce plat fait 2 repas : ce soir et demain midi.');

        $card = $this->say($session, 'Un plat pour ce soir et ma gamelle de demain')['messages'][1]['blocks'][0];

        self::assertCount(4, FakeGemini::$requests); // pas de 2e essai
        self::assertSame([2, 4, 340], [$card['recipe']['meals_covered'], $card['recipe']['total_portions'], $card['recipe']['ingredients'][0]['quantity']]);
    }

    public function testFixPortionsCommand(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        FakeGemini::queueText('Salut !');
        $sessionId = $this->request('POST', '/sessions', ['type' => 'shopping'], $this->token)['id'];
        $session = $em->find(ShoppingSession::class, $sessionId);
        $recipe = new Recipe(RecipeFixtures::chili(['title' => 'Pâtes à la carbonara', 'servings' => 2, 'meals_covered' => 1,
            'ingredients' => [
                ['name' => 'linguine', 'quantity' => 300, 'unit' => 'g', 'category' => 'epicerie', 'optional' => false],
                ['name' => 'carotte', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes', 'optional' => false],
                ['name' => 'gousse d\'ail', 'quantity' => 2, 'unit' => 'piece', 'category' => 'fruits_legumes', 'optional' => false],
                ['name' => 'œuf', 'quantity' => 3, 'unit' => 'piece', 'category' => 'cremerie', 'optional' => false],
            ],
            'steps' => ['Fais cuire les 300 g de linguine 10 min.', 'Mélange avec les œufs.'],
        ]), $session->getUser(), 'recipe_v3');
        $em->persist($recipe);
        $em->persist(new SessionRecipe($session, $recipe, 1, 1, []));
        $em->flush();
        $recipeId = (int) $recipe->getId();
        $version = $this->listVersion($sessionId);

        $dry = $this->runFix(['--dry-run' => true]);
        self::assertStringContainsString('#'.$recipeId.' « Pâtes à la carbonara » : carotte 2 pièce(s) → 200 g ; gousse d\'ail 2 pièce(s) → 2 gousse(s) ; feculent 150 → 85 g/portion', $dry);
        self::assertStringContainsString('Simulation : 1 recette(s) à corriger sur 1.', $dry);
        self::assertSame([300.0, 'g'], $this->ingredient($recipeId, 0));

        $this->runFix([]);
        self::assertSame([170.0, 'g'], $this->ingredient($recipeId, 0));
        self::assertSame([200.0, 'g'], $this->ingredient($recipeId, 1));
        self::assertSame([2.0, 'gousse'], $this->ingredient($recipeId, 2));
        self::assertSame([3.0, 'piece'], $this->ingredient($recipeId, 3));
        self::assertSame('["Fais cuire les 170 g de linguine 10 min.", "Mélange avec les œufs."]', $this->db()->fetchOne('SELECT steps FROM recipe WHERE id = ?', [$recipeId]));
        self::assertSame($version + 1, $this->listVersion($sessionId)); // liste de la session signalée « pas à jour »

        self::assertStringContainsString('Terminé : 0 recette(s) à corriger sur 1.', $this->runFix([])); // idempotente
    }

    /**
     * @param list<array{0: string, 1: int|float, 2: string, 3?: bool}> $ingredients
     *
     * @return array<string, mixed>
     */
    private static function recipe(array $ingredients, int $servings = 2, int $mealsCovered = 1): array
    {
        return [
            'servings' => $servings,
            'meals_covered' => $mealsCovered,
            'steps' => [],
            'ingredients' => array_map(static fn (array $i): array => ['name' => $i[0], 'quantity' => $i[1], 'unit' => $i[2], 'category' => 'epicerie', 'optional' => $i[3] ?? false], $ingredients),
        ];
    }

    /** @return array<string, mixed> */
    private static function pasta(int $grams, int $prep = 10, int $cook = 15): array
    {
        return RecipeFixtures::chili([
            'title' => 'Linguine au jambon',
            'servings' => 2,
            'meals_covered' => 1,
            'prep_time_min' => $prep,
            'cook_time_min' => $cook,
            'equipment' => [],
            'ingredients' => [
                ['name' => 'linguine', 'quantity' => $grams, 'unit' => 'g', 'category' => 'epicerie', 'optional' => false],
                ['name' => 'jambon blanc', 'quantity' => 120, 'unit' => 'g', 'category' => 'boucherie_poissonnerie', 'optional' => false],
                ['name' => 'sel', 'quantity' => null, 'unit' => null, 'category' => 'epicerie', 'optional' => false],
            ],
            'steps' => [
                'Coupe le jambon en lanières (5 min).',
                'Fais cuire les '.$grams.' g de linguine 10 min dans l\'eau bouillante salée.',
                'Réchauffe le jambon 5 min à la poêle, mélange et sers (5 min).',
            ],
        ]);
    }

    /** @param array<string, mixed> $options */
    private function runFix(array $options): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:recipes:fix-portions'));
        $tester->execute($options);
        self::assertSame(0, $tester->getStatusCode());

        return $tester->getDisplay();
    }

    /** @return array{0: float, 1: string} */
    private function ingredient(int $recipeId, int $position): array
    {
        $row = $this->db()->fetchAssociative('SELECT quantity, unit FROM recipe_ingredient WHERE recipe_id = ? AND position = ?', [$recipeId, $position]);

        return [(float) $row['quantity'], $row['unit']];
    }

    private function listVersion(int $sessionId): int
    {
        return (int) $this->db()->fetchOne('SELECT list_inputs_version FROM shopping_session WHERE id = ?', [$sessionId]);
    }

    /** @return array<string, mixed> */
    private function startSession(): array
    {
        FakeGemini::queueText('Salut !');
        $session = $this->request('POST', '/sessions', ['type' => 'shopping'], $this->token);
        self::assertResponseStatusCodeSame(201);

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function say(array $session, string $text): array
    {
        $turn = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => $text], $this->token);
        self::assertResponseIsSuccessful();

        return $turn;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
