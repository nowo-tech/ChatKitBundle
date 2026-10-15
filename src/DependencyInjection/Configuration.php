<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration tree for `nowo_chat_kit` (REQ-CFG-001: `default_profile` + `profiles`).
 *
 * Each profile is a complete, named chat setup (e.g. `default` for the end-user dock,
 * `support` for an agent inbox). The skeleton only exposes the minimal keys; feature
 * options (realtime, push, sounds, templates, theme tokens) are added per profile later.
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
            ->children()
                ->scalarNode('default_profile')
                    ->info('Profile used when no explicit profile is requested. Must exist under "profiles".')
                    ->defaultValue(self::DEFAULT_PROFILE)
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('profiles')
                    ->info('Named chat profiles (complete settings block per variant).')
                    ->useAttributeAsKey('name')
                    ->defaultValue([self::DEFAULT_PROFILE => ['enabled' => true]])
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')
                                ->info('Render the floating chat dock for this profile.')
                                ->defaultTrue()
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
