<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatKitRows;
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
     * @param bool $lock 1.30: read with a shared row lock (inside a transaction)
     *
     * @return ChatParticipant|null The row, or null
     */
    public function findOneFor(ChatConversation $conversation, ChatUserInterface $user, bool $lock = false): ?ChatParticipant
    {
        $criteria = ['conversation' => $conversation] + $this->refs->criteria('user', $user);
        // 1.30: a locking read (the latest committed row, whatever the transaction's snapshot) after a lost insert.
        $row = $lock
            ? $this->getEntityManager()->getUnitOfWork()->getEntityPersister(ChatParticipant::class)->load($criteria, null, null, [], LockMode::PESSIMISTIC_READ, 1)
            : $this->findOneBy($criteria);
        $row = $row instanceof ChatParticipant ? $row : null;
        if ($row instanceof ChatParticipant && !ChatUserReferences::same($row->getUser(), $user)) {
            // REQ-SEC-006 item 2: exactly this user's row (a folding collation may return a look-alike's). 1.28: the
            // read degrades to null and warns; assertInsertable() refuses the write that follows.
            $this->refs->reportLookAlike($this->userColumn(), $user);

            return null;
        }

        return $row;
    }

    /**
     * 1.30 (Beacon spec 239, audit QD-M2): the user's participant row, written now when there is none, inside the caller's
     * unit of work. The insert runs in a savepoint (ChatKitRows): when another request of the same person wrote the row
     * at the same moment, only the savepoint is rolled back and that row is read, so the entity manager and every entity
     * the request holds stay as they were.
     *
     * @param ChatConversation $conversation The conversation (stored)
     * @param ChatUserInterface $user The user
     * @param DateTimeImmutable $now Join time of a new row
     *
     * @throws ChatUserLookAlikeException When the column folds the user onto a look-alike's row (1.28)
     * @throws UniqueConstraintViolationException When the row that took the key cannot be read (a caller's older snapshot)
     *
     * @return ChatParticipant The managed row
     */
    public function claim(ChatConversation $conversation, ChatUserInterface $user, DateTimeImmutable $now): ChatParticipant
    {
        $row = $this->findOneFor($conversation, $user);
        if ($row instanceof ChatParticipant) {
            return $row;
        }
        $this->assertInsertable($user);
        // What the user reference listener checks for a row persisted through the unit of work.
        $this->refs->assertExact();
        $this->refs->assertKind($user);
        $lost = ChatKitRows::insert($this->getEntityManager(), new ChatParticipant($conversation, $user, $now));
        $row  = $this->findOneFor($conversation, $user, $lost instanceof UniqueConstraintViolationException);
        if ($row instanceof ChatParticipant) {
            return $row;
        }
        // A folding column took the key with a look-alike's row (the read above reported it).
        $this->assertInsertable($user);

        // The row is not visible to the caller's older snapshot (its own transaction, MySQL): a conflict (409).
        throw $lost ?? new LogicException('The participant row was written but cannot be read.'); // @codeCoverageIgnore
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
        // 1.30 (Beacon spec 239): a plain read of the rows to reopen first, then an UPDATE by primary key. A busy channel
        // gets a message every moment: the UPDATE … WHERE conversation = ? it replaces locked every participant row of
        // the conversation (and the index gaps around them on MySQL) on every message, against every reader moving
        // their cursor. Nearly always nothing is archived and nothing is written.
        $ids = $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.conversation = :conversation AND p.archivedAt IS NOT NULL')
            ->andWhere('p.mutedUntil IS NULL OR p.mutedUntil <= :now')
            ->setParameter('conversation', $conversation)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleColumnResult();
        if ($ids === []) {
            return 0;
        }
        $reopened = ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('p')
            ->update()
            ->set('p.archivedAt', ':none')
            ->where('p.id IN (:ids) AND p.archivedAt IS NOT NULL')
            ->setParameter('none', null)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute());
        $count = is_int($reopened) ? $reopened : 0;
        // Only the rows this manager holds are read again (the UPDATE bypassed them); 1.30: never the whole member list.
        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        foreach ($ids as $id) {
            $row = $unitOfWork->tryGetById($id, ChatParticipant::class);
            if ($row instanceof ChatParticipant) {
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
     * `table.column` of the user column of this entity, for the look-alike warning (1.28).
     */
    private function userColumn(): string
    {
        $metadata = $this->getClassMetadata();

        return $metadata->getTableName() . '.' . ($this->refs->isIdMode() ? $metadata->getColumnName('userRef') : 'user_id');
    }
}
