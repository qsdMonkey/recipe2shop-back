<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HealthControllerTest extends TestCase
{
    public function testReturns503WhenDatabaseIsUnreachable(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')->willThrowException(new \RuntimeException('Connection refused'));

        $response = (new HealthController($connection, new NullLogger()))();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['status' => 'degraded', 'db' => 'error'],
            json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
