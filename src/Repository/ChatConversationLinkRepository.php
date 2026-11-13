<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;

/**
 * 1.19: the pinned links of a conversation.
 *
 * @extends ServiceEntityRepository<ChatConversationLink>
 */
class ChatConversationLinkRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatConversationLink entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatConversationLink::class);
    }

    /**
     * The pinned links of a conversation, oldest first (their authors fetched in the same query).
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return list<ChatConversationLink>
     */
    public function forConversation(ChatConversation $conversation): array
    {
        $qb = $this->createQueryBuilder('l');
        $this->refs->join($qb, 'l.createdBy', 'author');

        return $qb->where('l.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of pinned links of a conversation.
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return int The count
     */
    public function countFor(ChatConversation $conversation): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Deletes pinned links not changed since the cutoff (the last change, else the creation), in a bounded batch, for the
     * retention purge (1.19, audit FZ-L3).
     *
     * @param DateTimeImmutable $cutoff Links last changed before this go
     * @param int $batchSize Maximum links per call
     *
     * @return int Links deleted
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff, int $batchSize): int
    {
        $ids = $this->createQueryBuilder('l')
            ->select('l.id')
            ->where('(l.updatedAt IS NULL AND l.createdAt < :cutoff) OR l.updatedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults($batchSize)
            ->getQuery()
            ->getSingleColumnResult();
        if ($ids === []) {
            return 0;
        }

        return (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('l')
            ->delete()
            ->where('l.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
    }

    /**
     * One pinned link of the conversation by its public id.
     *
     * @param ChatConversation $conversation The conversation
     * @param string $uuid The link's public id
     *
     * @return ChatConversationLink|null The link, or null when it is not one of the conversation's
     */
    public function findOneInConversation(ChatConversation $conversation, string $uuid): ?ChatConversationLink
    {
        return $this->findOneBy(['conversation' => $conversation, 'uuid' => $uuid]);
    }
}
