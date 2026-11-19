<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use Normalizer;

use function array_slice;
use function array_unique;
use function array_values;
use function class_exists;
use function implode;
use function in_array;
use function max;
use function mb_strlen;
use function mb_strpos;
use function mb_strtolower;
use function mb_substr;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function str_contains;
use function trim;

use const PREG_SPLIT_DELIM_CAPTURE;
use const PREG_SPLIT_NO_EMPTY;

/**
 * 1.37: the text rules of message search, pure functions (no state, FrankenPHP worker mode): the words of a query, the
 * case- and accent-insensitive check that the words appear in the text the viewer sees, the plain-text excerpt and its
 * highlighted segments. Every output is plain text: the page escapes it (Twig), the JSON and the palette carry it as text.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatSearchText
{
    /** At most this many distinct words of a query are used. */
    public const int MAX_WORDS = 8;

    /** Longest excerpt, in characters. */
    public const int SNIPPET_LENGTH = 200;

    /** Characters kept before the first match in an excerpt. */
    private const int SNIPPET_LEAD = 60;

    /**
     * The words of a text: split on white space, lower case, de-duplicated, at most {@see self::MAX_WORDS}.
     *
     * @param string $text The query text
     *
     * @return list<string> The words
     */
    public static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $words[] = mb_strtolower($word);
        }

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /**
     * Lower case without accents (when ext-intl is there; lower case only otherwise), for comparisons only.
     *
     * @param string $text Any text
     *
     * @return string The folded text
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);
            $text       = (string) preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $text : $decomposed);
        }

        return $text;
    }

    /**
     * Whether every word is in the text (folded substring match).
     *
     * @param list<string> $words The query words
     * @param string $text The text the viewer sees
     *
     * @return bool True when all words appear
     */
    public static function containsAll(array $words, string $text): bool
    {
        $folded = self::fold($text);
        foreach ($words as $word) {
            if (!str_contains($folded, self::fold($word))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A plain-text excerpt of at most {@see self::SNIPPET_LENGTH} characters, starting a little before the first word
     * found (case-insensitive), with `…` where text was cut.
     *
     * @param string $text The text the viewer sees (plain text)
     * @param list<string> $words The query words
     *
     * @return string The excerpt
     */
    public static function snippet(string $text, array $words): string
    {
        $lower = mb_strtolower($text);
        $first = null;
        foreach ($words as $word) {
            $position = mb_strpos($lower, $word);
            if ($position !== false && ($first === null || $position < $first)) {
                $first = $position;
            }
        }
        $start   = max(0, ($first ?? 0) - self::SNIPPET_LEAD);
        $excerpt = mb_substr($text, $start, self::SNIPPET_LENGTH);
        $prefix  = $start > 0 ? '…' : '';
        $suffix  = $start + self::SNIPPET_LENGTH < mb_strlen($text) ? '…' : '';

        return $prefix . trim($excerpt) . $suffix;
    }

    /**
     * The excerpt split into plain-text segments, the words marked (case-insensitive, exact characters), so that a
     * template can wrap the marked ones in `<mark>` while escaping every segment.
     *
     * @param string $snippet A plain-text excerpt
     * @param list<string> $words The query words
     *
     * @return list<array{text: string, mark: bool}> The segments, in order
     */
    public static function segments(string $snippet, array $words): array
    {
        if ($words === []) {
            return [['text' => $snippet, 'mark' => false]];
        }
        $quoted = [];
        foreach ($words as $word) {
            $quoted[] = preg_quote($word, '/');
        }
        $parts    = preg_split('/(' . implode('|', $quoted) . ')/iu', $snippet, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        foreach ($parts as $part) {
            $segments[] = ['text' => $part, 'mark' => self::isWord($part, $words)];
        }

        return $segments;
    }

    /**
     * @param list<string> $words
     */
    private static function isWord(string $part, array $words): bool
    {
        return in_array(mb_strtolower($part), $words, true);
    }
}
