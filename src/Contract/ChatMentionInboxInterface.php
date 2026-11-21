<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Model\ChatMentionView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 2.0: "messages that mention me" for a host page (a dashboard Mentions list), read and marked read through ChatKit's
 * own (private) entity manager. ChatMentionInbox is the default implementation, aliased to this interface.
 *
 * The listing answers with read-only views (ChatMentionView) of the conversations the user may still read: direct
 * threads, the given channels and, for support, the threads the user owns (any thread for an agent); deleted messages
 * never count. Which channels the user may use is the caller's answer (`$channelKeys`), as in ChatChannelSourceInterface.
 *
 * The mark methods apply only to mentions of exactly that user: the user key is compared byte for byte, another user's
 * mention id and an unknown id are skipped silently (no error, no existence oracle). They write in one transaction on
 * ChatKit's runner and are idempotent (the first read time is kept). Never wrap them in your own runner and never write
 * the mention rows through your own entity manager.
 */
interface ChatMentionInboxInterface
{
    /**
     * The user's newest mentions, newest first.
     *
     * @param ChatUserInterface $user The mentioned user
     * @param list<string> $channelKeys Channels the user may use today (exact keys; look-alikes are left out)
     * @param bool $supportAgent Whether the user answers the support inbox
     * @param int $limit Maximum rows (0 or less: none)
     * @param bool $unreadOnly Only unread mentions
     * @param DateTimeImmutable|null $since Only mentions created at or after this time
     * @param bool $channelsOnly Only channel conversations (e.g. the host filters by one project)
     *
     * @return list<ChatMentionView> The mentions
     */
    public function mentions(ChatUserInterface $user, array $channelKeys, bool $supportAgent, int $limit, bool $unreadOnly = false, ?DateTimeImmutable $since = null, bool $channelsOnly = false): array;

    /**
     * How many mentions mentions() would list (same arguments, no limit).
     *
     * @param ChatUserInterface $user The mentioned user
     * @param list<string> $channelKeys Channels the user may use today
     * @param bool $supportAgent Whether the user answers the support inbox
     * @param bool $unreadOnly Only unread mentions
     * @param DateTimeImmutable|null $since Only mentions created at or after this time
     * @param bool $channelsOnly Only channel conversations
     *
     * @return int The count
     */
    public function countMentions(ChatUserInterface $user, array $channelKeys, bool $supportAgent, bool $unreadOnly = false, ?DateTimeImmutable $since = null, bool $channelsOnly = false): int;

    /**
     * Marks one of the user's mentions read (keeps the first read time).
     *
     * @param ChatUserInterface|string $user The mentioned user, or its key (the host user id as a string, or the string reference)
     * @param int|string $mentionId The mention id (ChatMentionView::$id); a string must be a canonical decimal
     *
     * @return bool True when the id is one of exactly this user's mentions (now read, or read before); false for an
     *              unknown id or another user's mention (nothing is written)
     */
    public function markMentionRead(ChatUserInterface|string $user, int|string $mentionId): bool;

    /**
     * Marks several of the user's mentions read (keeps the first read times). Unknown ids and other users' mentions are
     * skipped silently.
     *
     * @param ChatUserInterface|string $user The mentioned user, or its key
     * @param array<array-key, int|string> $mentionIds Mention ids (ChatMentionView::$id)
     *
     * @return int How many of exactly this user's mentions this call turned from unread to read (0 when repeated)
     */
    public function markMentionsRead(ChatUserInterface|string $user, array $mentionIds): int;
}
