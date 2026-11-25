<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\ClientApi;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;

use function base64_decode;
use function base64_encode;
use function preg_match;
use function rtrim;
use function strlen;
use function strtr;

/**
 * 2.4 (client API v1): the opaque cursor of the conversation list — the position after the last conversation a page
 * scanned (its last activity and id, newest first). Base64url of `v1.<Y-m-d\TH:i:s>.<id>`: the wall-clock time as the
 * database stores it, so the next query compares exactly what it reads. Pure functions.
 *
 * @internal
 */
final class ChatClientCursor
{
    private const string FORMAT = 'Y-m-d\TH:i:s';

    /** The decoded cursor: `v1.<date>T<time>.<id>`. */
    private const string PATTERN = '/^v1\.(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.([1-9]\d{0,18})$/D';

    /**
     * The cursor after a conversation.
     *
     * @param ChatConversation $conversation The last conversation scanned
     *
     * @return string The cursor
     */
    public static function after(ChatConversation $conversation): string
    {
        return rtrim(strtr(base64_encode('v1.' . $conversation->getLastMessageAt()->format(self::FORMAT) . '.' . $conversation->getId()), '+/', '-_'), '=');
    }

    /**
     * Reads a cursor.
     *
     * @param string $cursor A cursor from after()
     *
     * @return array{DateTimeImmutable, int}|null The last activity and id; null when it is not a valid cursor
     */
    public static function read(string $cursor): ?array
    {
        if ($cursor === '' || strlen($cursor) > 64 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || preg_match(self::PATTERN, $decoded, $match) !== 1) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $match[1]);
        if ($time === false || $time->format(self::FORMAT) !== $match[1]) {
            return null;
        }

        return [$time, (int) $match[2]];
    }
}
