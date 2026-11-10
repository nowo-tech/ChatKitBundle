<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Sidebar;

use Nowo\ChatKitBundle\Contract\ChatSidebarSectionProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Model\ChatSidebarAction;
use Nowo\ChatKitBundle\Model\ChatSidebarItem;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\UX\Icons\IconRendererInterface;
use Throwable;

use function array_chunk;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_countable;
use function is_string;
use function mb_substr;
use function parse_url;
use function preg_match;
use function preg_replace;
use function strtolower;
use function trim;
use function usort;

/**
 * 1.25: the host sections of the chat sidebar for one viewer, as the dock receives them (`GET …/api/sidebar`).
 *
 * Every section of every ChatSidebarSectionProviderInterface (tag priority order), without the reserved keys and the
 * repeated ones, ordered by position (stable). Rows with a conversationUuid are kept only when the conversation exists
 * and ChatAccess::canView() lets the viewer read it, and get their unread count, unread mentions and muted state from
 * the chat (three queries for all of them). Link rows and actions keep only URLs that pass the bundle's rules: a link
 * is a path or an absolute http(s) URL (Configuration::CHANNELS_URL_PATTERN); a dialog URL is same-origin (a path, or
 * an absolute URL on the request's origin, reduced to its path). Texts are trimmed, stripped of control and
 * bidirectional characters and cut; the dock writes them with textContent.
 */
final class ChatSidebar
{
    /** Host sections shown at most. */
    public const int MAX_SECTIONS = 20;

    /** Rows per section shown at most. */
    public const int MAX_ITEMS = 200;

    /** Actions per section shown at most. */
    public const int MAX_ACTIONS = 6;

    /** Longest label (characters). */
    public const int LABEL_MAX_LENGTH = 80;

    /** Longest subtitle (characters). */
    public const int SUBTITLE_MAX_LENGTH = 160;

    /** Conversation uuids looked up per request at most (MAX_SECTIONS × MAX_ITEMS). */
    public const int MAX_UUIDS = self::MAX_SECTIONS * self::MAX_ITEMS;

    /** Uuids per `IN (…)` query. */
    private const int UUID_CHUNK = 1000;

