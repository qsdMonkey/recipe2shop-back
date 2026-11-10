<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Gemini\GeminiBudget;
use App\Gemini\GeminiException;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;

final class ProductionHardeningTest extends ApiTestCase
{
    public function testSecurityHeaders(): void
    {
        $this->client->request('GET', '/health');

        $headers = $this->client->getResponse()->headers;
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertNull($headers->get('Strict-Transport-Security')); // HTTP direct
    }

    public function testForwardedHeadersFromTrustedProxy(): void
    {
        // Le reverse proxy (IP privée, de confiance) transmet l'IP du client et le HTTPS.
        $this->client->request('GET', '/health', server: [
            'REMOTE_ADDR' => '192.168.1.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertSame('203.0.113.7', $this->client->getRequest()->getClientIp());
        self::assertSame('max-age=31536000; includeSubDomains', $this->client->getResponse()->headers->get('Strict-Transport-Security'));
    }

    public function testForwardedHeadersFromUntrustedClientAreIgnored(): void
    {
        $this->client->request('GET', '/health', server: ['REMOTE_ADDR' => '203.0.113.50', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']);

        self::assertSame('203.0.113.50', $this->client->getRequest()->getClientIp());
    }

    public function testHealthExposesNothingSensitive(): void
    {
        self::assertSame(['status' => 'ok', 'db' => 'ok'], $this->request('GET', '/health'));
    }

    public function testDevFixtureIsNotAvailableOutsideDev(): void
    {
        $this->request('GET', '/dev/chat-fixture');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDailyGeminiBudget(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("INSERT INTO generation_log (purpose, model, prompt_version, input_tokens, output_tokens, thinking_tokens, cost_usd, duration_ms, success, created_at) VALUES ('conversation', 'm', 'v', 1, 1, 0, 1.5, 10, 1, ?)", [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')]);

        (new GeminiBudget($db, new NullLogger(), 2.0))->assertAvailable(); // 1,50 $ < 2 $ : OK
        (new GeminiBudget($db, new NullLogger(), 0.0))->assertAvailable(); // 0 = pas de plafond

        $this->expectException(GeminiException::class);
        (new GeminiBudget($db, new NullLogger(), 1.0))->assertAvailable();
    }
}
