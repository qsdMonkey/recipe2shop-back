<?php

declare(strict_types=1);

namespace App\Memory;

/** Opération de mémoire refusée (doublon, conflit d'annulation…). $errorCode = code machine de l'API. */
final class MemoryException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
