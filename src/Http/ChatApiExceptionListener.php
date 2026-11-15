<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function str_starts_with;

/**
 * Renders ChatApiException as the API's JSON error shape, never cached.
 *
 * 1.30 (Beacon spec 239): a storage failure that reaches one of ChatKit's own routes is never a 500. A transient one
 * (TransientStorageFailure from the transaction runner, or a deadlock / lock wait / serialization failure the runner did
 * not see) answers **503** with `Retry-After`; a conflict (a unique key another request took first, an optimistic lock)
 * answers **409**. The API routes get `{ok: false, error: "unavailable" | "conflict"}`; the chat page routes get Symfony's
 * ServiceUnavailableHttpException / ConflictHttpException (the original as previous), rendered by the host's error page.
 * It runs at priority -64, after Symfony logged the failure, so the storage error stays in the logs; the client never
 * sees SQL or a driver message. Routes of the host are left alone (see DoctrineDeadlockRetryBundle's `http_mapping`).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onStorageFailure', priority: -64)]
final class ChatApiExceptionListener
{
    /** Route names of ChatKit's controllers. */
    private const string ROUTE_PREFIX = 'nowo_chat_';

    /** Route names of ChatKit's JSON API. */
    private const string API_ROUTE_PREFIX = 'nowo_chat_api_';

    /** Retry-After when the failure carries no hint. */
    private const int RETRY_AFTER = 1;

    private TransientFailureClassifier $classifier;

    /**
     * Builds the listener (nowo-tech/transaction-doctrine's classifier recognises the transient storage errors).
     */
    public function __construct()
    {
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Replaces the response of a ChatApiException with `{ok: false, error: <code>}` (plus `message` when it carries a
     * reason) and its HTTP status.
     *
     * @param ExceptionEvent $event The kernel exception event
     */
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ChatApiException) {
            return;
        }
        $response = new JsonResponse(['ok' => false, 'error' => $exception->getMessage()] + ($exception->reason === '' ? [] : ['message' => mb_scrub($exception->reason, 'UTF-8')]), $exception->getCode());
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->setResponse($response);
    }

    /**
     * 1.30: a transient storage failure on a ChatKit route answers 503 + Retry-After, a conflict 409.
     *
     * @param ExceptionEvent $event The kernel exception event
     */
    public function onStorageFailure(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        $error = $event->getThrowable();
        if (!is_string($route) || !str_starts_with($route, self::ROUTE_PREFIX) || $error instanceof HttpExceptionInterface) {
            return;
        }
        $retryAfter = $this->retryAfter($error);
        $conflict   = $retryAfter === null && $this->isConflict($error);
        if ($retryAfter === null && !$conflict) {
            return;
        }
        if (!str_starts_with($route, self::API_ROUTE_PREFIX)) {
            $event->setThrowable($conflict
                ? new ConflictHttpException('The chat changed meanwhile. Reload and try again.', $error)
                : new ServiceUnavailableHttpException($retryAfter, TransientStorageFailure::DEFAULT_MESSAGE, $error));

            return;
        }
        $response = new JsonResponse(['ok' => false, 'error' => $conflict ? 'conflict' : 'unavailable'], $conflict ? Response::HTTP_CONFLICT : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }
        $event->setResponse($response);
    }

    /**
     * @return int|null Seconds of the Retry-After of a transient failure; null when the failure is not transient
     */
    private function retryAfter(Throwable $error): ?int
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds() ?? self::RETRY_AFTER;
            }
        }

        return $this->classifier->isTransient($error) ? self::RETRY_AFTER : null;
    }

    /**
     * A unique key another request took first, or an optimistic lock that lost (not one that wraps a failed commit,
     * whose outcome is unknown).
     */
    private function isConflict(Throwable $error): bool
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
            if ($current instanceof OptimisticLockException) {
                return !$current->getPrevious() instanceof Throwable;
            }
        }

        return false;
    }
}
