<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionContract\TransactionRunnerInterface;

/**
 * 1.30 (audit QD-M3): the TransactionRunnerInterface a test host binds, on the APPLICATION's manager, as
 * DoctrineDeadlockRetryBundle 3.0's adapter does (`wrapInTransaction()`: it flushes and commits the application's work).
 * ChatKit must never run its writes through it. Counts the runs.
 */
final class HostEntityManagerRunner implements TransactionRunnerInterface
{
    public int $runs = 0;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $operation): mixed
    {
        ++$this->runs;

        return $this->entityManager->wrapInTransaction(static fn (): mixed => $operation());
    }
}
