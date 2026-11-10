<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\ApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Format d'erreur unique de l'API : {"code": 409, "error": "session_archived", "message": "..."}.
 * - "error" : code machine stable (ApiException), ou déduit du statut HTTP par défaut.
 * - "violations" : ajouté pour les erreurs de validation (422).
 * Le format des erreurs de LexikJWT / gesdinet ({"code","message"}) est complété avec "error" au passage.
 * Les erreurs 500 restent gérées par Symfony (page de debug en dev).
 */
final class JsonExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = false,
        #[Autowire(service: 'monolog.logger.recipe2shop')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    private const DEFAULT_ERRORS = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        415 => 'unsupported_media_type',
        422 => 'validation_failed',
        429 => 'rate_limited',
        503 => 'service_unavailable',
    ];

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité négative : le journal des exceptions de Symfony (ErrorListener::logKernelException, priorité 0)
            // doit passer AVANT nous, car définir une réponse arrête la propagation de l'événement.
            KernelEvents::EXCEPTION => ['onException', -64],
            KernelEvents::RESPONSE => ['onResponse', -10],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            // Erreur inattendue : en prod, réponse générique SANS trace (Symfony la journalise déjà en « critical »).
            // En dev, on laisse la page de debug de Symfony.
            if (!$this->debug) {
                $event->setResponse(new JsonResponse(['code' => 500, 'error' => 'server_error', 'message' => 'Internal server error.'], 500));
            }

            return;
        }

        $status = $exception->getStatusCode();
        $body = [
            'code' => $status,
            'error' => $exception instanceof ApiException ? $exception->errorCode : self::defaultError($status),
            'message' => $exception->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'),
        ];

        $previous = $exception->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $body['error'] = 'validation_failed';
            $body['message'] = 'Validation failed.';
            $body['violations'] = [];
            foreach ($previous->getViolations() as $violation) {
                // Noms de champs en snake_case, comme dans le JSON (clientId → client_id).
                $field = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $violation->getPropertyPath()));
                $body['violations'][] = ['field' => $field, 'message' => (string) $violation->getMessage()];
            }
        }

        $event->setResponse(new JsonResponse($body, $status, $exception->getHeaders()));
    }

    /** Ajoute "error" aux réponses d'erreur JSON produites hors de nos exceptions (LexikJWT, gesdinet). */
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (429 === $response->getStatusCode() && $event->isMainRequest()) {
            $request = $event->getRequest();
            $this->logger?->warning('http.rate_limited', ['path' => $request->getPathInfo(), 'method' => $request->getMethod(), 'ip' => $request->getClientIp()]);
        }
        if ($response->getStatusCode() < 400 || !$response instanceof JsonResponse) {
            return;
        }

        $data = json_decode((string) $response->getContent(), true);
        if (!\is_array($data) || !isset($data['code']) || isset($data['error'])) {
            return;
        }

        $response->setData(['code' => $data['code'], 'error' => self::defaultError($response->getStatusCode())] + $data);
    }

    private static function defaultError(int $status): string
    {
        return self::DEFAULT_ERRORS[$status] ?? ($status >= 500 ? 'server_error' : 'error');
    }
}
