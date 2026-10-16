<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Twig;

use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatLinkPreviewProviderInterface;
use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\LinkPreview\NullLinkPreviewProvider;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Icons\Exception\IconNotFoundException;
use Symfony\UX\Icons\IconRendererInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

use function assert;
use function strlen;

/**
 * `nowo_chat_dock_config()`: what the dock needs (endpoints, CSRF token, settings, UI strings, the channel of
 * the current page). Null for guests and unreachable accounts, so the dock template renders nothing.
 */
final class ChatDockExtension extends AbstractExtension
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
        private readonly array $reactions = [],
        private readonly int $pollMs = 4000,
        private readonly string $iconSet = 'ux_icon',
        private readonly array $icons = [],
        private readonly ?IconRendererInterface $iconRenderer = null,
    ) {
    }

    /**
     * Registers `nowo_chat_dock_config()` and `nowo_chat_icons()`.
     *
     * @return list<TwigFunction> The Twig functions
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_chat_dock_config', $this->config(...)),
            new TwigFunction('nowo_chat_icons', $this->icons(...)),
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
            'csrf'              => $this->csrf->getToken(ChatApiController::CSRF_ID)->getValue(),
            'locale'            => $locale,
            'contextChannel'    => $channel instanceof ChatChannel ? ['key' => $channel->key, 'label' => $channel->label] : null,
            'pollMs'            => $this->pollMs,
            'maxLength'         => ChatMessage::MAX_LENGTH,
            'editWindowSeconds' => ChatMessage::EDIT_WINDOW_SECONDS,
            'reactions'         => $this->reactions,
            'endpoints'         => [
                'conversations' => $this->urls->generate('nowo_chat_api_conversations'),
                'contacts'      => $this->urls->generate('nowo_chat_api_contacts'),
                'direct'        => $this->urls->generate('nowo_chat_api_open_direct'),
                'channel'       => $this->urls->generate('nowo_chat_api_open_channel'),
                'support'       => $this->urls->generate('nowo_chat_api_open_support'),
                'supportInbox'  => $this->urls->generate('nowo_chat_api_support_inbox'),
                'linkPreviews'  => $this->urls->generate('nowo_chat_api_link_previews'),
                'page'          => $this->pattern('nowo_chat_page_conversation'),
                'conversation'  => $this->pattern('nowo_chat_api_conversation'),
                'messages'      => $this->pattern('nowo_chat_api_messages'),
                'message'       => $this->pattern('nowo_chat_api_edit', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'reactions'     => $this->pattern('nowo_chat_api_react', ['messageUuid' => self::PLACEHOLDER], '__message__'),
                'read'          => $this->pattern('nowo_chat_api_read'),
                'typing'        => $this->pattern('nowo_chat_api_typing'),
                'buzz'          => $this->pattern('nowo_chat_api_buzz'),
                'resolve'       => $this->pattern('nowo_chat_api_resolve'),
                'realtime'      => $this->urls->generate('nowo_chat_api_realtime'),
                'presence'      => $this->urls->generate('nowo_chat_api_presence'),
            ],
            'prefs' => [
                'sound'    => $settings->getSound(),
                'buzz'     => $settings->isBuzzEnabled(),
                'presence' => $settings->isPresenceVisible(),
            ],
            'isAgent'      => $this->access->isSupportAgent($user),
            'linkPreviews' => !$this->linkPreviews instanceof NullLinkPreviewProvider,
            'i18n'         => $this->strings($locale),
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
