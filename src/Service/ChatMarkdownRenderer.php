<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;

use function count;
use function html_entity_decode;
use function str_contains;
use function strip_tags;
use function strlen;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PREG_OFFSET_CAPTURE;

/**
 * Light Markdown for chat messages, safe by construction — the whole text is
 * HTML-escaped first, then a closed set of rules adds tags: fenced code blocks, inline code, **bold**,
 * *italic* / _italic_, ~~strike~~, bullet / numbered lists, line breaks, http(s) autolinks
 * (`rel="noopener noreferrer nofollow ugc"`, new tab) and @mention tokens (`@[name](publicId)`, ChatMentions) as
 * `<span class="nowo-chat-mention" data-mention="publicId">@name</span>`, and #references (`#[Title](type:id)`,
 * ChatReferences) as an empty slot filled for each viewer at read time, and 1.19 link cards (`!link[Title](https://…)`,
 * ChatLinkUrls::card(); a card with a link that is not accepted stays plain text). No raw HTML, no images, no other URL
 * schemes.
 */
final class ChatMarkdownRenderer
{
    /**
     * 1.33 (UiKit 2.54 SECURITY): UiKit's context-menu attributes (`data-nowo-ui-context-menu`, `-source`, `-scope`,
     * `-label`, `-open` …: every name with this prefix) and `data-nowo-ui-card`. A message body must never carry them, or
     * its content could open another element's kebab items on right-click; stripAttributes() removes them from every tag.
     */
    public const string RESERVED_ATTRIBUTE_PREFIX = 'data-nowo-ui-context-menu';

    /** 1.33: the UiKit card marker, also reserved (see RESERVED_ATTRIBUTE_PREFIX). */
    public const string RESERVED_CARD_ATTRIBUTE = 'data-nowo-ui-card';

    /** 1.33 (audit SQ2-L1): any occurrence of a reserved name, renamed after the tag reader (fail closed). */
    private const string RESERVED_NAME = '~data-nowo-ui-(context-menu|card)~i';

    /**
     * Builds the renderer.
     *
     * @param ChatLinkUrls $links 1.19: URL rules and providers of the link cards (`!link[Title](https://…)`)
     */
    public function __construct(
        private readonly ChatLinkUrls $links = new ChatLinkUrls(),
    ) {
    }

    /**
     * Renders a message body to safe HTML: escapes everything, then applies fenced / inline code, bold, italic,
     * strike, lists, line breaks and http(s) autolinks.
     *
     * @param string $markdown The raw message text
     *
     * @return string The sanitized HTML; empty for an empty message
     */
    public function render(string $markdown): string
    {
        // 1.37 (audit VA3-7): a backtick in a #reference title never opens a code span.
        $text = str_replace(["\r\n", "\r"], "\n", trim(self::clean(ChatReferences::safeTitles($markdown))));
        if ($text === '') {
            return '';
        }

        // Fenced code blocks are cut out first so no other rule touches their content.
        $blocks = [];
        $text   = (string) preg_replace_callback('/```(?:[a-z0-9_-]*\n)?(.*?)```/s', static function (array $m) use (&$blocks): string {
            $blocks[] = '<pre><code>' . self::escape(rtrim($m[1], "\n")) . '</code></pre>';

            return "\u{E000}B" . (count($blocks) - 1) . "\u{E000}";
        }, $text);

        $html = [];
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var 'ol'|'ul'|null $list */
        $list = null;
        /** @var list<string> $items */
        $items = [];
        /** @var list<string> $quote */
        $quote      = [];
        $flushQuote = function () use (&$quote, &$html): void {
            if ($quote !== []) {
                // 1.11: `> ` lines are a quote (one paragraph, line breaks kept; no nesting).
                $html[] = '<blockquote><p>' . implode('<br>', array_map($this->inline(...), $quote)) . '</p></blockquote>';
                $quote  = [];
            }
        };
        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[]    = '<p>' . implode('<br>', array_map($this->inline(...), $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $flushList = function () use (&$list, &$items, &$html): void {
            if ($list !== null) {
                $html[] = '<' . $list . '>' . implode('', array_map(fn (string $item): string => '<li>' . $this->inline($item) . '</li>', $items)) . '</' . $list . '>';
                $list   = null;
                $items  = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*>\s?(.*)$/', $line, $q) === 1) {
                $flushParagraph();
                $flushList();
                $quote[] = $q[1];
                continue;
            }
            $flushQuote();
            if (preg_match('/^\x{E000}B(\d+)\x{E000}$/u', trim($line), $m) === 1) {
                $flushParagraph();
                $flushList();
                $html[] = $blocks[(int) $m[1]];
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m) === 1 || preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $n) === 1) {
                $kind = isset($m[1]) ? 'ul' : 'ol';
                $flushParagraph();
                if ($list !== $kind) {
                    $flushList();
                    $list = $kind;
                }
                $items[] = $m[1] ?? $n[1];
                continue;
            }
            $flushList();
            if (trim($line) === '') {
                $flushParagraph();
                continue;
            }
            $paragraph[] = $line;
        }
        $flushQuote();
        $flushParagraph();
        $flushList();

        // A code block marker left inline (text on the same line) renders as the block itself.
        return self::stripAttributes((string) preg_replace_callback('/\x{E000}B(\d+)\x{E000}/u', static fn (array $m): string => $blocks[(int) $m[1]], implode('', $html)));
    }

