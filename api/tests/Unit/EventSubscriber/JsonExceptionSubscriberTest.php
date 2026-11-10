<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\JsonExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class JsonExceptionSubscriberTest extends TestCase
{
    public function testUnexpectedErrorInProdIsGenericWithoutTrace(): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('SQLSTATE secret details in /app/src/Foo.php'));

        (new JsonExceptionSubscriber(debug: false))->onException($event);

        self::assertSame(500, $event->getResponse()->getStatusCode());
        self::assertSame('{"code":500,"error":"server_error","message":"Internal server error."}', $event->getResponse()->getContent());
    }

    public function testRunsAfterSymfonyExceptionLogging(): void
    {
        // ErrorListener::logKernelException (priorité 0) journalise les exceptions non rattrapées (canal request,
        // niveau critical) : notre réponse JSON doit arriver après, sinon la propagation s'arrête avant le log.
        self::assertLessThan(0, JsonExceptionSubscriber::getSubscribedEvents()[\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION][1]);
    }

    public function testUnexpectedErrorInDebugIsLeftToSymfony(): void
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('boom'));

        (new JsonExceptionSubscriber(debug: true))->onException($event);

        self::assertNull($event->getResponse());
    }
}
