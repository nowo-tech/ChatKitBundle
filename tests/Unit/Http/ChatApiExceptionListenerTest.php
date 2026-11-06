<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Http;

use Nowo\ChatKitBundle\Http\ChatApiException;
use Nowo\ChatKitBundle\Http\ChatApiExceptionListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ChatApiExceptionListenerTest extends TestCase
{
    public function testRendersOnlyChatApiExceptions(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $other  = new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new RuntimeException('x'));
        (new ChatApiExceptionListener())($other);
        self::assertNull($other->getResponse());

        $event = new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new ChatApiException('read_only', 403));
        (new ChatApiExceptionListener())($event);
        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertSame('{"ok":false,"error":"read_only"}', $event->getResponse()->getContent());

        $denied = new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new ChatApiException('channel_denied', 403, null, 'Plan limit'));
        (new ChatApiExceptionListener())($denied);
        self::assertSame('{"ok":false,"error":"channel_denied","message":"Plan limit"}', $denied->getResponse()?->getContent());
    }
}
