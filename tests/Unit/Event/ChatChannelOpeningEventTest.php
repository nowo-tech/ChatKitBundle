<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Event;

use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatChannelOpeningEventTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function keys(): iterable
    {
        yield 'prefix' => ['project:0190', 'project'];
        yield 'nested' => ['project:a:b', 'project'];
        yield 'no prefix' => ['general', null];
        yield 'empty prefix' => [':general', null];
    }

    #[DataProvider('keys')]
    public function testTheScopeIsWhatTheKeySaysBeforeItsFirstColon(string $key, ?string $scope): void
    {
        $event = new ChatChannelOpeningEvent(new ChatChannel($key, 'Acme'), new TestUser('Ana'));

        self::assertSame($key, $event->channelKey);
        self::assertSame($scope, $event->scope);
    }

    public function testDenyRecordsTheReasonAndStopsTheOtherListeners(): void
    {
        $user  = new TestUser('Ana');
        $event = new ChatChannelOpeningEvent(new ChatChannel('project:x', 'Acme'), $user);

        self::assertSame($user, $event->user);
        self::assertFalse($event->isDenied());
        self::assertFalse($event->isPropagationStopped());
        self::assertSame('', $event->reason());

        $event->deny('Plan limit');

        self::assertTrue($event->isDenied());
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('Plan limit', $event->reason());
    }
}
