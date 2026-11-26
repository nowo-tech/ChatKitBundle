<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\DependencyInjection;

use Nowo\ChatKitBundle\ClientApi\ChatClientApiGuard;
use Nowo\ChatKitBundle\Contract\ChatCardProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatComposerActionProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatMessageMenuSlotProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Support\ChatEmojiCatalog;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function count;

/**
 * 1.35: the message menu parameters, and the quick reactions the server would refuse are refused at boot.
 */
final class NowoChatKitExtensionMessageMenuTest extends TestCase
{
    public function testParametersAndAcceptedReactions(): void
    {
        $container = $this->load([]);
        $quick     = $container->getParameter('nowo_chat_kit.message_menu.quick_reactions');
        $save      = $container->getParameter('nowo_chat_kit.message_menu.save');
        $accepted  = $container->getParameter('nowo_chat_kit.accepted_reactions');
        self::assertIsArray($quick);
        self::assertIsArray($save);
        self::assertIsArray($accepted);
        self::assertSame(['👍', '❤️', '😂', '😮', '😢', '🙏'], $quick['emojis']);
        self::assertFalse($save['enabled']);
        self::assertCount(count(ChatEmojiCatalog::accepted(['👍', '❤️', '😂', '😮', '😢', '🎉'], true)), $accepted);

        $strict = $this->load(['quick_reactions' => ['picker' => false, 'emojis' => ['👍']]]);
        self::assertSame(['👍', '❤️', '😂', '😮', '😢', '🎉'], $strict->getParameter('nowo_chat_kit.accepted_reactions'));
    }

    public function testAQuickReactionTheServerWouldRefuseFailsTheBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('message_menu.quick_reactions.emojis has "🙏"');
        $this->load(['quick_reactions' => ['picker' => false]]);
    }

    public function testAContextPolicyNeedsIdsOnlyRealtimeEvents(): void
    {
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => TestUser::class, 'realtime_events' => 'ids', 'client_api' => ['principal_resolver' => 'app.tokens', 'context_policy' => 'app.contexts']]]]], $container);
        self::assertTrue($container->getParameter('nowo_chat_kit.client_api.enabled'));
        self::assertNull($container->getDefinition(ChatClientApiGuard::class)->getArgument('$revoker'));

        // Audit ZBW-L2: with `full`, every context's message bodies would reach the client through the per-person hub topic.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('client_api.context_policy needs "realtime_events: ids"');
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => TestUser::class, 'client_api' => ['principal_resolver' => 'app.tokens', 'context_policy' => 'app.contexts']]]]], new ContainerBuilder());
    }

    public function testTheTokenRevokerGoesOnTheGuard(): void
    {
        // 2.4.1: POST logout revokes the presented token through the host's revoker.
        $container = new ContainerBuilder();
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['user_class' => TestUser::class, 'client_api' => ['principal_resolver' => 'app.tokens', 'token_revoker' => 'app.revoker']]]]], $container);
        self::assertEquals(new Reference('app.revoker'), $container->getDefinition(ChatClientApiGuard::class)->getArgument('$revoker'));
    }

    public function testSlotsTakeTheirPlaceInTheOrder(): void
    {
        // 2.6 (spec 259): `slot:<key>` places a host slot; the card/slot ports are autoconfigured.
        $container = $this->load(['order' => ['reply', 'slot:save_vault', 'delete']]);
        $menu      = $container->getParameter('nowo_chat_kit.message_menu');
        self::assertIsArray($menu);
        self::assertSame(['reply', 'slot:save_vault', 'delete'], $menu['order']);
        self::assertArrayHasKey(ChatCardProviderInterface::class, $container->getAutoconfiguredInstanceof());
        self::assertArrayHasKey(ChatComposerActionProviderInterface::class, $container->getAutoconfiguredInstanceof());
        self::assertArrayHasKey(ChatMessageMenuSlotProviderInterface::class, $container->getAutoconfiguredInstanceof());
        foreach ([['slot:Bad'], ['slot:a', 'slot:a'], ['slot:'], [1]] as $order) {
            try {
                $this->load(['order' => $order]);
                self::fail('an invalid order must fail');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('message_menu.order takes each of', $exception->getMessage());
            }
        }
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
