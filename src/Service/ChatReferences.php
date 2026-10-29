<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use LogicException;
use Nowo\ChatKitBundle\Contract\ChatReferenceProviderInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatReferenceScope;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function sprintf;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PREG_SET_ORDER;

/**
 * References to application items in messages (`/task deploy` → `#[Deploy the API](task:42)`), through the tagged
 * ChatReferenceProviderInterface services. Writing: the command menu and the search offer only what the
 * conversation's audience may open (ChatReferenceScope); on send and edit a token is kept only when its provider
 * resolves it for that audience, anything else is stored as plain text. Reading: the stored HTML holds an empty
 * slot per token (no type, id or title); each slot is filled for the viewer at read time — a card, or the neutral
 * "Content not visible" chip. Resolutions are cached per viewer for the current request (reset between requests).
 */
final class ChatReferences implements ResetInterface
{
    /** A reference token: `#[Title](type:id)`. Group 1 is the title, 2 the type, 3 the id. */
    public const string TOKEN = '/#\[([^\[\]\n]{1,120})\]\(([a-z][a-z0-9_-]{0,31}):([A-Za-z0-9_-]{1,64})\)/u';

    /** A code block or span (group 1), or a token (groups 2-4). */
    private const string CODE_OR_TOKEN = '/(```.*?```|`[^`\n]+`)|#\[([^\[\]\n]{1,120})\]\(([a-z][a-z0-9_-]{0,31}):([A-Za-z0-9_-]{1,64})\)/su';

    /** What ChatMarkdownRenderer writes for each token: an empty slot filled for the viewer at read time. */
    public const string SLOT = '<span class="nowo-chat-ref-slot"></span>';

    /** 1.10.1: a slot alone in its paragraph (only cards, spaces and line breaks) becomes a full-width card. */
    private const string BLOCK_SLOT = '<span class="nowo-chat-ref-slot" data-block></span>';

    /** At most this many references are kept in one message; later tokens stay plain text. */
    public const int MAX_REFERENCES = 10;

    /** At most this many items are offered by one search. */
    public const int SEARCH_LIMIT = 8;

    private const string DOMAIN = 'NowoChatKitBundle';

    /** @var array<string, ChatReferenceProviderInterface>|null */
    private ?array $byKey = null;

    /** @var array<int, array<string, ChatReference|false>> viewer id => `type:id` => card (false: not readable) */
    private array $resolved = [];

