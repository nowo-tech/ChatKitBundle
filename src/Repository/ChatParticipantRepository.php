<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Queries for participant rows (read cursors, mute state).
 *
 * @extends ServiceEntityRepository<ChatParticipant>
 */
class ChatParticipantRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatParticipant entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatParticipant::class);
    }

    /**
     * Read cursors of the other participants.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $except The viewer, left out
     * @param int $limit Maximum number of rows
     *
     * @return list<ChatParticipant> Participants with a read cursor, furthest read first
     */
    public function findReaders(ChatConversation $conversation, ChatUserInterface $except, int $limit = 50): array
    {
        /** @var list<ChatParticipant> $rows */
        $rows = $this->createQueryBuilder('p')
            ->innerJoin('p.user', 'u')->addSelect('u')
            ->where('p.conversation = :conversation AND p.user <> :except AND p.lastReadMessageId IS NOT NULL')
            ->setParameter('conversation', $conversation)
            ->setParameter('except', $except)
            ->orderBy('p.lastReadMessageId', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The user's participant rows of these conversations, in one query.
     *
     * @param ChatUserInterface $user The user
     * @param list<int> $conversationIds Doctrine ids of the conversations
     *
     * @return array<int, ChatParticipant> conversation id => row (conversations without a row are absent)
     */
    public function findForUser(ChatUserInterface $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }
        /** @var list<ChatParticipant> $rows */
        $rows = $this->createQueryBuilder('p')
            ->where('p.user = :user AND IDENTITY(p.conversation) IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $conversationIds)
            ->getQuery()
            ->getResult();
        $byConversation = [];
        foreach ($rows as $row) {
            $byConversation[(int) $row->getConversation()->getId()] = $row;
        }

        return $byConversation;
    }
}
