<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Avatar;

use function preg_match;
use function strlen;

/**
 * Which profile picture URLs the bundle emits (1.16): `https://host[:port]/…` without user info, or a same-origin path
 * that starts with exactly one `/`. Everything else is dropped: `http:`, `javascript:`, `data:` (images included),
 * `blob:`, `file:` and any other scheme, protocol-relative `//host`, IPv6 literal hosts, ports outside 1–65535,
 * backslashes, quotes (`"`, `'`, backtick), `<` and `>`, relative paths, whitespace and control characters (a trailing
 * line feed included), and anything longer than 2048 bytes. The dock repeats the same check (`avatar.ts`); both run
 * the shared table `src/Resources/assets/tests/fixtures/avatar-urls.json`.
 *
 * @internal
 */
final class AvatarUrlPolicy
{
    public const int MAX_LENGTH = 2048;

    /** Characters never allowed anywhere: ASCII controls, space, DEL, backslash, quotes, backtick, angle brackets. */
    private const string FORBIDDEN = '/[\x00-\x20\x7F\\\\"\'`<>]/';

    /** A same-origin path (one leading slash) or https with a host of non-reserved characters and an optional port. */
    private const string SHAPE = '~^(?:/(?![/\\\\])|https://[^/?#@\[\]:]+(?::([0-9]{1,5}))?(?=[/?#]|\z))~iD';

    /**
     * The URL when it is allowed, null otherwise.
     *
     * @param string|null $url The resolver's value
     *
     * @return string|null The same URL, or null when it is rejected
     */
    public static function sanitize(?string $url): ?string
    {
        if ($url === null || $url === '' || strlen($url) > self::MAX_LENGTH || preg_match(self::FORBIDDEN, $url) === 1
            || preg_match(self::SHAPE, $url, $match) !== 1) {
            return null;
        }
        $port = $match[1] ?? '';

        return $port === '' || ((int) $port >= 1 && (int) $port <= 65535) ? $url : null;
    }
}
