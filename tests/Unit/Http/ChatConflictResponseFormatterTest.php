<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Http;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\ChatKitBundle\Http\ChatApiExceptionListener;
use Nowo\ChatKitBundle\Http\ChatConflictResponseFormatter;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use PDOException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function json_decode;

/**
 * 1.34: the formatter answers ChatKit's own API routes in ChatKit's JSON shape and leaves the rest alone.
 */
final class ChatConflictResponseFormatterTest extends TestCase
{
    public function testItAnswersTheChatShapeOnTheApiRoutes(): void
    {
        $response = (new ChatConflictResponseFormatter())->format($this->request('nowo_chat_api_send'), $this->context(false));
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('{"ok":false,"error":"unavailable","code":"CONFLICT_001"}', $response->getContent());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
    }

    public function testItKeepsThePolicyStatusAndAsksToReloadAfterACommit(): void
    {
        $response = (new ChatConflictResponseFormatter())->format($this->request('nowo_chat_api_send'), $this->context(true, 503));
        self::assertSame(503, $response?->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'unavailable', 'code' => 'CONFLICT_002', 'message' => ChatApiExceptionListener::PARTIAL_MESSAGE], json_decode((string) $response->getContent(), true));
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
    }

    public function testPagesAndHostRoutesFallThrough(): void
    {
        $formatter = new ChatConflictResponseFormatter();
        self::assertNull($formatter->format($this->request('nowo_chat_page_send'), $this->context(false)));
        self::assertNull($formatter->format($this->request('app_orders'), $this->context(false)));
        self::assertNull($formatter->format(Request::create('/x'), $this->context(false)));
    }

    private function request(string $route): Request
    {
        $request = Request::create('/x', 'POST');
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function context(bool $committed, int $status = 409): ConflictResponseContext
    {
        $failure = new DeadlockException(PdoDriverException::new(new PDOException('Deadlock')), null);

        return new ConflictResponseContext($status, $committed ? null : 1, 'concurrency_conflict', 'Retry later.', 3, $failure, $committed);
    }
}
