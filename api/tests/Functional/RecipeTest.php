<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GenerationLog;
use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class RecipeTest extends ApiTestCase
{
    private const CLIENT_ID = '0b9d4a8e-1c2f-4e3a-9b7c-5d6e7f8a9b0c';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser();
    }

    public function testProposeRecipeGeneratesValidatesStoresAndShowsCard(): void
    {
        $this->request('POST', '/me/memory', ['category' => 'allergie', 'value' => 'arachides'], $this->token);
        $session = $this->startSession();
        $turn = $this->proposeChili($session);

        $blocks = $turn['messages'][1]['blocks'];
        self::assertSame(['recipe', 'text'], array_column($blocks, 'type'));
        $card = $blocks[0];
        self::assertSame(['proposed', 1, false], [$card['status'], $card['suggested_day'], $card['cooked']]);
        self::assertSame(['Ustensile absent de ton profil : cocotte'], $card['warnings']);
        self::assertSame('Chili sin carne', $card['recipe']['title']);
        self::assertSame(['name' => 'haricots rouges', 'quantity' => 800, 'unit' => 'g', 'category' => 'epicerie', 'optional' => false], $card['recipe']['ingredients'][0]);
        self::assertSame(['name' => 'sel', 'quantity' => null, 'unit' => null, 'category' => 'epicerie', 'optional' => false], $card['recipe']['ingredients'][3]);

        // 2e appel Gemini dédié : responseSchema, niveau de réflexion propre, prompt recipe_v1, allergies.
        $generation = FakeGemini::$requests[2]['body'];
        self::assertSame('application/json', $generation['generationConfig']['responseMimeType']);
        self::assertSame(['thinkingLevel' => 'low'], $generation['generationConfig']['thinkingConfig']);
        self::assertContains('piece', $generation['generationConfig']['responseSchema']['properties']['ingredients']['items']['properties']['unit']['enum']);
        $system = $generation['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('Tu es le chef de Recett\'IA', $system);
        self::assertStringContainsString('Allergies (CONTRAINTE ABSOLUE) [allergie] : « arachides »', $system);
        self::assertStringContainsString('Consigne de l\'assistant : chili végétarien', $generation['contents'][0]['parts'][0]['text']);

        // Le chat reçoit le résultat (id, titre) pour présenter la carte.
        $response = end(FakeGemini::$requests[3]['body']['contents'])['parts'][0]['functionResponse']['response'];
        self::assertSame(['ok', 'Chili sin carne', 3], [$response['status'], $response['title'], $response['meals_covered']]);

        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        self::assertSame('proposed', $detail['recipes'][0]['status']);
        self::assertSame($card['recipe']['id'], $detail['recipes'][0]['recipe']['id']);

        $purposes = array_map(static fn (GenerationLog $l): string => $l->getPurpose(), self::getContainer()->get(EntityManagerInterface::class)->getRepository(GenerationLog::class)->findBy([], ['id' => 'ASC']));
        self::assertSame(['greeting', 'conversation', 'propose_recipe', 'conversation'], $purposes);
    }

    public function testInvalidRecipeIsRetriedWithErrors(): void
    {
        $session = $this->startSession();
        $bad = RecipeFixtures::chili();
        $bad['ingredients'][1]['unit'] = null; // quantité sans unité
        $bad['suggested_day'] = 12;            // hors période
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili']);
        FakeGemini::queueJson($bad);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueText('Voici !');

        $blocks = $this->say($session, 'Une idée ?')['messages'][1]['blocks'];

        self::assertSame('recipe', $blocks[0]['type']);
        $retry = FakeGemini::$requests[3]['body']['contents'];
        self::assertSame(['user', 'model', 'user'], array_column($retry, 'role'));
        self::assertStringContainsString('une quantité doit avoir une unité', $retry[2]['parts'][0]['text']);
        self::assertStringContainsString('[suggested_day]', $retry[2]['parts'][0]['text']);
    }

    public function testRecipeStillInvalidAfterTwoAttemptsIsReportedToChat(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili']);
        FakeGemini::queueJson(['title' => 'x']);
        FakeGemini::queueJson(['title' => 'x']);
        FakeGemini::queueText('Désolé, je réessaie autrement.');

        $blocks = $this->say($session, 'Une idée ?')['messages'][1]['blocks'];

        self::assertSame(['text'], array_column($blocks, 'type'));
        $response = end(FakeGemini::$requests[4]['body']['contents'])['parts'][0]['functionResponse']['response'];
        self::assertSame('error', $response['status']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipe'));
    }

    public function testOnlyOneRecipeAtATime(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili']);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'autre']);
        FakeGemini::queueText('Voici une idée.');

        $blocks = $this->say($session, 'Deux idées ?')['messages'][1]['blocks'];

        self::assertSame(['recipe', 'text'], array_column($blocks, 'type'));
        $response = end(FakeGemini::$requests[4]['body']['contents'])['parts'][0]['functionResponse']['response'];
        self::assertStringContainsString('attend encore la réponse', $response['error']);
    }

    public function testAcceptButtonIsAnIdempotentTurn(): void
    {
        $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'cocotte en fonte'], $this->token);
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('set_meal_plan', ['total_meals' => 8, 'dinners' => 6, 'lunchboxes' => 2]);
        FakeGemini::queueText('Noté, 8 repas.');
        $this->say($session, '6 dîners et 2 gamelles');
        $recipeId = $this->proposeChili($session)['messages'][1]['blocks'][0]['recipe']['id'];
        $uri = \sprintf('/sessions/%d/recipes/%d/accept', $session['id'], $recipeId);

        FakeGemini::queueText('Super ! On continue ?');
        $turn = $this->request('POST', $uri, ['client_id' => self::CLIENT_ID], $this->token);

        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'action', 'action' => 'accept_recipe', 'recipe_id' => $recipeId, 'recipe_title' => 'Chili sin carne', 'comment' => null]], $turn['messages'][0]['blocks']);
        self::assertSame(['meals_planned' => 8, 'meals_covered' => 3], $turn['session']['progress']);
        $contents = end(FakeGemini::$requests)['body']['contents'];
        self::assertStringContainsString('[Bouton ✅] J\'accepte la recette « Chili sin carne »', end($contents)['parts'][0]['text']);
        self::assertStringContainsString('Repas couverts par les recettes acceptées : 3 sur 8', end(FakeGemini::$requests)['body']['systemInstruction']['parts'][0]['text']);

        // La carte affichée plus haut reflète l'état actuel ; l'avertissement « cocotte » n'existe pas (ustensile connu).
        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        $card = $detail['messages'][4]['blocks'][0];
        self::assertSame(['recipe', 'accepted', []], [$card['type'], $card['status'], $card['warnings']]);

        // Rejeu du même client_id : même réponse, pas de nouvel appel.
        $count = \count(FakeGemini::$requests);
        self::assertSame($turn['messages'], $this->request('POST', $uri, ['client_id' => self::CLIENT_ID], $this->token)['messages']);
        self::assertCount($count, FakeGemini::$requests);

        // Nouvelle tentative : déjà décidée.
        $body = $this->request('POST', $uri, ['client_id' => '11111111-2222-4333-8444-555555555555'], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('recipe_not_proposed', $body['error']);
    }

    public function testRejectButtonWithComment(): void
    {
        $session = $this->startSession();
        $recipeId = $this->proposeChili($session)['messages'][1]['blocks'][0]['recipe']['id'];

        FakeGemini::queueText('D\'accord, autre chose.');
        $turn = $this->request('POST', \sprintf('/sessions/%d/recipes/%d/reject', $session['id'], $recipeId), ['comment' => ' trop long à cuire '], $this->token);

        self::assertResponseIsSuccessful();
        self::assertSame(['reject_recipe', 'trop long à cuire'], [$turn['messages'][0]['blocks'][0]['action'], $turn['messages'][0]['blocks'][0]['comment']]);
        $last = end(FakeGemini::$requests)['body'];
        self::assertStringContainsString('Commentaire : trop long à cuire', end($last['contents'])['parts'][0]['text']);
        self::assertStringContainsString('« Chili sin carne » (recipe_id '.$recipeId.', 3 repas, jour 1) — « trop long à cuire »', $last['systemInstruction']['parts'][0]['text']);
        self::assertSame('rejected', $this->request('GET', '/sessions/'.$session['id'], null, $this->token)['recipes'][0]['status']);
    }

    public function testDecisionErrors(): void
    {
        $session = $this->startSession();
        $this->request('POST', \sprintf('/sessions/%d/recipes/999999/accept', $session['id']), null, $this->token);
        self::assertResponseStatusCodeSame(404);

        $recipeId = $this->proposeChili($session)['messages'][1]['blocks'][0]['recipe']['id'];
        $this->startSession(); // archive la session
        $body = $this->request('POST', \sprintf('/sessions/%d/recipes/%d/accept', $session['id'], $recipeId), null, $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('session_archived', $body['error']);
    }

    public function testWrittenDecisionMealPlanAndInventoryTools(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('set_inventory', ['items' => [['label' => 'Courgette', 'quantity' => 2, 'unit' => 'piece'], ['label' => 'riz']]]);
        FakeGemini::queueFunctionCall('set_meal_plan', ['total_meals' => 5, 'dinners' => 5, 'notes' => 'pas de four']);
        FakeGemini::queueText('Noté !');
        $turn = $this->say($session, 'J\'ai 2 courgettes et du riz, 5 dîners sans four');
        self::assertSame(['meals_planned' => 5, 'meals_covered' => 0], $turn['session']['progress']);

        $recipeId = $this->proposeChili($session)['messages'][1]['blocks'][0]['recipe']['id'];
        $system = end(FakeGemini::$requests)['body']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('courgette (2 piece), riz', $system);
        self::assertStringContainsString('Repas prévus : 5 (5 dîners) — notes : pas de four.', $system);

        // L'inventaire est remplacé, pas cumulé.
        FakeGemini::queueFunctionCall('set_inventory', ['items' => [['label' => 'riz']]]);
        FakeGemini::queueFunctionCall('record_recipe_decision', ['recipe_id' => $recipeId, 'decision' => 'accepted']);
        FakeGemini::queueText('Parfait.');
        $turn = $this->say($session, 'Oui parfait, et j\'ai mangé les courgettes');
        self::assertSame(3, $turn['session']['progress']['meals_covered']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM inventory_item WHERE session_id = ?', [$session['id']]));
        self::assertSame('accepted', $this->request('GET', '/sessions/'.$session['id'], null, $this->token)['recipes'][0]['status']);
    }

    public function testSuggestNewSessionBlock(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('suggest_new_session', ['reason' => 'Nouvelle semaine de courses.']);
        FakeGemini::queueText('Démarre une nouvelle liste !');

        $blocks = $this->say($session, 'Je refais mes courses demain pour la semaine prochaine')['messages'][1]['blocks'];

        self::assertSame(['type' => 'new_session', 'reason' => 'Nouvelle semaine de courses.'], $blocks[0]);
    }

    public function testShoppingToolsAreNotOfferedInOnboarding(): void
    {
        FakeGemini::queueText('Bienvenue');
        $session = $this->request('POST', '/sessions', ['type' => 'onboarding'], $this->token);
        FakeGemini::queueText('OK');
        $this->say($session, 'Salut');

        $names = array_column(end(FakeGemini::$requests)['body']['tools'][0]['functionDeclarations'], 'name');
        self::assertEqualsCanonicalizing(['update_memory', 'finish_onboarding', 'echo_test'], $names);
    }

    public function testRecipeDetailWithServingsAndSurvivesAccountDeletion(): void
    {
        $session = $this->startSession();
        $recipeId = $this->proposeChili($session)['messages'][1]['blocks'][0]['recipe']['id'];

        $recipe = $this->request('GET', "/recipes/$recipeId?servings=3", null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $recipe['servings']);
        self::assertSame([1200, 3, 30, null], array_column($recipe['ingredients'], 'quantity'));

        $this->request('GET', "/recipes/$recipeId", null, $this->registerUser('bob@example.com'));
        self::assertResponseStatusCodeSame(404);

        $this->request('DELETE', '/me', ['password' => 'correct-horse-battery'], $this->token);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipe WHERE id = ? AND created_by_id IS NULL', [$recipeId]));
        self::assertSame(4, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipe_ingredient WHERE recipe_id = ?', [$recipeId]));
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
    private function proposeChili(array $session): array
    {
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili végétarien pour 3 repas', 'meals_covered' => 3, 'suggested_day' => 1]);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueText('Que dis-tu de ce chili ?');

        return $this->say($session, 'Une idée de plat ?');
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
