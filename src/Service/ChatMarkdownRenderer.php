<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use function count;
use function strlen;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Light Markdown for chat messages, safe by construction — the whole text is
 * HTML-escaped first, then a closed set of rules adds tags: fenced code blocks, inline code, **bold**,
 * *italic* / _italic_, ~~strike~~, bullet / numbered lists, line breaks, http(s) autolinks
 * (`rel="noopener nofollow ugc"`, new tab) and @mention tokens (`@[name](publicId)`, ChatMentions) as
 * `<span class="nowo-chat-mention" data-mention="publicId">@name</span>`, and #references (`#[Title](type:id)`,
 * ChatReferences) as an empty slot filled for each viewer at read time. No raw HTML, no images, no other URL schemes.
 */
final class ChatMarkdownRenderer
{
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
        $text = str_replace(["\r\n", "\r"], "\n", trim(self::clean($markdown)));
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
        return (string) preg_replace_callback('/\x{E000}B(\d+)\x{E000}/u', static fn (array $m): string => $blocks[(int) $m[1]], implode('', $html));
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
        $text = (string) preg_replace([ChatMentions::TOKEN, ChatReferences::TOKEN, '~\[([^\]\n]{1,200})\]\(((?:https?://|mailto:)[^\s)]+)\)~i', '/```[a-z0-9_-]*\n?/', '/[*_~`]+/', '/^[ \t]*([-*+]|\d+[.)]|>)[ \t]*/m', '/\s+/'], ['@$1', '#…', '$1', '', '', '', ' '], self::clean($markdown));
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

        return '<a href="' . self::escape($href) . '"' . ($external ? ' rel="noopener nofollow ugc" target="_blank"' : ' rel="nofollow"') . '>' . self::escape($label) . '</a>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
