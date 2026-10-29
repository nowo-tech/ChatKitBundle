<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function count;
use function is_array;
use function is_string;

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

    /** 1.x default of `page_layout`: the bundle's own document, replaced by the UiKit layout (REQ-UI-003). */
    public const string LEGACY_PAGE_LAYOUT = '@NowoChatKitBundle/page_layout.html.twig';

    /** Default template of the full-page chat (`web_ui.templates.page`). */
    public const string PAGE_TEMPLATE = '@NowoChatKitBundle/page.html.twig';

    /** Dock icon → Symfony UX Icons name (Tabler set), used with `icon_set: ux_icon`. */
    public const array DEFAULT_ICONS = [
        'chat'        => 'tabler:message-circle',
        'compose'     => 'tabler:edit',
        'chevronUp'   => 'tabler:chevron-up',
        'chevronDown' => 'tabler:chevron-down',
        'tuck'        => 'tabler:arrow-bar-to-right',
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
        'ban'         => 'tabler:ban',
        'link'        => 'tabler:link',
        'external'    => 'tabler:external-link',
        'archive'     => 'tabler:archive',
        'unarchive'   => 'tabler:arrow-back-up',
        'pin'         => 'tabler:pin',
        'format'      => 'tabler:typography',
        'quick'       => 'tabler:message-bolt',
        'bold'        => 'tabler:bold',
        'italic'      => 'tabler:italic',
        'strike'      => 'tabler:strikethrough',
        'code'        => 'tabler:code',
        'quote'       => 'tabler:quote',
        'list'        => 'tabler:list',
        'help'        => 'tabler:help',
        'auto'        => 'tabler:clock-play',
    ];

    /** Support ticket topics offered by default (labels and replies in the bundle catalogue). */
    public const array DEFAULT_SUPPORT_TOPICS = [
        ['key' => 'incident', 'icon' => 'tabler:bug', 'emoji' => '🐞'],
        ['key' => 'billing', 'icon' => 'tabler:receipt', 'emoji' => '💳'],
        ['key' => 'how_to', 'icon' => 'tabler:help-circle', 'emoji' => '❓'],
        ['key' => 'account', 'icon' => 'tabler:key', 'emoji' => '🔐'],
        ['key' => 'other', 'icon' => 'tabler:dots', 'emoji' => '💬'],
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
                            ->booleanNode('push_preview')
                                ->info('Put the first 140 characters of the message in the Web Push body (shown on lock screens). Off: a generic "New message".')
                                ->defaultFalse()
                            ->end()
                            ->arrayNode('doctrine')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->scalarNode('table_prefix')
                                        ->info('Prepended to every table, index and unique constraint of the bundle (e.g. "app_" gives app_nowo_chat_message). Empty: none.')
                                        ->defaultValue('')
                                        ->validate()
                                            ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || preg_match('/^[a-z0-9_]*$/', $prefix) !== 1)
                                            ->thenInvalid('table_prefix may only contain lowercase letters, digits and underscores.')
                                        ->end()
                                    ->end()
                                ->end()
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
                            ->arrayNode('web_ui')
                                ->info('UiKit page contract (REQ-UI-003) of the full-page chat (/chat).')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->scalarNode('layout_template')
                                        ->info('Layout the chat page extends; null (default) = nowo_ui_kit.layouts.admin. It must render the UiKit canonical blocks.')
                                        ->defaultNull()
                                    ->end()
                                    ->arrayNode('templates')
                                        ->addDefaultsIfNotSet()
                                        ->children()
                                            ->scalarNode('page')
                                                ->info('Template of the chat page (a host template that extends @!NowoChatKitBundle/page.html.twig and overrides one block).')
                                                ->defaultValue(self::PAGE_TEMPLATE)
                                                ->cannotBeEmpty()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('page_layout')
                                ->info('Deprecated: 1.x layout with the `title`, `stylesheets`, `javascripts` and `body` blocks; when set (and web_ui.layout_template is not), the chat page keeps its 1.x template (page_legacy.html.twig).')
                                ->defaultNull()
                                ->setDeprecated('nowo-tech/chat-kit-bundle', '1.5', 'The "%path%.%node%" option is deprecated, use "web_ui.layout_template" (a layout rendering the UiKit canonical blocks; null = nowo_ui_kit.layouts.admin) instead.')
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
                            ->integerNode('auto_reply_interval_hours')
                                ->info('At most one automatic reply per conversation and person in this many hours (1.11).')
                                ->defaultValue(4)
                                ->min(1)
                            ->end()
                            ->integerNode('max_pins')
                                ->info('Pinned messages per conversation (1.10).')
                                ->defaultValue(20)
                                ->min(1)
                            ->end()
                            ->integerNode('auto_away_minutes')
                                ->info('Minutes without keyboard or pointer input (in every tab) before an available user shows as away. 0 = never.')
                                ->defaultValue(10)
                                ->min(0)
                            ->end()
                            ->append($this->supportNode())
                            ->arrayNode('limits')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->integerNode('send_per_minute')->defaultValue(30)->min(1)->end()
                                    ->integerNode('actions_per_minute')->defaultValue(240)->min(1)->end()
                                    ->integerNode('typing_per_minute')->defaultValue(30)->min(1)->end()
                                    ->integerNode('reads_per_minute')->defaultValue(600)->min(1)->end()
                                    ->integerNode('references_per_minute')
                                        ->info('#reference searches (command menu and picker) per user and minute.')
                                        ->defaultValue(120)->min(1)
                                    ->end()
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

    /**
     * `support`: ticket numbering, the resume / read-only thresholds and the topics.
     */
    private function supportNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('support'))->getRootNode();
        $node
            ->info('Support tickets: numbering, when to ask "continue or new", when a resolved ticket turns read-only, and the topics.')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('ticket_prefix')
                    ->info('Prefix of the readable ticket number (SUP-000123).')
                    ->defaultValue('SUP-')
                ->end()
                ->integerNode('ticket_digits')
                    ->info('Digits of the ticket number (zero-padded).')
                    ->defaultValue(6)->min(1)->max(12)
                ->end()
                ->integerNode('resume_after_days')
                    ->info('A user opening support whose last ticket has had no message for this many days (or is resolved) is asked "continue it or open a new one?".')
                    ->defaultValue(7)->min(1)
                ->end()
                ->integerNode('close_after_days')
                    ->info('Days after which a resolved ticket turns read-only (closed tickets are read-only at once). 0: resolved tickets never turn read-only.')
                    ->defaultValue(30)->min(0)
                ->end()
                ->booleanNode('emojis')
                    ->info('Decorative emojis (aria-hidden) before the ticket status, the topic and the assistant\'s lines; false shows text only.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('translation_domain')
                    ->info('Translation domain of the topic labels and replies below.')
                    ->defaultValue('NowoChatKitBundle')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('topics')
                    ->info('Topics offered when a ticket opens, in order. label / reply default to support.topic.<key> / support.topic_reply.<key>; reply "" posts no automatic reply.')
                    ->defaultValue(array_map(static fn (array $topic): array => $topic + ['label' => null, 'reply' => null, 'assignee' => null], self::DEFAULT_SUPPORT_TOPICS))
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')
                                ->isRequired()
                                ->validate()
                                    ->ifTrue(static fn (mixed $key): bool => !is_string($key) || preg_match('/^[a-z0-9_-]{1,64}$/', $key) !== 1)
                                    ->thenInvalid('A support topic key may only contain 1–64 lowercase letters, digits, "_" and "-".')
                                ->end()
                            ->end()
                            ->scalarNode('label')->defaultNull()->end()
                            ->scalarNode('reply')->defaultNull()->end()
                            ->scalarNode('icon')->defaultNull()->end()
                            ->scalarNode('emoji')
                                ->info('Decorative emoji before the topic label (null: the built-in one of incident, billing, how_to, account and other; "": none).')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('assignee')
                                ->info('Public id of the agent new tickets of this topic are assigned to.')
                                ->defaultNull()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $topics): bool => count(array_unique(array_column($topics, 'key'))) !== count($topics))
                        ->thenInvalid('Support topic keys must be unique.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}
