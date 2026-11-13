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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function assert;

/**
 * House rule LQ (audit OE): a provider that folds case or accents answers `find('project:acme')` with the channel it
 * knows as `project:Acme`. That look-alike is an unknown channel, so neither reading nor writing, pinning or managing
 * links of the conversation stored under the asked key goes through it.
 */
final class ChatAccessLookAlikeChannelTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function lookAlikeKeys(): iterable
    {
        yield 'case' => ['project:Acme'];
        yield 'accent' => ['project:acmé'];
        yield 'nfd' => ["project:acme\u{0301}"];
    }

    #[DataProvider('lookAlikeKeys')]
    public function testAProviderThatReturnsALookAlikeKeyFailsClosed(string $returnedKey): void
    {
        $user = new TestUser('Ana');
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, 3);
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        $provider->method('find')->willReturn(new ChatChannel($returnedKey, 'Acme'));
        $provider->method('canUse')->willReturn(true);
        $conversation = ChatConversation::channel('project:acme');

        $policy = $this->createStubForIntersectionOfInterfaces([
            ChatAccessPolicyInterface::class,
            ChatPinPolicyInterface::class,
            ChatLinkPolicyInterface::class,
        ]);
        $policy->method('isReachable')->willReturn(true);
        $policy->method('canPinInChannel')->willReturn(true);
        $policy->method('canManageLinksInChannel')->willReturn(true);
        assert($policy instanceof ChatAccessPolicyInterface);
        $access = new ChatAccess($policy, $provider, $this->createStub(ChatDirectoryInterface::class));

        self::assertNull($access->channel('project:acme'), 'look-alike is an unknown channel');
        self::assertFalse($access->canUseChannel($user, 'project:acme'));
        self::assertFalse($access->canWriteChannel($user, 'project:acme'));
        self::assertFalse($access->canView($conversation, $user));
        self::assertFalse($access->canPin($conversation, $user));
        self::assertFalse($access->canManageLinks($conversation, $user));
        self::assertFalse($access->canManageLinks($conversation, $user, false));
    }

    public function testTheExactKeyStillResolves(): void
    {
        $provider = $this->createStub(ChatChannelProviderInterface::class);
        $provider->method('find')->willReturnCallback(static fn (string $key): ChatChannel => new ChatChannel($key, 'Acme'));
        $access = new ChatAccess($this->createStub(ChatAccessPolicyInterface::class), $provider, $this->createStub(ChatDirectoryInterface::class));

        self::assertSame('project:acme', $access->channel('project:acme')?->key);
    }
}
