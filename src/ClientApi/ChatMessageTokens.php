<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\Model\ChatLinkProvider;
use Nowo\ChatKitBundle\Model\ChatReference;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatMentions;
use Nowo\ChatKitBundle\Service\ChatReferences;

use function array_merge;
use function array_values;
use function count;
use function explode;
use function in_array;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;
use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * 2.4 (client API v1, Q-BD-16): a message body as structured tokens instead of HTML, for clients that render text
 * nodes only. The same closed rule set as ChatMarkdownRenderer, read from the stored Markdown:
 *
 * - blocks: `paragraph` and `quote` (inline tokens, `break` between lines), `list` (`ordered`, one token list per item)
 *   and `code` (a fenced block, as text);
 * - inline tokens: `text` (with `marks`: `bold`, `italic`, `strike`), `break`, `code`, `mention` (`userUuid`, `name`),
 *   `link` (`url`, `text`, `card`, the link card's `provider`) and `reference`.
 *
 * A #reference is resolved for the reader (R2): a card the reader may open (`visible: true` with its type, title,
 * subtitle, badge, tone and a safe URL), else the neutral placeholder (`visible: false` and the "Content not visible"
 * text only — no type, id or title). Links are http(s) or mailto only; a link card whose URL is not accepted stays text.
 * Stateless (the reference cards are memoised by ChatReferences for the request).
 */
