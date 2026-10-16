<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Security;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * FrankenPHP worker mode without a kernel reset: answers are memoized on the request, never carried to the next one.
 */
final class ChatAccessMemoTest extends TestCase
{
    public function testMemoLivesOnTheRequestOnly(): void
    {
        $policy = $this->createMock(ChatAccessPolicyInterface::class);
        $policy->expects(self::exactly(4))->method('isSupportAgent')->willReturnOnConsecutiveCalls(true, false, true, true);
        $user = $this->createStub(ChatUserInterface::class);
        $user->method('getId')->willReturn(7);
        $requests = new RequestStack();
        $access   = new ChatAccess($policy, $this->createStub(ChatChannelProviderInterface::class), $this->createStub(ChatDirectoryInterface::class), $requests);

        $requests->push(Request::create('/a'));
        self::assertTrue($access->isSupportAgent($user));
        self::assertTrue($access->isSupportAgent($user), 'memoized within the request');
        $requests->pop();

        $requests->push(Request::create('/b'));
        self::assertFalse($access->isSupportAgent($user), 'the next request asks the policy again (role revoked)');
        $access->reset();
        self::assertTrue($access->isSupportAgent($user), 'reset() clears the current request memo');
        $requests->pop();

        // Console / Messenger: no request, no memo.
        self::assertTrue($access->isSupportAgent($user));
    }
}
