<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Security;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelWritePolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatPinPolicyInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function assert;

/**
 * 1.24: who writes in a channel — members, unless the channel is read only or the provider's
 * ChatChannelWritePolicyInterface says no; pins and links follow the same rule.
 */
final class ChatAccessWriteTest extends TestCase
{
    public function testReadOnlyChannelsAndTheWritePolicy(): void
    {
        $user = new TestUser('Ana');
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, 3);
        $policy = $this->createStubForIntersectionOfInterfaces([ChatAccessPolicyInterface::class, ChatPinPolicyInterface::class]);
        $policy->method('isReachable')->willReturn(true);
        $policy->method('canPinInChannel')->willReturn(true);
        assert($policy instanceof ChatAccessPolicyInterface);
        $conversation = ChatConversation::channel('project:acme');

        $open   = $this->provider(new ChatChannel('project:acme', 'Acme'));
        $access = new ChatAccess($policy, $open, $this->createStub(ChatDirectoryInterface::class));
        self::assertTrue($access->canWriteChannel($user, 'project:acme'), 'default: every member writes');
        self::assertTrue($access->canPin($conversation, $user));
        self::assertTrue($access->canManageLinks($conversation, $user));
        self::assertFalse($access->canWriteChannel($user, 'project:unknown'));

        $access = new ChatAccess($policy, $this->provider(new ChatChannel('project:acme', 'Acme', true)), $this->createStub(ChatDirectoryInterface::class));
        self::assertFalse($access->canWriteChannel($user, 'project:acme'), 'read only');
        self::assertFalse($access->canPin($conversation, $user));
        self::assertFalse($access->canManageLinks($conversation, $user));
        self::assertTrue($access->canView($conversation, $user), 'still readable');

        $writers = $this->createStubForIntersectionOfInterfaces([ChatChannelProviderInterface::class, ChatChannelWritePolicyInterface::class]);
        $writers->method('find')->willReturn(new ChatChannel('project:acme', 'Acme'));
        $writers->method('canUse')->willReturn(true);
        $writers->method('canWrite')->willReturnOnConsecutiveCalls(false, true);
        assert($writers instanceof ChatChannelProviderInterface);
        $access = new ChatAccess($policy, $writers, $this->createStub(ChatDirectoryInterface::class));
        self::assertFalse($access->canWriteChannel($user, 'project:acme'), 'the provider says no');
        self::assertTrue($access->canWriteChannel($user, 'project:acme'));
    }

    private function provider(ChatChannel $channel): ChatChannelProviderInterface
    {
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        $provider->method('find')->willReturnCallback(static fn (string $key): ?ChatChannel => $key === $channel->key ? $channel : null);
        $provider->method('canUse')->willReturn(true);

        return $provider;
    }
}
