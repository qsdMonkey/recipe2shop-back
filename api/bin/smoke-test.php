<?php

/*
 * Vérifications de production (lancé dans le conteneur php par smoke-test.sh) :
 * /health, en-têtes, inscription → connexion → création de session (1 appel Gemini) → suppression du compte,
 * /dev/chat-fixture en 404, erreur 500 générique sans trace. Code de sortie ≠ 0 si un contrôle échoue.
 */

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$base = 'http://127.0.0.1:8080';
$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("%s %s%s\n", $ok ? '✔' : '✘', $label, $ok || '' === $detail ? '' : ' — '.$detail);
    $failures += $ok ? 0 : 1;
};
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($base): array {
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (null !== $token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => null === $body ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 60]]);
    $start = microtime(true);
    $raw = (string) @file_get_contents($base.$path, false, $ctx);
    $status = (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
    $respHeaders = [];
    foreach (array_slice($http_response_header ?? [], 1) as $h) {
        [$k, $v] = array_map('trim', explode(':', $h, 2)) + [1 => ''];
        $respHeaders[strtolower($k)] = $v;
    }

    return [$status, json_decode($raw, true), $respHeaders, microtime(true) - $start, $raw];
};

// 1. Santé et en-têtes
[$status, $health, $headers] = $call('GET', '/health');
$check('/health répond {"status":"ok","db":"ok"}', 200 === $status && ['status' => 'ok', 'db' => 'ok'] === $health, json_encode($health));
$check('en-têtes de sécurité', 'nosniff' === ($headers['x-content-type-options'] ?? null) && 'DENY' === ($headers['x-frame-options'] ?? null) && isset($headers['content-security-policy']));
$check('pas d\'en-tête Server / X-Powered-By', !isset($headers['server']) && !isset($headers['x-powered-by']), json_encode(array_intersect_key($headers, ['server' => 1, 'x-powered-by' => 1])));

// 2. Fixture de dev absente
[$status] = $call('GET', '/dev/chat-fixture');
$check('/dev/chat-fixture → 404', 404 === $status, (string) $status);

// 3. Parcours : inscription → connexion → session (1 appel Gemini) → suppression
$email = 'smoke-'.bin2hex(random_bytes(4)).'@example.com';
$password = bin2hex(random_bytes(12));
[$status, $body] = $call('POST', '/auth/register', ['email' => $email, 'password' => $password]);
$check('inscription → 201', 201 === $status, (string) $status);
[$status, $body] = $call('POST', '/auth/login', ['email' => $email, 'password' => $password]);
$token = $body['token'] ?? null;
$check('connexion → 200 + token', 200 === $status && is_string($token), (string) $status);
[$status, $body, , $seconds] = $call('POST', '/sessions', ['type' => 'shopping'], $token);
$greeting = $body['messages'][0]['blocks'][0]['text'] ?? '';
$check(sprintf('création de session → 201 avec accueil (%.1f s)', $seconds), 201 === $status && '' !== $greeting, (string) $status);
[$status, $body] = $call('GET', '/me', null, null);
$check('route protégée sans token → 401 unauthorized', 401 === $status && 'unauthorized' === ($body['error'] ?? null));

// 4. Erreur 500 forcée (noyau démarré à part, base injoignable) : réponse générique, sans trace
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'mysql://nobody:nothing@127.0.0.1:1/nothing?serverVersion=8.4';
putenv('DATABASE_URL='.$_SERVER['DATABASE_URL']);
$kernel = new Kernel('prod', false);
$request = Request::create('/me', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json']);
$response = $kernel->handle($request);
$content = (string) $response->getContent();
$check('erreur 500 forcée → réponse générique', 500 === $response->getStatusCode() && '{"code":500,"error":"server_error","message":"Internal server error."}' === $content, $response->getStatusCode().' '.substr($content, 0, 200));
$check('aucune trace / détail SQL dans la réponse', !preg_match('/SQLSTATE|Exception|\.php|trace/i', $content));
$kernel->shutdown();

// 5. Suppression du compte de test
[$status] = $call('DELETE', '/me', ['password' => $password], $token);
$check('suppression du compte de test → 204', 204 === $status, (string) $status);

echo "SMOKE_EMAIL=$email\n";
exit($failures > 0 ? 1 : 0);
