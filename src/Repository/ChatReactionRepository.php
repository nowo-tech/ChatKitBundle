<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * @extends ServiceEntityRepository<ChatReaction>
 */
class ChatReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatReaction::class);
    }

    public function findOneFor(ChatMessage $message, ChatUserInterface $user, string $emoji): ?ChatReaction
    {
        return $this->findOneBy(['message' => $message, 'user' => $user, 'emoji' => $emoji]);
    }

    /**
     * Reactions of many messages in one query, in first-reaction order.
     *
     * @param list<int> $messageIds
     *
     * @return array<int, array<string, list<string>>> message id => emoji => public ids of the people
     */
    public function summaryFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        /** @var list<ChatReaction> $rows */
        $rows = $this->createQueryBuilder('r')
            ->innerJoin('r.user', 'u')->addSelect('u')
            ->where('IDENTITY(r.message) IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
        $summary = [];
        foreach ($rows as $reaction) {
            $summary[(int) $reaction->getMessage()->getId()][$reaction->getEmoji()][] = $reaction->getUser()->getChatPublicId();
        }

        return $summary;
    }
}
