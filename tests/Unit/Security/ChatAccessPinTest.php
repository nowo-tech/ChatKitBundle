<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Security;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
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
 * Who may pin in a channel (1.10): only whom an access policy that implements ChatPinPolicyInterface allows; nobody
 * when the policy does not implement it, and never someone who cannot read the channel.
 */
final class ChatAccessPinTest extends TestCase
{
    public function testChannelPinsFollowThePinPolicy(): void
    {
        $user = new TestUser('Ana');
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, 3);
        $channel  = new ChatChannel('project:acme', 'Acme');
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        $provider->method('find')->willReturn($channel);
        $provider->method('canUse')->willReturn(true);
        $conversation = ChatConversation::channel('project:acme');

        $policy = $this->createStubForIntersectionOfInterfaces([ChatAccessPolicyInterface::class, ChatPinPolicyInterface::class]);
        $policy->method('isReachable')->willReturn(true);
        $policy->method('canPinInChannel')->willReturnOnConsecutiveCalls(true, false);
        assert($policy instanceof ChatAccessPolicyInterface);
        $access = new ChatAccess($policy, $provider, $this->createStub(ChatDirectoryInterface::class));
        self::assertTrue($access->canPin($conversation, $user));
        self::assertFalse($access->canPin($conversation, $user));

        $plain = $this->createStub(ChatAccessPolicyInterface::class);
        $plain->method('isReachable')->willReturn(true);
        self::assertFalse((new ChatAccess($plain, $provider, $this->createStub(ChatDirectoryInterface::class)))->canPin($conversation, $user), 'deny by default');

        $unreachable = $this->createStub(ChatAccessPolicyInterface::class);
        self::assertFalse((new ChatAccess($unreachable, $provider, $this->createStub(ChatDirectoryInterface::class)))->canPin($conversation, $user), 'cannot read it');
    }
}
