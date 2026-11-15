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
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * 1.30 (Beacon spec 239): storage failures on ChatKit's routes are 503 (transient, with Retry-After) or 409 (conflict),
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
        yield 'runner failure with its hint' => [new TransientStorageFailure(4, TransientStorageFailure::DEFAULT_MESSAGE, $deadlock), 503, '4'];
        yield 'runner failure without a hint' => [new TransientStorageFailure(), 503, '1'];
        yield 'runner failure wrapped by the host' => [new RuntimeException('wrapped', 0, new TransientStorageFailure(2)), 503, '2'];
        yield 'deadlock the runner did not see' => [$deadlock, 503, '1'];
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
        self::assertSame($status === 503 ? '{"ok":false,"error":"unavailable"}' : '{"ok":false,"error":"conflict"}', $response->getContent(), 'no SQL, no driver text');
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testThePageRoutesGetHttpExceptionsWithTheOriginalAsPrevious(): void
    {
        $failure = new TransientStorageFailure(3);
        $event   = $this->event('nowo_chat_page_send', $failure);
        (new ChatApiExceptionListener())->onStorageFailure($event);
        $replaced = $event->getThrowable();
        self::assertInstanceOf(ServiceUnavailableHttpException::class, $replaced);
        self::assertSame(['Retry-After' => 3], $replaced->getHeaders());
        self::assertSame($failure, $replaced->getPrevious());
        self::assertNull($event->getResponse());

        $unique = new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('Duplicate')), null);
        $event  = $this->event('nowo_chat_page_conversation', $unique);
        (new ChatApiExceptionListener())->onStorageFailure($event);
        self::assertInstanceOf(ConflictHttpException::class, $event->getThrowable());
        self::assertSame($unique, $event->getThrowable()->getPrevious());
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
