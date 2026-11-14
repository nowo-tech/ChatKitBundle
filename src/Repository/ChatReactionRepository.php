<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
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
        $row = $this->findOneBy(['message' => $message, 'emoji' => $emoji] + $this->refs->criteria('user', $user));
        if ($row instanceof ChatReaction && !ChatUserReferences::same($row->getUser(), $user)) {
            // REQ-SEC-006 item 2: exactly this user's reaction. 1.28: the read degrades to null and warns;
            // assertInsertable() refuses the write that follows.
            $this->refs->reportLookAlike($this->userColumn(), $user);

            return null;
        }

        return $row;
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

    /**
     * 1.28: refuses to insert a reaction row for a user whose look-alike this request already found in the table,
     * because the insert would clash with that row's unique key inside the flush and close the entity manager.
     *
     * @param ChatUserInterface $user The user the row would be written for
     *
     * @throws ChatUserLookAlikeException When a look-alike row of that user was read in this table in this request
     */
    public function assertInsertable(ChatUserInterface $user): void
    {
        $this->refs->assertNoLookAlike($this->userColumn(), $user);
    }

    /**
     * `table.column` of the user column of this entity, for the look-alike warning (1.28).
     */
    private function userColumn(): string
    {
        $metadata = $this->getClassMetadata();

        return $metadata->getTableName() . '.' . ($this->refs->isIdMode() ? $metadata->getColumnName('userRef') : 'user_id');
    }
}
