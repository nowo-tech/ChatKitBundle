<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Search\ChatMessageSearch;
use Nowo\ChatKitBundle\Search\ChatSearchIndex;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_unique;
use function count;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function strlen;

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

    /** 1.24: `web_ui.channels_url`: a path (one leading slash, no backslash) or an absolute http(s) URL, without spaces or control characters. */
    public const string CHANNELS_URL_PATTERN = '~^(?:/(?![/\\\\])|https?://[^/\\\\\s\x00-\x1F\x7F]+(?:/|\z))[^\s\\\\\x00-\x1F\x7F]*\z~';

    /** Default template of the full-page chat (`web_ui.templates.page`). */
    public const string PAGE_TEMPLATE = '@NowoChatKitBundle/page.html.twig';

    /** 1.17: dock reactions to a modal overlay (`dock.overlay`). */
    public const array DOCK_OVERLAY_MODES = ['hide', 'inert', 'none'];

    /** 1.35: items of the message menu, in their default order (`message_menu.order`). */
    public const array MESSAGE_MENU_ITEMS = ['reply', 'thread', 'quote', 'forward', 'copy_text', 'copy_link', 'save', 'mark_unread', 'pin', 'info', 'edit', 'delete'];

    /** 1.35: default quick reactions of the message menu. */
    public const array DEFAULT_QUICK_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

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
        'bookmark'    => 'tabler:bookmark',
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
        // 1.19: the Links panel button; the provider icons (`provider_<key>`) come from `links.providers`.
        'links' => 'tabler:link',
        'video' => 'tabler:video',
        // 1.24: mute / unmute a conversation.
        'bell'    => 'tabler:bell',
        'bellOff' => 'tabler:bell-off',
        // 1.25: sidebar rows of private channels and the "add" actions.
        'lock' => 'tabler:lock',
        'plus' => 'tabler:plus',
    ];

    /**
     * 1.12: the suggested custom statuses of the status menu. `quick`: one-click buttons; `more`: the "More statuses"
     * drop-down, in groups. Labels are translation keys of the NowoChatKitBundle domain (a plain text works too);
     * `clear_after` is one of ChatUserSettings::CLEAR_AFTER.
     *
     * @var array{quick: list<array{emoji: string, label: string, clear_after: string}>, more: list<array{label: string, statuses: list<array{emoji: string, label: string, clear_after: string}>}>}
     */
    public const array DEFAULT_STATUS_PRESETS = [
        'quick' => [
            ['emoji' => '🍕', 'label' => 'chat.status_preset_lunch', 'clear_after' => '1h'],
            ['emoji' => '📅', 'label' => 'chat.status_preset_meeting', 'clear_after' => '1h'],
            ['emoji' => '🏠', 'label' => 'chat.status_preset_home', 'clear_after' => 'today'],
            ['emoji' => '🤒', 'label' => 'chat.status_preset_sick', 'clear_after' => 'today'],
            ['emoji' => '🌴', 'label' => 'chat.status_preset_holiday', 'clear_after' => 'week'],
        ],
        'more' => [
            [
                'label'    => 'chat.status_group_work',
                'statuses' => [
                    ['emoji' => '🎧', 'label' => 'chat.status_preset_focus', 'clear_after' => '2h'],
                    ['emoji' => '📞', 'label' => 'chat.status_preset_call', 'clear_after' => '1h'],
                    ['emoji' => '🎤', 'label' => 'chat.status_preset_presenting', 'clear_after' => '1h'],
                    ['emoji' => '🔍', 'label' => 'chat.status_preset_reviewing', 'clear_after' => '1h'],
                    ['emoji' => '🚆', 'label' => 'chat.status_preset_commuting', 'clear_after' => '1h'],
                    ['emoji' => '💻', 'label' => 'chat.status_preset_remote', 'clear_after' => 'today'],
                    ['emoji' => '🏢', 'label' => 'chat.status_preset_office', 'clear_after' => 'today'],
                    ['emoji' => '📚', 'label' => 'chat.status_preset_training', 'clear_after' => 'today'],
                    ['emoji' => '🛟', 'label' => 'chat.status_preset_support', 'clear_after' => 'today'],
                    ['emoji' => '✈️', 'label' => 'chat.status_preset_trip', 'clear_after' => 'week'],
                ],
            ],
            [
                'label'    => 'chat.status_group_personal',
                'statuses' => [
                    ['emoji' => '☕', 'label' => 'chat.status_preset_coffee', 'clear_after' => '30m'],
                    ['emoji' => '⏳', 'label' => 'chat.status_preset_back_soon', 'clear_after' => '30m'],
                    ['emoji' => '🩺', 'label' => 'chat.status_preset_doctor', 'clear_after' => '2h'],
                    ['emoji' => '🌙', 'label' => 'chat.status_preset_offline', 'clear_after' => 'today'],
                    ['emoji' => '🤒', 'label' => 'chat.status_preset_sick', 'clear_after' => 'today'],
                    ['emoji' => '🌴', 'label' => 'chat.status_preset_holiday', 'clear_after' => 'week'],
                    ['emoji' => '🍼', 'label' => 'chat.status_preset_parental', 'clear_after' => 'week'],
                ],
            ],
        ],
    ];

    /**
     * 1.19: known link providers (`links.providers`): label, hostnames (subdomains match too), the UX icon of the dock
     * icon `provider_<key>` and the built-in icon used when the application has no SVG for it.
     *
     * @var array<string, array{label: string, hosts: list<string>, icon: string|null, fallback_icon: string}>
     */
    public const array DEFAULT_LINK_PROVIDERS = [
        'teams' => ['label' => 'Microsoft Teams', 'hosts' => ['teams.microsoft.com', 'teams.live.com'], 'icon' => 'tabler:brand-teams', 'fallback_icon' => 'video'],
        'meet'  => ['label' => 'Google Meet', 'hosts' => ['meet.google.com'], 'icon' => 'tabler:brand-google', 'fallback_icon' => 'video'],
        'zoom'  => ['label' => 'Zoom', 'hosts' => ['zoom.us', 'zoom.com'], 'icon' => 'tabler:brand-zoom', 'fallback_icon' => 'video'],
        'jitsi' => ['label' => 'Jitsi Meet', 'hosts' => ['meet.jit.si', '8x8.vc'], 'icon' => 'tabler:video', 'fallback_icon' => 'video'],
        'webex' => ['label' => 'Webex', 'hosts' => ['webex.com'], 'icon' => 'tabler:video', 'fallback_icon' => 'video'],
    ];

    /** 1.32: `runtime.<setting>.source` values (nowo-tech/runtime-setting-contract `RuntimeSettingSource`). */
    public const array RUNTIME_SOURCES = ['env', 'db', 'db_then_env'];

    /** 1.32: default id of the retention runtime setting. */
    public const string RUNTIME_RETENTION_KEY = 'chat.retention_days';

    /**
     * 1.32: a runtime setting id, as RuntimeSettingId accepts it (checked again by the contract): `namespace.key`
     * (namespace not `env`) or an environment variable name. Lengths are checked separately.
     */
    public const string RUNTIME_KEY_PATTERN = '/^(?:(?!env\.)[a-z][a-z0-9_]{0,63}\.[a-z0-9_]{1,128}(?:\.[a-z0-9_]+)*|[A-Z][A-Z0-9_]{0,127})$/D';

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
                                ->info('Application user entity implementing Nowo\\ChatKitBundle\\Model\\ChatUserInterface (required with Doctrine unless doctrine.user_reference is "id").')
                                ->defaultNull()
                            ->end()
                            ->integerNode('retention_days')
                                ->info('Messages older than this are purged by nowo:chat:purge (minimum 30).')
                                ->defaultValue(365)
                                ->min(30)
                            ->end()
                            ->arrayNode('runtime')
                                ->info('1.32: settings read at run time through nowo-tech/runtime-setting-contract (optional dependency). Omitted: nothing changes.')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->arrayNode('retention_days')
                                        ->info('Read retention_days from a runtime setting on every purge. A string is the setting id (source db_then_env). Needs the contract and its resolver (NowoRuntimeSettingBundle).')
                                        ->beforeNormalization()
                                            ->ifString()
                                            ->then(static fn (string $key): array => ['key' => $key, 'source' => 'db_then_env'])
                                        ->end()
                                        ->children()
                                            ->enumNode('source')
                                                ->info('env: the configured retention_days only; db: the runtime setting only (unset: the purge fails); db_then_env: the runtime setting, else retention_days.')
                                                ->values(self::RUNTIME_SOURCES)
                                                ->defaultValue('db_then_env')
                                            ->end()
                                            ->scalarNode('key')
                                                ->info('Setting id: "namespace.key" (lowercase) or an environment variable name.')
                                                ->defaultValue(self::RUNTIME_RETENTION_KEY)
                                                ->cannotBeEmpty()
                                                ->validate()
                                                    ->ifTrue(static fn (mixed $key): bool => !is_string($key) || preg_match(self::RUNTIME_KEY_PATTERN, $key) !== 1)
                                                    ->thenInvalid('Invalid runtime setting key %s: use "namespace.key" (lowercase) or an environment variable name.')
                                                ->end()
                                            ->end()
                                            ->enumNode('on_error')
                                                ->info('Only fail_closed: the purge deletes data, so an invalid or unreadable value stops it (never a fallback).')
                                                ->values(['fail_closed'])
                                                ->defaultValue('fail_closed')
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
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
                                    ->scalarNode('table_name_prefix')
                                        ->info('Replaces the "nowo_chat_" part of every table, index and unique-constraint name of the bundle (e.g. "chat_" gives chat_message and idx_chat_message_created).')
                                        ->defaultValue(TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX)
                                        ->validate()
                                            ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || preg_match(TablePrefixListener::PREFIX_PATTERN, $prefix) !== 1)
                                            ->thenInvalid('table_name_prefix must start with a lowercase letter and contain only lowercase letters, digits and underscores.')
                                        ->end()
                                    ->end()
                                    ->scalarNode('table_prefix')
                                        ->info('Prepended to every table, index and unique-constraint name after table_name_prefix is applied (e.g. "app_" gives app_nowo_chat_message and app_idx_nowo_chat_message_created). Empty: none.')
                                        ->defaultValue('')
                                        ->validate()
                                            ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || ($prefix !== '' && preg_match(TablePrefixListener::PREFIX_PATTERN, $prefix) !== 1))
                                            ->thenInvalid('table_prefix must be empty or start with a lowercase letter and contain only lowercase letters, digits and underscores.')
                                        ->end()
                                    ->end()
                                    ->enumNode('user_reference')
                                        ->info('1.24: "relation" (default) = Doctrine relations and foreign keys to user_class (resolve_target_entities); "id" = the user columns hold the user id as a string, without foreign keys, and users are loaded through ChatDirectoryInterface (user_class optional; generate a migration when you switch); 1.27 "string" = id mode with string references: every chat user implements ChatUserReferenceInterface, the directory ChatUserReferenceDirectoryInterface, and the user columns must be binary NO PAD on MySQL / MariaDB (see UPGRADING).')
                                        ->values([ChatUserReferences::RELATION, ChatUserReferences::ID, ChatUserReferences::STRING])
                                        ->defaultValue(ChatUserReferences::RELATION)
                                    ->end()
                                    ->scalarNode('entity_manager')
                                        ->info('1.27: the Doctrine entity manager that maps the chat entities, the source of private_entity_manager (default: "default").')
                                        ->defaultValue('default')
                                        ->cannotBeEmpty()
                                    ->end()
                                    ->booleanNode('private_entity_manager')
                                        ->info('1.27 (REQ-ARCH-001 rule 6): true = the bundle reads and writes its rows through a private entity manager created on the connection, mapping and events of entity_manager, so a chat write never flushes, clears or closes the application\'s manager. null (default) keeps the 1.26 behaviour (the chat flushes the default manager); false too, deprecated: true becomes the only mode in 2.0.')
                                        ->defaultNull()
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
                                    ->booleanNode('assets')
                                        ->info('1.21: false = the chat page leaves out the nowo-chat.css and nowo-chat.js tags (the host bundles them).')
                                        ->defaultTrue()
                                    ->end()
                                    ->scalarNode('channels_url')
                                        ->info('1.24: host page to browse or create channels; when set, the dock\'s "New message" picker links to it. A path ("/chat/channels") or an absolute http(s) URL; null (default) = no link.')
                                        ->defaultNull()
                                        ->validate()
                                            ->ifTrue(static fn (mixed $url): bool => $url !== null && (!is_string($url) || preg_match(self::CHANNELS_URL_PATTERN, $url) !== 1))
                                            ->thenInvalid('web_ui.channels_url must be a path starting with a single "/" or an absolute http(s) URL, got %s.')
                                        ->end()
                                    ->end()
                                    ->booleanNode('message_context_menu')
                                        ->info('1.33: right-click, Shift+F10, the ContextMenu key or a long press on a message opens the items of its "More actions" kebab (UiKit 2.54 context menu). null (default) = follow UiKit\'s theme flag nowo_ui_kit.theme.context_menus (the host loads the runtime through its scripts partial); true = always on (the dock loads UiKit\'s js/nowo-ui-context-menu.js itself unless the page has it); false = always off.')
                                        ->defaultNull()
                                    ->end()
                                    ->arrayNode('page')
                                        ->addDefaultsIfNotSet()
                                        ->children()
                                            ->booleanNode('full_height')
                                                ->info('1.21: true = the chat page fills the host content area (class nowo-chat-page--fill).')
                                                ->defaultFalse()
                                            ->end()
                                            ->booleanNode('open_recent')
                                                ->info('1.25: true = /chat (no conversation in the address) opens the most recent conversation beside the list on wide screens; false (default) = "Pick a conversation". Needs JavaScript.')
                                                ->defaultFalse()
                                            ->end()
                                        ->end()
                                    ->end()
                                    ->arrayNode('dock')
                                        ->info('1.25: the floating dock\'s conversation list.')
                                        ->addDefaultsIfNotSet()
                                        ->children()
                                            ->booleanNode('sidebar_sections')
                                                ->info('1.25: true = the dock lists conversations in the grouped sidebar of the full-page chat (Recent / Unread, the host sections of ChatSidebarSectionProviderInterface, Direct messages and groups); false (default) = the 1.24 list.')
                                                ->defaultFalse()
                                            ->end()
                                            ->booleanNode('dialog_runtime')
                                                ->info('1.26: true = the dock loads UiKit\'s remote-dialog runtime (js/nowo-ui-dialog.js, UiKit asset package) itself, so the sidebar actions (Browse / Create channel…) open as dialogs on every page; skipped when the page already has it. false (default) = the host layout loads it (UiKit document layout or its `scripts` partial).')
                                                ->defaultFalse()
                                            ->end()
                                        ->end()
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
                            ->arrayNode('dock')
                                ->info('1.17: how the floating dock reacts while the page shows a modal overlay (nowo:overlay-open / -close events, html[data-nowo-overlay]).')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->enumNode('overlay')
                                        ->info('hide: invisible and inert; inert: painted but inert; none: 1.16 behaviour.')
                                        ->values(self::DOCK_OVERLAY_MODES)
                                        ->defaultValue('hide')
                                    ->end()
                                    ->booleanNode('watch_aria_modal')
                                        ->info('Also suppress the dock while a modal <dialog> or a visible [aria-modal="true"] element is open outside it (MutationObserver).')
                                        ->defaultFalse()
                                    ->end()
                                ->end()
                            ->end()
                            ->append($this->linksNode())
                            ->append($this->searchNode())
                            ->append($this->searchKitNode())
                            ->append($this->messageMenuNode())
                            ->append($this->statusPresetsNode())
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
                                    ->integerNode('searches_per_minute')
                                        ->info('1.37: message searches per user and minute: the page and the API share one budget, the SearchKit palette has its own of the same size (one token per palette call since 1.38).')
                                        ->defaultValue(30)->min(3)
                                    ->end()
                                    ->integerNode('mention_all_per_hour')
                                        ->info('1.24: @channel / @here mentions per writer and hour, when the channel provider allows them (ChatChannelMentionPolicyInterface); over the limit the message is sent without the group mention.')
                                        ->defaultValue(6)->min(1)
                                    ->end()
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
     * `status_presets` (1.12): the suggested custom statuses — `quick` buttons and the `more` drop-down (groups).
     */
    private function statusPresetsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('status_presets'))->getRootNode();
        $node
            ->info('Suggested custom statuses of the status menu: "quick" one-click buttons and the "more" drop-down (groups). A list you set replaces the default one.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('quick')
                    ->info('One-click buttons: emoji, label (translation key of the NowoChatKitBundle domain, or a text) and clear_after.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('emoji')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->enumNode('clear_after')->values(ChatUserSettings::CLEAR_AFTER)->defaultValue('never')->end()
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_STATUS_PRESETS['quick'])
                ->end()
                ->arrayNode('more')
                    ->info('Groups of the "More statuses" drop-down: label (translation key or text) and statuses (like "quick"). An empty list hides the drop-down.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('statuses')
                                ->requiresAtLeastOneElement()
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('emoji')->isRequired()->cannotBeEmpty()->end()
                                        ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                                        ->enumNode('clear_after')->values(ChatUserSettings::CLEAR_AFTER)->defaultValue('never')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue(self::DEFAULT_STATUS_PRESETS['more'])
                ->end()
            ->end();

        return $node;
    }

    /**
     * `links` (1.19): pinned links per conversation, link cards in messages and the Links panel.
     */
    /**
     * 1.35 (Beacon, owner decision D-20261118-02): the richer message menu. Items that add stored data (save, message
     * info) are off by default; the others only use what the viewer already sees.
     */
    private function messageMenuNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('message_menu'))->getRootNode();
        $node
            ->info('1.35: the message menu (kebab and context menu): quick reactions, copy text, quote, mark unread, save for later, message info; 1.36: forward, threads, edit history. Every item is checked again on the server.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('quick_reactions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('emojis')
                            ->info('Row of emoji at the top of the menu; [] hides the row. Each must be one of `reactions`, or of the picker catalog when `picker` is on.')
                            ->scalarPrototype()->end()
                            ->defaultValue(self::DEFAULT_QUICK_REACTIONS)
                        ->end()
                        ->booleanNode('picker')
                            ->info('"More…" opens the emoji picker; the server then accepts `reactions` plus the picker catalog (ChatEmojiCatalog), nothing else.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('copy_text')
                    ->info('"Copy text": the plain text the viewer sees (Markdown stripped, mentions as names). Client side.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('quote')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('"Quote": the message as a multi-line quote in the composer (mentions as plain names: nobody is notified).')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('max_length')
                            ->info('Characters of the quoted text, cut with "…".')
                            ->defaultValue(1000)
                            ->min(20)
                            ->max(4000)
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('mark_unread')
                    ->info('"Mark unread from here": moves the viewer\'s read marker to just before the message (other people\'s read receipts never go back).')
                    ->defaultTrue()
                ->end()
                ->arrayNode('save')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('"Save for later": per-user saved messages, the "Saved" list (GET /chat/saved and the dock). Stores data: off by default.')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('max_per_user')
                            ->info('Saved messages per user; 0 = unlimited.')
                            ->defaultValue(500)
                            ->min(0)
                        ->end()
                        ->booleanNode('dock_entry')
                            ->info('A "Saved" button in the dock header (when save is enabled).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('message_info')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('"Message info": who read the message (and when, with read_times). Stores read times: off by default.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('read_times')
                            ->info('Store and show read times (only of users who share them: the reciprocal "Share read times" preference).')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('per_person_max_members')
                            ->info('Up to this many participants the info lists people; above it, counts only.')
                            ->defaultValue(20)
                            ->min(2)
                            ->max(500)
                        ->end()
                        ->booleanNode('own_messages_only')
                            ->info('Read info on the viewer\'s own messages only; moderators (support agents) see it on every message.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('forward')
                    ->info('1.36: "Forward": copies a text message to other conversations (or a new direct conversation).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Offer "Forward". Off by default.')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('max_targets')
                            ->info('Conversations one forward may go to.')
                            ->defaultValue(5)
                            ->min(1)
                            ->max(20)
                        ->end()
                        ->booleanNode('from_support')
                            ->info('Messages of support conversations may be forwarded out (off: never).')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('threads')
                    ->info('1.36: "Reply in thread": one-level threads under a message; replies stay out of the main timeline.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Offer threads. Stores thread read state: off by default.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('edit_history')
                    ->info('1.36: edit history: the previous texts of an edited message, for its author and moderators.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Keep the previous texts. Stores data: off by default.')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('max_revisions')
                            ->info('Previous texts kept per message; older ones are dropped in the same write.')
                            ->defaultValue(20)
                            ->min(1)
                            ->max(200)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('order')
                    ->info('Order of the menu items (a subset hides the others). Known: ' . implode(', ', self::MESSAGE_MENU_ITEMS) . '.')
                    ->scalarPrototype()->end()
                    ->defaultValue(self::MESSAGE_MENU_ITEMS)
                    ->validate()
                        ->ifTrue(static fn (array $order): bool => array_diff($order, self::MESSAGE_MENU_ITEMS) !== [] || count(array_unique($order)) !== count($order))
                        ->thenInvalid('message_menu.order takes each of ' . implode(', ', self::MESSAGE_MENU_ITEMS) . ' at most once, got %s.')
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $menu): bool => array_filter($menu['quick_reactions']['emojis'], static fn (mixed $emoji): bool => !is_string($emoji) || $emoji === '' || strlen($emoji) > 32) !== [])
                ->thenInvalid('message_menu.quick_reactions.emojis takes non-empty emoji of at most 32 bytes, got %s.')
            ->end();

        return $node;
    }

    /**
     * `search` (1.37): message search, a database full-text search filtered per viewer.
     */
    private function searchNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('search'))->getRootNode();
        $node
            ->info('1.37: message search (the page /chat/search, GET /chat/api/search and a dock header link), filtered to the messages each viewer may read. Build the full-text index before turning it on (UPGRADING.md, nowo:chat:search-index).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Offer message search; off, its routes answer 404 and the dock shows no search link.')
                    ->defaultFalse()
                ->end()
                ->booleanNode('dock_entry')
                    ->info('A search link in the dock header (with search enabled).')
                    ->defaultTrue()
                ->end()
                ->booleanNode('fulltext')
                    ->info('Use the database full-text index (MySQL / MariaDB FULLTEXT, PostgreSQL tsvector); false: LIKE only (no index needed, slow on big tables).')
                    ->defaultTrue()
                ->end()
                ->scalarNode('postgresql_config')
                    ->info('PostgreSQL text search configuration of the index and the query ("simple": no stemming, any language). Changing it needs the index built again.')
                    ->defaultValue(ChatSearchIndex::DEFAULT_POSTGRES_CONFIG)
                    ->validate()
                        ->ifTrue(static fn (mixed $config): bool => !is_string($config) || preg_match('/^[a-z_][a-z0-9_]{0,62}$/D', $config) !== 1)
                        ->thenInvalid('search.postgresql_config takes the name of a text search configuration (lower-case letters, digits and underscores), got %s.')
                    ->end()
                ->end()
                ->integerNode('mysql_min_token_length')
                    ->info('innodb_ft_min_token_size of the MySQL / MariaDB server: shorter words are matched with LIKE.')
                    ->defaultValue(3)->min(1)->max(84)
                ->end()
                ->integerNode('min_query_length')
                    ->info('Shortest search text, in characters.')
                    ->defaultValue(2)->min(1)->max(50)
                ->end()
                ->integerNode('max_query_length')
                    ->info('Longest search text, in characters (longer text answers 400 query_too_long).')
                    ->defaultValue(200)->min(10)->max(500)
                ->end()
                ->integerNode('page_size')
                    ->info('Results per page.')
                    ->defaultValue(20)->min(1)->max(ChatMessageSearch::MAX_PAGE_SIZE)
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $search): bool => $search['min_query_length'] > $search['max_query_length'])
                ->thenInvalid('search.min_query_length cannot be greater than search.max_query_length, got %s.')
            ->end();

        return $node;
    }

    /**
     * `search_kit` (1.37): the optional SearchKit provider of the Ctrl/⌘+K palette.
     */
    private function searchKitNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('search_kit'))->getRootNode();
        $node
            ->info('1.37: groups of the SearchKit palette and results page (nowo-tech/search-kit-bundle ^1.10): "Messages" (1.37, needs search.enabled) and "Conversations" (1.38), with the permission rules of ChatKit\'s own pages. Both need SearchKit\'s recents revalidated or off.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Register the SearchKit groups (only when SearchKit is installed).')
                    ->defaultFalse()
                ->end()
                ->integerNode('priority')
                    ->info('Tag priority of the "Messages" group (higher groups first).')
                    ->defaultValue(30)
                ->end()
                ->arrayNode('messages')
                    ->info('1.38: the "Messages" group (group key `messages`).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the group (with search_kit.enabled; it answers only while search.enabled is on).')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('max_candidates')
                            ->info('Messages read and checked per search (SearchKit\'s candidate window): bounds the per-row checks, the visible total and the deepest results page.')
                            ->min(1)
                            ->max(1000)
                            ->defaultValue(100)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('channels')
                    ->info('1.38: the "Conversations" group (group key `chat_channels`): direct conversations, channels and support tickets the viewer can open, found by their name.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the group (with search_kit.enabled).')
                            ->defaultTrue()
                        ->end()
                        ->integerNode('priority')
                            ->info('Tag priority of the group (higher groups first).')
                            ->defaultValue(35)
                        ->end()
                        ->integerNode('max_candidates')
                            ->info('Conversations read and checked per search (SearchKit\'s candidate window).')
                            ->min(1)
                            ->max(1000)
                            ->defaultValue(100)
                        ->end()
                        ->integerNode('direct_scan')
                            ->info('The viewer\'s newest direct conversations in which the other person\'s name is matched (the names come from the directory, not from ChatKit\'s tables).')
                            ->min(1)
                            ->max(5000)
                            ->defaultValue(500)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function linksNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('links'))->getRootNode();
        $node
            ->info('Links (1.19): pinned links under the conversation header, link cards in messages and the Links panel. Nothing is ever fetched from a link (no server-side preview).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Offer the pinned links, the "Attach link" action and the Links panel; off, the links API answers 404 and nothing is indexed.')
                    ->defaultTrue()
                ->end()
                ->integerNode('max_pinned')
                    ->info('Pinned links per conversation.')
                    ->defaultValue(20)->min(1)->max(200)
                ->end()
                ->booleanNode('allow_http')
                    ->info('Also accept http: links (https: only by default).')
                    ->defaultFalse()
                ->end()
                ->enumNode('channel_managers')
                    ->info('Who manages the pinned links of a channel: members (every member) or admins (whom ChatLinkPolicyInterface allows; else ChatPinPolicyInterface; else nobody). Both people of a direct conversation always do; in tickets, the agents.')
                    ->values(['members', 'admins'])
                    ->defaultValue('members')
                ->end()
                ->arrayNode('providers')
                    ->info('Known providers (icon and label), matched on the hostname only (the host or a subdomain of it). A map you set replaces the default one.')
                    ->useAttributeAsKey('key')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('hosts')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $host): bool => !is_string($host) || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1)
                                        ->thenInvalid('A link provider host must be a lowercase hostname such as "teams.microsoft.com".')
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('icon')
                                ->info('UX Icons name of the provider (icon_set: ux_icon); null: the built-in fallback icon.')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('fallback_icon')
                                ->info('Built-in dock icon when the application has no SVG for the provider (video, link …).')
                                ->defaultValue('video')
                                ->cannotBeEmpty()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $providers): bool => array_filter(array_keys($providers), static fn (int|string $key): bool => $key === 'other' || preg_match('/^[a-z0-9_]{1,32}$/', (string) $key) !== 1) !== [])
                        ->thenInvalid('A link provider key may only contain 1–32 lowercase letters, digits and "_" ("other" is the filter of links without a provider).')
                    ->end()
                    ->defaultValue(self::DEFAULT_LINK_PROVIDERS)
                ->end()
            ->end();

        return $node;
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
