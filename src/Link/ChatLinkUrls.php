<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Link;

use InvalidArgumentException;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Model\ChatLinkProvider;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;

use function array_slice;
use function function_exists;
use function htmlspecialchars;
use function idn_to_ascii;
use function is_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rawurlencode;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;
use const PHP_URL_HOST;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * 1.19: URL rules of the links feature (pinned links and message link cards), the known providers, the link card
 * token (`!link[Title](https://…)`) and the links of a message text for the link index. Pure functions over the
 * configuration: nothing is fetched (no preview, no DNS), so no request ever leaves the server for a link.
 *
 * A link is accepted only as an absolute `https:` URL (`http:` too when the host opts in with `links.allow_http`), with
 * a hostname, without user information, at most {@see self::URL_MAX_LENGTH} characters, without spaces or control
 * characters. It is normalised: lowercase scheme and hostname (an internationalised hostname becomes its ASCII form when
 * ext-intl is available, and is refused otherwise), no default port, a `/` path at least, and every character outside the
 * URL set (quotes, brackets, parentheses, non-ASCII …) percent-encoded, so the stored URL is safe in an attribute and in
 * the card token.
 */
final readonly class ChatLinkUrls
{
    public const int URL_MAX_LENGTH = 2048;

    public const int TITLE_MAX_LENGTH = 120;

    /** Links indexed per message (the first ones). */
    public const int MAX_PER_MESSAGE = 20;

    /** A link card in a message: `!link[Title](https://…)`; the title may be empty. */
    public const string CARD_TOKEN = '~!link\[([^\]\n]{0,' . self::TITLE_MAX_LENGTH . '})\]\((https?://[^\s<>"\'`)]+)\)~i';

    /** Code (fenced or inline): nothing inside it is a link card or an indexed link. */
    private const string CODE = '~```.*?```|`[^`\n]+`~s';

    private const string HOSTNAME = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /** @var list<ChatLinkProvider> */
    private array $providers;

    /**
     * Builds the rules from the `links` options.
     *
     * @param array<string, array{label: string, hosts: list<string>, icon?: string|null, fallback_icon?: string}> $providers `links.providers` (key => provider)
     * @param bool $allowHttp Also accept `http:` links (`links.allow_http`)
     */
    public function __construct(
        array $providers = Configuration::DEFAULT_LINK_PROVIDERS,
        private bool $allowHttp = false,
    ) {
        $list = [];
        foreach ($providers as $key => $provider) {
            $list[] = new ChatLinkProvider(
                (string) $key,
                $provider['label'],
                array_values(array_map(static fn (string $host): string => strtolower(trim($host)), $provider['hosts'])),
                'provider_' . $key,
                $provider['fallback_icon'] ?? 'video',
            );
        }
        $this->providers = $list;
    }

    /**
     * Whether `http:` links are accepted.
     *
     * @return bool True when the host opted in
     */
    public function allowsHttp(): bool
    {
        return $this->allowHttp;
    }

    /**
     * The configured providers, in order.
     *
     * @return list<ChatLinkProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * The provider a hostname belongs to.
     *
     * @param string $host Lowercase hostname
     *
     * @return ChatLinkProvider|null The first matching provider, or null for a generic link
     */
    public function provider(string $host): ?ChatLinkProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->matches($host)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * A provider by key.
     *
     * @param string $key Provider key
     *
     * @return ChatLinkProvider|null The provider, or null when unknown
     */
    public function providerByKey(string $key): ?ChatLinkProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->key === $key) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Validates and normalises a link.
     *
     * @param string $url The link as typed
     *
     * @throws InvalidArgumentException `invalid_link_url` when it is not an accepted link
     *
     * @return string The normalised URL
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::URL_MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F\\\\]/u', $url) !== 0) {
            throw new InvalidArgumentException('invalid_link_url');
        }
        $parts  = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (!is_array($parts) || $scheme !== 'https' && ($scheme !== 'http' || !$this->allowHttp)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !preg_match('~^https?://~i', $url)) {
            throw new InvalidArgumentException('invalid_link_url');
        }
        $host = $this->asciiHost(strtolower($parts['host']));
        $port = $parts['port'] ?? null;
        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }
        $normalized = $scheme . '://' . $host . ($port === null ? '' : ':' . $port)
            . $this->encode(($parts['path'] ?? '') === '' ? '/' : $parts['path'])
            . (isset($parts['query']) ? '?' . $this->encode($parts['query']) : '')
            . (isset($parts['fragment']) ? '#' . $this->encode($parts['fragment']) : '');
        if (strlen($normalized) > self::URL_MAX_LENGTH) {
            throw new InvalidArgumentException('invalid_link_url');
        }

        return $normalized;
    }

    /**
     * The hostname of a normalised URL.
     *
     * @param string $url A URL returned by normalize()
     *
     * @return string The lowercase hostname ('' when there is none)
     */
    public function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Cleans an optional title: control and bidi characters go, whitespace collapses, empty means none.
     *
     * @param string|null $title The title as typed
     *
     * @throws InvalidArgumentException `invalid_link_title` when longer than {@see self::TITLE_MAX_LENGTH} characters
     *
     * @return string|null The title, or null for none
     */
    public function title(?string $title): ?string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', ChatMarkdownRenderer::clean((string) $title)));
        if ($title === '') {
            return null;
        }
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            throw new InvalidArgumentException('invalid_link_title');
        }

        return $title;
    }

    /**
     * What a link shows: its title, else the provider label, else the hostname.
     *
     * @param string|null $title The link title
     * @param string $host The hostname
     *
     * @return string The display label
     */
    public function label(?string $title, string $host): string
    {
        return $title ?? $this->provider($host)->label ?? $host;
    }

    /**
     * The JSON view of a link's provider.
     *
     * @param string $host The hostname
     *
     * @return array{key: string, label: string, icon: string, fallbackIcon: string}|null Null for a generic link
     */
    public function providerView(string $host): ?array
    {
        return $this->provider($host)?->toArray();
    }

    /**
     * Validates the link cards of a message text and writes them back normalised (outside code only).
     *
     * @param string $body The message text
     *
     * @throws InvalidArgumentException `invalid_link` when a card holds a link that is not accepted
     *
     * @return string The text with normalised cards
     */
    public function normalizeCards(string $body): string
    {
        return $this->outsideCode($body, function (string $text): string {
            return (string) preg_replace_callback(self::CARD_TOKEN, function (array $match): string {
                try {
                    $url   = $this->normalize($match[2]);
                    $title = $this->title($match[1]) ?? '';
                } catch (InvalidArgumentException $exception) {
                    throw new InvalidArgumentException('invalid_link', 0, $exception);
                }

                return '!link[' . $title . '](' . $url . ')';
            }, $text);
        });
    }

    /**
     * The HTML of a link card (everything escaped): an external link with the provider icon, the label and the
     * provider and hostname.
     *
     * @param string $url A URL accepted by normalize()
     * @param string|null $title The card title (null: the provider label or the hostname shows)
     *
     * @return string The card HTML
     */
    public function card(string $url, ?string $title): string
    {
        $host     = $this->host($url);
        $provider = $this->provider($host);
        $icon     = $provider instanceof ChatLinkProvider
            ? ' data-icon="' . $this->escape($provider->icon) . '" data-icon-fallback="' . $this->escape($provider->fallbackIcon) . '"'
            : ' data-icon="link"';

        return '<a class="nowo-chat-linkcard" href="' . $this->escape($url) . '" target="_blank" rel="noopener noreferrer nofollow ugc"'
            . ' data-title="' . $this->escape($title ?? '') . '"'
            . ($provider instanceof ChatLinkProvider ? ' data-provider="' . $this->escape($provider->key) . '"' : '') . '>'
            . '<span class="nowo-chat-linkcard__icon"' . $icon . ' data-icon-size="18" aria-hidden="true"></span>'
            . '<span class="nowo-chat-linkcard__text">'
            . '<span class="nowo-chat-linkcard__title">' . $this->escape($this->label($title, $host)) . '</span>'
            . '<span class="nowo-chat-linkcard__meta">' . $this->escape($provider instanceof ChatLinkProvider ? $provider->label . ' · ' . $host : $host) . '</span>'
            . '</span></a>';
    }

    /**
     * The links of a message text for the link index, in order: link cards (`card`), then Markdown links and bare
     * http(s) / www. links (`link`); code is skipped, links that are not accepted are left out, each URL once (a card
     * wins), at most {@see self::MAX_PER_MESSAGE}.
     *
     * @param string $body The message text
     *
     * @return list<array{url: string, title: string|null, kind: 'card'|'link'}>
     */
    public function extract(string $body): array
    {
        /** @var list<array{0: string, 1: string|null, 2: 'card'|'link'}> $candidates */
        $candidates = [];
        $text       = (string) preg_replace(self::CODE, ' ', ChatMarkdownRenderer::clean($body));
        $text       = (string) preg_replace_callback(self::CARD_TOKEN, static function (array $match) use (&$candidates): string {
            $candidates[] = [$match[2], $match[1], ChatMessageLink::KIND_CARD];

            return ' ';
        }, $text);
        $text = (string) preg_replace_callback('~\[([^\]\n]{1,200})\]\((https?://[^\s<>"\'`)]+)\)~i', static function (array $match) use (&$candidates): string {
            $candidates[] = [$match[2], $match[1], ChatMessageLink::KIND_LINK];

            return ' ';
        }, $text);
        preg_match_all('~\b(?:https?://|www\.)[^\s<>"\'`]+~i', $text, $bare);
        foreach ($bare[0] as $raw) {
            $raw          = rtrim($raw, '.,;:!?)');
            $candidates[] = [str_starts_with(strtolower($raw), 'www.') ? 'https://' . $raw : $raw, null, ChatMessageLink::KIND_LINK];
        }
        $found = [];
        foreach ($candidates as [$url, $title, $kind]) {
            try {
                $url = $this->normalize($url);
                // A Markdown link text longer than a title is cut (an index title, never shown as the card's).
                $title = $title === null ? null : $this->title(mb_substr($title, 0, self::TITLE_MAX_LENGTH));
            } catch (InvalidArgumentException) {
                continue;
            }
            $found[$url] ??= ['url' => $url, 'title' => $title, 'kind' => $kind];
        }

        return array_slice(array_values($found), 0, self::MAX_PER_MESSAGE);
    }

    /**
     * Applies a rewrite to the parts of a text outside code.
     *
     * @param callable(string): string $rewrite
     */
    private function outsideCode(string $text, callable $rewrite): string
    {
        $parts = preg_split('~(```.*?```|`[^`\n]+`)~s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out   = '';
        foreach (is_array($parts) ? $parts : [$text] as $index => $part) {
            $out .= $index % 2 === 1 ? $part : $rewrite($part);
        }

        return $out;
    }

    /** The ASCII form of a hostname (ext-intl for an internationalised one); refuses anything else. */
    private function asciiHost(string $host): string
    {
        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            $host  = is_string($ascii) ? strtolower($ascii) : '';
        }
        if (preg_match(self::HOSTNAME, $host) !== 1) {
            throw new InvalidArgumentException('invalid_link_url');
        }

        return $host;
    }

    /** Percent-encodes every character outside the URL set (and a `%` not followed by two hex digits). */
    private function encode(string $part): string
    {
        return (string) preg_replace_callback(
            '/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~:\/?#@!$&*+,;=%]/u',
            static fn (array $match): string => rawurlencode($match[0]),
            $part,
        );
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
