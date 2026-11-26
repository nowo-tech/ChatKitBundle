<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Twig;

use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Badge\ChatAuthorBadges;
use Nowo\ChatKitBundle\Card\ChatCards;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Form\ChatDockComposerType;
use Nowo\ChatKitBundle\Form\ChatDockEditType;
use Nowo\ChatKitBundle\Form\ChatDockSearchType;
use Nowo\ChatKitBundle\Form\ChatDockStatusType;
use Nowo\ChatKitBundle\Form\ChatMessageSearchType;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatLinkProvider;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Port\ChatChannelPort;
use Nowo\ChatKitBundle\Port\ChatCurrentUserPort;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Sidebar\ChatSidebar;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Asset\Exception\InvalidArgumentException;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconRendererInterface;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\Markup;
use Twig\TwigFunction;

use function assert;
use function strlen;

/**
 * `nowo_chat_dock_config()`: what the dock needs (endpoints, CSRF token, settings, UI strings, the channel of
 * the current page). Null for guests and unreachable accounts, so the dock template renders nothing.
 * `nowo_chat_dock_forms()`: the Form views of the dock's search boxes, composer and inline editor.
 */
final class ChatDockExtension extends AbstractExtension implements GlobalsInterface
{
    private const string PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

    /** 1.25: ids of the two sidebar dialogs (UiKit remote form dialogs, `dock.html.twig`), by size. */
    public const array SIDEBAR_DIALOGS = ['md' => 'nowo-chat-sidebar-dialog', 'wide' => 'nowo-chat-sidebar-dialog-wide'];

    /** 2.6 (spec 259): id of the dialog of the composer actions, message menu slots and card actions (`dock.html.twig`). */
    public const string SLOT_DIALOG = 'nowo-chat-slot-dialog';

    /** 1.26: UiKit's remote-dialog runtime, in UiKit's asset package (`web_ui.dock.dialog_runtime`). */
    public const string DIALOG_RUNTIME = 'js/nowo-ui-dialog.js';

    /** 1.33: UiKit's context-menu runtime, in UiKit's asset package (`web_ui.message_context_menu: true`). */
    public const string CONTEXT_MENU_RUNTIME = 'js/nowo-ui-context-menu.js';

    /** 1.35: separator group of each message menu item (a separator goes between two groups). */
    public const array MENU_GROUPS = [
        'reply'       => 'compose',
        'thread'      => 'compose',
        'quote'       => 'compose',
        'forward'     => 'compose',
        'copy_text'   => 'keep',
        'copy_link'   => 'keep',
        'save'        => 'keep',
        'mark_unread' => 'keep',
        'pin'         => 'keep',
        'info'        => 'manage',
        'edit'        => 'manage',
        'delete'      => 'danger',
    ];

    /** 1.40: name of the search modal's form (its fields are `nowo_chat_msearch[q]` …; the client sends them unprefixed). */
    public const string SEARCH_FORM = 'nowo_chat_msearch';

    /** 1.26: name of UiKit's asset package. */
    public const string UI_KIT_PACKAGE = 'nowo_ui_kit';

