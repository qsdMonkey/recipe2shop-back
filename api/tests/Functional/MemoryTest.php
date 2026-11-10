<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeGemini;
use Doctrine\DBAL\Connection;

final class MemoryTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser();
    }

    public function testUpdateMemoryToolProducesHydratedMemoryBlock(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'ustensile', 'value' => 'Airfryer', 'reason' => 'achat']);
        FakeGemini::queueText('Top, un airfryer !');

        $turn = $this->say($session, 'J\'ai acheté un airfryer');

        $blocks = $turn['messages'][1]['blocks'];
        self::assertSame('memory', $blocks[0]['type']);
        $event = $blocks[0]['event'];
        self::assertSame(['action' => 'add', 'category' => 'ustensile', 'value' => 'Airfryer', 'old_value' => null, 'origin' => 'live', 'undone' => false], array_diff_key($event, ['id' => 0]));
        self::assertSame(['type' => 'text', 'text' => 'Top, un airfryer !'], $blocks[1]);

        // L'événement est rattaché au message de l'IA.
        self::assertSame($turn['messages'][1]['id'], (int) $this->db()->fetchOne('SELECT message_id FROM memory_event WHERE id = ?', [$event['id']]));

        self::assertSame(['Airfryer'], $this->values('ustensile'));

        // La mémoire est dans le contexte du tour suivant.
        FakeGemini::queueText('OK');
        $this->say($session, 'Et maintenant ?');
        self::assertStringContainsString('Ustensiles [ustensile] : « Airfryer »', $this->lastSystemInstruction());
    }

    public function testDuplicateAddIsSilentAndInvalidOperationsAreSentBackToGemini(): void
    {
        $session = $this->startSession();
        $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'airfryer'], $this->token);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'ustensile', 'value' => 'AIRFRYER', 'reason' => 'x']);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'delete', 'category' => 'ustensile', 'value' => 'poêle', 'reason' => 'x']);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'couleur', 'value' => 'bleu', 'reason' => 'x']);
        FakeGemini::queueText('Fini.');

        $turn = $this->say($session, 'x');

        self::assertSame(['text'], array_column($turn['messages'][1]['blocks'], 'type'));
        $responses = $this->functionResponses();
        self::assertSame('already_known', $responses[0]['status']);
        self::assertSame('error', $responses[1]['status']);
        self::assertStringContainsString('« airfryer »', $responses[1]['details'][0]);
        self::assertSame('Catégorie invalide.', $responses[2]['error']);
    }

    public function testUpdateDeleteAndSingleValuedCategories(): void
    {
        $session = $this->startSession();
        $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'poêle'], $this->token);
        $this->request('POST', '/me/memory', ['category' => 'frequence_courses', 'value' => 'toutes les semaines'], $this->token);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'delete', 'category' => 'ustensile', 'value' => 'Poele', 'reason' => 'cassée']);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'frequence_courses', 'value' => 'toutes les 2 semaines', 'reason' => 'x']);
        FakeGemini::queueText('Noté.');

        $blocks = $this->say($session, 'x')['messages'][1]['blocks'];

        self::assertSame(['action' => 'delete', 'category' => 'ustensile', 'value' => null, 'old_value' => 'poêle'], array_intersect_key($blocks[0]['event'], array_flip(['action', 'category', 'value', 'old_value'])));
        // Catégorie à valeur unique : l'ajout remplace (update).
        self::assertSame(['action' => 'update', 'value' => 'toutes les 2 semaines', 'old_value' => 'toutes les semaines'], array_intersect_key($blocks[1]['event'], array_flip(['action', 'value', 'old_value'])));
        self::assertSame([], $this->values('ustensile'));
        self::assertSame(['toutes les 2 semaines'], $this->values('frequence_courses'));
    }

    public function testAddThenDeleteInTheSameTurn(): void
    {
        $session = $this->startSession();
        $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'poêle'], $this->token);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'ustensile', 'value' => 'wok', 'reason' => 'x']);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'delete', 'category' => 'ustensile', 'value' => 'wok', 'reason' => 'finalement non']);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'delete', 'category' => 'ustensile', 'value' => 'poêle', 'reason' => 'cassée']);
        FakeGemini::queueFunctionCall('set_meal_plan', ['total_meals' => 3]);
        FakeGemini::queueText('Noté.');

        $blocks = $this->say($session, 'J\'ai un wok… ah non. Et ma poêle est cassée. 3 dîners.')['messages'][1]['blocks'];

        self::assertSame(['memory', 'memory', 'memory', 'text'], array_column($blocks, 'type'));
        self::assertSame([], $this->values('ustensile'));
    }

    public function testRealValueReplacesNoneSilently(): void
    {
        $session = $this->startSession();
        $this->request('POST', '/me/memory', ['category' => 'allergie', 'value' => 'aucune'], $this->token);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'allergie', 'value' => 'arachides', 'reason' => 'x']);
        FakeGemini::queueText('Noté.');

        $blocks = $this->say($session, 'x')['messages'][1]['blocks'];

        self::assertCount(1, array_filter($blocks, static fn (array $b): bool => 'memory' === $b['type']));
        self::assertSame(['arachides'], $this->values('allergie'));

        FakeGemini::queueText('OK');
        $this->say($session, 'y');
        self::assertStringContainsString('Allergies (CONTRAINTE ABSOLUE) [allergie] : « arachides »', $this->lastSystemInstruction());
    }

    public function testUndoFromChatChip(): void
    {
        $session = $this->startSession();
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'naime_pas', 'value' => 'poisson', 'reason' => 'x']);
        FakeGemini::queueText('Noté.');
        $eventId = $this->say($session, 'x')['messages'][1]['blocks'][0]['event']['id'];

        $body = $this->request('POST', "/me/memory/events/$eventId/undo", null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertTrue($body['event']['undone']);
        self::assertSame([], $this->values('naime_pas'));

        // La puce s'affiche « annulée » à la relecture de la session.
        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        self::assertTrue($detail['messages'][2]['blocks'][0]['event']['undone']);

        $body = $this->request('POST', "/me/memory/events/$eventId/undo", null, $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('already_undone', $body['error']);
    }

    public function testUndoUpdateAndDelete(): void
    {
        $item = $this->request('POST', '/me/memory', ['category' => 'foyer', 'value' => '2 adultes'], $this->token)['item'];
        $update = $this->request('PATCH', '/me/memory/'.$item['id'], ['value' => '2 adultes, 1 enfant'], $this->token)['event'];
        self::assertSame(['update', '2 adultes, 1 enfant', '2 adultes'], [$update['action'], $update['value'], $update['old_value']]);

        $this->request('POST', '/me/memory/events/'.$update['id'].'/undo', null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(['2 adultes'], $this->values('foyer'));

        $ustensile = $this->request('POST', '/me/memory', ['category' => 'ustensile', 'value' => 'wok'], $this->token)['item'];
        $this->request('DELETE', '/me/memory/'.$ustensile['id'], null, $this->token);
        self::assertResponseStatusCodeSame(204);
        $deleteEvent = (int) $this->db()->fetchOne("SELECT id FROM memory_event WHERE action = 'delete' AND old_value = 'wok'");
        $this->request('POST', "/me/memory/events/$deleteEvent/undo", null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(['wok'], $this->values('ustensile'));
    }

    public function testUndoUpdateConflictsWhenValueWasDeleted(): void
    {
        $item = $this->request('POST', '/me/memory', ['category' => 'niveau_cuisine', 'value' => 'débutant'], $this->token)['item'];
        $update = $this->request('PATCH', '/me/memory/'.$item['id'], ['value' => 'intermédiaire'], $this->token)['event'];
        $this->request('DELETE', '/me/memory/'.$item['id'], null, $this->token);

        $body = $this->request('POST', '/me/memory/events/'.$update['id'].'/undo', null, $this->token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('undo_conflict', $body['error']);
    }

    public function testMemoryPage(): void
    {
        $page = $this->request('GET', '/me/memory', null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['foyer', 'allergie', 'intolerance', 'regime', 'aime', 'naime_pas', 'ustensile', 'habitude', 'frequence_courses', 'contrainte', 'niveau_cuisine'],
            array_column($page['categories'], 'category'),
        );
        self::assertSame([], $page['categories'][0]['items']);

        $created = $this->request('POST', '/me/memory', ['category' => 'aime', 'value' => '  Cuisine thaï  '], $this->token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Cuisine thaï', $created['item']['value']);
        self::assertSame(['add', 'user'], [$created['event']['action'], $created['event']['origin']]);

        $body = $this->request('POST', '/me/memory', ['category' => 'aime', 'value' => 'cuisine THAI'], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('memory_duplicate', $body['error']);

        $this->request('POST', '/me/memory', ['category' => 'foyer', 'value' => 'seul'], $this->token);
        self::assertResponseStatusCodeSame(201);
        $replaced = $this->request('POST', '/me/memory', ['category' => 'foyer', 'value' => 'en couple'], $this->token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('update', $replaced['event']['action']);
        self::assertSame(['en couple'], $this->values('foyer'));

        $patched = $this->request('PATCH', '/me/memory/'.$created['item']['id'], ['value' => 'cuisine japonaise'], $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame('cuisine japonaise', $patched['item']['value']);

        $body = $this->request('POST', '/me/memory', ['category' => 'couleur', 'value' => ''], $this->token);
        self::assertResponseStatusCodeSame(422);
        self::assertEqualsCanonicalizing(['category', 'value'], array_column($body['violations'], 'field'));
    }

    public function testCannotTouchAnotherUsersMemory(): void
    {
        $item = $this->request('POST', '/me/memory', ['category' => 'aime', 'value' => 'pâtes'], $this->token);
        $bob = $this->registerUser('bob@example.com');

        $this->request('PATCH', '/me/memory/'.$item['item']['id'], ['value' => 'riz'], $bob);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', '/me/memory/'.$item['item']['id'], null, $bob);
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', '/me/memory/events/'.$item['event']['id'].'/undo', null, $bob);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->request('GET', '/me/memory', null, $bob)['categories'][4]['items']);
    }

    public function testOnboardingFinishFlow(): void
    {
        FakeGemini::queueText('Bienvenue !');
        $session = $this->request('POST', '/sessions', ['type' => 'onboarding'], $this->token);
        self::assertStringContainsString('Minimum de l\'onboarding encore manquant : le foyer ; la fréquence des courses', FakeGemini::$requests[0]['body']['systemInstruction']['parts'][0]['text']);

        // Trop tôt : le code refuse et dit à l'IA ce qui manque.
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'foyer', 'value' => '2 adultes', 'reason' => 'x']);
        FakeGemini::queueFunctionCall('finish_onboarding', []);
        FakeGemini::queueText('Et tu fais tes courses tous les combien ?');
        $blocks = $this->say($session, 'On est 2')['messages'][1]['blocks'];
        self::assertSame(['memory', 'text'], array_column($blocks, 'type'));
        self::assertStringContainsString('la fréquence des courses', $this->functionResponses()[1]['details'][0]);
        self::assertContains('finish_onboarding', array_column(FakeGemini::$requests[1]['body']['tools'][0]['functionDeclarations'], 'name'));

        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'frequence_courses', 'value' => 'toutes les semaines', 'reason' => 'x']);
        FakeGemini::queueFunctionCall('update_memory', ['action' => 'add', 'category' => 'allergie', 'value' => 'aucune', 'reason' => 'x']);
        FakeGemini::queueFunctionCall('finish_onboarding', []);
        FakeGemini::queueText('J\'ai tout ce qu\'il faut !');
        $blocks = $this->say($session, 'Toutes les semaines, pas d\'allergie')['messages'][1]['blocks'];
        self::assertSame(['memory', 'memory', 'finish_onboarding', 'text'], array_column($blocks, 'type'));
        self::assertSame(['type' => 'finish_onboarding'], $blocks[2]);
        // L'appel sans argument est renvoyé à Gemini avec "args": {} (et non [], refusé par Gemini).
        self::assertStringContainsString('"functionCall":{"name":"finish_onboarding","args":{}}', end(FakeGemini::$requests)['raw']);

        // Rappel de l'outil : pas de second bouton.
        FakeGemini::queueFunctionCall('finish_onboarding', []);
        FakeGemini::queueText('Tu peux terminer quand tu veux.');
        $blocks = $this->say($session, 'Autre chose ?')['messages'][1]['blocks'];
        self::assertSame(['text'], array_column($blocks, 'type'));
        $responses = $this->functionResponses();
        self::assertSame('already_shown', end($responses)['status']);

        $summary = $this->request('POST', \sprintf('/sessions/%d/finish-onboarding', $session['id']), null, $this->token);
        self::assertResponseIsSuccessful();
        self::assertSame('archived', $summary['status']);
        self::assertTrue($this->request('GET', '/me', null, $this->token)['onboarding_completed']);

        $this->request('POST', \sprintf('/sessions/%d/finish-onboarding', $session['id']), null, $this->token);
        self::assertResponseIsSuccessful(); // idempotent

        $body = $this->request('POST', '/sessions', ['type' => 'onboarding'], $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('onboarding_completed', $body['error']);

        $body = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'encore'], $this->token);
        self::assertSame('session_archived', $body['error']);
    }

    public function testOnboardingCanBeSkippedAndShoppingSessionIsNotOnboarding(): void
    {
        FakeGemini::queueText('Bienvenue !');
        $onboarding = $this->request('POST', '/sessions', ['type' => 'onboarding'], $this->token);
        $this->request('POST', \sprintf('/sessions/%d/finish-onboarding', $onboarding['id']), null, $this->token);
        self::assertResponseIsSuccessful();

        $shopping = $this->startSession();
        $body = $this->request('POST', \sprintf('/sessions/%d/finish-onboarding', $shopping['id']), null, $this->token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_onboarding', $body['error']);
    }

    public function testAccountDeletionRemovesMemory(): void
    {
        $this->request('POST', '/me/memory', ['category' => 'allergie', 'value' => 'arachides'], $this->token);

        $this->request('DELETE', '/me', ['password' => 'correct-horse-battery'], $this->token);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM user_memory'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM memory_event'));
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

    /** @return list<string> */
    private function values(string $category): array
    {
        foreach ($this->request('GET', '/me/memory', null, $this->token)['categories'] as $group) {
            if ($group['category'] === $category) {
                return array_column($group['items'], 'value');
            }
        }

        return [];
    }

    private function lastSystemInstruction(): string
    {
        return end(FakeGemini::$requests)['body']['systemInstruction']['parts'][0]['text'];
    }

    /** @return list<array<string, mixed>> réponses d'outils renvoyées à Gemini pendant le dernier tour */
    private function functionResponses(): array
    {
        $responses = [];
        foreach (FakeGemini::$requests as $request) {
            $last = end($request['body']['contents']);
            foreach ($last['parts'] as $part) {
                if (isset($part['functionResponse'])) {
                    $responses[] = $part['functionResponse']['response'];
                }
            }
        }

        return $responses;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
