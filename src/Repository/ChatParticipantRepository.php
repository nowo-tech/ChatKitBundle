<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function is_int;

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
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
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
        $qb = $this->createQueryBuilder('p');
        $this->refs->join($qb, 'p.user', 'u', true);

        return $qb->where('p.conversation = :conversation AND ' . $this->refs->field('p', 'user') . ' <> :except AND p.lastReadMessageId IS NOT NULL')
            ->setParameter('conversation', $conversation)
            ->setParameter('except', $this->refs->value($except))
            ->orderBy('p.lastReadMessageId', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * 1.24: ids of the users who muted the conversation now (left out of group mentions).
     *
     * @param ChatConversation $conversation The conversation
     * @param DateTimeImmutable $now Current time
     *
     * @return list<int|string> User ids (1.27: or string references, ChatUserReferences::idOrKey())
     */
    public function mutedUserIds(ChatConversation $conversation, DateTimeImmutable $now): array
    {
        $ids = $this->createQueryBuilder('p')
            ->select($this->refs->identity('p', 'user'))
            ->where('p.conversation = :conversation AND p.mutedUntil > :now')
            ->setParameter('conversation', $conversation)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(ChatUserReferences::idOrKey(...), $ids));
    }

    /**
     * 1.24: the user's participant row of the conversation, if any (both `doctrine.user_reference` modes).
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The user
     *
     * @return ChatParticipant|null The row, or null
     */
    public function findOneFor(ChatConversation $conversation, ChatUserInterface $user): ?ChatParticipant
    {
        $row = $this->findOneBy(['conversation' => $conversation] + $this->refs->criteria('user', $user));
        if ($row instanceof ChatParticipant && !ChatUserReferences::same($row->getUser(), $user)) {
            // REQ-SEC-006 item 2: exactly this user's row (a folding collation may return a look-alike's). 1.28: the
            // read degrades to null and warns; assertInsertable() refuses the write that follows.
            $this->refs->reportLookAlike($this->userColumn(), $user);

            return null;
        }

        return $row;
    }

    /**
     * 1.24: the participant rows of these users in the conversation (both `doctrine.user_reference` modes).
     *
     * @param ChatConversation $conversation The conversation
     * @param list<int|string> $userIds User keys
     *
     * @return list<ChatParticipant> The rows
     */
    public function findForUserIds(ChatConversation $conversation, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        /** @var list<ChatParticipant> $rows */
        $rows = $this->createQueryBuilder('p')
            ->where('p.conversation = :conversation AND ' . $this->refs->identity('p', 'user') . ' IN (:ids)')
            ->setParameter('conversation', $conversation)
            ->setParameter('ids', $this->refs->ids($userIds))
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
            ->where($this->refs->field('p', 'user') . ' = :user AND IDENTITY(p.conversation) IN (:ids)')
            ->setParameter('user', $this->refs->value($user))
            ->setParameter('ids', $conversationIds)
            ->getQuery()
            ->getResult();
        $byConversation = [];
        foreach ($rows as $row) {
            // 1.27 (audit OJ-M1, REQ-SEC-006 item 2): only rows of exactly this user.
            if (ChatUserReferences::same($row->getUser(), $user)) {
                $byConversation[(int) $row->getConversation()->getId()] = $row;
            }
        }

        return $byConversation;
    }

    /**
     * Brings the conversation back to the lists of the people who closed it, except those who muted it (1.10).
     *
     * @param ChatConversation $conversation The conversation that got a new message
     * @param DateTimeImmutable $now Current time (mutes still running keep it closed)
     *
     * @return int Rows reopened
     */
    public function reopenArchived(ChatConversation $conversation, DateTimeImmutable $now): int
    {
        $reopened = ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('p')
            ->update()
            ->set('p.archivedAt', ':none')
            ->where('p.conversation = :conversation AND p.archivedAt IS NOT NULL')
            ->andWhere('p.mutedUntil IS NULL OR p.mutedUntil <= :now')
            ->setParameter('none', null)
            ->setParameter('conversation', $conversation)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute());
        $count = is_int($reopened) ? $reopened : 0;
        if ($count > 0) {
            // Managed rows read again (the UPDATE bypassed them).
            foreach ($this->findBy(['conversation' => $conversation]) as $row) {
                $this->getEntityManager()->refresh($row);
            }
        }

        return $count;
    }

    /**
     * 1.28: refuses to insert a participant row for a user whose look-alike this request already found in the table,
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
     * 1.28 (audit PV-L2): whether a new participant row for `$user` could clash with the row of one of `$others` on a
     * user column that folds (references equal but for case, accents, width or trailing spaces). Never outside
     * `string` mode. ChatService::create() then writes that row only after a read proved the column exact.
     *
     * @param ChatUserInterface $user The user about to get a row
     * @param list<ChatUserInterface> $others The users already getting one
     *
     * @return bool True when the two rows may be one row to a folding column
     */
    public function mayClash(ChatUserInterface $user, array $others): bool
    {
        $key = $this->refs->isStringMode() ? ChatUserReferences::key($user) : null;
        if ($key === null) {
            return false;
        }
        foreach ($others as $other) {
            if (ChatUserReferences::mayFold($key, (string) ChatUserReferences::key($other))) {
                return true;
            }
        }

        return false;
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
