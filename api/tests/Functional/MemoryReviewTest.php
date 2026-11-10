<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Gemini\GeminiException;
use App\Message\MemoryReviewMessage;
use App\MessageHandler\MemoryReviewHandler;
use App\Tests\Support\FakeGemini;
use App\Tests\Support\RecipeFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MemoryReviewTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot(); // garde le transport en mémoire entre les requêtes
        $this->token = $this->registerUser();
    }

    public function testClosingASessionTriggersReviewAndChipsInNextSession(): void
    {
        $first = $this->startSession();
        $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'poêle'], $this->token);
        FakeGemini::queueText('Pas de souci.');
        $this->say($first, 'Encore du poisson ? Non merci, on n\'aime vraiment pas ça. Et ma poêle est morte.');
        $second = $this->startSession();

        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        self::assertSame($first['id'], $sent[0]->getMessage()->sessionId);

        FakeGemini::queueJson(['operations' => [
            ['action' => 'add', 'category' => 'naime_pas', 'value' => 'poisson', 'reason' => '« on n\'aime vraiment pas ça »'],
            ['action' => 'delete', 'category' => 'ustensile', 'value' => 'poêle', 'reason' => 'cassée'],
            ['action' => 'delete', 'category' => 'ustensile', 'value' => 'wok', 'reason' => 'absent : ignoré'],
            ['action' => 'add', 'category' => 'couleur', 'value' => 'bleu', 'reason' => 'catégorie invalide : ignorée'],
        ]]);
        $this->handler()(new MemoryReviewMessage($first['id']));

        $review = end(FakeGemini::$requests)['body'];
        self::assertSame(['thinkingLevel' => 'medium'], $review['generationConfig']['thinkingConfig']);
        self::assertSame('application/json', $review['generationConfig']['responseMimeType']);
        self::assertStringContainsString('Ne retiens que ce qui sera encore vrai la semaine prochaine', $review['systemInstruction']['parts'][0]['text']);
        self::assertStringContainsString('UTILISATEUR : Encore du poisson ?', $review['contents'][0]['parts'][0]['text']);
        self::assertStringContainsString('Ustensiles [ustensile] : « poêle »', $review['contents'][0]['parts'][0]['text']);

        // Puces dans la nouvelle session (message system), récupérables avec after_id.
        $greetingId = $second['messages'][0]['id'];
        $messages = $this->request('GET', \sprintf('/sessions/%d/messages?after_id=%d', $second['id'], $greetingId), null, $this->token)['messages'];
        self::assertCount(1, $messages);
        self::assertSame('system', $messages[0]['role']);
        self::assertSame(['text', 'memory', 'memory'], array_column($messages[0]['blocks'], 'type'));
        self::assertSame(['add', 'naime_pas', 'poisson', 'batch'], array_values(array_intersect_key($messages[0]['blocks'][1]['event'], array_flip(['action', 'category', 'value', 'origin']))));
        self::assertSame(['delete', 'poêle'], [$messages[0]['blocks'][2]['event']['action'], $messages[0]['blocks'][2]['event']['old_value']]);

        // Les messages system ne sont pas envoyés à Gemini ; la mémoire à jour l'est.
        FakeGemini::queueText('OK');
        $this->say($second, 'Salut');
        $last = end(FakeGemini::$requests)['body'];
        self::assertSame(['user', 'model', 'user'], array_column($last['contents'], 'role'));
        self::assertStringContainsString('N\'aime pas [naime_pas] : « poisson »', $last['systemInstruction']['parts'][0]['text']);

        self::assertNotNull($this->db()->fetchOne('SELECT memory_reviewed_at FROM shopping_session WHERE id = ?', [$first['id']]));

        // Rejouer le message ne refait rien.
        $count = \count(FakeGemini::$requests);
        $this->handler()(new MemoryReviewMessage($first['id']));
        self::assertCount($count, FakeGemini::$requests);
    }

    public function testSessionWithoutUserMessageIsReviewedWithoutGemini(): void
    {
        $first = $this->startSession();
        $this->startSession();
        $count = \count(FakeGemini::$requests);

        $this->handler()(new MemoryReviewMessage($first['id']));

        self::assertCount($count, FakeGemini::$requests);
        self::assertNotNull($this->db()->fetchOne('SELECT memory_reviewed_at FROM shopping_session WHERE id = ?', [$first['id']]));
    }

    public function testGeminiFailureIsRetriedByMessenger(): void
    {
        $first = $this->startSession();
        FakeGemini::queueText('OK');
        $this->say($first, 'Bonjour');
        $this->startSession();
        FakeGemini::queueHttpError(400);

        try {
            $this->handler()(new MemoryReviewMessage($first['id']));
            self::fail('The exception must reach Messenger (retry).');
        } catch (GeminiException) {
        }

        self::assertNull($this->db()->fetchOne('SELECT memory_reviewed_at FROM shopping_session WHERE id = ?', [$first['id']]));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM generation_log WHERE purpose = 'memory_review' AND success = 0"));
    }

    public function testRuleOfThreeDeletesOldestSessionOnlyAfterReviewAndKeepsRecipes(): void
    {
        $s1 = $this->startSession();
        FakeGemini::queueFunctionCall('propose_recipe', ['brief' => 'chili']);
        FakeGemini::queueJson(RecipeFixtures::chili());
        FakeGemini::queueText('Voici.');
        $recipeId = $this->say($s1, 'Une idée')['messages'][1]['blocks'][0]['recipe']['id'];
        $s2 = $this->startSession();
        $s3 = $this->startSession();
        $s4 = $this->startSession(); // 4e conversation : s1 doit partir… mais pas avant sa relecture

        self::assertSame(200, $this->statusOf('GET', '/sessions/'.$s1['id']));

        FakeGemini::queueJson(['operations' => []]);
        $this->handler()(new MemoryReviewMessage($s1['id']));

        self::assertSame(404, $this->statusOf('GET', '/sessions/'.$s1['id']));
        foreach ([$s2, $s3, $s4] as $kept) {
            self::assertSame(200, $this->statusOf('GET', '/sessions/'.$kept['id']));
        }
        $list = $this->request('GET', '/sessions', null, $this->token);
        self::assertSame([$s3['id'], $s2['id']], array_column($list['archived'], 'id'));

        // Seule la conversation est supprimée : la recette reste.
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM recipe WHERE id = ?', [$recipeId]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM session_recipe WHERE recipe_id = ?', [$recipeId]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM message WHERE session_id = ?', [$s1['id']]));
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

    private function statusOf(string $method, string $uri): int
    {
        $this->request($method, $uri, null, $this->token);

        return $this->client->getResponse()->getStatusCode();
    }

    private function handler(): MemoryReviewHandler
    {
        return self::getContainer()->get(MemoryReviewHandler::class);
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
