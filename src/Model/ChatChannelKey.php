<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use LogicException;

use function preg_match;
use function strlen;

/**
 * The format rule of a channel key (house rule LQ, look-alike keys). A key is compared byte for byte: `project:Acme`,
 * `project:acme` and `project:acme ` are three channels. A key must be 1–120 bytes of valid UTF-8, with no whitespace
 * or separator at either end and no control, format, surrogate, private-use, line or paragraph separator character
 * anywhere. Keys that break the rule are refused, never normalised: ChatAccess::channel() treats them as unknown and
 * ChatService::openChannel() / ChatConversation::channel() throw.
 */
final class ChatChannelKey
{
    /** Longest key, in bytes (the `channel_key` column length). */
    public const int MAX_LENGTH = 120;

    /** No control (Cc), format (Cf), surrogate (Cs), private-use (Co), line (Zl) or paragraph (Zp) separator. */
    private const string FORBIDDEN = '/[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Zl}\p{Zp}]/u';

    /** Whitespace or a space separator at the start or the end. */
    private const string PADDED = '/\A[\s\p{Z}]|[\s\p{Z}]\z/u';

    /**
     * Whether the key follows the format rule.
     *
     * @param string $key The channel key
     *
     * @return bool True when the key is usable
     */
    public static function isValid(string $key): bool
    {
        return $key !== ''
            && strlen($key) <= self::MAX_LENGTH
            && preg_match('//u', $key) === 1
            && preg_match(self::FORBIDDEN, $key) === 0
            && preg_match(self::PADDED, $key) === 0;
    }

    /**
     * Refuses a key that does not follow the format rule.
     *
     * @param string $key The channel key
     *
     * @throws LogicException When the key is not usable
     */
    public static function assertValid(string $key): void
    {
        if (!self::isValid($key)) {
            throw new LogicException('A channel key must be 1–120 bytes of UTF-8, with no whitespace at either end and no control or format characters.');
        }
    }
}
