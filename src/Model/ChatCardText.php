<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

use function mb_substr;
use function preg_match;
use function preg_replace;
use function trim;

/**
 * 2.6 (spec 259): the text and URL rules shared by the card, composer action and message menu slot value objects.
 * Pure functions, no state.
 */
final class ChatCardText
{
    /** A card type, composer action or slot key: the reference key grammar. */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** A card id: the reference id grammar (an opaque host id, never a secret on its own). */
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** A dock icon name. */
    public const string ICON_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,39}$/';

    /** Control, line / paragraph separator and bidirectional characters (never shown). */
    private const string UNSAFE = '/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u';

    /** A root-relative path (never `//host` or a backslash), without spaces or control characters. */
    private const string PATH = '~^/(?![/\\\\])[^\s\\\\\x00-\x1F\x7F]*\z~';

    /**
     * Plain text: control and bidirectional characters become spaces, trimmed, at most `$max` characters.
     *
     * @param string $text The text
     * @param int $max Longest result (characters)
     */
    public static function clip(string $text, int $max): string
    {
        return trim(mb_substr(trim((string) preg_replace(self::UNSAFE, ' ', $text)), 0, $max));
    }

    /**
     * A required plain text.
     *
     * @throws InvalidArgumentException When nothing is left
     */
    public static function required(string $text, int $max, string $what): string
    {
        $clipped = self::clip($text, $max);
        if ($clipped === '') {
            throw new InvalidArgumentException($what . ' needs a non-empty text.');
        }

        return $clipped;
    }

    /**
     * A key (KEY_PATTERN).
     *
     * @throws InvalidArgumentException When it does not match
     */
    public static function key(string $key, string $what): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException($what . ' key must match ' . self::KEY_PATTERN . ', got "' . $key . '".');
        }

        return $key;
    }

    /**
     * A dock icon name; anything else falls back to `$fallback`.
     */
    public static function icon(string $icon, string $fallback): string
    {
        return preg_match(self::ICON_PATTERN, $icon) === 1 ? $icon : $fallback;
    }

    /**
     * Whether a URL is a same-origin path (root-relative, never `//host`, no backslash, space or control character).
     */
    public static function isPath(string $url): bool
    {
        return preg_match(self::PATH, $url) === 1 && preg_match('/[\p{Z}]/u', $url) !== 1;
    }

    /**
     * A same-origin path.
     *
     * @throws InvalidArgumentException When the URL is not one
     */
    public static function path(string $url, string $what): string
    {
        if (!self::isPath($url)) {
            throw new InvalidArgumentException($what . ' URL must be a same-origin path (starting with one "/").');
        }

        return $url;
    }
}
