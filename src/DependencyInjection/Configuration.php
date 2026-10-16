<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function is_array;

/**
 * Configuration tree for `nowo_chat_kit` (REQ-CFG-001: `default_profile` + `profiles`).
 *
 * Each profile is a complete, named chat setup. The default profile's `user_class` is mapped to
 * ChatUserInterface through `doctrine.orm.resolve_target_entities`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_chat_kit';

    public const DEFAULT_PROFILE = 'default';

    /** Dock icon → Symfony UX Icons name (Tabler set), used with `icon_set: ux_icon`. */
    public const array DEFAULT_ICONS = [
        'chat'        => 'tabler:message-circle',
        'compose'     => 'tabler:edit',
        'chevronUp'   => 'tabler:chevron-up',
        'chevronDown' => 'tabler:chevron-down',
        'close'       => 'tabler:x',
        'send'        => 'tabler:send',
        'back'        => 'tabler:arrow-left',
        'arrowDown'   => 'tabler:arrow-down',
        'search'      => 'tabler:search',
        'hash'        => 'tabler:hash',
        'lifebuoy'    => 'tabler:lifebuoy',
        'retry'       => 'tabler:refresh',
        'buzz'        => 'tabler:bolt',
        'smile'       => 'tabler:mood-smile',
        'check'       => 'tabler:check',
        'edit'        => 'tabler:pencil',
        'trash'       => 'tabler:trash',
        'link'        => 'tabler:link',
        'external'    => 'tabler:external-link',
    ];

    /**
     * Builds the `nowo_chat_kit` tree.
     *
     * @return TreeBuilder The tree with `default_profile` and the `profiles` prototype
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);

        $treeBuilder->getRootNode()
            ->beforeNormalization()
                // An omitted `profiles` still gets one fully defaulted profile (prototype defaults apply).
                ->ifTrue(static fn (mixed $v): bool => is_array($v) && !isset($v['profiles']))
                ->then(static fn (array $v): array => $v + ['profiles' => [$v['default_profile'] ?? self::DEFAULT_PROFILE => []]])
            ->end()
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile used when no explicit profile is requested. Must exist under "profiles".')
                    ->defaultValue(self::DEFAULT_PROFILE)
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('profiles')
                    ->info('Named chat profiles (complete settings block per variant).')
                    ->useAttributeAsKey('name')
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')
                                ->info('Render the floating chat dock for this profile.')
                                ->defaultTrue()
                            ->end()
                            ->scalarNode('user_class')
                                ->info('Application user entity implementing Nowo\\ChatKitBundle\\Model\\ChatUserInterface.')
                                ->defaultNull()
                            ->end()
                            ->integerNode('retention_days')
                                ->info('Messages older than this are purged by nowo:chat:purge (minimum 30).')
                                ->defaultValue(365)
                                ->min(30)
                            ->end()
                            ->booleanNode('realtime')
                                ->info('Push messages, typing, presence and receipts live (needs a ChatRealtimeInterface; Mercure by default).')
                                ->defaultTrue()
                            ->end()
                            ->booleanNode('push')
                                ->info('Offer Web Push for new messages (needs a ChatPushSenderInterface implementation).')
                                ->defaultFalse()
                            ->end()
                            ->scalarNode('push_url')
                                ->info('URL a Web Push notification opens; {uuid} is the conversation id.')
                                ->defaultValue('/?chat={uuid}')
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('push_icon')
                                ->info('Icon URL for Web Push notifications.')
                                ->defaultNull()
                            ->end()
                            ->arrayNode('digest')
                                ->info('Email of the conversations still unread a while after a message (needs Messenger and a ChatDigestSenderInterface).')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->booleanNode('enabled')->defaultFalse()->end()
                                    ->integerNode('delay_minutes')->defaultValue(15)->min(1)->end()
                                    ->integerNode('interval_minutes')
                                        ->info('At most one email per user in this window.')
                                        ->defaultValue(60)->min(1)
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('page_layout')
                                ->info('Layout the full-page chat (/chat) extends; it must define the `title` and `body` blocks.')
                                ->defaultValue('@NowoChatKitBundle/page_layout.html.twig')
                                ->cannotBeEmpty()
                            ->end()
                            ->scalarNode('cache_pool')
                                ->info('PSR-6 pool for presence and recipient lists (shared between workers, e.g. Redis).')
                                ->defaultValue('cache.app')
                                ->cannotBeEmpty()
                            ->end()
                            ->arrayNode('reactions')
                                ->info('Emojis offered as message reactions.')
                                ->scalarPrototype()->end()
                                ->defaultValue(['👍', '❤️', '😂', '😮', '😢', '🎉'])
                            ->end()
                            ->enumNode('icon_set')
                                ->info('ux_icon: Symfony UX Icons with the "icons" map (default); svg_inline: the built-in SVG only.')
                                ->values(['ux_icon', 'svg_inline'])
                                ->defaultValue('ux_icon')
                            ->end()
                            ->arrayNode('icons')
                                ->info('UX Icons name per dock icon (merged over the Tabler defaults). An icon UX Icons cannot render falls back to the built-in SVG.')
                                ->useAttributeAsKey('name')
                                ->scalarPrototype()->end()
                                ->defaultValue(self::DEFAULT_ICONS)
                            ->end()
                            ->integerNode('presence_ttl')
                                ->info('Seconds a heartbeat keeps a user online.')
                                ->defaultValue(90)
                                ->min(30)
                            ->end()
                            ->arrayNode('limits')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->integerNode('send_per_minute')->defaultValue(30)->min(1)->end()
                                    ->integerNode('actions_per_minute')->defaultValue(240)->min(1)->end()
                                    ->integerNode('typing_per_minute')->defaultValue(30)->min(1)->end()
                                    ->integerNode('reads_per_minute')->defaultValue(600)->min(1)->end()
                                    ->integerNode('buzz_interval_seconds')
                                        ->info('Minimum seconds between two buzzes in the same conversation.')
                                        ->defaultValue(15)->min(1)
                                    ->end()
                                    ->integerNode('buzz_per_minute')->defaultValue(10)->min(1)->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => !array_key_exists($config['default_profile'], $config['profiles']))
                ->thenInvalid('nowo_chat_kit.default_profile must reference a key defined under nowo_chat_kit.profiles.')
            ->end();

        return $treeBuilder;
    }
}
