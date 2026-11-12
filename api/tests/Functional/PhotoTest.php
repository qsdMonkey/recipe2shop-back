<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FakeGemini;
use Doctrine\DBAL\Connection;

final class PhotoTest extends ApiTestCase
{
    private const CLIENT_ID = '7c1e2f3a-4b5c-4d6e-8f90-a1b2c3d4e5f6';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->registerUser();
    }

    public function testPhotoIsSentToGeminiOnceAndShownBeforeTheText(): void
    {
        $session = $this->startSession();
        $photo = self::jpeg();
        FakeGemini::queueFunctionCall('set_inventory', ['items' => [['label' => 'courgette', 'quantity' => 750, 'unit' => 'g'], ['label' => 'feta']]]);
        FakeGemini::queueText('Je vois : 3 courgettes et de la feta. C\'est noté !');

        $turn = $this->send($session, ['text' => ' voilà mon frigo ', 'client_id' => self::CLIENT_ID, 'photo' => ['mime' => 'image/jpeg', 'data' => $photo]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'photo', 'client_id' => self::CLIENT_ID], ['type' => 'text', 'text' => 'voilà mon frigo']], $turn['messages'][0]['blocks']);
        self::assertStringStartsWith('Je vois : 3 courgettes', $turn['messages'][1]['blocks'][0]['text']);

        // Les 2 appels du tour (outil puis réponse) reçoivent la photo, en résolution medium.
        foreach ([1, 2] as $i) {
            $body = FakeGemini::$requests[$i]['body'];
            $last = $body['contents'][array_key_last($body['contents']) - (2 === $i ? 2 : 0)];
            self::assertSame(['mimeType' => 'image/jpeg', 'data' => $photo], $last['parts'][0]['inlineData']);
            self::assertSame('[Photo envoyée] voilà mon frigo', $last['parts'][1]['text']);
            self::assertSame('MEDIA_RESOLUTION_MEDIUM', $body['generationConfig']['mediaResolution']);
        }
        self::assertStringContainsString('# Photos', FakeGemini::$requests[1]['body']['systemInstruction']['parts'][0]['text']);

        // Persisté (bloc photo, sans image) ; jamais d'image en base.
        $detail = $this->request('GET', '/sessions/'.$session['id'], null, $this->token);
        self::assertSame($turn['messages'][0]['blocks'], $detail['messages'][1]['blocks']);
        self::assertSame($turn['messages'][0]['blocks'], $this->request('GET', '/sessions/'.$session['id'].'/messages', null, $this->token)['messages'][1]['blocks']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM message WHERE CAST(blocks AS CHAR) LIKE ? OR content LIKE ? OR CAST(tool_calls AS CHAR) LIKE ?', array_fill(0, 3, '%'.substr($photo, 100, 40).'%')));

        // Tour suivant : historique en texte seul, la photo n'est pas renvoyée.
        FakeGemini::queueText('Avec plaisir.');
        $this->send($session, ['text' => 'Merci', 'client_id' => '0d4c5b6a-7e8f-4a1b-9c2d-3e4f5a6b7c8d']);
        $raw = json_encode(end(FakeGemini::$requests)['body'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('inlineData', $raw);
        self::assertStringContainsString('[Photo envoyée] voilà mon frigo', $raw);
        self::assertArrayNotHasKey('mediaResolution', end(FakeGemini::$requests)['body']['generationConfig']);

        // Rejeu du même client_id : même réponse, sans renvoyer la photo à Gemini.
        $count = \count(FakeGemini::$requests);
        $replay = $this->send($session, ['text' => 'voilà mon frigo', 'client_id' => self::CLIENT_ID, 'photo' => ['mime' => 'image/jpeg', 'data' => $photo]]);
        self::assertResponseIsSuccessful();
        self::assertSame($turn['messages'], $replay['messages']);
        self::assertCount($count, FakeGemini::$requests);
    }

    public function testPhotoWithoutTextAndOtherFormats(): void
    {
        $session = $this->startSession();
        FakeGemini::queueText('Je vois une page de recette : lasagnes.');
        $turn = $this->send($session, ['text' => '', 'client_id' => self::CLIENT_ID, 'photo' => ['mime' => 'image/png', 'data' => base64_encode("\x89PNG\r\n\x1A\n".random_bytes(500))]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'photo', 'client_id' => self::CLIENT_ID]], $turn['messages'][0]['blocks']);
        self::assertSame('[Photo envoyée]', end(FakeGemini::$requests)['body']['contents'][array_key_last(end(FakeGemini::$requests)['body']['contents'])]['parts'][1]['text']);

        FakeGemini::queueText('OK');
        $this->send($session, ['client_id' => '1d4c5b6a-7e8f-4a1b-9c2d-3e4f5a6b7c8d', 'photo' => ['mime' => 'image/webp', 'data' => base64_encode('RIFF'.pack('V', 600).'WEBPVP8 '.random_bytes(500))]]);
        self::assertResponseIsSuccessful();
    }

    public function testInvalidPhotosAreRejected(): void
    {
        $session = $this->startSession();
        $cases = [
            ['too large', ['mime' => 'image/jpeg', 'data' => base64_encode("\xFF\xD8\xFF".random_bytes(2 * 1024 * 1024))], 'photo_too_large'],
            ['bad mime', ['mime' => 'image/gif', 'data' => self::jpeg()], 'invalid_photo'],
            ['broken base64', ['mime' => 'image/jpeg', 'data' => 'not*base64!'], 'invalid_photo'],
            ['empty', ['mime' => 'image/jpeg', 'data' => ''], 'invalid_photo'],
            ['not a jpeg', ['mime' => 'image/jpeg', 'data' => base64_encode(str_repeat('a', 500))], 'invalid_photo'],
        ];
        foreach ($cases as [$label, $photo, $error]) {
            $body = $this->send($session, ['text' => 'Mon frigo', 'client_id' => self::CLIENT_ID, 'photo' => $photo]);
            self::assertResponseStatusCodeSame(422, $label);
            self::assertSame($error, $body['error'], $label);
        }

        // Texte vide sans photo, photo sans client_id : 422 validation_failed. 2000 caractères max pour le texte seul.
        $body = $this->send($session, ['text' => ' ']);
        self::assertSame(['validation_failed', 'text'], [$body['error'], $body['violations'][0]['field']]);
        $body = $this->send($session, ['text' => 'Mon frigo', 'photo' => ['mime' => 'image/jpeg', 'data' => self::jpeg()]]);
        self::assertSame(['validation_failed', 'client_id'], [$body['error'], $body['violations'][0]['field']]);
        $this->send($session, ['text' => str_repeat('a', 2001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, FakeGemini::$requests); // l'accueil seulement
    }

    public function testPhotosAreRefusedInOnboarding(): void
    {
        FakeGemini::queueText('Bienvenue');
        $onboarding = $this->request('POST', '/sessions', ['type' => 'onboarding'], $this->token);

        $body = $this->send($onboarding, ['text' => 'Mon frigo', 'client_id' => self::CLIENT_ID, 'photo' => ['mime' => 'image/jpeg', 'data' => self::jpeg()]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_shopping_session', $body['error']);
    }

    public function testBase64NeverReachesTheLogs(): void
    {
        $session = $this->startSession();
        $photo = self::jpeg();
        $marker = substr($photo, 200, 60);
        // Gemini recopie la valeur fautive dans son message d'erreur.
        FakeGemini::queueHttpError(400, 'Invalid value at contents[0].parts[0].inline_data.data: '.$photo);

        $body = $this->send($session, ['text' => 'Mon frigo', 'client_id' => self::CLIENT_ID, 'photo' => ['mime' => 'image/jpeg', 'data' => $photo]]);

        self::assertResponseStatusCodeSame(503);
        self::assertStringNotContainsString($marker, json_encode($body, \JSON_THROW_ON_ERROR));
        $logs = (string) file_get_contents(self::getContainer()->getParameter('kernel.logs_dir').'/test.log');
        self::assertStringContainsString('[base64 ', $logs);
        self::assertStringNotContainsString($marker, $logs);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM generation_log WHERE error LIKE ?', ['%'.$marker.'%']));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM generation_log WHERE error LIKE ?', ['%[base64 %']));
    }

    private static function jpeg(): string
    {
        return base64_encode("\xFF\xD8\xFF\xE0".random_bytes(3000));
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
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function send(array $session, array $payload): array
    {
        return $this->request('POST', \sprintf('/sessions/%d/messages', $session['id']), $payload, $this->token);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
