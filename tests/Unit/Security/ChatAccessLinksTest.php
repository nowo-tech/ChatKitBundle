<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Security;

use Nowo\ChatKitBundle\Contract\ChatAccessPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPolicyInterface;
use Nowo\ChatKitBundle\Contract\ChatPinPolicyInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function assert;

/**
 * 1.19: who manages the pinned links — both people of a direct conversation, agents in tickets, every channel member
 * (`members`) or, with `admins`, whom ChatLinkPolicyInterface allows, else ChatPinPolicyInterface, else nobody; never
 * someone who cannot read the conversation.
 */
final class ChatAccessLinksTest extends TestCase
{
    public function testChannelManagers(): void
    {
        $user         = $this->user('Ana', 3);
        $provider     = $this->provider();
        $conversation = ChatConversation::channel('project:acme');

        $links = $this->createStubForIntersectionOfInterfaces([ChatAccessPolicyInterface::class, ChatLinkPolicyInterface::class]);
        $links->method('isReachable')->willReturn(true);
        $links->method('canManageLinksInChannel')->willReturnOnConsecutiveCalls(true, false);
        assert($links instanceof ChatAccessPolicyInterface);
        $access = new ChatAccess($links, $provider, $this->createStub(ChatDirectoryInterface::class));
        self::assertTrue($access->canManageLinks($conversation, $user), 'members: every member');
        self::assertTrue($access->canManageLinks($conversation, $user, false));
        self::assertFalse($access->canManageLinks($conversation, $user, false));

        $pins = $this->createStubForIntersectionOfInterfaces([ChatAccessPolicyInterface::class, ChatPinPolicyInterface::class]);
        $pins->method('isReachable')->willReturn(true);
        $pins->method('canPinInChannel')->willReturn(true);
        assert($pins instanceof ChatAccessPolicyInterface);
        self::assertTrue((new ChatAccess($pins, $provider, $this->createStub(ChatDirectoryInterface::class)))->canManageLinks($conversation, $user, false), 'falls back to the pin policy');

        $plain = $this->createStub(ChatAccessPolicyInterface::class);
        $plain->method('isReachable')->willReturn(true);
        self::assertFalse((new ChatAccess($plain, $provider, $this->createStub(ChatDirectoryInterface::class)))->canManageLinks($conversation, $user, false), 'deny by default');

        $unreachable = $this->createStub(ChatAccessPolicyInterface::class);
        self::assertFalse((new ChatAccess($unreachable, $provider, $this->createStub(ChatDirectoryInterface::class)))->canManageLinks($conversation, $user), 'cannot read it');
    }

    public function testAChannelThatDisappearsIsDenied(): void
    {
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        // Read access, then (1.24) the write check (use + channel), then gone.
        $channel = new ChatChannel('project:acme', 'Acme');
        $provider->method('find')->willReturnOnConsecutiveCalls($channel, $channel, $channel, null);
        $provider->method('canUse')->willReturn(true);
        $policy = $this->createStub(ChatAccessPolicyInterface::class);
        $policy->method('isReachable')->willReturn(true);

        self::assertFalse((new ChatAccess($policy, $provider, $this->createStub(ChatDirectoryInterface::class)))->canManageLinks(ChatConversation::channel('project:acme'), $this->user('Ana', 3), false), 'an explicit deny, not a TypeError (audit FZ-I4)');
    }

    public function testDirectAndSupport(): void
    {
        $ana    = $this->user('Ana', 3);
        $luis   = $this->user('Luis', 4);
        $policy = $this->createStub(ChatAccessPolicyInterface::class);
        $policy->method('isReachable')->willReturn(true);
        $policy->method('isSupportAgent')->willReturnCallback(static fn (TestUser $user): bool => $user->getChatDisplayName() === 'Luis');
        $access = new ChatAccess($policy, $this->provider(), $this->createStub(ChatDirectoryInterface::class));

        self::assertTrue($access->canManageLinks(ChatConversation::direct($ana, $luis), $ana));
        $ticket = ChatConversation::support($ana);
        self::assertFalse($access->canManageLinks($ticket, $ana), 'the requester reads, agents manage');
        self::assertTrue($access->canManageLinks($ticket, $luis));
    }

    private function user(string $name, int $id): TestUser
    {
        $user = new TestUser($name);
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function provider(): ChatChannelProviderInterface
    {
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        $provider->method('find')->willReturn(new ChatChannel('project:acme', 'Acme'));
        $provider->method('canUse')->willReturn(true);

        return $provider;
    }
}
