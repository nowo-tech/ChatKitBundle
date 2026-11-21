<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagedRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * 1.36: who follows a thread and how far they read it. Every read keeps exactly the asked user's rows (REQ-SEC-006
 * item 2: a folding user column may return a look-alike's).
 *
 * @extends ServiceEntityRepository<ChatThreadRead>
 */
class ChatThreadReadRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatThreadRead entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatThreadRead::class);
    }

    /**
     * The user's row in a thread.
     *
     * @param ChatMessage $root The root
     * @param ChatUserInterface $user The user
     * @param bool $lock A locking read (after a lost insert)
     *
     * @return ChatThreadRead|null The row, or null when the user never followed or read the thread
     */
    public function findOneFor(ChatMessage $root, ChatUserInterface $user, bool $lock = false): ?ChatThreadRead
    {
        $criteria = ['root' => $root] + $this->refs->criteria('user', $user);
        // A locking read (the latest committed row, whatever the transaction's snapshot) after a lost insert.
        $row = $lock ? $this->getEntityManager()->getUnitOfWork()->getEntityPersister(ChatThreadRead::class)->load($criteria, null, null, [], LockMode::PESSIMISTIC_READ, 1) : $this->findOneBy($criteria);

        return $row instanceof ChatThreadRead && ChatUserReferences::same($row->getUser(), $user) ? $row : null;
    }

    /**
     * The user's row in a thread, created (following) when missing, inside the caller's unit of work: a row another
     * request wrote at the same moment is read instead (savepoint insert, nothing else undone).
     *
     * @param ChatMessage $root The root (stored)
     * @param ChatUserInterface $user The user
     * @param DateTimeImmutable $now When
     *
     * @throws UniqueConstraintViolationException When the row that took the key cannot be read (a caller's older snapshot)
     *
     * @return ChatThreadRead The managed row
     */
    public function claim(ChatMessage $root, ChatUserInterface $user, DateTimeImmutable $now): ChatThreadRead
    {
        $row = $this->findOneFor($root, $user);
        if ($row instanceof ChatThreadRead) {
            return $row;
        }
        $this->refs->assertExact();
        $this->refs->assertKind($user);
        $lost = ChatKitRows::insert($this->getEntityManager(), new ChatThreadRead($root, $user, $now));

        // The row is not visible to the caller's older snapshot (its own transaction, MySQL): a conflict (409).
        return $this->findOneFor($root, $user, $lost instanceof UniqueConstraintViolationException)
            ?? throw $lost ?? new LogicException('The thread row was written but cannot be read.'); // @codeCoverageIgnore
    }

    /**
     * Moves the user's read cursor of a thread forward (never back), in one statement: two tabs reading at the same
     * moment keep the newest.
     *
     * @param ChatThreadRead $row The user's row
     * @param int $replyId The newest reply read
     * @param DateTimeImmutable $now When
     *
     * @return bool True when the cursor moved
     */
    public function readUpTo(ChatThreadRead $row, int $replyId, DateTimeImmutable $now): bool
    {
        $moved = (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('t')->update()
            ->set('t.lastReadReplyId', ':reply')
            ->set('t.updatedAt', ':now')
            ->where('t.id = :id AND (t.lastReadReplyId IS NULL OR t.lastReadReplyId < :reply)')
            ->setParameter('reply', $replyId)
            ->setParameter('now', $now)
            ->setParameter('id', $row->getId())
            ->getQuery()
            ->execute()) > 0;
        // 2.0: the row the manager holds shows the stored cursor (the DQL update bypassed it).
        if ($moved) {
            ChatKitManagedRows::refresh($this->getEntityManager(), ChatThreadRead::class, [(int) $row->getId()]);
        }

        return $moved;
    }

    /**
     * The users who follow a thread.
     *
     * @param ChatMessage $root The root
     *
     * @return list<ChatUserInterface> The followers
     */
    public function followers(ChatMessage $root): array
    {
        $qb = $this->createQueryBuilder('t');
        $this->refs->join($qb, 't.user', 'u');
        /** @var list<ChatThreadRead> $rows */
        $rows = $qb->where('t.root = :root AND t.following = true')->setParameter('root', $root)->getQuery()->getResult();

        return array_values(array_map(static fn (ChatThreadRead $row): ChatUserInterface => $row->getUser(), $rows));
    }

    /**
     * The user's rows of several threads (one query for a page of the timeline).
     *
     * @param ChatUserInterface $user The user
     * @param non-empty-list<int> $rootIds Root ids
     *
     * @return array<int, ChatThreadRead> root id => row (exactly this user's)
     */
    public function rowsAmong(ChatUserInterface $user, array $rootIds): array
    {
        /** @var list<ChatThreadRead> $rows */
        $rows = $this->createQueryBuilder('t')
            ->where($this->refs->field('t', 'user') . ' = :user')
            ->andWhere('IDENTITY(t.root) IN (:ids)')
            ->setParameter('user', $this->refs->value($user))
            ->setParameter('ids', $rootIds)
            ->getQuery()
            ->getResult();
        $byRoot = [];
        foreach (array_filter($rows, static fn (ChatThreadRead $row): bool => ChatUserReferences::same($row->getUser(), $user)) as $row) {
            $byRoot[(int) $row->getRoot()->getId()] = $row;
        }

        return $byRoot;
    }
}
