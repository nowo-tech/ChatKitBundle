<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\TransactionRunnerAliasPass;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

/**
 * 1.30 (Beacon spec 239, load readiness): ChatKit's default {@see TransactionRunnerInterface}, registered as
 * `nowo_chat_kit.transaction_runner`. The bundle's services are wired to the interface, which
 * {@see TransactionRunnerAliasPass} aliases to this service only when
 * the host defines none: a host that aliases the interface (for example to DoctrineDeadlockRetryBundle's retrying
 * adapter) replaces it for every ChatKit write.
 *
 * It is nowo-tech/transaction-doctrine's one-attempt {@see DoctrineTransactionRunner} on the manager the chat rows are
 * written through (the application's manager, or ChatKit's private one with `doctrine.private_entity_manager: true`):
 * one transaction, the manager flushed before the commit, no retry. After a failure the levels it opened are rolled
 * back (the connection is closed only when that rollback fails, as after a MySQL deadlock in a nested transaction), the
 * manager is closed and reset through the registry so nothing of the failed unit of work stays pending, and a transient
 * failure (deadlock, lock wait timeout, serialization failure) is rethrown as
 * {@see TransientStorageFailure}, which ChatKit's routes answer with 503 and `Retry-After`.
 *
 * Stateless: safe as a shared service in a long-running worker (FrankenPHP worker mode with kernel reset off).
 */
final readonly class ChatKitTransactionRunner implements TransactionRunnerInterface
{
    private DoctrineTransactionRunner $runner;

    /**
     * @param EntityManagerInterface $entityManager The manager the chat rows are written through
     * @param ManagerRegistry|null $registry Resets that manager after a failure; null only clears it
     * @param string|null $managerName The registry name of that manager; null for the default one
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ?ManagerRegistry $registry = null,
        ?string $managerName = null,
        ?int $retryAfterSeconds = 1,
    ) {
        $this->runner = DoctrineTransactionRunner::forEntityManager($entityManager, $registry, $managerName, $retryAfterSeconds);
    }

    /**
     * A one-attempt runner on the connection only (no entity manager flushed, cleared or reset), for a unit of work
     * written through DBAL that must not flush what the manager holds (the first opening of a channel, audit GZ-L2).
     *
     * @param Connection $connection The connection the chat rows are written on
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     *
     * @return TransactionRunnerInterface The runner
     */
    public static function forConnection(Connection $connection, ?int $retryAfterSeconds = 1): TransactionRunnerInterface
    {
        return DoctrineTransactionRunner::forConnection($connection, $retryAfterSeconds);
    }

    /**
     * {@inheritDoc}
     */
    public function run(callable $operation): mixed
    {
        return $this->runner->run($operation);
    }
}
