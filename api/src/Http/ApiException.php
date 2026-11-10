<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Erreur métier avec un code machine stable (champ "error" de la réponse), ex. "session_archived".
 */
final class ApiException extends HttpException
{
    /** @param array<string, string> $headers */
    public function __construct(int $statusCode, public readonly string $errorCode, string $message, array $headers = [], ?\Throwable $previous = null)
    {
        parent::__construct($statusCode, $message, $previous, $headers);
    }
}