    /**
     * 1.33: removes UiKit's reserved attributes ({@see self::RESERVED_ATTRIBUTE_PREFIX}, {@see self::RESERVED_CARD_ATTRIBUTE})
     * from every tag of a message's HTML. The render escapes everything first, so they cannot come from the text; this
     * guards the HTML stored by older versions or written by the application, the #reference cards and the bot lines,
     * which the presenter passes through it too, and the server-rendered page (`message.html|raw`, no browser strip).
     * Tags are read the way the HTML tokenizer reads them (audit SQ-L1): an attribute name ends at whitespace, `/`, `>`
     * or `=`; a quoted value at its closing quote (the next attribute may follow at once); an unquoted value, backticks
     * included, at whitespace or `>`. Then, failing closed (audit SQ2-L1), every occurrence of the reserved names that is
     * left anywhere — inside a comment, a declaration or a raw-text element the reader does not model, or as text — is
     * renamed `data-removed-…`, so no element of the result can carry them.
     *
     * @param string $html Message HTML
     *
     * @return string The HTML without those attributes
     */
    public static function stripAttributes(string $html): string
    {
        if (stripos($html, 'data-nowo-ui-') === false) {
            return $html;
        }
        $out    = '';
        $length = strlen($html);
        $offset = 0;
        while (preg_match('/<[a-zA-Z]/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $out .= substr($html, $offset, $start - $offset);
            [$tag, $offset] = self::stripTag($html, $start, $length);
            $out .= $tag;
        }

        // Audit SQ2-L1, fail closed: the reader above does not know comments, declarations or raw-text elements, where
        // a fake tag could hide a real one. Message HTML has no use for these names: any occurrence left (in text, a
        // comment, a swallowed tag) is renamed, so no element can carry them.
        return (string) preg_replace(self::RESERVED_NAME, 'data-removed-$1', $out . substr($html, $offset));
    }

    /**
     * One start tag from `$start` (its `<`), without the reserved attributes, and the offset after it (its `>`, or the
     * end of the HTML for a tag left open).
     *
     * @return array{string, int}
     */
    private static function stripTag(string $html, int $start, int $length): array
    {
        $i   = $start + 1 + strcspn($html, " \t\n\f\r/>", $start + 1);
        $tag = substr($html, $start, $i - $start);
        while ($i < $length && $html[$i] !== '>') {
            // Separators before an attribute: whitespace and a "/" that does not close the tag.
            $gap = strspn($html, " \t\n\f\r/", $i);
            if ($gap > 0 && ($i + $gap >= $length || $html[$i + $gap] === '>')) {
                $tag .= substr($html, $i, $gap);
                $i += $gap;
                break;
            }
            $attribute = $i + $gap;
            // The name: a leading "=" belongs to it, then anything up to whitespace, "/", ">" or "=".
            $end  = $attribute + 1 + strcspn($html, " \t\n\f\r/>=", $attribute + 1);
            $name = strtolower(substr($html, $attribute, $end - $attribute));
            $end += strspn($html, " \t\n\f\r", $end);
            if ($end < $length && $html[$end] === '=') {
                $end += 1 + strspn($html, " \t\n\f\r", $end + 1);
                $quote = $end < $length ? $html[$end] : '';
                if ($quote === '"' || $quote === "'") {
                    $close = strpos($html, $quote, $end + 1);
                    $end   = $close === false ? $length : $close + 1;
                } else {
                    $end += strcspn($html, " \t\n\f\r>", $end);
                }
            } else {
                // No value: the whitespace after the name separates the next attribute.
                $end = $attribute + strlen($name);
            }
            $reserved = str_starts_with($name, self::RESERVED_ATTRIBUTE_PREFIX) || $name === self::RESERVED_CARD_ATTRIBUTE;
            // A dropped attribute takes its whitespace with it; a "/" separator stays.
            $tag .= $reserved ? (string) preg_replace('/[ \t\n\f\r]+$/', '', substr($html, $i, $gap)) : substr($html, $i, $end - $i);
            $i = $end;
        }

        return [$i < $length ? $tag . '>' : $tag, min($i + 1, $length)];
    }

    /**
     * Characters a message may not contain: the Private Use Area (the renderer's own
     * placeholders live there) and bidirectional overrides / isolates, which can make a link display reversed.
     *
     * @param string $text The text to clean
     *
     * @return string The text without those characters
     */
    public static function clean(string $text): string
    {
        // Private-use (placeholders), bidi overrides and C0 / DEL control characters (tab and line breaks kept).
        return (string) preg_replace('/[\x{E000}-\x{F8FF}\x{202A}-\x{202E}\x{2066}-\x{2069}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /**
     * Plain text for previews, push bodies and email digests (no Markdown markers, collapsed whitespace). A reference
     * token becomes `#…` here (no title): ChatReferences::plainText() shows it as the reader may see it.
     *
     * @param string $markdown The raw message text
     * @param int $maxLength Maximum length in characters; longer text is cut and ends with an ellipsis
     *
     * @return string The plain text
     */
    public function plainText(string $markdown, int $maxLength = 120): string
    {
        return self::plain($markdown, $maxLength);
    }

    /**
     * 1.37 (audit VA3-7): the HTML of a stored message: its stored render, or a new one when its body holds a
     * `#reference` token whose title has a backtick (stored by 1.13–1.36: that render shows the title to every reader).
     *
     * @param ChatMessage $message The message
     *
     * @return string The sanitized HTML
     */
    public function htmlOf(ChatMessage $message): string
    {
        return ChatReferences::hasUnsafeTitles($message->getBody()) ? $this->render($message->getBody()) : $message->getBodyHtml();
    }

    /**
     * 1.37: {@see self::plainText()} as a pure function (the entity's search text is built with it, audit VA2-1).
     *
     * @param string $markdown The raw message text
     * @param int $maxLength Maximum length in characters; longer text is cut and ends with an ellipsis
     *
     * @return string The plain text
     */
    public static function plain(string $markdown, int $maxLength = 120): string
    {
        $text = (string) preg_replace([ChatMentions::TOKEN, ChatReferences::TOKEN, ChatLinkUrls::CARD_TOKEN, '~\[([^\]\n]{1,200})\]\(((?:https?://|mailto:)[^\s)]+)\)~i', '/```[a-z0-9_-]*\n?/', '/[*_~`]+/', '/^[ \t]*([-*+]|\d+[.)]|>)[ \t]*/m', '/\s+/'], ['@$1', '#…', '$1', '$1', '', '', '', ' '], self::clean($markdown));
        $text = trim($text);

        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)) . '…' : $text;
    }

    private function inline(string $text): string
    {
        $codes = [];
        $text  = (string) preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::escape($m[1]) . '</code>';

            return "\u{E000}C" . (count($codes) - 1) . "\u{E000}";
        }, $text);