    /**
     * Builds the extension from security, routing, CSRF, translation, access checks and the dock settings.
     *
     * @param list<string> $reactions Emojis offered as reactions
     * @param int $pollMs Polling interval of the dock without realtime, in milliseconds
     * @param string $iconSet `ux_icon` or `svg_inline`
     * @param array<string, string> $icons dock icon → UX Icons name (icon_set: ux_icon)
     * @param IconRendererInterface|null $iconRenderer UX Icons renderer; null when symfony/ux-icons is not installed
     * @param string|null $pageLayout @deprecated since 2.0, ignored (the 1.x `page_layout`, removed in 2.0); removed in 3.0
     * @param ChatReferences|null $references #references (whether `/` commands and `#` are offered)
     * @param bool $supportEmojis Decorative emojis of the support assistant (`support.emojis`)
     * @param int $autoAwayMinutes Minutes without input before the dock reports the user idle (away); 0 = never
     * @param ClockInterface $clock Clock for the user's own status (expiry)
     * @param array<string, mixed> $statusPresets Suggested custom statuses (`status_presets`, 1.12: `quick` and `more`)
     * @param ChatAvatars|null $avatars 1.16: the user's own picture (`me.avatarUrl`); null gives null
     * @param array{overlay?: string, watch_aria_modal?: bool} $dock 1.17: dock reaction to modal overlays (`dock`)
     * @param ChatLinks|null $links 1.19: pinned links, link cards and the Links panel (`links`); null or disabled hides them
     * @param string|null $channelsUrl 1.24: host page to browse or create channels (`web_ui.channels_url`); null: no link
     * @param ChatCurrentUserPort|null $currentUser 1.24: the signed-in user's chat participant; null: the security user when it implements ChatUserInterface
     * @param bool $openRecent 1.25: the full-page chat opens the most recent conversation (`web_ui.page.open_recent`)
     * @param bool $dockSidebar 1.25: the dock shows the grouped sidebar (`web_ui.dock.sidebar_sections`)
     * @param ChatSidebar|null $sidebar 1.25: the host sections (the full-page chat is grouped when a provider exists)
     * @param bool $dialogRuntime 1.26: the dock loads UiKit's dialog runtime for the sidebar actions (`web_ui.dock.dialog_runtime`)
     * @param Packages|null $packages 1.26: asset packages (the URL of UiKit's js/nowo-ui-dialog.js); null without symfony/asset
     * @param ChatAuthorBadges|null $authorBadges 1.29: whether the application gives author badges; null: it does not
     * @param bool|null $messageContextMenu 1.33: the message context menu (`web_ui.message_context_menu`): null follows UiKit's theme flag, true / false force it
     * @param array<string, mixed> $messageMenu 1.35: `message_menu` (quick reactions, items and their order)
     * @param bool $searchDockEntry 1.37: the dock header links to message search (`search.enabled` and `search.dock_entry`)
     * @param bool $searchConversationEntry 1.40: each conversation header opens the search modal (`search.enabled` and `search.conversation_entry`)
     */
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly ChatAccess $access,
        private readonly ChatChannelPort $channels,
        private readonly ChatUserSettingsRepository $settings,
        private readonly ChatLinkPreviewProviderInterface $linkPreviews,
        private readonly FormFactoryInterface $forms,
        private readonly array $reactions = [],
        private readonly int $pollMs = 4000,
        private readonly string $iconSet = 'ux_icon',
        private readonly array $icons = [],
        private readonly ?IconRendererInterface $iconRenderer = null,
        ?string $pageLayout = null,
        private readonly ?ChatReferences $references = null,
        private readonly bool $supportEmojis = true,
        private readonly int $autoAwayMinutes = 10,
        private readonly ClockInterface $clock = new Clock(),
        private readonly array $statusPresets = Configuration::DEFAULT_STATUS_PRESETS,
        private readonly ?ChatAvatars $avatars = null,
        private readonly array $dock = ['overlay' => 'hide', 'watch_aria_modal' => false],
        private readonly ?ChatLinks $links = null,
        private readonly ?string $channelsUrl = null,
        private readonly ?ChatCurrentUserPort $currentUser = null,
        private readonly bool $openRecent = false,
        private readonly bool $dockSidebar = false,
        private readonly ?ChatSidebar $sidebar = null,
        private readonly bool $dialogRuntime = false,
        private readonly ?Packages $packages = null,
        private readonly ?ChatAuthorBadges $authorBadges = null,
        private readonly ?bool $messageContextMenu = null,
        private readonly array $messageMenu = [],
        private readonly bool $searchDockEntry = false,
        private readonly bool $searchConversationEntry = false,
        private readonly ?ChatCards $cards = null,
    ) {
    }

    /**
     * Registers `nowo_chat_dock_config()`, `nowo_chat_dock_forms()` and `nowo_chat_icons()`.
     *
     * @return list<TwigFunction> The Twig functions
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_chat_dock_config', $this->config(...)),
            new TwigFunction('nowo_chat_dock_forms', $this->forms(...)),
            new TwigFunction('nowo_chat_icons', $this->icons(...)),
        ];
    }

    /**
     * Twig globals (2.0: `nowo_chat_kit_page_layout`, deprecated since 1.5, is gone; the page extends the UiKit layout,
     * `web_ui.layout_template`). 1.29: `nowo_chat_author_badges`, true
     * when the application aliased ChatAuthorBadgeProviderInterface (the dock's `author-badge` template is rendered only
     * then, so the dock markup of a host without badges is unchanged). 1.33: `nowo_chat_message_context_menu`, the
     * `context_menu` option of each message's kebab (null: UiKit's `theme.context_menus` decides). 1.37:
     * `nowo_chat_search_entry`, true when the dock header links to message search. 1.40: `nowo_chat_search_conversation`,
     * true when each conversation header opens the search modal.
     *
     * @return array<string, mixed> The globals
     */
    public function getGlobals(): array
    {
        return [
            // 1.29: whether the application gives author badges (the dock template of a badge is rendered only then).
            'nowo_chat_author_badges' => $this->authorBadges?->enabled() ?? false,
            // 1.33: the message context menu (null: UiKit's theme flag; true / false: forced by web_ui.message_context_menu).
            'nowo_chat_message_context_menu' => $this->messageContextMenu,
            // 1.35: the items of each message's menu, in order, with their group (separators between groups) and the
            // quick reactions row.
            'nowo_chat_message_menu' => $this->menuTemplate(),
            // 1.37: the message search link of the dock header (`search.enabled` and `search.dock_entry`).
            'nowo_chat_search_entry' => $this->searchDockEntry,
            // 1.40: the search button of each conversation header and the search modal template.
            'nowo_chat_search_conversation' => $this->searchConversationEntry,
            // 2.6 (spec 259): the dialog of the host slots (composer actions, menu slots, card actions).
            'nowo_chat_slot_dialog' => $this->cards?->hasSlots() === true ? self::SLOT_DIALOG : null,
        ];
    }

    /**
     * Fresh Form views of the dock markup (a view renders once): `search` (children `query` and `people`),
     * `composer` and `edit` (child `body` each), `status` (children `emoji`, `text` and `clearAfter`, 1.10; `moreStatus`
     * and the view var `status_presets`, 1.12).
     *
     * 1.40: `message_search` (the search modal's form, {@see ChatMessageSearchType} named `nowo_chat_msearch` with the
     * scope field), only while the conversation header offers the modal.
     *
     * @return array{search: FormView, composer: FormView, edit: FormView, status: FormView, message_search?: FormView} The Form views
     */
    public function forms(): array
    {
        return ($this->searchConversationEntry ? ['message_search' => $this->forms->createNamed(self::SEARCH_FORM, ChatMessageSearchType::class, null, ['scope' => true, 'action' => $this->urls->generate('nowo_chat_page_search'), 'attr' => ['id' => self::SEARCH_FORM . '_form']])->createView()] : []) + [
            'search'   => $this->forms->create(ChatDockSearchType::class)->createView(),
            'composer' => $this->forms->create(ChatDockComposerType::class)->createView(),
            'edit'     => $this->forms->create(ChatDockEditType::class)->createView(),
            'status'   => $this->forms->create(ChatDockStatusType::class, null, ['locale' => $this->requestStack->getMainRequest()?->getLocale(), 'status_presets' => $this->statusPresets])->createView(),
        ];
    }

    /**
     * Dock configuration for the signed-in user (endpoints, CSRF token, preferences, UI strings, page channel).
     *
     * @return array<string, mixed>|null The configuration, or null for guests and unreachable accounts
     */
    public function config(): ?array
    {
        $user = $this->security->getUser();
        $user = $this->currentUser instanceof ChatCurrentUserPort ? $this->currentUser->resolve($user) : $user;
        if (!$user instanceof ChatUserInterface || !$this->access->isReachable($user)) {
            return null;
        }
        $request = $this->requestStack->getMainRequest();
        $locale  = $request?->getLocale() ?? 'en';
        $channel = $request instanceof Request ? $this->channels->contextChannel($user, $request) : null;
        if ($channel instanceof ChatChannel && !$this->access->canUseChannel($user, $channel->key)) {
            $channel = null;
        }
        $settings = $this->settings->forUser($user);

        return [
            'userUuid'          => $user->getChatPublicId(),
            'me'                => ['name' => $user->getChatDisplayName(), 'initials' => $user->getChatInitials(), 'avatarUrl' => $this->avatars?->urlOf($user)],
            'csrf'              => $this->csrf->getToken(ChatApiController::CSRF_ID)->getValue(),
            'locale'            => $locale,
            'contextChannel'    => $channel instanceof ChatChannel ? ['key' => $channel->key, 'label' => $channel->label] : null,
            'pollMs'            => $this->pollMs,
            'maxLength'         => ChatMessage::MAX_LENGTH,
            'editWindowSeconds' => ChatMessage::EDIT_WINDOW_SECONDS,
            'reactions'         => $this->reactions,
            // 1.35: the message menu (items offered, quick reactions, quote length, the Saved entry of the dock).
            'menu'      => $this->menuConfig(),
            'endpoints' => [
                'conversations'  => $this->urls->generate('nowo_chat_api_conversations'),
                'contacts'       => $this->urls->generate('nowo_chat_api_contacts'),
                'direct'         => $this->urls->generate('nowo_chat_api_open_direct'),
                'channel'        => $this->urls->generate('nowo_chat_api_open_channel'),
                'support'        => $this->urls->generate('nowo_chat_api_open_support'),
                'supportInbox'   => $this->urls->generate('nowo_chat_api_support_inbox'),
                'supportTickets' => $this->urls->generate('nowo_chat_api_support_tickets'),
                'supportTopic'   => $this->pattern('nowo_chat_api_support_topic'),
                'supportResume'  => $this->pattern('nowo_chat_api_support_resume'),
                'supportStatus'  => $this->pattern('nowo_chat_api_support_status'),
                'supportAssign'  => $this->pattern('nowo_chat_api_support_assign'),
                'linkPreviews'   => $this->urls->generate('nowo_chat_api_link_previews'),
                'page'           => $this->pattern('nowo_chat_page_conversation'),
                'conversation'   => $this->pattern('nowo_chat_api_conversation'),
                'messages'       => $this->pattern('nowo_chat_api_messages'),
                'members'        => $this->pattern('nowo_chat_api_members'),
                'references'     => $this->pattern('nowo_chat_api_references'),
                'message'        => $this->pattern('nowo_chat_api_edit', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'reactions'      => $this->pattern('nowo_chat_api_react', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'read'           => $this->pattern('nowo_chat_api_read'),
                'typing'         => $this->pattern('nowo_chat_api_typing'),
                'buzz'           => $this->pattern('nowo_chat_api_buzz'),
                'resolve'        => $this->pattern('nowo_chat_api_resolve'),
                'realtime'       => $this->urls->generate('nowo_chat_api_realtime'),
                'presence'       => $this->urls->generate('nowo_chat_api_presence'),
                'status'         => $this->urls->generate('nowo_chat_api_status'),
                'settings'       => $this->urls->generate('nowo_chat_api_settings'),
                'archive'        => $this->pattern('nowo_chat_api_archive'),
                'archiveRead'    => $this->urls->generate('nowo_chat_api_archive_read'),
                'pins'           => $this->pattern('nowo_chat_api_pins'),
                'autoReply'      => $this->urls->generate('nowo_chat_api_auto_reply'),
                'quickReplies'   => $this->urls->generate('nowo_chat_api_quick_replies'),
                'pin'            => $this->pattern('nowo_chat_api_pin', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                // 1.33: the address of a message (the "Copy link" item of its menu).
                'messageLink' => $this->pattern('nowo_chat_api_message_link', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                // 1.24: mute / unmute a conversation for the user.
                'mute' => $this->pattern('nowo_chat_api_mute'),
                // 1.35: the message menu (the client offers an item only when its endpoint is here).
                'unread'    => $this->pattern('nowo_chat_api_unread', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'info'      => $this->pattern('nowo_chat_api_info', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'save'      => $this->pattern('nowo_chat_api_save', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'saved'     => $this->urls->generate('nowo_chat_api_saved'),
                'savedPage' => $this->urls->generate('nowo_chat_page_saved'),
                // 1.36: forward, threads, edit history.
                'forwardTargets' => $this->urls->generate('nowo_chat_api_forward_targets'),
                'forward'        => $this->pattern('nowo_chat_api_forward', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'thread'         => $this->pattern('nowo_chat_api_thread', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'threadRead'     => $this->pattern('nowo_chat_api_thread_read', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'revisions'      => $this->pattern('nowo_chat_api_revisions', ['messageUuid' => self::PLACEHOLDER], '__message__'),
            ] + ($this->searchConversationEntry ? [
                // 1.40: the search modal of the conversation header (absent while it is off).
                'searchResults' => $this->urls->generate('nowo_chat_search_results'),
                'searchPage'    => $this->urls->generate('nowo_chat_page_search'),
            ] : []) + [
                // 1.26: the session's current CSRF token (asked for once when the page's token is refused).
                'csrf' => $this->urls->generate('nowo_chat_api_csrf'),
            ] + $this->linkEndpoints(),
            // Sounds per event, volume, buzz, opening, send key … (ChatUserSettings::preferences()).
            'prefs' => $settings->preferences(),
            // The user's own chat status (set from the dock's status menu) and the auto-away delay.
            'status' => $settings->statusView($this->clock->now()),
            // 1.11: the automatic reply, set from the status menu.
            'autoReply'       => $settings->autoReplyView(),
            'autoAwayMinutes' => $this->autoAwayMinutes,
            'isAgent'         => $this->access->isSupportAgent($user),
            'linkPreviews'    => !$this->linkPreviews instanceof NullLinkPreviewProvider,
            // `/` commands and the `#` picker are offered only when the application registers reference providers.
            'references' => $this->references?->isEnabled() ?? false,
            // Decorative emojis of the support assistant's own lines (greeting, "continue or new?").
            'supportEmojis' => $this->supportEmojis,
            // 1.17: what the floating dock does while the page shows a modal overlay.
            'dock' => [
                'overlay'        => $this->dock['overlay'] ?? 'hide',
                'watchAriaModal' => $this->dock['watch_aria_modal'] ?? false,
            ],
            // 1.19: links (absent when the feature is off).
            'links' => $this->linksConfig(),
            'i18n'  => $this->strings($locale),
        ] + ($this->channelsUrl === null || preg_match(Configuration::CHANNELS_URL_PATTERN, $this->channelsUrl) !== 1 ? [] : ['channelsUrl' => $this->channelsUrl])
            + $this->sidebarConfig()
            + $this->contextMenuConfig()
            + $this->slotsConfig();
    }

    /**
     * 1.35: what the message template renders: the items offered (in `message_menu.order`, each with its separator group)
     * and the quick reactions.
     *
     * @return array{items: list<array{name: string, group: string, key?: string}>, quick: list<string>, picker: bool, saved: bool}
     */
    private function menuTemplate(): array
    {
        $menu  = $this->menuConfig();
        $slots = $this->cards?->hasMenuSlots() === true;
        $items = [];
        foreach ($menu['order'] as $name) {
            if ($menu['items'][$name] ?? false) {
                $items[] = ['name' => $name, 'group' => self::MENU_GROUPS[$name]];
            } elseif ($slots && str_starts_with($name, 'slot:')) {
                // 2.6 (spec 259): the place of a host slot; the dock fills it per message (hidden when not offered).
                $items[] = ['name' => 'slot', 'key' => substr($name, 5), 'group' => 'slots'];
            }
        }
        if ($slots) {
            // The slots not listed in the order come last, cloned by the dock from this hidden item.
            $items[] = ['name' => 'slot', 'key' => '', 'group' => 'slots'];
        }

        return ['items' => $items, 'quick' => $menu['quick'], 'picker' => $menu['picker'], 'saved' => $menu['saveDockEntry']];
    }

    /**
     * 1.35: the message menu for the client and the template (defaults when the extension is built without it).
     *
     * @return array{order: list<string>, items: array<string, bool>, quick: list<string>, picker: bool, quoteMaxLength: int, saveDockEntry: bool, forwardMaxTargets: int, editHistory: bool}
     */
    private function menuConfig(): array
    {
        $menu = $this->messageMenu;
        /** @var list<string> $order */
        $order = $menu['order'] ?? Configuration::MESSAGE_MENU_ITEMS;
        /** @var list<string> $quick */
        $quick = $menu['quick_reactions']['emojis'] ?? Configuration::DEFAULT_QUICK_REACTIONS;

        return [
            'order' => $order,
            'items' => [
                'reply'       => true,
                'thread'      => ($menu['threads']['enabled'] ?? false) === true,
                'quote'       => ($menu['quote']['enabled'] ?? true) === true,
                'forward'     => ($menu['forward']['enabled'] ?? false) === true,
                'copy_text'   => ($menu['copy_text'] ?? true) === true,
                'copy_link'   => true,
                'save'        => ($menu['save']['enabled'] ?? false) === true,
                'mark_unread' => ($menu['mark_unread'] ?? true) === true,
                'pin'         => true,
                'info'        => ($menu['message_info']['enabled'] ?? false) === true,
                'edit'        => true,
                'delete'      => true,
            ],
            'quick'          => $quick,
            'picker'         => ($menu['quick_reactions']['picker'] ?? true) === true,
            'quoteMaxLength' => (int) ($menu['quote']['max_length'] ?? 1000),
            'saveDockEntry'  => ($menu['save']['enabled'] ?? false) === true && ($menu['save']['dock_entry'] ?? true) === true,
            // 1.36
            'forwardMaxTargets' => (int) ($menu['forward']['max_targets'] ?? 5),
            'editHistory'       => ($menu['edit_history']['enabled'] ?? false) === true,
        ];
    }

    /**
     * 1.33 (`web_ui.message_context_menu: true`): the address of UiKit's context-menu runtime, which the dock loads
     * unless the page already has it. With null the host's UiKit `scripts` partial loads it when `theme.context_menus`
     * is on; with false nothing is loaded. Configuration only: computed on each call, nothing kept between requests.
     *
     * @return array{contextMenuRuntime?: string}
     */
    private function contextMenuConfig(): array
    {
        if ($this->messageContextMenu !== true || !$this->packages instanceof Packages) {
            return [];
        }
        try {
            return ['contextMenuRuntime' => $this->packages->getUrl(self::CONTEXT_MENU_RUNTIME, self::UI_KIT_PACKAGE)];
        } catch (InvalidArgumentException) {
            // UiKit's asset package is not registered: the host loads the runtime (the kebab still works).
            return [];
        }
    }

    /**
     * 1.25: `openRecent` (only when set) and `sidebar` (only with a section provider or `web_ui.dock.sidebar_sections`):
     * the full-page chat is then grouped, the dock when the option is on, the endpoint of the host sections (null
     * without a provider) and the ids of the two dialogs.
     *
     * @return array<string, mixed>
     */
    private function sidebarConfig(): array
    {
        $config    = $this->openRecent ? ['openRecent' => true] : [];
        $providers = $this->sidebar?->hasProviders() ?? false;
        if (!$providers && !$this->dockSidebar) {
            return $config;
        }

        return $config + ['sidebar' => [
            // The full-page chat is grouped with a provider, or when the dock is.
            'page'     => true,
            'dock'     => $this->dockSidebar,
            'endpoint' => $providers ? $this->urls->generate('nowo_chat_api_sidebar') : null,
            'dialogs'  => self::SIDEBAR_DIALOGS,
        ] + $this->dialogRuntimeConfig()];
    }

    /**
     * 2.6 (spec 259): `slots` (only with a card, composer action or message menu slot provider): the endpoint of a
     * conversation's slots, the address of one card (composer chips), and the id of the host dialog.
     *
     * @return array{slots?: array<string, string>}
     */
    private function slotsConfig(): array
    {
        if ($this->cards?->hasSlots() !== true) {
            return [];
        }
        $card = str_replace([self::PLACEHOLDER, 'zztypezz', 'zzidzz'], ['__uuid__', '__type__', '__id__'], $this->urls->generate('nowo_chat_api_card', ['uuid' => self::PLACEHOLDER, 'type' => 'zztypezz', 'id' => 'zzidzz']));

        return ['slots' => [
            'endpoint' => $this->pattern('nowo_chat_api_slots'),
            'card'     => $card,
            'dialog'   => self::SLOT_DIALOG,
        ] + $this->dialogRuntimeConfig()];
    }

    /**
     * 1.26 (`web_ui.dock.dialog_runtime`): the address of UiKit's remote-dialog runtime, which the dock loads unless the
     * page already has it. Configuration only: computed on each call, nothing kept between requests.
     *
     * @return array{dialogRuntime?: string}
     */
    private function dialogRuntimeConfig(): array
    {
        if (!$this->dialogRuntime || !$this->packages instanceof Packages) {
            return [];
        }
        try {
            return ['dialogRuntime' => $this->packages->getUrl(self::DIALOG_RUNTIME, self::UI_KIT_PACKAGE)];
        } catch (InvalidArgumentException) {
            // UiKit's asset package is not registered (UiKit not enabled): the host loads the runtime, or the actions stay links.
            return [];
        }
    }

    /**
     * 1.19: the links endpoints (none when the feature is off).
     *
     * @return array<string, string>
     */
    private function linkEndpoints(): array
    {
        if ($this->links?->isEnabled() !== true) {
            return [];
        }

        return [
            'pinnedLinks' => $this->pattern('nowo_chat_api_pinned_links'),
            'pinnedLink'  => $this->pattern('nowo_chat_api_pinned_link', ['linkUuid' => self::PLACEHOLDER], '__link__'),
            'links'       => $this->pattern('nowo_chat_api_links'),
        ];
    }

    /**
     * 1.19: what the dock needs to offer links: limits, `http:` and the providers (null when the feature is off).
     *
     * @return array{enabled: true, allowHttp: bool, maxPinned: int, titleMaxLength: int, urlMaxLength: int, providers: list<array{key: string, label: string, hosts: list<string>, icon: string, fallbackIcon: string}>}|null
     */
    private function linksConfig(): ?array
    {
        if ($this->links?->isEnabled() !== true) {
            return null;
        }
        $urls = $this->links->urls();

        return [
            'enabled'        => true,
            'allowHttp'      => $urls->allowsHttp(),
            'maxPinned'      => $this->links->maxPinned(),
            'titleMaxLength' => ChatLinkUrls::TITLE_MAX_LENGTH,
            'urlMaxLength'   => ChatLinkUrls::URL_MAX_LENGTH,
            'providers'      => array_map(static fn (ChatLinkProvider $provider): array => $provider->toArray() + ['hosts' => $provider->hosts], $urls->providers()),
        ];
    }

    /**
     * Rendered SVG per dock icon when `icon_set: ux_icon` (and symfony/ux-icons is installed); empty otherwise,
     * so the dock keeps its built-in SVG. The SVG markup comes from the application's own icon set.
     *
     * @return array<string, Markup>
     */
    public function icons(): array
    {
        if ($this->iconSet !== 'ux_icon' || !$this->iconRenderer instanceof IconRendererInterface) {
            return [];
        }
        $rendered = [];
        foreach ($this->icons as $name => $icon) {
            try {
                $rendered[$name] = new Markup($this->iconRenderer->renderIcon($icon, ['aria-hidden' => 'true', 'focusable' => 'false']), 'UTF-8');
            } catch (IconNotFoundException) {
                // Not in the application's icon set: the dock keeps the built-in SVG for this one.
            }
        }

        return $rendered;
    }

    /**
     * Route with `__uuid__` (and optionally `__message__`) placeholders for the client.
     *
     * @param array<string, string> $extra
     */
    private function pattern(string $route, array $extra = [], string $extraPlaceholder = ''): string
    {
        $url = $this->urls->generate($route, ['uuid' => self::PLACEHOLDER] + $extra);
        if ($extra !== []) {
            // The message placeholder comes second in every route that has one.
            $position = strrpos($url, self::PLACEHOLDER);
            assert($position !== false);
            $url = substr_replace($url, $extraPlaceholder, $position, strlen(self::PLACEHOLDER));
        }

        return str_replace(self::PLACEHOLDER, '__uuid__', $url);
    }

    /**
     * Every `chat.*` string of the domain, for the client (keys without the `chat.` prefix).
     *
     * @return array<string, string>
     */
    private function strings(string $locale): array
    {
        $strings = [];
        if ($this->translator instanceof TranslatorBagInterface) {
            foreach (array_keys($this->translator->getCatalogue($locale)->all(ChatPresenter::DOMAIN)) as $key) {
                if (str_starts_with((string) $key, 'chat.')) {
                    $strings[substr((string) $key, 5)] = $this->translator->trans((string) $key, [], ChatPresenter::DOMAIN, $locale);
                }
            }
        }

        return $strings;
    }
}