final readonly class ChatMessageTokens
{
    /** Format id of the token body (`body.format`). */
    public const string FORMAT = 'tokens/1';

    /** A placeholder in the line: kind (Code, Mention, Reference, Link) and index. */
    private const string PLACEHOLDER = '/\x{E000}([CMRL])(\d+)\x{E000}/u';

    /** Emphasis rules of ChatMarkdownRenderer::inline(), in its order. */
    private const array EMPHASIS = [
        'bold'   => '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u',
        'strike' => '/~~(?=\S)(.+?)(?<=\S)~~/u',
        'italic' => '/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u',
        'em'     => '/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u',
    ];

    /**
     * @param ChatLinkUrls $links URL rules and providers of the link cards
     * @param ChatReferences|null $references Resolves the #references for the reader; null shows every one as not visible
     */
    public function __construct(
        private ChatLinkUrls $links,
        private ?ChatReferences $references = null,
    ) {
    }

    /**
     * The body of a message as the reader may see it.
     *
     * @param string $body Stored Markdown
     * @param ChatUserInterface $viewer The reader
     *
     * @return array{format: string, blocks: list<array<string, mixed>>}
     */
    public function of(string $body, ChatUserInterface $viewer): array
    {
        return ['format' => self::FORMAT, 'blocks' => $this->blocks($body, $this->references?->cardsFor($viewer, $body) ?? [])];
    }

    /**
     * A plain text as one paragraph (bot and system lines, already translated).
     *
     * @param string $text The text
     *
     * @return array{format: string, blocks: list<array<string, mixed>>}
     */
    public static function plain(string $text): array
    {
        $tokens = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $index => $line) {
            if ($index > 0) {
                $tokens[] = ['type' => 'break'];
            }
            if ($line !== '') {
                $tokens[] = ['type' => 'text', 'text' => $line];
            }
        }

        return ['format' => self::FORMAT, 'blocks' => $tokens === [] ? [] : [['type' => 'paragraph', 'tokens' => $tokens]]];
    }

    /**
     * The http(s) links of a token body that are not link cards, in order, each once (the link previews of a message).
     *
     * @param array{format: string, blocks: list<array<string, mixed>>} $body A body built by of()
     *
     * @return list<string> The URLs
     */
    public static function previewUrls(array $body): array
    {
        $urls = [];
        $walk = static function (array $tokens) use (&$urls): void {
            foreach ($tokens as $token) {
                if (($token['type'] ?? null) === 'link' && ($token['card'] ?? false) === false && preg_match('~^https?://~i', (string) $token['url']) === 1 && !in_array($token['url'], $urls, true)) {
                    $urls[] = (string) $token['url'];
                }
            }
        };
        foreach ($body['blocks'] as $block) {
            if (isset($block['tokens'])) {
                $walk($block['tokens']);
            }
            foreach ($block['items'] ?? [] as $item) {
                $walk($item);
            }
        }

        return $urls;
    }

    /**
     * @param array<string, ChatReference> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(string $body, array $cards): array
    {
        // 1.37 (audit VA3-7): a backtick in a #reference title never opens a code span (as the renderer).
        $text = str_replace(["\r\n", "\r"], "\n", trim(ChatMarkdownRenderer::clean(ChatReferences::safeTitles($body))));
        if ($text === '') {
            return [];
        }
        $fences = [];
        $text   = (string) preg_replace_callback('/```(?:[a-z0-9_-]*\n)?(.*?)```/s', static function (array $m) use (&$fences): string {
            // 1.37 (audit VA5-1/2): code never shows a reference title.
            $fences[] = ChatReferences::neutralTokens(rtrim($m[1], "\n"));

            return "\u{E000}B" . (count($fences) - 1) . "\u{E000}";
        }, $text);

        $blocks = [];
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var 'ol'|'ul'|null $list */
        $list = null;
        /** @var list<string> $items */
        $items = [];
        /** @var list<string> $quote */
        $quote = [];
        $flush = function (string $which) use (&$paragraph, &$list, &$items, &$quote, &$blocks, $cards): void {
            if ($which === 'quote' && $quote !== []) {
                $blocks[] = ['type' => 'quote', 'tokens' => $this->lines($quote, $cards)];
                $quote    = [];
            } elseif ($which === 'paragraph' && $paragraph !== []) {
                $blocks[]  = ['type' => 'paragraph', 'tokens' => $this->lines($paragraph, $cards)];
                $paragraph = [];
            } elseif ($which === 'list' && $list !== null) {
                $rows = [];
                foreach ($items as $item) {
                    $rows[] = $this->inline($item, $cards);
                }
                $blocks[] = ['type' => 'list', 'ordered' => $list === 'ol', 'items' => $rows];
                $list     = null;
                $items    = [];
            }
        };

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*>\s?(.*)$/', $line, $q) === 1) {
                $flush('paragraph');
                $flush('list');
                $quote[] = $q[1];
                continue;
            }
            $flush('quote');
            if (preg_match('/^\x{E000}B(\d+)\x{E000}$/u', trim($line), $m) === 1) {
                $flush('paragraph');
                $flush('list');
                $blocks[] = ['type' => 'code', 'text' => $fences[(int) $m[1]]];
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m) === 1 || preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $n) === 1) {
                $kind = isset($m[1]) ? 'ul' : 'ol';
                $flush('paragraph');
                if ($list !== $kind) {
                    $flush('list');
                    $list = $kind;
                }
                $items[] = $m[1] ?? $n[1];
                continue;
            }
            $flush('list');
            if (trim($line) === '') {
                $flush('paragraph');
                continue;
            }
            $paragraph[] = $line;
        }
        $flush('quote');
        $flush('paragraph');
        $flush('list');

        // A fence marker left inside a line (text on the same line) is its code, as text.
        return $this->inlineFences($blocks, $fences);
    }

    /**
     * @param list<string> $lines
     * @param array<string, ChatReference> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function lines(array $lines, array $cards): array
    {
        $tokens = [];
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $tokens[] = ['type' => 'break'];
            }
            $tokens = array_merge($tokens, $this->inline($line, $cards));
        }

        return $tokens;
    }

    /**
     * The inline tokens of one line, built by the renderer's rules in its order: code spans, mentions, references, link
     * cards, Markdown links, bare links, then emphasis.
     *
     * @param array<string, ChatReference> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function inline(string $text, array $cards): array
    {
        $codes = [];
        $text  = (string) preg_replace_callback('/`([^`\n]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = ['type' => 'code', 'text' => ChatReferences::neutralTokens($m[1])];

            return "\u{E000}C" . (count($codes) - 1) . "\u{E000}";
        }, $text);

        $mentions = [];
        $text     = (string) preg_replace_callback(ChatMentions::TOKEN, static function (array $m) use (&$mentions): string {
            $mentions[] = ['type' => 'mention', 'userUuid' => $m[2], 'name' => $m[1]];

            return "\u{E000}M" . (count($mentions) - 1) . "\u{E000}";
        }, $text);

        $references = [];
        $text       = (string) preg_replace_callback(ChatReferences::TOKEN, function (array $m) use (&$references, $cards): string {
            $references[] = $this->reference($cards[$m[2] . ':' . $m[3]] ?? null);

            return "\u{E000}R" . (count($references) - 1) . "\u{E000}";
        }, $text);

        $placeholders = ['C' => $codes, 'M' => $mentions, 'R' => $references];
        $links        = [];
        $text         = (string) preg_replace_callback(ChatLinkUrls::CARD_TOKEN, function (array $m) use (&$links, $placeholders): string {
            if (str_contains($m[1], "\u{E000}R")) {
                // A #reference keeps its token: no card around it (as the renderer).
                return $m[0];
            }
            try {
                $url   = $this->links->normalize($m[2]);
                $title = $this->links->title(self::plainOf($m[1], $placeholders));
            } catch (InvalidArgumentException) {
                // Not an accepted card: its text as written (no later rule reads it as a link).
                $links[] = ['type' => 'text', 'text' => self::plainOf($m[0], $placeholders)];

                return "\u{E000}L" . (count($links) - 1) . "\u{E000}";
            }
            $host     = $this->links->host($url);
            $provider = $this->links->provider($host);
            $links[]  = ['type' => 'link', 'url' => $url, 'text' => $this->links->label($title, $host), 'card' => true]
                + ($provider instanceof ChatLinkProvider ? ['provider' => ['key' => $provider->key, 'label' => $provider->label]] : []);

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}";
        }, $text);
        $text = (string) preg_replace_callback('~\[([^\]\n]{1,200})\]\(((?:https?://|mailto:)[^\s<>"\'`)\xEE]+)\)~i', static function (array $m) use (&$links, $placeholders): string {
            $links[] = ['type' => 'link', 'url' => $m[2], 'text' => self::plainOf($m[1], $placeholders), 'card' => false];

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}";
        }, $text);
        $text = (string) preg_replace_callback('~\b(?:https?://|www\.)[^\s<>"\'`\xEE]+|\b(?:mailto:)?[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b~i', static function (array $m) use (&$links): string {
            $raw  = rtrim($m[0], '.,;:!?)');
            $tail = substr($m[0], strlen($raw));
            $href = match (true) {
                str_contains($raw, '@') && preg_match('~^https?://~i', $raw) !== 1 => str_starts_with(strtolower($raw), 'mailto:') ? $raw : 'mailto:' . $raw,
                stripos($raw, 'www.') === 0                                        => 'https://' . $raw,
                default                                                            => $raw,
            };
            $links[] = ['type' => 'link', 'url' => $href, 'text' => (string) preg_replace('~^mailto:~i', '', $raw), 'card' => false];

            return "\u{E000}L" . (count($links) - 1) . "\u{E000}" . $tail;
        }, $text);
        $placeholders['L'] = $links;

        return $this->merge($this->styled($text, [], $placeholders));
    }

    /**
     * Emphasis first (the earliest rule that matches, ties in the renderer's order), then the placeholders.
     *
     * @param list<string> $marks
     * @param array<string, list<array<string, mixed>>> $placeholders
     *
     * @return list<array<string, mixed>>
     */
    private function styled(string $text, array $marks, array $placeholders): array
    {
        $best = null;
        foreach (self::EMPHASIS as $mark => $pattern) {
            if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1 && ($best === null || $match[0][1] < $best[1][0][1])) {
                $best = [$mark === 'em' ? 'italic' : $mark, $match];
            }
        }
        if ($best === null) {
            return $this->atoms($text, $marks, $placeholders);
        }
        [$mark, $match] = $best;
        $start          = $match[0][1];
        $end            = $start + strlen($match[0][0]);

        return array_merge(
            $this->atoms(substr($text, 0, $start), $marks, $placeholders),
            $this->styled($match[1][0], in_array($mark, $marks, true) ? $marks : [...$marks, $mark], $placeholders),
            $this->styled(substr($text, $end), $marks, $placeholders),
        );
    }

    /**
     * Text and placeholder tokens of a run without emphasis.
     *
     * @param list<string> $marks
     * @param array<string, list<array<string, mixed>>> $placeholders
     *
     * @return list<array<string, mixed>>
     */
    private function atoms(string $text, array $marks, array $placeholders): array
    {
        $tokens = [];
        $parts  = preg_split(self::PLACEHOLDER, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 0, $n = count($parts); $i < $n; ++$i) {
            if ($i % 3 === 0) {
                if ($parts[$i] !== '') {
                    $tokens[] = ['type' => 'text', 'text' => $parts[$i]] + ($marks === [] ? [] : ['marks' => $marks]);
                }
                continue;
            }
            $token = $placeholders[$parts[$i]][(int) $parts[$i + 1]] ?? null;
            ++$i;
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Adjacent text tokens with the same marks become one.
     *
     * @param list<array<string, mixed>> $tokens
     *
     * @return list<array<string, mixed>>
     */
    private function merge(array $tokens): array
    {
        $merged = [];
        foreach ($tokens as $token) {
            $last = count($merged) - 1;
            if ($token['type'] === 'text' && $last >= 0 && $merged[$last]['type'] === 'text' && ($merged[$last]['marks'] ?? []) === ($token['marks'] ?? [])) {
                $merged[$last]['text'] .= $token['text'];
                continue;
            }
            $merged[] = $token;
        }

        return array_values($merged);
    }

    /**
     * The plain text of a run with placeholders (a link's or a card's label): code as written, `@name`, a reference's
     * title when visible, else the neutral placeholder.
     *
     * @param array<string, list<array<string, mixed>>> $placeholders
     */
    private static function plainOf(string $text, array $placeholders): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, static function (array $p) use ($placeholders): string {
            $token = $placeholders[$p[1]][(int) $p[2]] ?? null;

            return match ($token['type'] ?? null) {
                'code'      => (string) $token['text'],
                'mention'   => '@' . $token['name'],
                'reference' => (string) ($token['title'] ?? $token['text']),
                default     => '',
            };
        }, $text);
    }

    /**
     * The token of a #reference for the reader: its card, or the neutral placeholder (R2).
     *
     * @return array<string, mixed>
     */
    private function reference(?ChatReference $card): array
    {
        $type = $card instanceof ChatReference ? $this->references?->typeView($card->type) : null;
        if (!$card instanceof ChatReference || $type === null) {
            return ['type' => 'reference', 'visible' => false, 'text' => $this->references?->restricted() ?? '…'];
        }
        $view = $card->toArray();

        return [
            'type'      => 'reference',
            'visible'   => true,
            'refType'   => $view['type'],
            'id'        => $view['id'],
            'typeLabel' => $type['label'],
            'icon'      => $type['icon'],
            'title'     => $view['title'],
            'url'       => ChatReferences::isSafeLink($view['url']) ? $view['url'] : null,
            'subtitle'  => $view['subtitle'],
            'badge'     => $view['badge'],
            'tone'      => $view['tone'],
        ];
    }

    /**
     * A fence marker inside a line of text (not alone on it) is that fence's code: as the renderer, which replaces it at
     * the end. In tokens it is an inline `code` token in place of the text marker.
     *
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $fences
     *
     * @return list<array<string, mixed>>
     */
    private function inlineFences(array $blocks, array $fences): array
    {
        if ($fences === []) {
            return $blocks;
        }
        $fix = static function (array $tokens) use ($fences): array {
            $out = [];
            foreach ($tokens as $token) {
                if ($token['type'] !== 'text' || !str_contains((string) $token['text'], "\u{E000}B")) {
                    $out[] = $token;
                    continue;
                }
                $parts = preg_split('/\x{E000}B(\d+)\x{E000}/u', (string) $token['text'], -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                foreach ($parts as $index => $part) {
                    if ($index % 2 === 1) {
                        $out[] = ['type' => 'code', 'text' => $fences[(int) $part] ?? ''];
                    } elseif ($part !== '') {
                        $out[] = ['type' => 'text', 'text' => $part] + (isset($token['marks']) ? ['marks' => $token['marks']] : []);
                    }
                }
            }

            return $out;
        };
        foreach ($blocks as $index => $block) {
            if (isset($block['tokens'])) {
                $blocks[$index]['tokens'] = $fix($block['tokens']);
            }
            if (isset($block['items'])) {
                foreach ($block['items'] as $item => $tokens) {
                    $blocks[$index]['items'][$item] = $fix($tokens);
                }
            }
        }

        return $blocks;
    }
}