        $mentions = [];
        $text     = (string) preg_replace_callback(ChatMentions::TOKEN, static function (array $m) use (&$mentions): string {
            $mentions[] = '<span class="nowo-chat-mention" data-mention="' . self::escape($m[2]) . '">@' . self::escape($m[1]) . '</span>';

            return "\u{E000}M" . (count($mentions) - 1) . "\u{E000}";
        }, $text);

        // A reference token becomes an empty slot, filled for each viewer at read time (ChatReferences::fill()).
        $references = 0;
        $text       = (string) preg_replace_callback(ChatReferences::TOKEN, static function () use (&$references): string {
            return "\u{E000}R" . $references++ . "\u{E000}";
        }, $text);

        $links = [];
        // 1.19: link cards first (their `[Title](url)` part is not a Markdown link).
        $text = (string) preg_replace_callback(ChatLinkUrls::CARD_TOKEN, function (array $m) use (&$links, $codes, $mentions): string {
            if (str_contains($m[1], "\u{E000}R")) {
                // A #reference keeps its slot (ChatReferences::fill() counts them): no card around it.
                return $m[0];
            }
            // Audit FZ-I3: inline code or a mention inside a card title reads as its text, not as a placeholder.
            $title = (string) preg_replace_callback(
                '/\x{E000}([CM])(\d+)\x{E000}/u',
                static fn (array $p): string => html_entity_decode(strip_tags(($p[1] === 'C' ? $codes : $mentions)[(int) $p[2]]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $m[1],
            );
            try {
                $links[] = $this->links->card($this->links->normalize($m[2]), $this->links->title($title));
            } catch (InvalidArgumentException) {
                $links[] = self::escape($m[0]);
            }

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}";
        }, $text);
        // 1.11: [text](url) with an http(s) or mailto URL; the text is plain (escaped), never markup.
        $text = (string) preg_replace_callback('~\[([^\]\n]{1,200})\]\(((?:https?://|mailto:)[^\s<>"\'`)]+)\)~i', static function (array $m) use (&$links): string {
            $links[] = self::anchor($m[2], $m[1]);

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}";
        }, $text);
        // Bare links: http(s)://…, www.… (as https) and e-mail addresses (as mailto:).
        $text = (string) preg_replace_callback('~\b(?:https?://|www\.)[^\s<>"\'`]+|\b(?:mailto:)?[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b~i', static function (array $m) use (&$links): string {
            $raw  = rtrim($m[0], '.,;:!?)');
            $tail = substr($m[0], strlen($raw));
            $href = match (true) {
                str_contains($raw, '@') && !preg_match('~^https?://~i', $raw) => str_starts_with(strtolower($raw), 'mailto:') ? $raw : 'mailto:' . $raw,
                stripos($raw, 'www.') === 0                                   => 'https://' . $raw,
                default                                                       => $raw,
            };
            $links[] = self::anchor($href, preg_replace('~^mailto:~i', '', $raw) ?? $raw);

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}" . $tail;
        }, $text);

        $text = self::escape($text);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/', '<em>$1</em>', $text);

        $text = (string) preg_replace_callback('/\x{E000}L(\d+)\x{E000}/u', static fn (array $m): string => $links[(int) $m[1]], $text);
        $text = (string) preg_replace_callback('/\x{E000}M(\d+)\x{E000}/u', static fn (array $m): string => $mentions[(int) $m[1]], $text);
        $text = (string) preg_replace('/\x{E000}R\d+\x{E000}/u', ChatReferences::SLOT, $text);

        return (string) preg_replace_callback('/\x{E000}C(\d+)\x{E000}/u', static fn (array $m): string => $codes[(int) $m[1]], $text);
    }

    /** A link that opens outside the chat (mailto: in the same tab). */
    private static function anchor(string $href, string $label): string
    {
        $external = !str_starts_with(strtolower($href), 'mailto:');

        return '<a href="' . self::escape($href) . '"' . ($external ? ' rel="noopener noreferrer nofollow ugc" target="_blank"' : ' rel="nofollow"') . '>' . self::escape($label) . '</a>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
