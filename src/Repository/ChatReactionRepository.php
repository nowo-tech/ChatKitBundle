<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * Queries for message reactions.
 *
 * @extends ServiceEntityRepository<ChatReaction>
 */
class ChatReactionRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatReaction entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatReaction::class);
    }

    /**
     * Finds the user's reaction with the given emoji on the message.
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user The reacting user
     * @param string $emoji The emoji
     *
     * @return ChatReaction|null The reaction, or null when the user has not reacted so
     */
    public function findOneFor(ChatMessage $message, ChatUserInterface $user, string $emoji): ?ChatReaction
    {
        return $this->findOneBy(['message' => $message, 'emoji' => $emoji] + $this->refs->criteria('user', $user));
    }

    /**
     * Reactions of many messages in one query, in first-reaction order.
     *
     * @param list<int> $messageIds Messages to summarize
     *
     * @return array<int, array<string, list<string>>> message id => emoji => public ids of the people
     */
    public function summaryFor(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $qb = $this->createQueryBuilder('r');
        $this->refs->join($qb, 'r.user', 'u', true);
        /** @var list<ChatReaction> $rows */
        $rows = $qb->where('IDENTITY(r.message) IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
        $summary = [];
        foreach ($rows as $reaction) {
            if ($reaction->getUser() instanceof UnresolvedChatUser) {
                // 1.24 (audit KC-L3): an account the directory no longer knows is not listed.
                continue;
            }
            $summary[(int) $reaction->getMessage()->getId()][$reaction->getEmoji()][] = $reaction->getUser()->getChatPublicId();
        }

        return $summary;
    }
}
