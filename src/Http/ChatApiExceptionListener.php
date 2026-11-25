<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

use function is_string;
use function str_starts_with;

/**
 * Renders ChatApiException as the API's JSON error shape, never cached.
 *
 * 1.30 (Beacon spec 239): a storage failure that reaches one of ChatKit's own routes is never a 500. A transient one
 * (TransientStorageFailure from the transaction runner, or a deadlock / lock wait / serialization failure the runner did
 * not see) answers with the status of the shared {@see ConflictResponsePolicy} and `Retry-After` (since 1.34 **409**,
 * was 503; `nowo_transaction_doctrine.conflict_response.status: 503` keeps 503; a conflict that already committed
 * ({@see ConcurrencyConflict}, `committed: true`) gets no `Retry-After` and a reload message); a conflict (a unique key another request took first, an optimistic lock)
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

    /** Answer text of a conflict that already committed part of the write: no blind retry. */
    public const string PARTIAL_MESSAGE = 'Part of your change may have been saved. Reload before trying again.';

    /** 1.35 (REQ-RUNTIME-003 rule 6): the header every conflict answer carries (`CONFLICT_001` | `CONFLICT_002`). */
    public const string CODE_HEADER = ConflictResponsePolicy::CODE_HEADER;

    private TransientFailureClassifier $classifier;

    private ConflictResponsePolicy $policy;

    /**
     * Builds the listener (nowo-tech/transaction-doctrine's classifier recognises the transient storage errors).
     *
     * @param ConflictResponsePolicy|null $policy The shared lock-conflict policy; 409 + 1 s when the host did not register nowo-tech/transaction-doctrine's bundle
     */
    public function __construct(?ConflictResponsePolicy $policy = null)
    {
        $this->classifier = new TransientFailureClassifier();
        $this->policy     = $policy ?? new ConflictResponsePolicy();
    }

    /**
     * Replaces the response of a ChatApiException with `{ok: false, error: <code>}` (plus `message` when it carries a
     * reason) and its HTTP status (2.4: and the exception's headers).
     *
     * @param ExceptionEvent $event The kernel exception event
     */
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ChatApiException) {
            return;
        }
        $response = new JsonResponse(['ok' => false, 'error' => $exception->getMessage()] + ($exception->reason === '' ? [] : ['message' => mb_scrub($exception->reason, 'UTF-8')]) + $exception->details, $exception->getCode(), $exception->headers);
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->setResponse($response);
    }

    /**
     * 1.30: a transient storage failure on a ChatKit route answers the policy's status (409; 503 opt-in) + Retry-After, a unique-key conflict 409.
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
        // 1.35 (REQ-RUNTIME-003 rule 6): CONFLICT_002 when the request already committed part of its work — in this
        // unit, or earlier in the same request (transaction-doctrine 1.6's request watermark) — with no Retry-After (read
        // again); CONFLICT_001 otherwise. In the JSON body and in the X-Conflict-Code header of every answer.
        $code      = self::committed($error) ? ConflictCode::Committed : ConflictCode::forRequest($event->getRequest(), $error);
        $committed = !$code->retrySafe();
        $headers   = $retryAfter !== null ? $this->policy->headersFor($code, $retryAfter) : [ConflictResponsePolicy::CODE_HEADER => $code->value];
        if (!str_starts_with($route, self::API_ROUTE_PREFIX)) {
            if ($conflict && !$committed) {
                $event->setThrowable(new ConflictHttpException('The chat changed meanwhile. Reload and try again.', $error, 0, $headers));
            } elseif ($committed) {
                $event->setThrowable(new HttpException($conflict ? Response::HTTP_CONFLICT : $this->policy->status, self::PARTIAL_MESSAGE, $error, $headers));
            } else {
                $event->setThrowable(new HttpException($this->policy->status, TransientStorageFailure::DEFAULT_MESSAGE, $error, $headers));
            }

            return;
        }
        $body = ['ok' => false, 'error' => $conflict ? 'conflict' : 'unavailable', 'code' => $code->value];
        if ($committed) {
            $body['message'] = self::PARTIAL_MESSAGE;
        }
        $response = new JsonResponse($body, $conflict ? Response::HTTP_CONFLICT : $this->policy->status, $headers);
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->setResponse($response);
    }

    /** Whether the chain holds a {@see ConcurrencyConflict} whose attempt already committed (no safe retry). */
    public static function committed(Throwable $error): bool
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict && $current->committed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int|null Seconds of the Retry-After of a transient failure; null when the failure is not transient
     */
    private function retryAfter(Throwable $error): ?int
    {
        for ($current = $error; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds() ?? $this->policy->retryAfterSeconds;
            }
        }

        return $this->classifier->isTransient($error) ? $this->policy->retryAfterSeconds : null;
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
