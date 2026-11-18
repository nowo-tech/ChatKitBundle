<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * 1.36 (REQ-RUNTIME-003 rule 7): the `Idempotency-Key`s of forwards, thread replies and sends. Every read keeps exactly
 * the asked user's rows (REQ-SEC-006 item 2).
 *
 * @extends ServiceEntityRepository<ChatIdempotencyKey>
 */
class ChatIdempotencyKeyRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatIdempotencyKey entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatIdempotencyKey::class);
    }

    /**
     * The user's row of a key.
     *
     * @param ChatUserInterface $user The user
     * @param string $key The key
     * @param bool $lock A locking read (the latest committed row, whatever the transaction's snapshot)
     *
     * @return ChatIdempotencyKey|null The row, or null when the key is new
     */
    public function findOneFor(ChatUserInterface $user, string $key, bool $lock = false): ?ChatIdempotencyKey
    {
        $criteria = ['key' => $key] + $this->refs->criteria('user', $user);
        $row      = $lock ? $this->getEntityManager()->getUnitOfWork()->getEntityPersister(ChatIdempotencyKey::class)->load($criteria, null, null, [], LockMode::PESSIMISTIC_READ, 1) : $this->findOneBy($criteria);

        // Exactly this user's key, compared byte for byte (a folding collation may return another one).
        return $row instanceof ChatIdempotencyKey && ChatUserReferences::same($row->getUser(), $user) && $row->getKey() === $key ? $row : null;
    }

    /**
     * Claims a key, first statement of the caller's unit of work, through a savepoint: a request holding the same key
     * makes this one wait until it ends; when it committed, the key is taken and nothing else is written.
     *
     * @param ChatUserInterface $user The user
     * @param string $key The key
     * @param string $requestHash Hash of the request
     * @param DateTimeImmutable $now When
     *
     * @return bool True when this request holds the key (false: another request used it first)
     */
    public function claim(ChatUserInterface $user, string $key, string $requestHash, DateTimeImmutable $now): bool
    {
        $this->refs->assertExact();
        $this->refs->assertKind($user);

        return !ChatKitRows::insert($this->getEntityManager(), new ChatIdempotencyKey($user, $key, $requestHash, $now)) instanceof UniqueConstraintViolationException;
    }

    /**
     * Stores what the request created, inside the same unit of work as the claim.
     *
     * @param ChatUserInterface $user The user
     * @param string $key The key
     * @param list<string> $messageUuids Uuids of the messages
     */
    public function complete(ChatUserInterface $user, string $key, array $messageUuids): void
    {
        ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('k')->update()
            ->set('k.result', ':result')
            ->where($this->refs->field('k', 'user') . ' = :user AND k.key = :key')
            ->setParameter('result', $messageUuids, Types::JSON)
            ->setParameter('user', $this->refs->value($user))
            ->setParameter('key', $key)
            ->getQuery()
            ->execute());
    }

    /**
     * Deletes keys older than the cutoff (a day: a replay after that is a new request).
     *
     * @param DateTimeImmutable $cutoff Keys created before this go
     *
     * @return int Rows deleted
     */
    public function purgeOlderThan(DateTimeImmutable $cutoff): int
    {
        return (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('k')->delete()
            ->where('k.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute());
    }
}
