<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GenerationLog;
use App\Message\MemoryReviewMessage;
use App\MessageHandler\MemoryReviewHandler;
use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class FavoriteTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot(); // garde le transport Messenger en mémoire (règle des 3)
        $this->token = $this->registerUser();
    }

    public function testFavoriteCrudIsIdempotentAndLimitedToProposedRecipes(): void
    {
        $session = $this->startSession();
        $recipeId = $this->proposeRecipe($session);

        self::assertSame(['favorites' => []], $this->request('GET', '/me/favorites', null, $this->token));

        foreach ([1, 2] as $_) {
            self::assertSame(['recipe_id' => $recipeId, 'favorite' => true], $this->request('PUT', "/me/favorites/$recipeId", null, $this->token));
            self::assertResponseStatusCodeSame(200);
        }
        $favorites = $this->request('GET', '/me/favorites', null, $this->token)['favorites'];
        self::assertCount(1, $favorites);
        self::assertSame(['Chili sin carne', 0], [$favorites[0]['recipe']['title'], $favorites[0]['times_accepted']]);
        self::assertSame($recipeId, $favorites[0]['recipe']['id']);
        self::assertCount(4, $favorites[0]['recipe']['ingredients']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $favorites[0]['favorited_at']));

        // Recette jamais proposée à cet utilisateur (ou inexistante) : 404.
        $bob = $this->registerUser('bob@example.com');
        $body = $this->request('PUT', "/me/favorites/$recipeId", null, $bob);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $body['error']);
        $this->request('PUT', '/me/favorites/999999', null, $this->token);
        self::assertResponseStatusCodeSame(404);

        foreach ([1, 2] as $_) {
            self::assertSame(['recipe_id' => $recipeId, 'favorite' => false], $this->request('DELETE', "/me/favorites/$recipeId", null, $this->token));
            self::assertResponseStatusCodeSame(200);
        }
        self::assertSame(['favorites' => []], $this->request('GET', '/me/favorites', null, $this->token));
    }

    public function testFavoritesAreListedMostRecentFirstAndDeletedWithTheAccount(): void
    {
        $session = $this->startSession();
        $chili = $this->proposeRecipe($session);
        $this->decide($session, $chili, 'reject');
        $curry = $this->proposeRecipe($session, 'Curry de lentilles');
        $this->request('PUT', "/me/favorites/$chili", null, $this->token);
        $this->request('PUT', "/me/favorites/$curry", null, $this->token);

        $titles = array_column(array_column($this->request('GET', '/me/favorites', null, $this->token)['favorites'], 'recipe'), 'title');
        self::assertSame(['Curry de lentilles', 'Chili sin carne'], $titles);

        $this->request('DELETE', '/me', ['password' => 'correct-horse-battery'], $this->token);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM favorite_recipe'));
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipe WHERE id IN (?, ?)', [$chili, $curry]));
    }

    public function testFavoriteFieldInChatBlocksAndSessionDetail(): void
    {
        $session = $this->startSession();
        $turn = $this->proposeTurn($session);
        $recipeId = $turn['messages'][1]['blocks'][0]['recipe']['id'];
        self::assertFalse($turn['messages'][1]['blocks'][0]['favorite']);
        self::assertSame(['status', 'favorite', 'suggested_day', 'cooked', 'warnings', 'recipe'], array_slice(array_keys($turn['messages'][1]['blocks'][0]), 1));

        $this->request('PUT', "/me/favorites/$recipeId", null, $this->token);
        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        self::assertTrue($detail['recipes'][0]['favorite']);
        self::assertTrue($this->recipeCard($detail)['favorite']);
        $messages = $this->request('GET', '/sessions/'.$session['id'].'/messages', null, $this->token)['messages'];
        self::assertTrue($messages[2]['blocks'][0]['favorite']);

        $this->request('DELETE', "/me/favorites/$recipeId", null, $this->token);
        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        self::assertFalse($detail['recipes'][0]['favorite']);
        self::assertFalse($this->recipeCard($detail)['favorite']);
    }

    public function testFavoriteSurvivesRuleOfThreeAndCountsAcceptances(): void
    {
        $s1 = $this->startSession();
        $recipeId = $this->proposeRecipe($s1);
        $this->decide($s1, $recipeId, 'accept');
        $this->request('PUT', "/me/favorites/$recipeId", null, $this->token);
        self::assertSame(1, $this->request('GET', '/me/favorites', null, $this->token)['favorites'][0]['times_accepted']);

        $s2 = $this->startSession();
        $this->addFavorite($s2, $recipeId);
        self::assertResponseIsSuccessful();
        $this->startSession();
        $s4 = $this->startSession();
        FakeGemini::queueJson(['operations' => []]);
        $this->handler()(new MemoryReviewMessage($s1['id'])); // relecture de s1 → supprimée (règle des 3)

        $this->request('GET', '/sessions/'.$s1['id'], null, $this->token);
        self::assertResponseStatusCodeSame(404);
        $favorites = $this->request('GET', '/me/favorites', null, $this->token)['favorites'];
        self::assertSame([$recipeId, 2], [$favorites[0]['recipe']['id'], $favorites[0]['times_accepted']]);
        $this->request('GET', "/recipes/$recipeId", null, $this->token);
        self::assertResponseIsSuccessful();

        // Retirée puis remise en favori après la suppression de sa session : toujours possible (created_by).
        $this->request('DELETE', "/me/favorites/$recipeId", null, $this->token);
        $this->request('PUT', "/me/favorites/$recipeId", null, $this->token);
        self::assertResponseIsSuccessful();
        // Le compteur repart des acceptations encore en base (s2).
        self::assertSame(1, $this->request('GET', '/me/favorites', null, $this->token)['favorites'][0]['times_accepted']);

        $this->addFavorite($s4, $recipeId);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->request('GET', '/me/favorites', null, $this->token)['favorites'][0]['times_accepted']);
    }

    public function testAddFavoriteDirectlyToSession(): void
    {
        $s1 = $this->startSession();
        $chili = $this->proposeRecipe($s1);
        $this->request('PUT', "/me/favorites/$chili", null, $this->token);

        $s2 = $this->startSession();
        FakeGemini::queueFunctionCall('set_meal_plan', ['total_meals' => 6, 'dinners' => 6]);
        FakeGemini::queueText('Noté.');
        $this->say($s2, '6 dîners');
        $curry = $this->proposeRecipe($s2, 'Curry de lentilles');
        $this->decide($s2, $curry, 'accept');
        FakeGemini::queueFunctionCall('generate_shopping_list', ['type' => 'main']);
        FakeGemini::queueText('Voici ta liste.');
        $listId = $this->say($s2, 'La liste stp')['messages'][1]['blocks'][0]['list']['id'];
        self::assertFalse($this->request('GET', "/shopping-lists/$listId", null, $this->token)['outdated']);

        $calls = \count(FakeGemini::$requests);
        $turn = $this->addFavorite($s2, $chili);

        self::assertResponseStatusCodeSame(200);
        self::assertCount($calls, FakeGemini::$requests); // aucun appel Gemini
        self::assertCount(1, $turn['messages']);
        self::assertSame('system', $turn['messages'][0]['role']);
        self::assertSame([['type' => 'text', 'text' => '❤️ « Chili sin carne » ajoutée depuis tes favoris.']], $turn['messages'][0]['blocks']);
        self::assertSame(['meals_planned' => 6, 'meals_covered' => 6], $turn['session']['progress']);
        self::assertSame($s2['id'], $turn['session']['id']);
        self::assertTrue($this->request('GET', "/shopping-lists/$listId", null, $this->token)['outdated']);

        $detail = $this->request('GET', '/sessions/'.$s2['id'], null, $this->token);
        $added = end($detail['recipes']);
        self::assertSame([$chili, 'accepted', true, null], [$added['recipe']['id'], $added['status'], $added['favorite'], $added['suggested_day']]);
        self::assertSame($turn['messages'][0], end($detail['messages']));

        // L'IA voit la recette dans l'état de la session au tour suivant.
        FakeGemini::queueText('Super.');
        $this->say($s2, 'Et maintenant ?');
        self::assertStringContainsString('« Chili sin carne » (recipe_id '.$chili.', 3 repas)', $this->systemInstruction()); // recettes acceptées
        self::assertStringContainsString('2 personnes) [déjà acceptée dans cette session]', $this->systemInstruction());       // favoris

        // Erreurs.
        $body = $this->addFavorite($s2, $chili);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('recipe_already_in_session', $body['error']);
        $body = $this->addFavorite($s2, $curry); // pas en favori
        self::assertResponseStatusCodeSame(404);
        self::assertSame('not_found', $body['error']);
        $this->addFavorite(['id' => 999999], $chili);
        self::assertResponseStatusCodeSame(404);
        $this->request('PUT', "/me/favorites/$curry", null, $this->token);
        $body = $this->addFavorite($s1, $curry); // s1 archivée
        self::assertResponseStatusCodeSame(409);
        self::assertSame('session_archived', $body['error']);
        $body = $this->request('POST', '/sessions/'.$s2['id'].'/favorites/'.$chili, null, $this->registerUser('bob@example.com'));
        self::assertResponseStatusCodeSame(404);

        FakeGemini::queueText('Bienvenue');
        $onboarding = $this->request('POST', '/sessions', ['type' => 'onboarding'], $this->token);
        $body = $this->addFavorite($onboarding, $chili);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_shopping_session', $body['error']);
    }

    public function testProposeFavoriteShowsCardWithoutSecondGeminiCall(): void
    {
        $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'cocotte'], $this->token);
        $s1 = $this->startSession();
        $chili = $this->proposeRecipe($s1);
        $this->request('PUT', "/me/favorites/$chili", null, $this->token);

        $s2 = $this->startSession();
        self::assertStringContainsString('« Chili sin carne » (recipe_id '.$chili.', 3 repas, batch_cooking/gamelle, 20 min de préparation + 40 min de cuisson, 2 personnes)', $this->systemInstruction());
        FakeGemini::$requests = [];
        FakeGemini::queueFunctionCall('propose_favorite', ['recipe_id' => $chili, 'suggested_day' => 2]);
        FakeGemini::queueText('Et si tu refaisais une de tes favorites ?');
        $turn = $this->say($s2, 'Refais le chili');

        self::assertCount(2, FakeGemini::$requests); // pas d'appel de génération de recette
        $card = $turn['messages'][1]['blocks'][0];
        self::assertSame(['recipe', 'proposed', true, 2, []], [$card['type'], $card['status'], $card['favorite'], $card['suggested_day'], $card['warnings']]);
        self::assertSame($chili, $card['recipe']['id']);
        $response = end(FakeGemini::$requests[1]['body']['contents'])['parts'][0]['functionResponse']['response'];
        self::assertSame(['ok', $chili, 'Chili sin carne'], [$response['status'], $response['recipe_id'], $response['title']]);
        $purposes = array_map(static fn (GenerationLog $l): string => $l->getPurpose(), $this->em()->getRepository(GenerationLog::class)->findBy([], ['id' => 'ASC']));
        self::assertNotContains('propose_recipe', \array_slice($purposes, -2));

        // Refusé dans la session : marqué dans le contexte, et l'outil refuse de le reproposer.
        $this->decide($s2, $chili, 'reject');
        self::assertStringContainsString('[REFUSÉE dans cette session : ne pas la reproposer]', $this->systemInstruction());
        FakeGemini::queueFunctionCall('propose_favorite', ['recipe_id' => $chili]);
        FakeGemini::queueText('D\'accord.');
        $this->say($s2, 'Remets le chili');
        self::assertSame('error', $this->lastFunctionResponse()['status']);
        self::assertStringContainsString('refusé', $this->lastFunctionResponse()['error']);

        // Recette qui n'est pas un favori (même proposée à l'utilisateur) : refusée.
        $curry = $this->proposeRecipe($s2, 'Curry de lentilles');
        $this->decide($s2, $curry, 'accept');
        FakeGemini::queueFunctionCall('propose_favorite', ['recipe_id' => $curry]);
        FakeGemini::queueText('Oups.');
        $this->say($s2, 'Encore le curry');
        self::assertStringContainsString('pas une recette favorite', $this->lastFunctionResponse()['error']);
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM session_recipe WHERE session_id = ?', [$s2['id']]));
    }

    public function testProposeFavoriteIsOfferedToTheChat(): void
    {
        $this->startSession();
        FakeGemini::$requests = [];
        $session = $this->startSession();
        FakeGemini::queueText('OK');
        $this->say($session, 'Salut');

        self::assertContains('propose_favorite', array_column(end(FakeGemini::$requests)['body']['tools'][0]['functionDeclarations'], 'name'));
        self::assertStringContainsString('Recettes favorites de l\'utilisateur : aucune.', $this->systemInstruction());
        self::assertStringContainsString('propose_favorite', $this->systemInstruction());
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
    private function proposeTurn(array $session, string $title = 'Chili sin carne'): array
    {
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => $title, 'meals_covered' => 3]);
        FakeGemini::queueJson(RecipeFixtures::chili(['title' => $title]));
        FakeGemini::queueText('Qu\'en dis-tu ?');

        return $this->say($session, 'Une idée de plat ?');
    }

    /** @param array<string, mixed> $session */
    private function proposeRecipe(array $session, string $title = 'Chili sin carne'): int
    {
        return $this->proposeTurn($session, $title)['messages'][1]['blocks'][0]['recipe']['id'];
    }

    /** @param array<string, mixed> $session */
    private function decide(array $session, int $recipeId, string $decision): void
    {
        FakeGemini::queueText('OK.');
        $this->request('POST', \sprintf('/sessions/%d/recipes/%d/%s', $session['id'], $recipeId, $decision), null, $this->token);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function addFavorite(array $session, int $recipeId): array
    {
        return $this->request('POST', \sprintf('/sessions/%d/favorites/%d', $session['id'], $recipeId), null, $this->token);
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

    /**
     * @param array<string, mixed> $detail
     *
     * @return array<string, mixed>
     */
    private function recipeCard(array $detail): array
    {
        foreach ($detail['messages'] as $message) {
            foreach ($message['blocks'] as $block) {
                if ('recipe' === $block['type']) {
                    return $block;
                }
            }
        }
        self::fail('Aucune carte recette.');
    }

    private function systemInstruction(): string
    {
        return end(FakeGemini::$requests)['body']['systemInstruction']['parts'][0]['text'];
    }

    /** @return array<string, mixed> */
    private function lastFunctionResponse(): array
    {
        return end(end(FakeGemini::$requests)['body']['contents'])['parts'][0]['functionResponse']['response'];
    }

    private function handler(): MemoryReviewHandler
    {
        return self::getContainer()->get(MemoryReviewHandler::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
