<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatReaction;

/**
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    public function findOneInConversation(ChatConversation $conversation, string $uuid): ?ChatMessage
    {
        return $this->findOneBy(['conversation' => $conversation, 'uuid' => $uuid]);
    }

    /**
     * Cursor page: the `limit` messages older than `beforeId` (newest page when null), returned
     * oldest first for rendering. `afterId` instead returns what arrived after it (reconnect catch-up).
     *
     * @return list<ChatMessage>
     */
    public function findPage(ChatConversation $conversation, int $limit, ?int $beforeId = null, ?int $afterId = null): array
    {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.author', 'a')->addSelect('a')
            ->where('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->setMaxResults($limit);
        if (null !== $afterId) {
            /** @var list<ChatMessage> $newer */
            $newer = $qb->andWhere('m.id > :after')->setParameter('after', $afterId)
                ->orderBy('m.id', 'ASC')->getQuery()->getResult();

            return $newer;
        }
        if (null !== $beforeId) {
            $qb->andWhere('m.id < :before')->setParameter('before', $beforeId);
        }

        /** @var list<ChatMessage> $rows */
        $rows = $qb->orderBy('m.id', 'DESC')->getQuery()->getResult();

        return array_reverse($rows);
    }

    public function findLatest(ChatConversation $conversation): ?ChatMessage
    {
        return $this->findOneBy(['conversation' => $conversation], ['id' => 'DESC']);
    }

    /**
     * Unread counts per conversation id for one user, one query: messages newer than the read cursor
     * and not written by the user. Conversations without a participant row count every foreign message.
     *
     * @param list<int> $conversationIds
     *
     * @return array<int, int>
     */
    public function countUnreadByConversation(int $userId, array $conversationIds): array
    {
        if ([] === $conversationIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT m.conversation_id AS cid, COUNT(m.id) AS n
               FROM nowo_chat_message m
          LEFT JOIN nowo_chat_participant p ON p.conversation_id = m.conversation_id AND p.user_id = :user
              WHERE m.conversation_id IN (:ids)
                AND m.deleted_at IS NULL
                AND (m.author_id IS NULL OR m.author_id <> :user)
                AND m.id > COALESCE(p.last_read_message_id, 0)
           GROUP BY m.conversation_id',
            ['user' => $userId, 'ids' => $conversationIds],
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
     * @param list<int> $conversationIds
     *
     * @return array<int, ChatMessage> conversation id => message
     */
    public function findLatestByConversationIds(array $conversationIds): array
    {
        if ([] === $conversationIds) {
            return [];
        }
        $latestIds = $this->createQueryBuilder('m2')
            ->select('MAX(m2.id)')
            ->where('IDENTITY(m2.conversation) IN (:ids)')
            ->groupBy('m2.conversation')
            ->setParameter('ids', $conversationIds)
            ->getQuery()
            ->getSingleColumnResult();
        if ([] === $latestIds) {
            return [];
        }
        /** @var list<ChatMessage> $messages */
        $messages = $this->createQueryBuilder('m')
            ->leftJoin('m.author', 'a')->addSelect('a')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $latestIds)
            ->getQuery()
            ->getResult();
        $byConversation = [];
        foreach ($messages as $message) {
            $byConversation[(int) $message->getConversation()->getId()] = $message;
        }

        return $byConversation;
    }

    /** one batch of messages older than the retention window; returns rows deleted. */
    public function purgeOlderThan(\DateTimeImmutable $cutoff, int $batchSize): int
    {
        $ids = $this->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getSingleColumnResult();
        if ([] === $ids) {
            return 0;
        }
        $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatReaction::class, 'r')
            ->where('IDENTITY(r.message) IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();

        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
