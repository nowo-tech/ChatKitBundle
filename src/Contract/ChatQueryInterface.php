<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Model\ChatConversationListItem;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 2.2 (DDD-host checklist B7, REQ-ARCH-001): what a host lists and counts in the chat, as read models — so it neither
 * injects ChatKit's repositories nor writes DQL over its entities (aliased to Query\ChatQuery). Every answer is a view
 * (ChatConversationView, ChatMessageView, ChatConversationListItem), a uuid or a number; the rows are read through
 * ChatKit's own entity manager and never leave it.
 *
 * Two kinds of methods:
 * - **for a user** (`conversationsFor()`, `conversation()`, `messages()`, `unreadCounts()`): the user is a
 *   ChatUserInterface or its public id (see ChatAccessInterface), and only what that user may read now is answered, with
 *   the JSON API's rules. An unknown uuid, a look-alike, an unusable user id and a conversation the user may not read
 *   give the same answer (null, an empty list, left out);
 * - **system reads** (`conversationsByUuids()`, `channelConversations()` and the usage counts): no actor; check access
 *   in your own use case first (e.g. billing, a back-office page, a sidebar the host builds from its own channels).
 *
 * Uuids, channel keys and user keys compare exactly: in SQL, then again in PHP (house rule LQ), so a look-alike (other
 * case, padded, folded by a collation) never counts or answers for another. A channel key that breaks the ChatChannelKey
 * rule and a public id that cannot be one are skipped without a query. Lists are capped (`$limit` 1–100, at most 500
 * uuids or keys per call).
 *
 * Nothing is kept between calls (FrankenPHP worker mode, kernel reset false); every answer is read at that moment
 * (REQ-RUNTIME-003) and may be stale by the time it is used. Message cursors are message ids (ChatMessageView::$id).
 */
interface ChatQueryInterface
{
    /** Most rows one page holds. */
    public const int MAX_LIMIT = 100;

    /** Most uuids, channel keys or public ids one call reads. */
    public const int MAX_BATCH = 500;

    /**
     * The user's conversations, newest activity first, as the JSON API lists them: direct and support conversations with
     * the user's participant row, the conversations of the channels the provider offers the user
     * (ChatChannelSourceInterface::channelsFor()), every support thread holding a message for a support agent; then only
     * what the user may read now. A page may hold fewer than `$limit` rows (rows the user closed or may no longer read are
     * dropped after the page is read): continue with the last row's `lastMessageAt` as `$before` until a page is empty.
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param int $limit Page size (clamped to 1–100)
     * @param DateTimeImmutable|null $before Only conversations with older activity; null for the first page
     * @param bool $archived False: conversations the user did not close; true: only the closed ones
     * @param list<string> $kinds `direct`, `channel` and / or `support`; empty for every kind (unknown kinds are ignored)
     *
     * @return list<ChatConversationListItem> The rows; empty for an unknown or unusable user
     */
    public function conversationsFor(ChatUserInterface|string $user, int $limit = 50, ?DateTimeImmutable $before = null, bool $archived = false, array $kinds = []): array;

    /**
     * One conversation the user may read now; null otherwise (unknown, look-alike and forbidden alike).
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $conversationUuid Public uuid of the conversation
     *
     * @return ChatConversationView|null The view
     */
    public function conversation(ChatUserInterface|string $user, string $conversationUuid): ?ChatConversationView;

    /**
     * A page of the conversation's main timeline (thread replies are not part of it), oldest first, when the user may
     * read the conversation; an empty list otherwise. `$afterId` (newer than that message, oldest first: a catch-up)
     * takes precedence over `$beforeId` (older than that message: the previous page); neither gives the newest page.
     * Deleted messages come as tombstones (`deleted: true`, empty body), as in the API.
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param string $conversationUuid Public uuid of the conversation
     * @param int $limit Page size (clamped to 1–100)
     * @param int|null $beforeId Messages older than this message id
     * @param int|null $afterId Messages newer than this message id
     *
     * @return list<ChatMessageView> The page, oldest first
     */
    public function messages(ChatUserInterface|string $user, string $conversationUuid, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): array;

    /**
     * The user's unread counts of the asked conversations, in one query: messages of others after the user's read cursor
     * (thread replies and deleted messages not counted). Only conversations the user may read now are answered (0
     * included); unknown, look-alike and forbidden uuids are left out alike.
     *
     * @param ChatUserInterface|string $user The user, or its public id
     * @param list<string> $conversationUuids Public uuids (at most 500 are read)
     *
     * @return array<string, int> uuid => unread count
     */
    public function unreadCounts(ChatUserInterface|string $user, array $conversationUuids): array;

    /**
     * System read: the conversations of exactly these uuids, without an access check.
     *
     * @param list<string> $conversationUuids Public uuids (at most 500 are read)
     *
     * @return array<string, ChatConversationView> uuid => view; unknown and look-alike uuids are left out
     */
    public function conversationsByUuids(array $conversationUuids): array;

    /**
     * System read: the conversations of exactly these application channel keys (one conversation per channel, created
     * when the channel is first opened), without an access check.
     *
     * @param list<string> $channelKeys Channel keys (ChatChannel::$key; at most 500 are read)
     *
     * @return array<string, ChatConversationView> channel key => view; channels never opened are left out
     */
    public function channelConversations(array $channelKeys): array;

    /**
     * System read (usage, billing): how many of these channel keys have a conversation.
     *
     * @param list<string> $channelKeys Channel keys (at most 500 are read)
     *
     * @return int The number of channel conversations
     */
    public function countChannelConversations(array $channelKeys): int;

    /**
     * System read (usage, billing): the total stored length of the message bodies (every message, thread replies and
     * tombstones included) of the conversations of these channel keys. The length is the database's `LENGTH()` of the
     * Markdown body: bytes on MySQL / MariaDB, characters on PostgreSQL and SQLite.
     *
     * @param list<string> $channelKeys Channel keys (at most 500 are read)
     *
     * @return int The total length
     */
    public function channelBodyLength(array $channelKeys): int;

    /**
     * System read (usage, billing): the total stored length of the message bodies these users wrote in direct
     * conversations (measured as channelBodyLength()). The users are public ids, looked up through
     * ChatDirectoryInterface::findByPublicId() one by one and kept only when the directory answers with exactly that id.
     *
     * @param list<string> $authorPublicIds Public ids of the authors (at most 500 are read)
     *
     * @return int The total length
     */
    public function directBodyLengthByAuthors(array $authorPublicIds): int;
}
