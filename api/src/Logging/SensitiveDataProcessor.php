<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Filet de sécurité appliqué à TOUS les logs : jamais de mot de passe, token, texte de message, valeur de mémoire
 * (allergies…), prompt ni réponse Gemini, ni photo (base64) ; adresses email masquées (a***@example.com).
 */
#[AsMonologProcessor]
final class SensitiveDataProcessor implements ProcessorInterface
{
    /** Clés dont la valeur est toujours remplacée (comparaison insensible à la casse, sur la fin du nom). */
    private const REDACTED_KEYS = [
        'password', 'plainpassword', 'pass_phrase', 'passphrase', 'token', 'refresh_token', 'authorization', 'cookie',
        'secret', 'api_key', 'apikey', 'x-goog-api-key', 'text', 'content', 'message_text', 'value', 'old_value',
        'new_value', 'values', 'prompt', 'system_instruction', 'contents', 'response', 'body', 'args', 'blocks', 'op',
        'operations', 'transcript', 'comment', 'label', 'photo', 'data', 'inline_data', 'inlinedata',
    ];

    /** Au-delà de cette longueur, une suite de caractères base64 est une donnée binaire (photo) : jamais journalisée. */
    private const BASE64_MIN_LENGTH = 200;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::maskEmails($record->message),
            context: self::scrub($record->context),
            extra: self::scrub($record->extra),
        );
    }

    /** Remplace toute longue suite base64 (photo) par « [base64 N car.] ». Aussi utilisé pour les erreurs Gemini. */
    public static function maskBase64(string $text): string
    {
        return (string) preg_replace_callback(
            '#[A-Za-z0-9+/_-]{'.self::BASE64_MIN_LENGTH.',}={0,2}#',
            static fn (array $m): string => '[base64 '.\strlen($m[0]).' car.]',
            $text,
        );
    }

    public static function maskEmails(string $text): string
    {
        $text = self::maskBase64($text);
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
                $data[$key] = ['class' => $value::class, 'message' => mb_substr(self::maskEmails($value->getMessage()), 0, 300), 'file' => basename($value->getFile()).':'.$value->getLine()];
            }
        }

        return $data;
    }
}
