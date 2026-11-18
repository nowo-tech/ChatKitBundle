<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Support\ChatEmojiCatalog;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function count;

/**
 * 1.35: the message menu parameters, and the quick reactions the server would refuse are refused at boot.
 */
final class NowoChatKitExtensionMessageMenuTest extends TestCase
{
    public function testParametersAndAcceptedReactions(): void
    {
        $container = $this->load([]);
        self::assertSame(['👍', '❤️', '😂', '😮', '😢', '🙏'], $container->getParameter('nowo_chat_kit.message_menu.quick_reactions')['emojis']);
        self::assertFalse($container->getParameter('nowo_chat_kit.message_menu.save')['enabled']);
        self::assertCount(count(ChatEmojiCatalog::accepted(['👍', '❤️', '😂', '😮', '😢', '🎉'], true)), $container->getParameter('nowo_chat_kit.accepted_reactions'));

        $strict = $this->load(['quick_reactions' => ['picker' => false, 'emojis' => ['👍']]]);
        self::assertSame(['👍', '❤️', '😂', '😮', '😢', '🎉'], $strict->getParameter('nowo_chat_kit.accepted_reactions'));
    }

    public function testAQuickReactionTheServerWouldRefuseFailsTheBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('message_menu.quick_reactions.emojis has "🙏"');
        $this->load(['quick_reactions' => ['picker' => false]]);
    }

    /**
     * @param array<string, mixed> $menu
     */
    private function load(array $menu): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => TestUser::class, 'message_menu' => $menu]]]], $container);

        return $container;
    }
}
