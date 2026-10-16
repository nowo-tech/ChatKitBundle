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
 * *italic* / _italic_, ~~strike~~, bullet / numbered lists, line breaks and http(s) autolinks
 * (`rel="noopener nofollow ugc"`, new tab). No raw HTML, no images, no other URL schemes.
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
        $items          = [];
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
     * Plain text for previews, push bodies and email digests (no Markdown markers, collapsed whitespace).
     *
     * @param string $markdown The raw message text
     * @param int $maxLength Maximum length in characters; longer text is cut and ends with an ellipsis
     *
     * @return string The plain text
     */
    public function plainText(string $markdown, int $maxLength = 120): string
    {
        $text = (string) preg_replace(['/```[a-z0-9_-]*\n?/', '/[*_~`]+/', '/^[ \t]*([-*+]|\d+[.)])[ \t]+/m', '/\s+/'], ['', '', '', ' '], self::clean($markdown));
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

        $links = [];
        $text  = (string) preg_replace_callback('~\bhttps?://[^\s<>"\'`]+~i', static function (array $m) use (&$links): string {
            $url     = rtrim($m[0], '.,;:!?)');
            $tail    = substr($m[0], strlen($url));
            $links[] = '<a href="' . self::escape($url) . '" rel="noopener nofollow ugc" target="_blank">' . self::escape($url) . '</a>';

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}" . $tail;
        }, $text);

        $text = self::escape($text);
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/', '<em>$1</em>', $text);

        $text = (string) preg_replace_callback('/\x{E000}L(\d+)\x{E000}/u', static fn (array $m): string => $links[(int) $m[1]], $text);

        return (string) preg_replace_callback('/\x{E000}C(\d+)\x{E000}/u', static fn (array $m): string => $codes[(int) $m[1]], $text);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
