<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Http;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use LogicException;
use Nowo\ChatKitBundle\Http\ChatApiExceptionListener;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function json_decode;

/**
 * 1.30 (Beacon spec 239): storage failures on ChatKit's routes are 409 + Retry-After (transient; 503 with the policy opt-in) or 409 (conflict),
 * never 500; the host's routes and HTTP exceptions are left alone; the client never sees the storage error.
 */
final class ChatApiExceptionListenerStorageTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, int, string|null}>
     */
    public static function failures(): iterable
    {
        $deadlock = new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found; SELECT secret FROM t')), null);
        $unique   = new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('Duplicate entry secret')), null);
        yield 'runner failure with its hint' => [new TransientStorageFailure(4, TransientStorageFailure::DEFAULT_MESSAGE, $deadlock), 409, '4'];
        yield 'runner failure without a hint' => [new TransientStorageFailure(), 409, '1'];
        yield 'runner failure wrapped by the host' => [new RuntimeException('wrapped', 0, new TransientStorageFailure(2)), 409, '2'];
        yield 'deadlock the runner did not see' => [$deadlock, 409, '1'];
        yield 'unique key taken by another request' => [$unique, 409, null];
        yield 'unique key wrapped' => [new LogicException('wrapped', 0, $unique), 409, null];
        yield 'optimistic lock lost' => [OptimisticLockException::lockFailed(new stdClass()), 409, null];
    }

    #[DataProvider('failures')]
    public function testTheApiAnswersWithAFixedJsonBody(Throwable $failure, int $status, ?string $retryAfter): void
    {
        $event = $this->event('nowo_chat_api_send', $failure);
        (new ChatApiExceptionListener())->onStorageFailure($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($retryAfter !== null ? '{"ok":false,"error":"unavailable","code":"CONFLICT_001"}' : '{"ok":false,"error":"conflict","code":"CONFLICT_001"}', $response->getContent(), 'no SQL, no driver text');
        // 1.35 (REQ-RUNTIME-003 rule 6): every conflict answer carries its code in a header too.
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testThePageRoutesGetHttpExceptionsWithTheOriginalAsPrevious(): void
    {
        $failure = new TransientStorageFailure(3);
        $event   = $this->event('nowo_chat_page_send', $failure);
        (new ChatApiExceptionListener())->onStorageFailure($event);
        $replaced = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $replaced);
        self::assertSame(409, $replaced->getStatusCode());
        self::assertSame(['Retry-After' => '3', 'X-Conflict-Code' => 'CONFLICT_001'], $replaced->getHeaders());
        self::assertSame($failure, $replaced->getPrevious());
        self::assertNull($event->getResponse());

        $unique = new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('Duplicate')), null);
        $event  = $this->event('nowo_chat_page_conversation', $unique);
        (new ChatApiExceptionListener())->onStorageFailure($event);
        self::assertInstanceOf(ConflictHttpException::class, $event->getThrowable());
        self::assertSame($unique, $event->getThrowable()->getPrevious());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001'], $event->getThrowable()->getHeaders());
    }

    public function testThe503OptInOfThePolicyKeepsTheOldStatusForJsonAndHtml(): void
    {
        $policy = new ConflictResponsePolicy(ConflictResponsePolicy::STATUS_SERVICE_UNAVAILABLE, 4);

        $event = $this->event('nowo_chat_api_send', new TransientStorageFailure());
        (new ChatApiExceptionListener($policy))->onStorageFailure($event);
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('4', $event->getResponse()->headers->get('Retry-After'));
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_001"}', $event->getResponse()->getContent());

        $event = $this->event('nowo_chat_page_send', new TransientStorageFailure(9));
        (new ChatApiExceptionListener($policy))->onStorageFailure($event);
        $page = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $page);
        self::assertSame(503, $page->getStatusCode());
        self::assertSame('9', $page->getHeaders()['Retry-After'] ?? null);

        $unique = new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('Duplicate')), null);
        $event  = $this->event('nowo_chat_api_send', $unique);
        (new ChatApiExceptionListener($policy))->onStorageFailure($event);
        self::assertSame(409, $event->getResponse()?->getStatusCode());
    }

    public function testThePolicysRetryAfterIsTheFallbackHint(): void
    {
        $event = $this->event('nowo_chat_api_send', new TransientStorageFailure());
        (new ChatApiExceptionListener(new ConflictResponsePolicy(409, 6)))->onStorageFailure($event);
        self::assertSame('6', $event->getResponse()?->headers->get('Retry-After'));
    }

    public function testACommittedConflictHasNoRetryAfterOnJsonAndHtml(): void
    {
        $deadlock = new DeadlockException(PdoDriverException::new(new PDOException('Deadlock')), null);
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 5, $deadlock, true);

        $event = $this->event('nowo_chat_api_send', new RuntimeException('wrapped', 0, $conflict));
        (new ChatApiExceptionListener())->onStorageFailure($event);
        $response = $event->getResponse();
        self::assertSame(409, $response?->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'unavailable', 'code' => 'CONFLICT_002', 'message' => ChatApiExceptionListener::PARTIAL_MESSAGE], json_decode((string) $response->getContent(), true));
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));

        $event = $this->event('nowo_chat_page_send', $conflict);
        (new ChatApiExceptionListener())->onStorageFailure($event);
        $page = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $page);
        self::assertSame(409, $page->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $page->getHeaders());
        self::assertSame($conflict, $page->getPrevious());

        // Not committed: the retry is invited.
        $open  = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 5, $deadlock, false);
        $event = $this->event('nowo_chat_api_send', $open);
        (new ChatApiExceptionListener())->onStorageFailure($event);
        self::assertSame('5', $event->getResponse()?->headers->get('Retry-After'));
    }

    /**
     * @return iterable<string, array{string|null, Throwable}>
     */
    public static function leftAlone(): iterable
    {
        yield 'a host route' => ['app_orders', new TransientStorageFailure()];
        yield 'no route' => [null, new TransientStorageFailure()];
        yield 'an HTTP exception' => ['nowo_chat_api_send', new NotFoundHttpException()];
        yield 'any other failure' => ['nowo_chat_api_send', new RuntimeException('bug')];
        yield 'an optimistic lock around a failed commit (outcome unknown)' => ['nowo_chat_api_send', new OptimisticLockException('Commit failed', null, new RuntimeException('gone'))];
    }

    #[DataProvider('leftAlone')]
    public function testEverythingElseIsLeftAlone(?string $route, Throwable $failure): void
    {
        $event = $this->event($route, $failure);
        (new ChatApiExceptionListener())->onStorageFailure($event);

        self::assertNull($event->getResponse());
        self::assertSame($failure, $event->getThrowable());
    }

    private function event(?string $route, Throwable $failure): ExceptionEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