    /**
     * Builds the service from the tagged providers, the access checks (direct peers) and the translator.
     *
     * @param iterable<ChatReferenceProviderInterface> $providers The application's reference providers
     */
    public function __construct(
        #[AutowireIterator(ChatReferenceProviderInterface::TAG)]
        private readonly iterable $providers,
        private readonly ChatAccess $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Forgets the resolutions of the finished request (worker mode).
     */
    public function reset(): void
    {
        $this->resolved = [];
    }

    /**
     * Whether any provider is registered (the dock offers `/` and `#` only then).
     *
     * @return bool True with at least one provider
     */
    public function isEnabled(): bool
    {
        return $this->providers() !== [];
    }

    /**
     * The scope of someone writing in a conversation: the writer, plus the other person of a direct conversation.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The writer
     *
     * @return ChatReferenceScope The scope
     */
    public function scopeFor(ChatConversation $conversation, ChatUserInterface $user): ChatReferenceScope
    {
        $audience = [$user];
        $peer     = $conversation->isDirect() ? $this->access->directPeer($conversation, $user) : null;
        if ($peer instanceof ChatUserInterface && $peer->getId() !== $user->getId()) {
            $audience[] = $peer;
        }

        return new ChatReferenceScope($user, $audience, $conversation);
    }

    /**
     * The commands offered in the scope: one per provider whose type the audience may open somewhere in it.
     *
     * @param ChatReferenceScope $scope Writer, audience and conversation
     *
     * @return list<array{key: string, label: string, icon: string}> The commands
     */
    public function types(ChatReferenceScope $scope): array
    {
        $types = [];
        foreach ($this->providers() as $key => $provider) {
            if ($provider->isAvailable($scope)) {
                $types[] = ['key' => $key, 'label' => $provider->label(), 'icon' => $provider->icon()];
            }
        }

        return $types;
    }

    /**
     * Items of one type (or of every offered type with `*`) matching the query, readable by the whole audience.
     *
     * @param ChatReferenceScope $scope Writer, audience and conversation
     * @param string $type A type key, or `*` for all
     * @param string $query Search text
     *
     * @return list<ChatReference> At most SEARCH_LIMIT items
     */
    public function search(ChatReferenceScope $scope, string $type, string $query): array
    {
        $found = [];
        foreach ($this->providers() as $key => $provider) {
            if (($type === '*' || $type === $key) && $provider->isAvailable($scope)) {
                foreach ($provider->search($scope, $query, self::SEARCH_LIMIT) as $reference) {
                    // A provider answers for its own type only.
                    if ($reference->type === $key) {
                        $found[] = $reference;
                    }
                }
            }
        }

        return array_slice($found, 0, self::SEARCH_LIMIT);
    }

    /**
     * Reference tokens of a text, outside code spans and blocks, in order (duplicates kept: one per rendered slot).
     *
     * @param string $body Message Markdown
     *
     * @return list<array{title: string, type: string, id: string}> The tokens
     */
    public static function parse(string $body): array
    {
        $text = (string) preg_replace(['/```.*?```/s', '/`[^`\n]+`/'], ' ', $body);
        preg_match_all(self::TOKEN, $text, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => ['title' => $match[1], 'type' => $match[2], 'id' => $match[3]], $matches);
    }

    /**
     * Validates the tokens of a message about to be stored: a token stays only when its provider resolves the item
     * for the writer's scope (its title becomes the item's current title); a forged, foreign, unknown or unreadable
     * token — or one past MAX_REFERENCES — becomes the plain text `#Title` the writer typed.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $author The writer
     * @param string $body Normalized message Markdown
     *
     * @return string The body to store
     */
    public function validate(ChatConversation $conversation, ChatUserInterface $author, string $body): string
    {
        $tokens = self::parse($body);
        if ($tokens === []) {
            return $body;
        }
        $scope = $this->scopeFor($conversation, $author);
        $ids   = [];
        foreach ($tokens as $token) {
            $ids[$token['type']][$token['id']] = true;
        }
        $valid = [];
        foreach ($ids as $type => $set) {
            $provider = $this->providers()[$type] ?? null;
            foreach ($provider?->resolve($scope, array_map('strval', array_keys($set))) ?? [] as $id => $reference) {
                if ($reference instanceof ChatReference && isset($set[(string) $id]) && $reference->type === $type) {
                    $valid[$type . ':' . $id] = $reference;
                }
            }
        }
        $kept = 0;

        // Code spans and blocks are kept as written (their tokens are never rendered as cards).
        return (string) preg_replace_callback(self::CODE_OR_TOKEN, static function (array $match) use ($valid, &$kept): string {
            if ($match[1] !== '') {
                return $match[1];
            }
            $reference = $valid[$match[3] . ':' . $match[4]] ?? null;
            if (!$reference instanceof ChatReference || $kept >= self::MAX_REFERENCES) {
                return '#' . $match[2];
            }
            ++$kept;

            return self::token($reference);
        }, $body);
    }

    /**
     * The token of an item: `#[Title](type:id)` (brackets and line breaks in the title become spaces).
     *
     * @param ChatReference $reference The item
     *
     * @return string The token
     */
    public static function token(ChatReference $reference): string
    {
        $title = trim((string) preg_replace('/[\[\]\s]+/u', ' ', $reference->title));

        return '#[' . mb_substr($title === '' ? '?' : $title, 0, 120) . '](' . $reference->type . ':' . $reference->id . ')';
    }

    /**
     * Resolves the references of several messages for a viewer at once (one resolve() per type: no N+1).
     *
     * @param ChatUserInterface $viewer The reader
     * @param iterable<ChatMessage> $messages Messages about to be shown
     */
    public function prime(ChatUserInterface $viewer, iterable $messages): void
    {
        $tokens = [];
        foreach ($messages as $message) {
            if (!$message->isDeleted() && !$message->isBot()) {
                $tokens = [...$tokens, ...self::parse($message->getBody())];
            }
        }
        $this->resolveFor($viewer, $tokens);
    }

    /**
     * The message HTML with its reference slots filled for the viewer, and the cards it may see (the editor turns them
     * back into tokens). Without a viewer (one realtime payload for every member) the slots stay empty and `pending`
     * tells the client to fetch the message for its user.
     *
     * @param string $html Stored HTML (ChatMarkdownRenderer)
     * @param string $body Stored Markdown (its tokens, in slot order)
     * @param ChatUserInterface|null $viewer The reader; null for viewer-neutral payloads
     *
     * @return array{html: string, references: list<array<string, string>>, pending: bool} The filled HTML, the readable cards and whether slots wait for a viewer
     */
    public function fill(string $html, string $body, ?ChatUserInterface $viewer): array
    {
        if (!str_contains($html, self::SLOT)) {
            return ['html' => $html, 'references' => [], 'pending' => false];
        }
        if (!$viewer instanceof ChatUserInterface) {
            return ['html' => $html, 'references' => [], 'pending' => true];
        }
        $tokens   = self::parse($body);
        $cards    = $this->resolveFor($viewer, $tokens);
        $readable = [];
        $index    = 0;
        // Paragraphs holding only cards (and spaces / line breaks): full-width cards; cards within text stay compact chips.
        $html = (string) preg_replace_callback(
            '~<p>((?:\s|<br\s*/?>|' . preg_quote(self::SLOT, '~') . ')+)</p>~',
            static fn (array $match): string => '<p>' . str_replace(self::SLOT, self::BLOCK_SLOT, $match[1]) . '</p>',
            $html,
        );
        $html = (string) preg_replace_callback('/' . preg_quote(self::SLOT, '/') . '|' . preg_quote(self::BLOCK_SLOT, '/') . '/', function (array $match) use ($tokens, $cards, &$readable, &$index): string {
            $token     = $tokens[$index++] ?? null;
            $reference = $token === null ? null : ($cards[$token['type'] . ':' . $token['id']] ?? null);
            if ($reference instanceof ChatReference) {
                $readable[$reference->key()] = $reference->toArray();
            }

            return $this->chip($reference, $match[0] === self::BLOCK_SLOT);
        }, $html);

        return ['html' => $html, 'references' => array_values($readable), 'pending' => false];
    }

    /**
     * Plain text of a message for a viewer (conversation previews, push bodies, e-mail digests): a readable reference
     * becomes `#Title`, any other the neutral "Content not visible".
     *
     * @param ChatMarkdownRenderer $markdown The renderer (plain-text rules)
     * @param string $body Stored Markdown
     * @param ChatUserInterface $viewer The reader
     * @param int $maxLength Maximum length in characters
     * @param string|null $locale Locale of the placeholder; null for the current one
     *
     * @return string The plain text
     */
    public function plainText(ChatMarkdownRenderer $markdown, string $body, ChatUserInterface $viewer, int $maxLength, ?string $locale = null): string
    {
        if (preg_match(self::TOKEN, $body) === 1) {
            $cards = $this->resolveFor($viewer, self::parse($body));
            $body  = (string) preg_replace_callback(self::TOKEN, function (array $match) use ($cards, $locale): string {
                $reference = $cards[$match[2] . ':' . $match[3]] ?? null;

                return $reference instanceof ChatReference ? '#' . $reference->title : '[' . $this->restricted($locale) . ']';
            }, $body);
        }

        return $markdown->plainText($body, $maxLength);
    }

    /**
     * The neutral placeholder of an item the reader may not open ("Content not visible").
     *
     * @param string|null $locale Locale; null for the current one
     *
     * @return string The translated placeholder
     */
    public function restricted(?string $locale = null): string
    {
        return $this->translator->trans('chat.reference_restricted', [], self::DOMAIN, $locale);
    }

    /**
     * Cards of the tokens readable by the viewer (cached per viewer for the request).
     *
     * @param list<array{title: string, type: string, id: string}> $tokens
     *
     * @return array<string, ChatReference> `type:id` => card
     */
    private function resolveFor(ChatUserInterface $viewer, array $tokens): array
    {
        $viewerId = (int) $viewer->getId();
        $cache    = $this->resolved[$viewerId] ?? [];
        $missing  = [];
        foreach ($tokens as $token) {
            if (!isset($cache[$token['type'] . ':' . $token['id']])) {
                $missing[$token['type']][$token['id']] = true;
            }
        }
        $scope = ChatReferenceScope::reader($viewer);
        foreach ($missing as $type => $set) {
            $ids      = array_map('strval', array_keys($set));
            $provider = $this->providers()[$type] ?? null;
            $found    = $provider?->resolve($scope, $ids) ?? [];
            foreach ($ids as $id) {
                $reference                = $found[$id] ?? null;
                $cache[$type . ':' . $id] = $reference instanceof ChatReference && $reference->type === $type && $reference->id === $id ? $reference : false;
            }
        }
        $this->resolved[$viewerId] = $cache;

        return array_filter($cache, static fn (ChatReference|false $reference): bool => $reference !== false);
    }

    /**
     * The card of a readable item, or the neutral chip (no title, type, project, status or id). `$block`: alone in its
     * paragraph (full-width card), otherwise a compact chip within the text; the title attribute carries the full text.
     */
    private function chip(?ChatReference $reference, bool $block = false): string
    {
        $variant = $block ? ' nowo-chat-ref--block' : '';
        if (!$reference instanceof ChatReference) {
            return '<span class="nowo-chat-ref nowo-chat-ref--restricted' . $variant . '"><span class="nowo-chat-ref__icon" data-icon="lock" data-icon-size="14" aria-hidden="true"></span><span class="nowo-chat-ref__title">' . $this->escape($this->restricted()) . '</span></span>';
        }
        $provider = $this->providers()[$reference->type];
        $card     = $reference->toArray();
        $inner    = '<span class="nowo-chat-ref__icon" data-icon="' . $this->escape($provider->icon()) . '" data-icon-size="14" aria-hidden="true"></span>'
            . '<span class="nowo-chat-ref__type">' . $this->escape($provider->label()) . '</span>'
            . '<span class="nowo-chat-ref__title">' . $this->escape($card['title']) . '</span>'
            . ($card['subtitle'] === '' ? '' : '<span class="nowo-chat-ref__subtitle">' . $this->escape($card['subtitle']) . '</span>')
            . ($card['badge'] === '' ? '' : '<span class="nowo-chat-ref__badge nowo-chat-ref__badge--' . $card['tone'] . '">' . $this->escape($card['badge']) . '</span>');
        $full       = implode(' · ', array_filter([$provider->label(), $card['title'], $card['subtitle'], $card['badge']], static fn (string $part): bool => $part !== ''));
        $attributes = ' data-ref-type="' . $this->escape($reference->type) . '" title="' . $this->escape($full) . '"';

        return $this->isSafeUrl($reference->url)
            ? '<a class="nowo-chat-ref' . $variant . '" href="' . $this->escape($reference->url) . '"' . $attributes . '>' . $inner . '</a>'
            : '<span class="nowo-chat-ref' . $variant . '"' . $attributes . '>' . $inner . '</span>';
    }

    /** Root-relative (`/x`, never `//host`) or http(s) URLs only. */
    private function isSafeUrl(string $url): bool
    {
        return preg_match('~^(?:/(?![/\\\\])|https?://)~i', $url) === 1 && preg_match('/[\x00-\x20]/', $url) !== 1;
    }

    /**
     * Providers by key. A key that is not a valid type, or a second provider of the same key, is a configuration
     * error: it fails loudly instead of letting service order decide which provider answers.
     *
     * @return array<string, ChatReferenceProviderInterface>
     */
    private function providers(): array
    {
        if ($this->byKey === null) {
            $byKey = [];
            foreach ($this->providers as $provider) {
                $key = $provider->key();
                if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $key) !== 1 || isset($byKey[$key])) {
                    throw new LogicException(sprintf('Chat reference provider %s: the key "%s" is invalid or already taken.', $provider::class, $key));
                }
                $byKey[$key] = $provider;
            }
            // @igor-ignore - built once from the container's immutable provider list, not per-request state.
            $this->byKey = $byKey;
        }

        return $this->byKey;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
