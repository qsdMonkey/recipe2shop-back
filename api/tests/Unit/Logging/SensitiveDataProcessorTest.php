<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SensitiveDataProcessorTest extends TestCase
{
    public function testRedactsSecretsContentAndMasksEmails(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'Login of alice.martin@example.com', [
            'email' => 'alice.martin@example.com',
            'password' => 'hunter2',
            'token' => 'eyJ…',
            'text' => 'Mon fils est allergique aux arachides',
            'nested' => ['value' => 'arachides', 'user_id' => 12, 'Authorization' => 'Bearer x'],
            'exception' => new \RuntimeException("SQLSTATE[23000]: Duplicate entry 'arachides-12' for key"),
            'duration_ms' => 1200,
        ]);

        $out = (new SensitiveDataProcessor())($record);

        self::assertSame('Login of a***@example.com', $out->message);
        self::assertSame('a***@example.com', $out->context['email']);
        self::assertSame('[redacted]', $out->context['password']);
        self::assertSame('[redacted]', $out->context['token']);
        self::assertSame('[redacted]', $out->context['text']);
        self::assertSame(['value' => '[redacted]', 'user_id' => 12, 'Authorization' => '[redacted]'], $out->context['nested']);
        self::assertSame(\RuntimeException::class, $out->context['exception']['class']);
        self::assertStringNotContainsString('arachides', $out->context['exception']['message']);
        self::assertSame(1200, $out->context['duration_ms']);
    }

    public function testPhotoBase64IsNeverLogged(): void
    {
        $photo = base64_encode(random_bytes(3000));
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'Gemini HTTP 400: bad value '.$photo, [
            'photo' => ['mime' => 'image/jpeg', 'data' => $photo],
            'error' => 'inline_data '.$photo,
            'exception' => new \RuntimeException('Base64 decoding failed for "'.$photo.'"'),
            'request_id' => 'abc-123',
        ]);

        $out = (new SensitiveDataProcessor())($record);

        self::assertStringNotContainsString(substr($photo, 300, 50), json_encode([$out->message, $out->context], \JSON_THROW_ON_ERROR));
        self::assertMatchesRegularExpression('/^Gemini HTTP 400: bad value \[base64 \d+ car\.\]$/', $out->message);
        self::assertSame('[redacted]', $out->context['photo']);
        self::assertStringStartsWith('Base64 decoding failed for "[base64 ', $out->context['exception']['message']);
        self::assertSame('abc-123', $out->context['request_id']);
        self::assertSame('pas une photo, juste du texte', SensitiveDataProcessor::maskBase64('pas une photo, juste du texte'));
    }
}
