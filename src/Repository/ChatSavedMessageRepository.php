<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagedRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitRows;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_filter;
use function array_map;
use function array_values;

/**
 * 1.35: queries for the messages users saved for later. Every read keeps exactly the asked user's rows (REQ-SEC-006
 * item 2: a folding user column may return a look-alike's).
 *
 * @extends ServiceEntityRepository<ChatSavedMessage>
 */
class ChatSavedMessageRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatSavedMessage entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatSavedMessage::class);
    }

    /**
     * The user's saved row of a message.
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user The user
     *
     * @return ChatSavedMessage|null The row, or null when the user has not saved it
     */
    public function findOneFor(ChatMessage $message, ChatUserInterface $user): ?ChatSavedMessage
    {
        $row = $this->findOneBy(['message' => $message] + $this->refs->criteria('user', $user));
        if ($row instanceof ChatSavedMessage && !ChatUserReferences::same($row->getUser(), $user)) {
            $this->refs->reportLookAlike($this->userColumn(), $user);

            return null;
        }

        return $row;
    }

    /**
     * Saves the message for the user inside the caller's unit of work, through a savepoint: when another request saved
     * it at the same moment, that row is kept and nothing else is undone.
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user The user
     * @param DateTimeImmutable $now Save time
     *
     * @return bool True when this request wrote the row (false: another one had just saved it)
     */
    public function claim(ChatMessage $message, ChatUserInterface $user, DateTimeImmutable $now): bool
    {
        $this->refs->assertExact();
        $this->refs->assertKind($user);

        return !ChatKitRows::insert($this->getEntityManager(), new ChatSavedMessage($message, $user, $now)) instanceof UniqueConstraintViolationException;
    }

    /**
     * Deletes a saved row by id, inside the caller's unit of work (a row another request deleted meanwhile is no error).
     *
     * @param ChatSavedMessage $row The row
     *
     * @return bool True when this request deleted it
     */
    public function delete(ChatSavedMessage $row): bool
    {
        $deleted = (int) $this->createQueryBuilder('s')->delete()->where('s.id = :id')->setParameter('id', $row->getId())->getQuery()->execute() > 0;
        // 2.0: the row is gone either way (this request or another one deleted it): the manager forgets it.
        ChatKitManagedRows::forget($this->getEntityManager(), [$row]);

        return $deleted;
    }

    /**
     * How many messages the user saved (the `max_per_user` limit), deleted messages included.
     *
     * @param ChatUserInterface $user The user
     *
     * @return int The count
     */
    public function countFor(ChatUserInterface $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where($this->refs->field('s', 'user') . ' = :user')
            ->setParameter('user', $this->refs->value($user))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A page of the user's saved rows, newest save first, with their messages and conversations.
     *
     * @param ChatUserInterface $user The user
     * @param int|null $before Only rows older than this saved row id (the next page); null for the first page
     * @param int $limit Rows at most
     *
     * @return list<ChatSavedMessage> The rows (exactly this user's)
     */
    public function page(ChatUserInterface $user, ?int $before, int $limit): array
    {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.message', 'm')->addSelect('m')
            ->innerJoin('m.conversation', 'c')->addSelect('c')
            ->where($this->refs->field('s', 'user') . ' = :user')
            ->setParameter('user', $this->refs->value($user))
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit);
        if ($before !== null) {
            $qb->andWhere('s.id < :before')->setParameter('before', $before);
        }
        /** @var list<ChatSavedMessage> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_values(array_filter($rows, static fn (ChatSavedMessage $row): bool => ChatUserReferences::same($row->getUser(), $user)));
    }

    /**
     * Which of the messages the user saved (one query for a page of the thread).
     *
     * @param ChatUserInterface $user The user
     * @param list<int> $messageIds Message ids
     *
     * @return list<int> The saved ones among them
     */
    public function savedAmong(ChatUserInterface $user, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        /** @var list<ChatSavedMessage> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where($this->refs->field('s', 'user') . ' = :user')
            ->andWhere('IDENTITY(s.message) IN (:ids)')
            ->setParameter('user', $this->refs->value($user))
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getResult();

        return array_values(array_map(
            static fn (ChatSavedMessage $row): int => (int) $row->getMessage()->getId(),
            array_filter($rows, static fn (ChatSavedMessage $row): bool => ChatUserReferences::same($row->getUser(), $user)),
        ));
    }

    /**
     * Refuses to insert a row for a user whose look-alike this request already found in the table (the insert would
     * clash with that row's unique key).
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
     * `table.column` of the user column of this entity, for the look-alike warning.
     */
    private function userColumn(): string
    {
        $metadata = $this->getClassMetadata();

        return $metadata->getTableName() . '.' . ($this->refs->isIdMode() ? $metadata->getColumnName('userRef') : 'user_id');
    }
}
