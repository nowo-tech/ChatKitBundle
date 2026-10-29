<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Twig;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Form\ChatDockComposerType;
use Nowo\ChatKitBundle\Form\ChatDockEditType;
use Nowo\ChatKitBundle\Form\ChatDockSearchType;
use Nowo\ChatKitBundle\Form\ChatDockStatusType;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Symfony\Bundle\SecurityBundle\Security;
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
 * Global `nowo_chat_kit_page_layout` (deprecated): the 1.x layout of `page_legacy.html.twig` (`page_layout`).
 */
final class ChatDockExtension extends AbstractExtension implements GlobalsInterface
{
    private const string PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

    /**
     * Builds the extension from security, routing, CSRF, translation, access checks and the dock settings.
     *
     * @param list<string> $reactions Emojis offered as reactions
     * @param int $pollMs Polling interval of the dock without realtime, in milliseconds
     * @param string $iconSet `ux_icon` or `svg_inline`
     * @param array<string, string> $icons dock icon → UX Icons name (icon_set: ux_icon)
     * @param IconRendererInterface|null $iconRenderer UX Icons renderer; null when symfony/ux-icons is not installed
     * @param string|null $pageLayout Deprecated 1.x layout (`page_layout`); null = the bundle's 1.x default document
     * @param ChatReferences|null $references #references (whether `/` commands and `#` are offered)
     * @param bool $supportEmojis Decorative emojis of the support assistant (`support.emojis`)
     * @param int $autoAwayMinutes Minutes without input before the dock reports the user idle (away); 0 = never
     * @param ClockInterface $clock Clock for the user's own status (expiry)
     */
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly ChatAccess $access,
        private readonly ChatChannelProviderInterface $channels,
        private readonly ChatUserSettingsRepository $settings,
        private readonly ChatLinkPreviewProviderInterface $linkPreviews,
        private readonly FormFactoryInterface $forms,
        private readonly array $reactions = [],
        private readonly int $pollMs = 4000,
        private readonly string $iconSet = 'ux_icon',
        private readonly array $icons = [],
        private readonly ?IconRendererInterface $iconRenderer = null,
        private readonly ?string $pageLayout = null,
        private readonly ?ChatReferences $references = null,
        private readonly bool $supportEmojis = true,
        private readonly int $autoAwayMinutes = 10,
        private readonly ClockInterface $clock = new Clock(),
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
     * Twig globals: `nowo_chat_kit_page_layout` (deprecated), the layout the 1.x chat page (`page_legacy.html.twig`)
     * extends; the 1.5 page extends the UiKit layout (`web_ui.layout_template`).
     *
     * @return array<string, mixed> The globals
     */
    public function getGlobals(): array
    {
        return ['nowo_chat_kit_page_layout' => $this->pageLayout ?? Configuration::LEGACY_PAGE_LAYOUT];
    }

    /**
     * Fresh Form views of the dock markup (a view renders once): `search` (children `query` and `people`),
     * `composer` and `edit` (child `body` each), `status` (children `emoji`, `text` and `clearAfter`, 1.10).
     *
     * @return array{search: FormView, composer: FormView, edit: FormView, status: FormView} The Form views
     */
    public function forms(): array
    {
        return [
            'search'   => $this->forms->create(ChatDockSearchType::class)->createView(),
            'composer' => $this->forms->create(ChatDockComposerType::class)->createView(),
            'edit'     => $this->forms->create(ChatDockEditType::class)->createView(),
            'status'   => $this->forms->create(ChatDockStatusType::class)->createView(),
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
            'me'                => ['name' => $user->getChatDisplayName(), 'initials' => $user->getChatInitials()],
            'csrf'              => $this->csrf->getToken(ChatApiController::CSRF_ID)->getValue(),
            'locale'            => $locale,
            'contextChannel'    => $channel instanceof ChatChannel ? ['key' => $channel->key, 'label' => $channel->label] : null,
            'pollMs'            => $this->pollMs,
            'maxLength'         => ChatMessage::MAX_LENGTH,
            'editWindowSeconds' => ChatMessage::EDIT_WINDOW_SECONDS,
            'reactions'         => $this->reactions,
            'endpoints'         => [
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
            ],
            // Sounds per event, volume, buzz, opening, send key … (ChatUserSettings::preferences()).
            'prefs' => $settings->preferences(),
            // The user's own chat status (set from the dock's status menu) and the auto-away delay.
            'status'          => $settings->statusView($this->clock->now()),
            'autoAwayMinutes' => $this->autoAwayMinutes,
            'isAgent'         => $this->access->isSupportAgent($user),
            'linkPreviews'    => !$this->linkPreviews instanceof NullLinkPreviewProvider,
            // `/` commands and the `#` picker are offered only when the application registers reference providers.
            'references' => $this->references?->isEnabled() ?? false,
            // Decorative emojis of the support assistant's own lines (greeting, "continue or new?").
            'supportEmojis' => $this->supportEmojis,
            'i18n'          => $this->strings($locale),
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
