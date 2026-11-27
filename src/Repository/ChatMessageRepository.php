<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Closure;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagedRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_reverse;
use function array_unique;
use function array_values;
use function count;
use function strtr;

/**
 * Queries for messages: cursor pages, latest message per conversation, unread counts and the retention purge.
 *
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatMessage entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * Finds a message by its public uuid within the given conversation.
     *
     * @param ChatConversation $conversation The conversation the message must belong to
     * @param string $uuid Public message id
     *
     * @return ChatMessage|null The message, or null when not in that conversation
     */
    public function findOneInConversation(ChatConversation $conversation, string $uuid): ?ChatMessage
    {
        return $this->findOneBy(['conversation' => $conversation, 'uuid' => $uuid]);
    }

    /**
     * 2.8 (spec 260): the newest messages of a conversation whose body holds the token tail `](type:id)` of a card,
     * thread replies included and deleted messages left out, searched among the newest `$window` messages only. Two
     * queries on the conversation's index (the window's lowest id, then the page); the bodies are refreshed from the
     * database (a worker's manager may hold an older copy). The caller checks the exact token (outside code).
     *
     * @param ChatConversation $conversation The conversation
     * @param string $type The card type (KEY_PATTERN)
     * @param string $id The card id (ID_PATTERN)
     * @param int $window Newest messages searched (at least 1)
     * @param int $limit Messages returned at most
     *
     * @return list<ChatMessage> Newest first
     */
    public function findCardHolders(ChatConversation $conversation, string $type, string $id, int $window, int $limit): array
    {
        /** @var list<int|string> $floor */
        $floor = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.id', 'DESC')
            ->setFirstResult($window - 1)
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleColumnResult();
        $qb = $this->createQueryBuilder('m');
        $this->refs->join($qb, 'm.author', 'a');
        /** @var list<ChatMessage> $rows */
        $rows = $qb->where('m.conversation = :conversation AND m.id >= :floor AND m.deletedAt IS NULL')
            ->andWhere("m.body LIKE :token ESCAPE '!'")
            ->setParameter('conversation', $conversation)
            ->setParameter('floor', (int) ($floor[0] ?? 0))
            ->setParameter('token', '%' . strtr('](' . $type . ':' . $id . ')', ['!' => '!!', '%' => '!%', '_' => '!_']) . '%')
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }

    /**
     * Cursor page: the `limit` messages older than `beforeId` (newest page when null), returned
     * oldest first for rendering. `afterId` instead returns what arrived after it (reconnect catch-up).
     *
     * @param ChatConversation $conversation The conversation
     * @param int $limit Page size
     * @param int|null $beforeId Return messages older than this id; null for the newest page
     * @param int|null $afterId Return messages newer than this id instead (takes precedence)
     *
     * @return list<ChatMessage> The page, oldest first
     */
    public function findPage(ChatConversation $conversation, int $limit, ?int $beforeId = null, ?int $afterId = null): array
    {
        $qb = $this->createQueryBuilder('m');
        $this->refs->join($qb, 'm.author', 'a');
        // 1.36: thread replies are not part of the main timeline.
        $qb->where('m.conversation = :conversation AND m.threadRoot IS NULL')
            ->setParameter('conversation', $conversation)
            ->setMaxResults($limit);
        if ($afterId !== null) {
            /** @var list<ChatMessage> $newer */
            $newer = $qb->andWhere('m.id > :after')->setParameter('after', $afterId)
                ->orderBy('m.id', 'ASC')->getQuery()->getResult();

            return $newer;
        }
        if ($beforeId !== null) {
            $qb->andWhere('m.id < :before')->setParameter('before', $beforeId);
        }

        /** @var list<ChatMessage> $rows */
        $rows = $qb->orderBy('m.id', 'DESC')->getQuery()->getResult();

        return array_reverse($rows);
    }

    /**
     * 1.35: id of the newest message of the conversation before a message ("Mark unread from here").
     *
     * @param ChatConversation $conversation The conversation
     * @param int $messageId The message
     *
     * @return int|null The id, or null when the message is the first one
     */
    public function findIdBefore(ChatConversation $conversation, int $messageId): ?int
    {
        $id = $this->createQueryBuilder('m')->select('MAX(m.id)')
            ->where('m.conversation = :conversation AND m.id < :id AND m.threadRoot IS NULL')
            ->setParameter('conversation', $conversation)
            ->setParameter('id', $messageId)
            ->getQuery()
            ->getSingleScalarResult();

        return $id === null ? null : (int) $id;
    }

    /**
     * Finds the newest message of the conversation.
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return ChatMessage|null The newest message, or null when the conversation is empty
     */
    public function findLatest(ChatConversation $conversation): ?ChatMessage
    {
        // 1.36: of the main timeline (a thread reply is not the conversation's newest message).
        return $this->findOneBy(['conversation' => $conversation, 'threadRoot' => null], ['id' => 'DESC']);
    }

    /**
     * Unread counts per conversation id for one user, one query: messages newer than the read cursor
     * and not written by the user. Conversations without a participant row count every foreign message.
     *
     * @param int|string $userId Key of the reader (ChatUserReferences::key())
     * @param list<int> $conversationIds Conversations to count
     *
     * @return array<int, int> conversation id => unread count (conversations with none are left out)
     */
    public function countUnreadByConversation(int|string $userId, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        // Physical names come from the metadata: doctrine.table_name_prefix / table_prefix rename them (REQ-ORM-002).
        $em           = $this->getEntityManager();
        $messages     = $em->getClassMetadata(ChatMessage::class)->getTableName();
        $participants = $em->getClassMetadata(ChatParticipant::class)->getTableName();
        // 1.27 (audit OJ-M1, REQ-SEC-006 item 6): with user references on MySQL / MariaDB the user columns are compared
        // byte for byte in SQL too (a folding legacy column would count a look-alike's read cursor or messages).
        $exact       = $this->refs->isIdMode() && $em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $sameUser    = $exact ? ' AND CAST(p.user_id AS BINARY) = CAST(:user AS BINARY)' : '';
        $otherAuthor = $exact ? 'CAST(m.author_id AS BINARY) <> CAST(:user AS BINARY)' : 'm.author_id <> :user';
        $rows        = $em->getConnection()->fetchAllAssociative(
            'SELECT m.conversation_id AS cid, COUNT(m.id) AS n
               FROM ' . $messages . ' m
          LEFT JOIN ' . $participants . ' p ON p.conversation_id = m.conversation_id AND p.user_id = :user' . $sameUser . '
              WHERE m.conversation_id IN (:ids)
                AND m.thread_root_id IS NULL
                AND m.deleted_at IS NULL
                AND (m.author_id IS NULL OR ' . $otherAuthor . ')
                AND m.id > COALESCE(p.last_read_message_id, 0)
           GROUP BY m.conversation_id',
            // 1.24: in `doctrine.user_reference: id` the user columns hold the id as text.
            ['user' => $this->refs->sqlValue($userId), 'ids' => $conversationIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['cid']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Newest message of each conversation in one query (list previews).
     *
     * @param list<int> $conversationIds Conversations to look up
     *
     * @return array<int, ChatMessage> conversation id => message
     */
    public function findLatestByConversationIds(array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        $latestIds = $this->createQueryBuilder('m2')
            ->select('MAX(m2.id)')
            ->where('IDENTITY(m2.conversation) IN (:ids) AND m2.threadRoot IS NULL')
            ->groupBy('m2.conversation')
            ->setParameter('ids', $conversationIds)
            ->getQuery()
            ->getSingleColumnResult();
        if ($latestIds === []) {
            return [];
        }
        $qb = $this->createQueryBuilder('m');
        $this->refs->join($qb, 'm.author', 'a');
        /** @var list<ChatMessage> $messages */
        $messages = $qb->where('m.id IN (:ids)')
            ->setParameter('ids', $latestIds)
            ->getQuery()
            ->getResult();
        $byConversation = [];
        foreach ($messages as $message) {
            $byConversation[(int) $message->getConversation()->getId()] = $message;
        }

        return $byConversation;
    }

    /**
     * Deletes one batch of messages older than the retention window, with their reactions.
     *
     * @param DateTimeImmutable $cutoff Messages created before this are deleted
     * @param int $batchSize Maximum number of messages per batch
     * @param (Closure(list<int>): void)|null $beforeDelete 2.7: called with the batch's message ids (thread replies
     *                                                      included) before they are deleted (their files are marked)
     *
     * @return int The number of messages deleted
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff, int $batchSize, ?Closure $beforeDelete = null): int
    {
        $ids = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getSingleColumnResult();
        if ($ids === []) {
            return 0;
        }
        // 1.36: a purged root takes its thread with it (replies, their rows), and the previous texts go with the messages.
        $replies = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('IDENTITY(m.threadRoot) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult();
        $ids = array_values(array_unique([...$ids, ...$replies]));
        if ($beforeDelete instanceof Closure) {
            $beforeDelete(array_values(array_map(intval(...), $ids)));
        }
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatMessageRevision::class, 'rv')
            ->where('IDENTITY(rv.message) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatThreadRead::class, 'tr')
            ->where('IDENTITY(tr.root) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        // Forwards of these messages keep their neutral marker.
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('f')
            ->update()
            ->set('f.forwardedFrom', ':none')
            ->where('IDENTITY(f.forwardedFrom) IN (:ids)')
            ->setParameter('none', null)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatReaction::class, 'r')
            ->where('IDENTITY(r.message) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        // 1.35: and the Saved lists.
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatSavedMessage::class, 'sv')
            ->where('IDENTITY(sv.message) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        // 1.19: their links leave the Links panel.
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatMessageLink::class, 'ml')
            ->where('IDENTITY(ml.message) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());

        // Replies before their roots (the self-reference).
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('m')
            ->delete()
            ->where('m.id IN (:ids) AND m.threadRoot IS NOT NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());

        $deleted = (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('m')
            ->delete()
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        // 2.0: purged rows the manager held are forgotten, and forwards it holds drop the pointer the database dropped.
        ChatKitManagedRows::forgetGone($this->getEntityManager(), [ChatMessage::class, ChatMessageRevision::class, ChatThreadRead::class, ChatReaction::class, ChatSavedMessage::class, ChatMessageLink::class, ChatMessageMention::class]);

        return $deleted;
    }

    /**
     * 1.36: a page of a thread's replies, oldest first (`afterId`: what arrived after it; `beforeId`: the older page).
     *
     * @param ChatMessage $root The root
     * @param int $limit Page size
     * @param int|null $beforeId Replies older than this id; null for the newest page
     * @param int|null $afterId Replies newer than this id instead (takes precedence)
     *
     * @return list<ChatMessage> The page, oldest first
     */
    public function findReplies(ChatMessage $root, int $limit, ?int $beforeId = null, ?int $afterId = null): array
    {
        $qb = $this->createQueryBuilder('m');
        $this->refs->join($qb, 'm.author', 'a');
        $qb->where('m.threadRoot = :root')->setParameter('root', $root)->setMaxResults($limit);
        if ($afterId !== null) {
            /** @var list<ChatMessage> $newer */
            $newer = $qb->andWhere('m.id > :after')->setParameter('after', $afterId)->orderBy('m.id', 'ASC')->getQuery()->getResult();

            return $newer;
        }
        if ($beforeId !== null) {
            $qb->andWhere('m.id < :before')->setParameter('before', $beforeId);
        }
        /** @var list<ChatMessage> $rows */
        $rows = $qb->orderBy('m.id', 'DESC')->getQuery()->getResult();

        return array_reverse($rows);
    }

    /**
     * 1.36: counts a new (or deleted) reply on its root in one statement, under the conversation's lock (the unit of
     * work of every reply takes it first): concurrent replies never lose a count.
     *
     * @param ChatMessage $root The root
     * @param int $delta +1 for a new reply, -1 for a deleted one
     * @param DateTimeImmutable|null $at Time of the new reply (moves the last reply time forward only); null keeps it
     */
    public function countReply(ChatMessage $root, int $delta, ?DateTimeImmutable $at): void
    {
        $qb = $this->createQueryBuilder('m')->update()
            ->set('m.threadReplyCount', 'm.threadReplyCount + :delta')
            ->where('m.id = :id')
            ->setParameter('delta', $delta)
            ->setParameter('id', $root->getId());
        if ($delta < 0) {
            $qb->andWhere('m.threadReplyCount > 0');
        }
        ChatKitSavepoint::write($this->getEntityManager(), static fn (): mixed => $qb->getQuery()->execute());
        if ($at instanceof DateTimeImmutable) {
            ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('m')->update()
                ->set('m.threadLastReplyAt', ':at')
                ->where('m.id = :id AND (m.threadLastReplyAt IS NULL OR m.threadLastReplyAt < :at)')
                ->setParameter('at', $at)
                ->setParameter('id', $root->getId())
                ->getQuery()
                ->execute());
        }
    }

    /**
     * 1.36: id of the newest reply (not deleted) of each thread, one query.
     *
     * @param non-empty-list<int> $rootIds Root ids
     *
     * @return array<int, int> root id => newest reply id (threads without a reply are left out)
     */
    public function latestReplyIds(array $rootIds): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.threadRoot) AS root', 'MAX(m.id) AS latest')
            ->where('IDENTITY(m.threadRoot) IN (:ids) AND m.deletedAt IS NULL')
            ->groupBy('m.threadRoot')
            ->setParameter('ids', $rootIds)
            ->getQuery()
            ->getArrayResult();
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row['root']] = (int) $row['latest'];
        }

        return $latest;
    }

    /**
     * 1.36: the newest repliers of threads (up to three per root, newest first), one query for a page of the timeline.
     *
     * @param non-empty-list<int> $rootIds Root ids
     *
     * @return array<int, list<ChatUserInterface>> root id => repliers
     */
    public function recentRepliers(array $rootIds): array
    {
        $qb = $this->createQueryBuilder('m');
        $this->refs->join($qb, 'm.author', 'a');
        /** @var list<ChatMessage> $rows */
        $rows = $qb->where('IDENTITY(m.threadRoot) IN (:ids) AND m.deletedAt IS NULL')
            ->setParameter('ids', $rootIds)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults(count($rootIds) * 20)
            ->getQuery()
            ->getResult();
        $byRoot = [];
        $seen   = [];
        foreach ($rows as $reply) {
            $root   = (int) $reply->getThreadRoot()?->getId();
            $author = $reply->getAuthor();
            // An author-less reply is a tombstone (erased), which the query leaves out already.
            $key = $root . ':' . ($author instanceof ChatUserInterface ? ChatUserReferences::key($author) : '');
            if (!$author instanceof ChatUserInterface || isset($seen[$key]) || count($byRoot[$root] ?? []) >= 3) {
                continue;
            }
            $seen[$key]      = true;
            $byRoot[$root][] = $author;
        }

        return $byRoot;
    }

    /**
     * Moves every message of one conversation to another (a support draft continued in an earlier ticket).
     *
     * @param ChatConversation $from The conversation that loses its messages
     * @param ChatConversation $to The conversation that receives them
     *
     * @return int The number of messages moved
     */
    public function moveAll(ChatConversation $from, ChatConversation $to): int
    {
        $fromId = $from->getId();
        $moved  = (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('m')
            ->update()
            ->set('m.conversation', ':to')
            ->where('m.conversation = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute());
        // 2.0: the moved messages the manager holds point at their new conversation.
        ChatKitManagedRows::refreshManaged($this->getEntityManager(), ChatMessage::class, static fn (ChatMessage $message): bool => $message->getConversation()->getId() === $fromId);

        return $moved;
    }

    /**
     * Pinned messages of a conversation, latest pin first (1.10).
     *
     * @param ChatConversation $conversation The conversation
     * @param int $limit Maximum number of rows
     *
     * @return list<ChatMessage> The pinned messages
     */
    public function findPinned(ChatConversation $conversation, int $limit = 50): array
    {
        /** @var list<ChatMessage> $rows */
        $rows = $this->createQueryBuilder('m')
            ->where('m.conversation = :conversation AND m.pinnedAt IS NOT NULL AND m.deletedAt IS NULL')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.pinnedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * 2.2 (ChatQueryInterface usage counts): the summed `LENGTH()` of the message bodies per channel key, every message of
     * the channel conversations of these keys (thread replies and tombstones included). The SQL `IN` runs on the column
     * collation, so the caller keeps only the exact keys it asked for (house rule LQ).
     *
     * @param list<string> $channelKeys Channel keys
     *
     * @return array<string, int> stored channel key => total length
     */
    public function bodyLengthByChannelKey(array $channelKeys): array
    {
        if ($channelKeys === []) {
            return [];
        }
        /** @var list<array{channel: string, total: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('c.channelKey AS channel, SUM(LENGTH(m.body)) AS total')
            ->innerJoin('m.conversation', 'c')
            ->where('c.kind = :channel AND c.channelKey IN (:keys)')
            ->setParameter('channel', ConversationKind::Channel)
            ->setParameter('keys', $channelKeys)
            ->groupBy('c.channelKey')
            ->getQuery()
            ->getArrayResult();
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row['channel']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * 2.2 (ChatQueryInterface usage counts): the summed `LENGTH()` of the message bodies per author key, messages of
     * direct conversations only. The caller keeps only the exact keys it asked for (house rule LQ).
     *
     * @param list<string> $authorKeys User keys (ChatUserReferences::key())
     *
     * @return array<string, int> stored author key => total length
     */
    public function bodyLengthByDirectAuthor(array $authorKeys): array
    {
        if ($authorKeys === []) {
            return [];
        }
        /** @var list<array{author: int|string, total: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select($this->refs->identity('m', 'author') . ' AS author, SUM(LENGTH(m.body)) AS total')
            ->innerJoin('m.conversation', 'c')
            ->where('c.kind = :direct AND ' . $this->refs->field('m', 'author') . ' IN (:authors)')
            ->setParameter('direct', ConversationKind::Direct)
            ->setParameter('authors', $this->refs->ids($authorKeys))
            ->groupBy($this->refs->field('m', 'author'))
            ->getQuery()
            ->getArrayResult();
        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['author']] = (int) $row['total'];
        }

        return $totals;
    }
}