    /** A dock icon name (`channel`, `private`, `hash`, a host name under `nowo_chat_kit.icons` …). */
    private const string DOCK_ICON_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,39}$/';

    /** Unicode spaces and separators (the browser refuses them too, so both sides agree; audit KJ-I6). */
    private const string UNICODE_SPACE = '/[\p{Z}]/u';

    /** A UX Icons name (`prefix:name`). */
    private const string UX_ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*:[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Builds the sidebar from the tagged providers and the chat's own data.
     *
     * @param iterable<ChatSidebarSectionProviderInterface> $providers The application's section providers (priority order)
     * @param ChatAccess $access Read access of every conversation row
     * @param ChatConversationRepository $conversations Finds the rows' conversations by uuid
     * @param ChatMessageRepository $messages Unread counts
     * @param ChatMessageMentionRepository $mentions Unread mentions
     * @param ChatParticipantRepository $participants Muted state
     * @param ClockInterface $clock Clock of the mute check
     * @param IconRendererInterface|null $iconRenderer UX Icons renderer (UX icon names); null without symfony/ux-icons
     * @param string $iconSet `ux_icon` renders UX icon names; `svg_inline` never does
     * @param LoggerInterface|null $logger Where a provider that throws is reported (it is skipped)
     */
    public function __construct(
        #[AutowireIterator(ChatSidebarSectionProviderInterface::TAG)]
        private readonly iterable $providers,
        private readonly ChatAccess $access,
        private readonly ChatConversationRepository $conversations,
        private readonly ChatMessageRepository $messages,
        private readonly ChatMessageMentionRepository $mentions,
        private readonly ChatParticipantRepository $participants,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?IconRendererInterface $iconRenderer = null,
        private readonly string $iconSet = 'ux_icon',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether the application registers at least one section provider (the full-page chat then shows the grouped list).
     *
     * @return bool True with a provider
     */
    public function hasProviders(): bool
    {
        // The tagged iterator counts its services without creating them.
        if (is_countable($this->providers)) {
            return count($this->providers) > 0;
        }
        foreach ($this->providers as $provider) {
            return true;
        }

        return false;
    }

    /**
     * The viewer's host sections, ready for the JSON answer.
     *
     * @param ChatUserInterface $viewer The signed-in chat user
     * @param string $origin Scheme, host and port of the request (`https://app.example`), for the dialog URLs
     *
     * @return list<array{key: string, label: string, position: int, collapsible: bool, collapsedByDefault: bool, items: list<array<string, mixed>>, actions: list<array<string, mixed>>}>
     */
    public function view(ChatUserInterface $viewer, string $origin): array
    {
        $sections = [];
        $seen     = [];
        foreach ($this->providers as $provider) {
            foreach ($this->sectionsOf($provider, $viewer) as $section) {
                if (in_array($section->key, ChatSidebarSection::RESERVED_KEYS, true) || isset($seen[$section->key])) {
                    continue;
                }
                $seen[$section->key] = true;
                $sections[]          = $section;
            }
        }
        // Stable: sections with the same position keep the providers' order.
        usort($sections, static fn (ChatSidebarSection $a, ChatSidebarSection $b): int => $a->position <=> $b->position);
        $sections = array_slice($sections, 0, self::MAX_SECTIONS);
        // Only the rows that can be shown (first of each key, at most MAX_ITEMS per section) are looked up.
        $rows = array_map($this->candidates(...), $sections);

        $states = $this->conversationStates($viewer, $rows);
        $view   = [];
        foreach ($sections as $index => $section) {
            $view[] = [
                'key'                => $section->key,
                'label'              => $this->clip($section->label, self::LABEL_MAX_LENGTH) ?? $section->key,
                'position'           => $section->position,
                'collapsible'        => $section->collapsible,
                'collapsedByDefault' => $section->collapsible && $section->collapsedByDefault,
                'items'              => $this->items($rows[$index], $states),
                'actions'            => $this->actions($section, $origin),
            ];
        }

        return $view;
    }

    /**
     * The sections of one provider; a provider that throws is logged and skipped (the others still show).
     *
     * @return list<ChatSidebarSection>
     */
    private function sectionsOf(ChatSidebarSectionProviderInterface $provider, ChatUserInterface $viewer): array
    {
        $sections = [];
        try {
            foreach ($provider->sections($viewer) as $section) {
                $sections[] = $section;
            }
        } catch (Throwable $exception) {
            $this->logger?->error('Chat sidebar provider {provider} failed: {message}', ['provider' => $provider::class, 'message' => $exception->getMessage(), 'exception' => $exception]);

            return [];
        }

        return $sections;
    }

    /**
     * The rows of a section that can be shown: the first one of each key, at most MAX_ITEMS (a later row of a key
     * never takes the place of a dropped one).
     *
     * @return list<ChatSidebarItem>
     */
    private function candidates(ChatSidebarSection $section): array
    {
        $rows = [];
        foreach ($section->items as $item) {
            if (!isset($rows[$item->key])) {
                $rows[$item->key] = $item;
                if (count($rows) === self::MAX_ITEMS) {
                    break;
                }
            }
        }

        return array_values($rows);
    }

    /**
     * Unread, mentions and muted of every readable conversation row, in three queries (uuids looked up by chunks).
     *
     * @param list<list<ChatSidebarItem>> $sections The rows of each section
     *
     * @return array<string, array{unread: int, mentions: int, muted: bool}> uuid => state (unreadable and unknown uuids are absent)
     */
    private function conversationStates(ChatUserInterface $viewer, array $sections): array
    {
        $uuids = [];
        foreach ($sections as $rows) {
            foreach ($rows as $item) {
                if ($item->conversationUuid !== null) {
                    $uuids[] = $item->conversationUuid;
                }
            }
        }
        $found = [];
        foreach (array_chunk(array_slice(array_values(array_unique($uuids)), 0, self::MAX_UUIDS), self::UUID_CHUNK) as $chunk) {
            $found = [...$found, ...array_values($this->conversations->findByUuids($chunk))];
        }
        $readable = [];
        foreach ($found as $conversation) {
            // Never trust the provider for what the chat then shows: the same rule as opening the conversation.
            if ($this->access->canView($conversation, $viewer)) {
                $readable[(int) $conversation->getId()] = $conversation;
            }
        }
        $ids          = array_keys($readable);
        $unread       = $this->messages->countUnreadByConversation((int) $viewer->getId(), $ids);
        $mentions     = $this->mentions->countUnreadByConversation($viewer, $ids);
        $participants = $this->participants->findForUser($viewer, $ids);
        $now          = $this->clock->now();
        $states       = [];
        foreach ($readable as $id => $conversation) {
            $states[$conversation->getUuid()] = [
                'unread'   => $unread[$id] ?? 0,
                'mentions' => $mentions[$id] ?? 0,
                'muted'    => isset($participants[$id]) && $participants[$id]->isMuted($now),
            ];
        }

        return $states;
    }

    /**
     * @param list<ChatSidebarItem> $rows The section's candidate rows
     * @param array<string, array{unread: int, mentions: int, muted: bool}> $states
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $rows, array $states): array
    {
        $items = [];
        foreach ($rows as $item) {
            $row = $this->item($item, $states);
            if ($row !== null) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * @param array<string, array{unread: int, mentions: int, muted: bool}> $states
     *
     * @return array<string, mixed>|null The row, or null when it is left out
     */
    private function item(ChatSidebarItem $item, array $states): ?array
    {
        if ($item->conversationUuid !== null) {
            $state = $states[$item->conversationUuid] ?? null;
            if ($state === null) {
                // Unknown, or not readable by the viewer: left out silently.
                return null;
            }
            $url = null;
        } else {
            $url = $this->linkUrl($item->url);
            if ($url === null) {
                return null;
            }
            $state = ['unread' => $item->unread, 'mentions' => $item->mentions, 'muted' => $item->muted];
        }

        return [
            'key'              => $item->key,
            'label'            => $this->clip($item->label, self::LABEL_MAX_LENGTH) ?? $item->key,
            'subtitle'         => $this->clip($item->subtitle, self::SUBTITLE_MAX_LENGTH),
            'conversationUuid' => $item->conversationUuid,
            'url'              => $url,
        ] + $state + $this->icon($item->icon, 'channel');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actions(ChatSidebarSection $section, string $origin): array
    {
        $actions = [];
        $keys    = [];
        foreach ($section->actions as $action) {
            if (isset($keys[$action->key])) {
                continue;
            }
            // The first row of a key decides, even when it is left out (a later one never takes its place).
            $keys[$action->key] = true;
            $row                = $this->action($action, $origin);
            if ($row === null) {
                continue;
            }
            $actions[] = $row;
            if (count($actions) === self::MAX_ACTIONS) {
                break;
            }
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>|null The action, or null when neither of its URLs passes
     */
    private function action(ChatSidebarAction $action, string $origin): ?array
    {
        $url    = $this->linkUrl($action->url);
        $dialog = $this->dialogUrl($action->dialogUrl, $origin);
        if ($url === null && $dialog === null) {
            return null;
        }

        return [
            'key'        => $action->key,
            'label'      => $this->clip($action->label, self::LABEL_MAX_LENGTH) ?? $action->key,
            'url'        => $url,
            'dialogUrl'  => $dialog,
            'dialogSize' => $action->dialogSize,
        ] + ($action->icon === null ? ['icon' => null] : $this->icon($action->icon, null));
    }

    /**
     * A link: a path (one leading slash, no backslash) or an absolute http(s) URL, as `web_ui.channels_url`.
     */
    private function linkUrl(?string $url): ?string
    {
        return is_string($url) && preg_match(Configuration::CHANNELS_URL_PATTERN, $url) === 1 && preg_match(self::UNICODE_SPACE, $url) !== 1 ? $url : null;
    }

    /**
     * A dialog URL: same origin only — a path, or an absolute URL on the request's origin (reduced to its path).
     */
    private function dialogUrl(?string $url, string $origin): ?string
    {
        if (!is_string($url) || $this->linkUrl($url) === null) {
            return null;
        }
        if ($url[0] === '/') {
            return $url;
        }
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (strtolower($parts['scheme'] . '://' . $parts['host'] . $port) !== strtolower($origin)) {
            return null;
        }
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $path = (string) preg_replace('~^/+~', '/', $path);

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * The icon of a row or an action: a dock icon name as it is, or a UX Icons name rendered here (`iconSvg`, from the
     * application's own icon set) next to the dock icon the browser falls back to.
     *
     * @param string|null $fallback Name used when the icon is neither (null: no icon)
     *
     * @return array{icon: string|null, iconSvg?: string}
     */
    private function icon(string $icon, ?string $fallback): array
    {
        if (preg_match(self::DOCK_ICON_PATTERN, $icon) === 1) {
            return ['icon' => $icon];
        }
        if (preg_match(self::UX_ICON_PATTERN, $icon) !== 1) {
            return ['icon' => $fallback];
        }
        if ($this->iconSet === 'ux_icon' && $this->iconRenderer instanceof IconRendererInterface) {
            try {
                return ['icon' => $fallback, 'iconSvg' => $this->iconRenderer->renderIcon($icon, ['aria-hidden' => 'true', 'focusable' => 'false'])];
            } catch (Throwable) {
                // Not in the application's icon set (or the set cannot be read): the dock's own icon.
            }
        }

        return ['icon' => $fallback];
    }

    /**
     * Trimmed text without control, line / paragraph separators or bidirectional marks, at most `$max` characters.
     */
    private function clip(?string $text, int $max): ?string
    {
        $text = mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', (string) $text)), 0, $max);

        return $text === '' ? null : $text;
    }
}
