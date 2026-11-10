<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthReportsApiAndDatabaseOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(
            ['status' => 'ok', 'db' => 'ok'],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
