<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function preg_match_all;

/**
 * 1.27 (REQ-SEC-006 item 1): the format rule of a user reference (ChatUserReferenceInterface, `doctrine.user_reference:
 * id`): 1 to 64 characters of valid UTF-8 (the column length), no whitespace or space separator at either end, no
 * control, format, surrogate, private-use, line or paragraph separator character anywhere. Never trimmed or truncated:
 * a reference that breaks the rule is refused before anything is written.
 */
final class ChatUserReference
{
    /** Longest reference, in characters (the user columns are `VARCHAR(64)`). */
    public const int MAX_LENGTH = 64;

    /** No control (Cc), format (Cf), surrogate (Cs), private-use (Co), line (Zl) or paragraph (Zp) separator. */
    private const string FORBIDDEN = '/[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Zl}\p{Zp}]/u';

    /** Whitespace or a space separator at the start or the end. */
    private const string PADDED = '/\A[\s\p{Z}]|[\s\p{Z}]\z/u';

    /**
     * Whether the reference follows the format rule.
     *
     * @param string $reference The reference
     *
     * @return bool True when usable
     */
    public static function isValid(string $reference): bool
    {
        return $reference !== ''
            && preg_match('//u', $reference) === 1
            && preg_match_all('/./us', $reference) <= self::MAX_LENGTH
            && preg_match(self::FORBIDDEN, $reference) === 0
            && preg_match(self::PADDED, $reference) === 0;
    }

    /**
     * Refuses a reference that breaks the format rule.
     *
     * @param string $reference The reference
     *
     * @throws InvalidArgumentException When it is not usable
     *
     * @return string The reference, unchanged
     */
    public static function assertValid(string $reference): string
    {
        if (!self::isValid($reference)) {
            throw new InvalidArgumentException('A chat user reference must be 1–64 characters of UTF-8, with no whitespace at either end and no control or format characters.');
        }

        return $reference;
    }
}
