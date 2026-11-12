<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\DBAL\Connection;

/** Règle des poids (validation des recettes), astuces (tips) et pas-à-pas (start_cooking). */
final class CookingTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser();
    }

    public function testPiecesAreRefusedForAVegetableAndTheRecipeIsRetried(): void
    {
        $session = $this->startSession();
        $bad = RecipeFixtures::chili();
        $bad['ingredients'][1] = ['name' => 'carotte', 'quantity' => 3, 'unit' => 'piece', 'category' => 'fruits_legumes', 'optional' => false];
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili']);
        FakeGemini::queueJson($bad);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée ?')['messages'][1]['blocks'][0];

        self::assertSame('recipe', $card['type']);
        $retry = FakeGemini::$requests[3]['body']['contents'][2]['parts'][0]['text'];
        self::assertStringContainsString('[ingredients][1] (« carotte ») : l\'unité « piece » est interdite pour ce produit. Donne un poids en g ou kg', $retry);
        // Le prompt recipe_v4 exige des poids et un vrai pas-à-pas.
        $system = FakeGemini::$requests[2]['body']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('TOUJOURS des poids ou des volumes', $system);
        self::assertStringContainsString('6 à 12 étapes détaillées', $system);
        self::assertArrayHasKey('tips', FakeGemini::$requests[2]['body']['generationConfig']['responseSchema']['properties']);
    }

    public function testPiecesAreAllowedForEggsAndBayLeaf(): void
    {
        $session = $this->startSession();
        $recipe = RecipeFixtures::chili(['title' => 'Omelette']);
        $recipe['ingredients'] = [
            ['name' => 'œuf', 'quantity' => 6, 'unit' => 'piece', 'category' => 'cremerie', 'optional' => false],
            ['name' => 'feuille de laurier', 'quantity' => 1, 'unit' => 'piece', 'category' => 'epicerie', 'optional' => false],
            ['name' => 'tortilla de blé', 'quantity' => 4, 'unit' => 'piece', 'category' => 'boulangerie', 'optional' => false],
            ['name' => 'ail', 'quantity' => 2, 'unit' => 'gousse', 'category' => 'fruits_legumes', 'optional' => false],
        ];
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'omelette']);
        FakeGemini::queueJson($recipe);
        FakeGemini::queueText('Voici !');

        $card = $this->say($session, 'Une idée ?')['messages'][1]['blocks'][0];

        self::assertSame('Omelette', $card['recipe']['title']);
        self::assertCount(4, FakeGemini::$requests); // accueil, chat, génération (1 seul essai), chat
    }

    public function testTipsArePresentEverywhere(): void
    {
        $session = $this->startSession();
        $card = $this->proposeChili($session);
        $recipeId = $card['recipe']['id'];
        $tips = ['Encore meilleur réchauffé le lendemain.', 'Se congèle très bien en portions.'];

        self::assertSame($tips, $card['recipe']['tips']);
        self::assertSame(['steps', 'tips', 'equipment'], \array_slice(array_keys($card['recipe']), 11, 3));
        self::assertSame($tips, $this->request('GET', '/sessions/'.$session['id'], null, $this->token)['recipes'][0]['recipe']['tips']);
        self::assertSame($tips, $this->request('GET', "/recipes/$recipeId?servings=4", null, $this->token)['tips']);
        $this->request('PUT', "/me/favorites/$recipeId", null, $this->token);
        self::assertSame($tips, $this->request('GET', '/me/favorites', null, $this->token)['favorites'][0]['recipe']['tips']);

        // Recette d'avant recipe_v4 (migration) : tips = [].
        $this->db()->executeStatement('UPDATE recipe SET tips = JSON_ARRAY() WHERE id = ?', [$recipeId]);
        self::assertSame([], $this->request('GET', "/recipes/$recipeId", null, $this->token)['tips']);
    }

    public function testStartCookingShowsAGuideCardWithHouseholdServings(): void
    {
        $this->request('POST', '/me/memory', ['category' => 'foyer', 'value' => '2 adultes et 2 enfants de 6 et 9 ans'], $this->token);
        $session = $this->startSession();
        $recipeId = $this->proposeChili($session)['recipe']['id'];

        FakeGemini::queueFunctionCall('start_cooking', ['recipe_id' => $recipeId]);
        FakeGemini::queueText('C\'est parti ! Tout est dans le pas à pas 👇');
        $calls = \count(FakeGemini::$requests);
        $turn = $this->say($session, 'Ce soir je fais le chili');
        self::assertCount($calls + 2, FakeGemini::$requests); // 2 appels de chat, pas de génération

        $expected = ['type' => 'cooking_guide', 'recipe_id' => $recipeId, 'title' => 'Chili sin carne', 'servings' => 4];
        self::assertSame([$expected, ['type' => 'text', 'text' => 'C\'est parti ! Tout est dans le pas à pas 👇']], $turn['messages'][1]['blocks']);
        $response = end(FakeGemini::$requests[\count(FakeGemini::$requests) - 1]['body']['contents'])['parts'][0]['functionResponse']['response'];
        self::assertSame(['ok', 4], [$response['status'], $response['servings']]);
        // Relu depuis la base : même bloc, même ordre de clés.
        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        self::assertSame($expected, end($detail['messages'])['blocks'][0]);

        // Nombre de personnes donné par l'utilisateur.
        FakeGemini::queueFunctionCall('start_cooking', ['recipe_id' => $recipeId, 'servings' => 6]);
        FakeGemini::queueText('C\'est parti !');
        self::assertSame(6, $this->say($session, 'On sera 6 ce soir')['messages'][1]['blocks'][0]['servings']);

        $system = end(FakeGemini::$requests)['body']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('appelle start_cooking(recipe_id)', $system);
    }

    public function testStartCookingWithoutHouseholdUsesRecipeServingsAndRefusesOtherRecipes(): void
    {
        $bobToken = $this->registerUser('bob@example.com');
        FakeGemini::queueText('Salut !');
        $bobSession = $this->request('POST', '/sessions', ['type' => 'shopping'], $bobToken);
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili']);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueText('Voici.');
        $bobRecipe = $this->request('POST', \sprintf('/sessions/%d/messages', $bobSession['id']), ['text' => 'Une idée'], $bobToken)['messages'][1]['blocks'][0]['recipe']['id'];

        $session = $this->startSession();
        $recipeId = $this->proposeChili($session)['recipe']['id'];
        FakeGemini::queueFunctionCall('start_cooking', ['recipe_id' => $recipeId, 'servings' => 0]);
        FakeGemini::queueText('C\'est parti !');
        self::assertSame(2, $this->say($session, 'Je fais le chili')['messages'][1]['blocks'][0]['servings']); // portions de la recette

        foreach ([$bobRecipe, 999999] as $forbidden) {
            FakeGemini::queueFunctionCall('start_cooking', ['recipe_id' => $forbidden]);
            FakeGemini::queueText('Je ne trouve pas cette recette.');
            $blocks = $this->say($session, 'Je fais celle de Bob')['messages'][1]['blocks'];
            self::assertSame(['text'], array_column($blocks, 'type'));
            $response = end(end(FakeGemini::$requests)['body']['contents'])['parts'][0]['functionResponse']['response'];
            self::assertSame('error', $response['status']);
            self::assertStringContainsString('Recette introuvable', $response['error']);
        }
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
     * @return array<string, mixed> la carte recette
     */
    private function proposeChili(array $session): array
    {
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili végétarien', 'meals_covered' => 3]);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueText('Que dis-tu de ce chili ?');

        return $this->say($session, 'Une idée de plat ?')['messages'][1]['blocks'][0];
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
