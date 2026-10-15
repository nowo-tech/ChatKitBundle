<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders ChatApiException as the API's JSON error shape, never cached.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class ChatApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ChatApiException) {
            return;
        }
        $response = new JsonResponse(['ok' => false, 'error' => $exception->getMessage()], $exception->getCode());
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->setResponse($response);
    }
}
