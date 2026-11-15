<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitRowLock;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatTicketCounter;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;

/**
 * Sequential ticket numbers (1, 2, 3 … per application, per conversation table).
 *
 * 1.30 (Beacon spec 239): the next number comes from one counter row ({@see ChatTicketCounter}) moved with a
 * single-row `UPDATE … SET last_number = last_number + 1`, in the same transaction as the ticket's own number. Up to
 * 1.29 one UPDATE read `MAX(ticket_number) + 1` of the conversation table, which locked the whole ticket index (and its
 * gaps) on MySQL / MariaDB and deadlocked with any other conversation write. Guarantees:
 *
 * - **gap-free and unique**: the counter and the ticket commit or roll back together, so a failed ticket opening
 *   hands no number out; two openings at the same moment queue on the counter row and get consecutive numbers;
 * - **never reused**: a purged or erased ticket's number is not given again (1.29 gave the highest one again once
 *   it was deleted);
 * - the counter starts after the numbers already in the table (the first ticket after the upgrade reads the
 *   maximum once), and catches up when a number is found taken (rows numbered outside ChatKit, a restored backup).
 */
final readonly class ChatTicketNumbers
{
    private const int ATTEMPTS = 5;

    /** Keeps the caller's transaction usable after a lost race on the counter's first row or a taken number. */
    private const string SAVEPOINT = 'nowo_chat_kit_ticket_number';

    private TransactionRunnerInterface $transactions;

    /**
     * Builds the generator from the entity manager (its connection and the table names).
     *
     * @param EntityManagerInterface $entityManager The entity manager
     * @param TransactionRunnerInterface|null $transactions 1.30: runs a call made outside a transaction; null uses ChatKit's default runner
     */
    public function __construct(private EntityManagerInterface $entityManager, ?TransactionRunnerInterface $transactions = null)
    {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * Gives the persisted draft the next number in the database and returns it (the entity is not changed). Inside the
     * caller's transaction (ChatSupportTickets runs it in the ticket's unit of work), the counter row stays locked until
     * that transaction ends; called outside one, it runs in a unit of work of its own.
     *
     * @param ChatConversation $draft A persisted support draft
     *
     * @throws LogicException When the draft is not persisted or no number could be written
     *
     * @return int The new ticket number (the draft's number when it already has one)
     */
    public function assign(ChatConversation $draft): int
    {
        $id         = $draft->getId() ?? throw new LogicException('Only a persisted draft gets a ticket number.');
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            return $this->transactions->run(fn (): int => $this->assignIn($connection, $id));
        }

        return $this->assignIn($connection, $id);
    }

    private function assignIn(Connection $connection, int $id): int
    {
        $conversations = $this->entityManager->getClassMetadata(ChatConversation::class)->getTableName();
        // The draft's row first (locked: always draft, then counter), so a second opening of the same draft waits for
        // the first, then finds its number and takes none.
        $current = ChatKitRowLock::fetchOne($connection->createQueryBuilder()->select('ticket_number')->from($conversations)
            ->where('id = :id')->setParameter('id', $id, ParameterType::INTEGER));
        if ($current !== false && $current !== null) {
            return (int) $current;
        }
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $number = $this->next($connection, $conversations);
            $connection->createSavepoint(self::SAVEPOINT);
            try {
                $connection->executeStatement('UPDATE ' . $conversations . ' SET ticket_number = ? WHERE id = ? AND ticket_number IS NULL', [$number, $id], [ParameterType::INTEGER, ParameterType::INTEGER]);
            } catch (UniqueConstraintViolationException) {
                $connection->rollbackSavepoint(self::SAVEPOINT);
                $connection->releaseSavepoint(self::SAVEPOINT);
                // The number is taken (numbered outside ChatKit, a restored backup): the counter catches up.
                [$table, $key, $last] = $this->counter();
                $connection->executeStatement('UPDATE ' . $table . ' SET ' . $last . ' = (SELECT COALESCE(MAX(ticket_number), 0) FROM ' . $conversations . ') WHERE ' . $key . ' = ?', [$conversations]);

                continue;
            }
            $connection->releaseSavepoint(self::SAVEPOINT);
            if ($this->entityManager instanceof ChatKitEntityManager) {
                // The private manager's row was written beside its unit of work, at this transaction level.
                $this->entityManager->noteWrite();
            }

            return (int) $connection->fetchOne('SELECT ticket_number FROM ' . $conversations . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        }

        throw new LogicException('No free ticket number after ' . self::ATTEMPTS . ' attempts.');
    }

    /**
     * The next number of the counter row, locked until the caller's transaction ends.
     */
    private function next(Connection $connection, string $conversations): int
    {
        [$table, $key, $last] = $this->counter();
        // A plain read first: an UPDATE of a missing row would take a gap lock on MySQL, and two first tickets would
        // then deadlock on their inserts.
        if ($connection->fetchOne('SELECT 1 FROM ' . $table . ' WHERE ' . $key . ' = ?', [$conversations]) === false) {
            $connection->createSavepoint(self::SAVEPOINT);
            try {
                // The first ticket of this table: start after the numbers already there (the only read of the maximum).
                $connection->executeStatement('INSERT INTO ' . $table . ' (' . $key . ', ' . $last . ') SELECT ?, COALESCE(MAX(ticket_number), 0) + 1 FROM ' . $conversations, [$conversations]);
                $connection->releaseSavepoint(self::SAVEPOINT);

                return (int) $connection->fetchOne('SELECT ' . $last . ' FROM ' . $table . ' WHERE ' . $key . ' = ?', [$conversations]);
                // @codeCoverageIgnoreStart
                // Two sessions are needed: tests/Database/TicketNumberLocksTest (MySQL, PostgreSQL) covers this branch.
            } catch (UniqueConstraintViolationException) {
                // Another first ticket wrote the row at the same moment: take the next number of that row.
                $connection->rollbackSavepoint(self::SAVEPOINT);
                $connection->releaseSavepoint(self::SAVEPOINT);
            }
            // @codeCoverageIgnoreEnd
        }
        $connection->executeStatement('UPDATE ' . $table . ' SET ' . $last . ' = ' . $last . ' + 1 WHERE ' . $key . ' = ?', [$conversations]);

        return (int) $connection->fetchOne('SELECT ' . $last . ' FROM ' . $table . ' WHERE ' . $key . ' = ?', [$conversations]);
    }

    /**
     * @return array{0: string, 1: string, 2: string} The counter table, its key column and its number column
     */
    private function counter(): array
    {
        $metadata = $this->entityManager->getClassMetadata(ChatTicketCounter::class);

        return [$metadata->getTableName(), $metadata->getColumnName('key'), $metadata->getColumnName('lastNumber')];
    }
}
