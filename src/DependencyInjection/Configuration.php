<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

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

    /**
     * Builds the `nowo_chat_kit` tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);

        $treeBuilder->getRootNode()
            ->beforeNormalization()
                // An omitted `profiles` still gets one fully defaulted profile (prototype defaults apply).
                ->ifTrue(static fn (mixed $v): bool => \is_array($v) && !isset($v['profiles']))
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
                ->ifTrue(static fn (array $config): bool => !\array_key_exists($config['default_profile'], $config['profiles']))
                ->thenInvalid('nowo_chat_kit.default_profile must reference a key defined under nowo_chat_kit.profiles.')
            ->end();

        return $treeBuilder;
    }
}
