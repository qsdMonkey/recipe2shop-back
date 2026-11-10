<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Filet de sécurité appliqué à TOUS les logs : jamais de mot de passe, token, texte de message, valeur de mémoire
 * (allergies…), prompt ni réponse Gemini ; adresses email masquées (a***@example.com).
 */
#[AsMonologProcessor]
final class SensitiveDataProcessor implements ProcessorInterface
{
    /** Clés dont la valeur est toujours remplacée (comparaison insensible à la casse, sur la fin du nom). */
    private const REDACTED_KEYS = [
        'password', 'plainpassword', 'pass_phrase', 'passphrase', 'token', 'refresh_token', 'authorization', 'cookie',
        'secret', 'api_key', 'apikey', 'x-goog-api-key', 'text', 'content', 'message_text', 'value', 'old_value',
        'new_value', 'values', 'prompt', 'system_instruction', 'contents', 'response', 'body', 'args', 'blocks', 'op',
        'operations', 'transcript', 'comment', 'label',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::maskEmails($record->message),
            context: self::scrub($record->context),
            extra: self::scrub($record->extra),
        );
    }

    public static function maskEmails(string $text): string
    {
        // Messages d'erreur SQL (« Duplicate entry 'arachide-…' ») : les valeurs entre guillemets sont masquées.
        if (str_contains($text, 'SQLSTATE') || str_contains($text, 'executing')) {
            $text = (string) preg_replace("/'[^']*'/", "'?'", $text);
        }

        return (string) preg_replace_callback(
            '/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/',
            static fn (array $m): string => $m[1].'***@'.$m[2],
            $text,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function scrub(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $data[$key] = '[redacted]';
            } elseif (\is_array($value)) {
                $data[$key] = $depth < 5 ? self::scrub($value, $depth + 1) : '[…]';
            } elseif (\is_string($value)) {
                $data[$key] = self::maskEmails($value);
            } elseif ($value instanceof \Throwable) {
                // Le message d'une exception peut contenir une donnée : on garde la classe et l'emplacement.
                $data[$key] = ['class' => $value::class, 'message' => self::maskEmails(mb_substr($value->getMessage(), 0, 300)), 'file' => basename($value->getFile()).':'.$value->getLine()];
            }
        }

        return $data;
    }
}
