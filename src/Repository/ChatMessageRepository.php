<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;

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
        $qb->where('m.conversation = :conversation')
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
     * Finds the newest message of the conversation.
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return ChatMessage|null The newest message, or null when the conversation is empty
     */
    public function findLatest(ChatConversation $conversation): ?ChatMessage
    {
        return $this->findOneBy(['conversation' => $conversation], ['id' => 'DESC']);
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
        $exact        = $this->refs->isIdMode() && $em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        $sameUser     = $exact ? ' AND CAST(p.user_id AS BINARY) = CAST(:user AS BINARY)' : '';
        $otherAuthor  = $exact ? 'CAST(m.author_id AS BINARY) <> CAST(:user AS BINARY)' : 'm.author_id <> :user';
        $rows         = $em->getConnection()->fetchAllAssociative(
            'SELECT m.conversation_id AS cid, COUNT(m.id) AS n
               FROM ' . $messages . ' m
          LEFT JOIN ' . $participants . ' p ON p.conversation_id = m.conversation_id AND p.user_id = :user' . $sameUser . '
              WHERE m.conversation_id IN (:ids)
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
            ->where('IDENTITY(m2.conversation) IN (:ids)')
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
     *
     * @return int The number of messages deleted
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff, int $batchSize): int
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
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatReaction::class, 'r')
            ->where('IDENTITY(r.message) IN (:ids)')
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

        return (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('m')
            ->delete()
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
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
        return (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('m')
            ->update()
            ->set('m.conversation', ':to')
            ->where('m.conversation = :from')
            ->setParameter('to', $to)
            ->setParameter('from', $from)
            ->getQuery()
            ->execute());
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
}
