<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            // Vraie requête vers MySQL : échoue si la base est injoignable.
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $db = 'ok';
        } catch (\Throwable $e) {
            $this->logger->error('Healthcheck: database unreachable', ['exception' => $e]);
            $db = 'error';
        }

        return new JsonResponse(
            ['status' => 'ok' === $db ? 'ok' : 'degraded', 'db' => $db],
            'ok' === $db ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
