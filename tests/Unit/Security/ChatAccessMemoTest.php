<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Security;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function in_array;

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

    public function testUsersAreLoadedOncePerRequestAndForgottenOnReset(): void
    {
        $ana = $this->createStub(ChatUserInterface::class);
        $ana->method('getId')->willReturn(1);
        $luis = $this->createStub(ChatUserInterface::class);
        $luis->method('getId')->willReturn(2);
        $directory = $this->createMock(ChatDirectoryInterface::class);
        $directory->expects(self::exactly(4))->method('findByIds')
            ->willReturnCallback(static fn (array $ids): array => array_values(array_filter([$ana, $luis], static fn (ChatUserInterface $user): bool => in_array($user->getId(), $ids, true))));
        $requests = new RequestStack();
        $access   = new ChatAccess($this->createStub(ChatAccessPolicyInterface::class), $this->createStub(ChatChannelProviderInterface::class), $directory, $requests);
        $direct   = ChatConversation::direct($ana, $luis);

        $requests->push(Request::create('/a'));
        self::assertSame([1 => $ana, 2 => $luis], $access->usersByIds([1, 2, 9])); // query 1
        self::assertSame($luis, $access->directPeer($direct, $ana), 'memo hit: no query per row');
        self::assertSame([], $access->usersByIds([9]), 'unknown ids are memoized as gone');
        self::assertSame([], $access->usersByIds([]));
        $access->reset();
        self::assertSame($ana, $access->directPeer($direct, $luis)); // query 2: reset() cleared the memo
        $requests->pop();

        // Console / Messenger: no request, no memo.
        self::assertSame([2 => $luis], $access->usersByIds([2])); // query 3
        self::assertSame([2 => $luis], $access->usersByIds([2])); // query 4
        self::assertSame([], $access->usersByIds([]));
    }
}
