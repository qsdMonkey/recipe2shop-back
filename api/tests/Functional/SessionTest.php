<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GenerationLog;
use App\Tests\Support\FakeGemini;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class SessionTest extends ApiTestCase
{
    private const CLIENT_ID = '7b0e3c3e-2f6a-4f7e-9a4c-1d2e3f4a5b6c';

    public function testCreateShoppingSessionReturnsAiGreeting(): void
    {
        $token = $this->registerUser();
        FakeGemini::queueText('Salut ! Qu\'as-tu dans ton frigo ?');

        $session = $this->request('POST', '/sessions', ['type' => 'shopping'], $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('shopping', $session['type']);
        self::assertSame('active', $session['status']);
        self::assertFalse($session['read_only']);
        self::assertSame(7, $session['period_days']);
        self::assertSame(['meals_planned' => null, 'meals_covered' => 0], $session['progress']);
        self::assertSame([], $session['recipes']);
        self::assertSame([], $session['shopping_lists']);
        self::assertCount(1, $session['messages']);
        self::assertSame('assistant', $session['messages'][0]['role']);
        self::assertSame([['type' => 'text', 'text' => 'Salut ! Qu\'as-tu dans ton frigo ?']], $session['messages'][0]['blocks']);

        // Requête envoyée à Gemini : prompt système versionné + contexte, message automatique d'accueil, pas d'outils.
        $request = FakeGemini::$requests[0];
        self::assertStringContainsString('models/gemini-test:generateContent', $request['url']);
        self::assertContains('x-goog-api-key: test-key', $request['headers']);
        $system = $request['body']['systemInstruction']['parts'][0]['text'];
        self::assertStringContainsString('session de courses', $system);
        self::assertStringContainsString('Date du jour', $system);
        self::assertCount(1, $request['body']['contents']);
        self::assertSame('user', $request['body']['contents'][0]['role']);
        self::assertStringContainsString('Message automatique', $request['body']['contents'][0]['parts'][0]['text']);
        self::assertArrayNotHasKey('tools', $request['body']);
    }

    public function testPeriodComesFromShoppingFrequencyInMemory(): void
    {
        $token = $this->registerUser();
        $this->request('POST', '/me/memory', ['category' => 'frequence_courses', 'value' => 'une fois par mois'], $token);
        FakeGemini::queueText('Salut !');

        $session = $this->request('POST', '/sessions', ['type' => 'shopping'], $token);

        self::assertSame(30, $session['period_days']);
        self::assertStringContainsString('période prévue : 30 jours', FakeGemini::$requests[0]['body']['systemInstruction']['parts'][0]['text']);
    }

    public function testEmptyBodyCreatesShoppingSession(): void
    {
        $token = $this->registerUser();
        FakeGemini::queueText('Salut !');

        $session = $this->request('POST', '/sessions', null, $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('shopping', $session['type']);
    }

    public function testGreetingFallsBackToStaticTextWhenGeminiFails(): void
    {
        $token = $this->registerUser();
        FakeGemini::queueHttpError(500); // accueil : pas de nouvel essai (durée plafonnée)

        $session = $this->request('POST', '/sessions', ['type' => 'shopping'], $token);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('On prépare tes courses', $session['messages'][0]['blocks'][0]['text']);
        self::assertSame(0, FakeGemini::remaining());
    }

    public function testNewShoppingSessionArchivesThePreviousOne(): void
    {
        $token = $this->registerUser();
        $first = $this->createSession($token);
        $second = $this->createSession($token);

        $list = $this->request('GET', '/sessions', null, $token);
        self::assertResponseIsSuccessful();
        self::assertSame($second['id'], $list['active']['id']);
        self::assertCount(1, $list['archived']);
        self::assertSame($first['id'], $list['archived'][0]['id']);
        self::assertSame('archived', $list['archived'][0]['status']);
        self::assertNotNull($list['archived'][0]['closed_at']);

        $archived = $this->request('GET', '/sessions/'.$first['id'], null, $token);
        self::assertTrue($archived['read_only']);
    }

    public function testArchivedListShowsAtMostTwoSessions(): void
    {
        $token = $this->registerUser();
        for ($i = 0; $i < 4; ++$i) {
            $this->createSession($token);
        }

        self::assertCount(2, $this->request('GET', '/sessions', null, $token)['archived']);
    }

    public function testSendMessageReturnsTurnResponse(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token, 'Salut, qu\'as-tu dans ton frigo ?');
        FakeGemini::queueText('Super, des courgettes ! Combien de repas ?');

        $turn = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => '  Des courgettes  ', 'client_id' => self::CLIENT_ID], $token);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $turn['messages']);
        [$user, $assistant] = $turn['messages'];
        self::assertSame('user', $user['role']);
        self::assertSame([['type' => 'text', 'text' => 'Des courgettes']], $user['blocks']);
        self::assertSame('assistant', $assistant['role']);
        self::assertSame([['type' => 'text', 'text' => 'Super, des courgettes ! Combien de repas ?']], $assistant['blocks']);
        self::assertGreaterThan($user['id'], $assistant['id']);
        self::assertSame($session['id'], $turn['session']['id']);
        self::assertNotNull($turn['session']['last_message_at']);

        // Historique envoyé à Gemini : message automatique, accueil de l'IA, nouveau message.
        $contents = FakeGemini::$requests[1]['body']['contents'];
        self::assertSame(['user', 'model', 'user'], array_column($contents, 'role'));
        self::assertSame('Salut, qu\'as-tu dans ton frigo ?', $contents[1]['parts'][0]['text']);
        self::assertSame('Des courgettes', $contents[2]['parts'][0]['text']);

        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $token);
        self::assertSame(['assistant', 'user', 'assistant'], array_column($detail['messages'], 'role'));
    }

    public function testSameClientIdReturnsSameTurnWithoutCallingGeminiAgain(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        FakeGemini::queueText('Réponse unique');
        $uri = \sprintf('/sessions/%d/messages', $session['id']);

        $first = $this->request('POST', $uri, ['text' => 'Bonjour', 'client_id' => self::CLIENT_ID], $token);
        $second = $this->request('POST', $uri, ['text' => 'Bonjour', 'client_id' => strtoupper(self::CLIENT_ID)], $token);

        self::assertResponseIsSuccessful();
        self::assertSame($first['messages'], $second['messages']);
        self::assertCount(2, FakeGemini::$requests); // accueil + 1 seul tour
        self::assertCount(3, $this->request('GET', '/sessions/'.$session['id'], null, $token)['messages']);
    }

    public function testGeminiFailureKeepsNothingAndSameClientIdCanBeRetried(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        $uri = \sprintf('/sessions/%d/messages', $session['id']);
        FakeGemini::queueHttpError(400);

        $error = $this->request('POST', $uri, ['text' => 'Bonjour', 'client_id' => self::CLIENT_ID], $token);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('ai_unavailable', $error['error']);
        self::assertCount(1, $this->request('GET', '/sessions/'.$session['id'], null, $token)['messages']);

        // Le même client_id relance un tour neuf, et la session n'est pas restée verrouillée.
        FakeGemini::queueText('Me revoilà');
        $turn = $this->request('POST', $uri, ['text' => 'Bonjour', 'client_id' => self::CLIENT_ID], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('Me revoilà', $turn['messages'][1]['blocks'][0]['text']);

        // Les appels sont tracés, y compris celui en échec.
        $logs = $this->em()->getRepository(GenerationLog::class)->findBy([], ['id' => 'ASC']);
        self::assertSame([true, false, true], array_map(static fn (GenerationLog $l): bool => $l->isSuccess(), $logs));
        self::assertSame(['greeting', 'conversation', 'conversation'], array_map(static fn (GenerationLog $l): string => $l->getPurpose(), $logs));
        self::assertSame('system_v7', $logs[0]->getPromptVersion());
        // 1000 tokens × 0,30 $/M + (100 + 50) tokens × 2,50 $/M
        self::assertSame('0.000675', $logs[0]->getCostUsd());
    }

    public function testToolCallLoop(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        FakeGemini::queueFunctionCall('echo_test', ['text' => 'coucou'], text: 'Je regarde…', id: 'call-1');
        FakeGemini::queueText('C\'est fait.');

        $turn = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'Teste l\'outil', 'client_id' => self::CLIENT_ID], $token);

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['type' => 'text', 'text' => 'Je regarde…'],
            ['type' => 'test_card', 'value' => 'coucou'],
            ['type' => 'text', 'text' => 'C\'est fait.'],
        ], $turn['messages'][1]['blocks']);

        $first = FakeGemini::$requests[1]['body'];
        $toolNames = array_column($first['tools'][0]['functionDeclarations'], 'name');
        self::assertContains('echo_test', $toolNames);
        self::assertContains('update_memory', $toolNames);
        self::assertNotContains('finish_onboarding', $toolNames); // onboarding uniquement
        self::assertSame('AUTO', $first['toolConfig']['functionCallingConfig']['mode']);

        // 2e appel : la réponse du modèle est renvoyée telle quelle (signature), suivie du résultat de l'outil.
        $contents = FakeGemini::$requests[2]['body']['contents'];
        $modelTurn = $contents[\count($contents) - 2];
        self::assertSame('model', $modelTurn['role']);
        self::assertSame('sig-echo_test', $modelTurn['parts'][1]['thoughtSignature']);
        self::assertSame(
            ['functionResponse' => ['name' => 'echo_test', 'response' => ['status' => 'ok', 'echoed' => 'coucou'], 'id' => 'call-1']],
            end($contents)['parts'][0],
        );
    }

    public function testToolErrorsAreSentBackToGemini(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        FakeGemini::queueFunctionCall('echo_test', []);
        FakeGemini::queueFunctionCall('unknown_tool', []);
        FakeGemini::queueText('Désolé.');

        $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'x'], $token);

        self::assertResponseIsSuccessful();
        $responses = array_map(static fn (array $r): array => end($r['body']['contents'])['parts'][0]['functionResponse']['response'], \array_slice(FakeGemini::$requests, 2));
        self::assertSame('error', $responses[0]['status']);
        self::assertStringContainsString('"text" is required', $responses[0]['error']);
        self::assertSame('Unknown tool "unknown_tool".', $responses[1]['error']);
    }

    public function testLastRoundForbidsToolsAndEmptyAnswerGetsFallback(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        for ($i = 0; $i < 5; ++$i) {
            FakeGemini::queueFunctionCall('echo_test', ['text' => 'boucle '.$i]);
        }

        $turn = $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'x'], $token);

        self::assertResponseIsSuccessful();
        self::assertCount(6, FakeGemini::$requests); // accueil + 5 tours maximum
        self::assertSame('NONE', FakeGemini::$requests[5]['body']['toolConfig']['functionCallingConfig']['mode']);
        $blocks = $turn['messages'][1]['blocks'];
        self::assertSame('text', end($blocks)['type']);
        self::assertStringContainsString('reformuler', end($blocks)['text']);
    }

    public function testValidation(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        $uri = \sprintf('/sessions/%d/messages', $session['id']);

        $body = $this->request('POST', $uri, ['text' => '   ', 'client_id' => 'not-a-uuid'], $token);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $body['error']);
        self::assertEqualsCanonicalizing(['text', 'client_id'], array_column($body['violations'], 'field'));

        $this->request('POST', $uri, ['text' => str_repeat('a', 2001)], $token);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', '/sessions', ['type' => 'party'], $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testArchivedSessionIsReadOnly(): void
    {
        $token = $this->registerUser();
        $first = $this->createSession($token);
        $this->createSession($token);

        $body = $this->request('POST', \sprintf('/sessions/%d/messages', $first['id']), ['text' => 'Hello'], $token);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('session_archived', $body['error']);
    }

    public function testCannotAccessAnotherUsersSession(): void
    {
        $session = $this->createSession($this->registerUser('alice@example.com'));
        $bob = $this->registerUser('bob@example.com');

        foreach ([['GET', ''], ['GET', '/messages'], ['POST', '/messages']] as [$method, $suffix]) {
            $body = $this->request($method, '/sessions/'.$session['id'].$suffix, 'POST' === $method ? ['text' => 'x'] : null, $bob);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('not_found', $body['error']);
        }
    }

    public function testConcurrentTurnIsRejected(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        $uri = \sprintf('/sessions/%d/messages', $session['id']);

        // Un tour en cours sur la session (verrou posé il y a 10 s).
        $this->connection()->executeStatement('UPDATE shopping_session SET turn_locked_at = ? WHERE id = ?', [(new \DateTimeImmutable('-10 seconds'))->format('Y-m-d H:i:s'), $session['id']]);
        $body = $this->request('POST', $uri, ['text' => 'x'], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('turn_in_progress', $body['error']);

        // Verrou abandonné depuis plus de 2 minutes : la session est débloquée.
        $this->connection()->executeStatement('UPDATE shopping_session SET turn_locked_at = ? WHERE id = ?', [(new \DateTimeImmutable('-3 minutes'))->format('Y-m-d H:i:s'), $session['id']]);
        FakeGemini::queueText('OK');
        $this->request('POST', $uri, ['text' => 'x'], $token);
        self::assertResponseIsSuccessful();
    }

    public function testSameClientIdStillInProgressIsRejectedThenReleasedWhenStale(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        $uri = \sprintf('/sessions/%d/messages', $session['id']);
        $insertTurn = fn (string $startedAt) => $this->connection()->executeStatement(
            "INSERT INTO conversation_turn (session_id, client_id, kind, status, started_at) VALUES (?, ?, 'message', 'in_progress', ?)",
            [$session['id'], self::CLIENT_ID, (new \DateTimeImmutable($startedAt))->format('Y-m-d H:i:s')],
        );

        $insertTurn('-5 seconds');
        $body = $this->request('POST', $uri, ['text' => 'x', 'client_id' => self::CLIENT_ID], $token);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('turn_in_progress', $body['error']);

        $this->connection()->executeStatement('DELETE FROM conversation_turn WHERE client_id = ?', [self::CLIENT_ID]);
        $insertTurn('-5 minutes');
        FakeGemini::queueText('Repris');
        $turn = $this->request('POST', $uri, ['text' => 'x', 'client_id' => self::CLIENT_ID], $token);
        self::assertResponseIsSuccessful();
        self::assertSame('Repris', $turn['messages'][1]['blocks'][0]['text']);
    }

    public function testMessagesAfterId(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);
        $greetingId = $session['messages'][0]['id'];
        FakeGemini::queueText('Réponse');
        $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), ['text' => 'Question'], $token);

        $body = $this->request('GET', \sprintf('/sessions/%d/messages?after_id=%d', $session['id'], $greetingId), null, $token);

        self::assertResponseIsSuccessful();
        self::assertSame(['user', 'assistant'], array_column($body['messages'], 'role'));
        self::assertCount(3, $this->request('GET', \sprintf('/sessions/%d/messages', $session['id']), null, $token)['messages']);
    }

    public function testOnboardingSessionIsIdempotent(): void
    {
        $token = $this->registerUser();
        self::assertFalse($this->request('GET', '/me', null, $token)['onboarding_completed']);
        FakeGemini::queueText('Bienvenue ! Vous êtes combien à la maison ?');

        $first = $this->request('POST', '/sessions', ['type' => 'onboarding'], $token);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('onboarding', $first['type']);
        self::assertNull($first['progress']);
        self::assertStringContainsString('fais connaissance', FakeGemini::$requests[0]['body']['systemInstruction']['parts'][0]['text']);

        $second = $this->request('POST', '/sessions', ['type' => 'onboarding'], $token);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first['id'], $second['id']);
        self::assertCount(1, FakeGemini::$requests);

        // L'onboarding n'apparaît pas dans l'historique des sessions de courses.
        self::assertNull($this->request('GET', '/sessions', null, $token)['active']);
    }

    public function testDeletingAccountDeletesSessionsAndKeepsAnonymousLogs(): void
    {
        $token = $this->registerUser();
        $session = $this->createSession($token);

        $this->request('DELETE', '/me', ['password' => 'correct-horse-battery'], $token);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM shopping_session WHERE id = ?', [$session['id']]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM message WHERE session_id = ?', [$session['id']]));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM generation_log WHERE user_id IS NULL AND session_id IS NULL'));
    }

    public function testRequiresAuthentication(): void
    {
        $body = $this->request('GET', '/sessions');

        self::assertResponseStatusCodeSame(401);
        self::assertSame('unauthorized', $body['error']);
    }

    /** @return array<string, mixed> */
    private function createSession(string $token, string $greeting = 'Salut !'): array
    {
        FakeGemini::queueText($greeting);
        $session = $this->request('POST', '/sessions', ['type' => 'shopping'], $token);
        self::assertResponseStatusCodeSame(201);

        return $session;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
