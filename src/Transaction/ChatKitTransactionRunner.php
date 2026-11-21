<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_filter;
use function is_object;
use function spl_object_id;

/**
 * 1.30 (Beacon spec 239, audits QD-M1 / QD-M3): the runner of every ChatKit write, registered as
 * `nowo_chat_kit.transaction_runner` and injected into the ChatKit services for their `TransactionRunnerInterface`
 * argument. ChatKit never runs its writes through a runner the application binds to the interface: such a runner drives
 * the application's entity manager (it would flush and commit the application's work with a chat write, and reset
 * that manager after a chat failure).
 *
 * One attempt, one transaction; a transient failure (deadlock, lock wait timeout, serialization failure) surfaces as
 * TransientStorageFailure (409 + Retry-After on ChatKit's routes). After any failure no transaction of its own stays
 * open: nowo-tech/transaction-doctrine rolls back to the entry level and closes the connection only when that rollback
 * fails (a MySQL deadlock inside a nested flush).
 *
 * The manager's pending work is flushed first, on its own, so a failed chat unit never rolls it back (PV-L6).
 *
 * - **Private entity manager** (how the bundle wires it; since 2.0 the only mode): nowo-tech/transaction-doctrine 1.1's
 *   ORM runner on ChatKit's own manager. After a storage failure that manager is closed and replaced; after a refusal it
 *   is left alone, or only what the run touched is forgotten. The application's manager is never involved.
 * - **Any other manager** (`privateManager: false`, a runner built by hand): the chat unit runs in a connection-level
 *   transaction, because transaction-doctrine's
 *   ORM runner would close and reset (or clear) the application's manager after a storage failure (audit QD-M1). After a
 *   failure only the ChatKit rows the unit added, wrote or changed are detached (and the rows that point at them); every
 *   other entity stays managed. A failed flush closes the manager (the ORM does): it stays closed for the rest of the
 *   request, so the application's next flush fails loudly as in 1.29, and {@see self::reset()} replaces it at the next
 *   request, message or command boundary (a FrankenPHP worker never serves a later request with it).
 *
 * Stateless.
 */
final readonly class ChatKitTransactionRunner implements TransactionRunnerInterface, ResetInterface
{
    /** The service id the ChatKit services are wired to. */
    public const string SERVICE_ID = 'nowo_chat_kit.transaction_runner';

    /**
     * @param EntityManagerInterface $entityManager The manager the chat rows are written through
     * @param ManagerRegistry|null $registry Resets that manager (after a failure with the private manager; another closed manager at a boundary)
     * @param string|null $managerName The registry name of that manager; null for the default one
     * @param bool $privateManager Whether it is ChatKit's private manager
     * @param int|null $retryAfterSeconds Retry-After hint of the TransientStorageFailure; null for none
     * @param LoggerInterface|null $logger Warns when a closed manager is replaced at a boundary
     * @param object|null $hostFlushProbe @deprecated since 2.0, ignored (the 1.39 host-flush probe of the removed shared-manager mode); removed in 3.0
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private bool $privateManager = false,
        private ?int $retryAfterSeconds = 1,
        private ?LoggerInterface $logger = null,
        ?object $hostFlushProbe = null,
    ) {
        unset($hostFlushProbe);
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
        $manager = $this->entityManager;
        // Pending work first, on its own: a failed chat unit never rolls it back (PV-L6, QD-M1).
        $manager->flush();
        if ($this->privateManager) {
            // ChatKit's own manager: transaction-doctrine 1.1's runner (a storage failure closes and replaces it, a
            // refusal leaves it alone or forgets only what the run touched). The application's manager is not involved.
            return DoctrineTransactionRunner::forEntityManager($manager, $this->registry, $this->managerName, $this->retryAfterSeconds)->run($operation);
        }
        // The application's manager (shared): transaction-doctrine's ORM runner would close and reset it (or clear it)
        // after a storage failure, detaching the application's entities behind its back (audit QD-M1). The unit runs in
        // a connection-level transaction instead, and only the ChatKit rows it touched are forgotten.
        $before = $this->chatRows($manager);
        try {
            return DoctrineTransactionRunner::forConnection($manager->getConnection(), $this->retryAfterSeconds)->run(static function () use ($operation, $manager): mixed {
                $result = $operation();
                $manager->flush();

                return $result;
            });
        } catch (Throwable $failure) {
            // A manager the ORM closed in a failed flush stays closed: loud for the application, as in 1.29.
            if ($manager->isOpen()) {
                $this->forgetChatRows($manager, $before);
            }

            throw $failure;
        }
    }

    /**
     * 1.39: reported the application's pending changes before a flush of the shared manager (host-flush probe).
     *
     * @deprecated since 2.0, does nothing (the bundle always writes through its private manager); removed in 3.0
     *
     * @param EntityManagerInterface $entityManager The manager about to be flushed
     */
    public function beforeHostFlush(EntityManagerInterface $entityManager): void
    {
        unset($entityManager);
    }

    /**
     * At a request, message or command boundary: a non-private manager that a failed chat flush closed is replaced, so the
     * next request of a long-running worker starts with an open one. Nothing happens to an open manager, or with the
     * private manager (ChatKit replaces its own).
     */
    public function reset(): void
    {
        if ($this->privateManager || !$this->registry instanceof ManagerRegistry || $this->entityManager->isOpen()) {
            return;
        }
        $this->registry->resetManager($this->managerName);
        $this->logger?->warning('ChatKit: the entity manager closed by a failed chat write was replaced at the request boundary.', ['bundle' => 'nowo_chat_kit']);
    }

    /**
     * The ChatKit rows the manager holds, with the data it knows them by (before a unit of work).
     *
     * @return array<int, array<string, mixed>> object id => original data
     */
    private function chatRows(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = [];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if (ChatKitManagerRegistry::isBundleEntity($class)) {
                foreach ($entities as $entity) {
                    $rows[spl_object_id($entity)] = $unitOfWork->getOriginalEntityData($entity);
                }
            }
        }

        return $rows;
    }

    /**
     * After a failed unit: detaches the ChatKit rows it added, wrote or changed (their database state was rolled back);
     * a ChatKit row the caller held and the unit did not change stays managed, and so does every entity of the
     * application. A later ChatKit call loads its rows again.
     *
     * @param array<int, array<string, mixed>> $before {@see self::chatRows()} before the unit
     */
    private function forgetChatRows(EntityManagerInterface $manager, array $before): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $rows       = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if (ChatKitManagerRegistry::isBundleEntity($class)) {
                foreach ($entities as $entity) {
                    $rows[] = $entity;
                }
            }
        }
        $stale = [];
        $kept  = [];
        foreach (array_filter($rows, static fn (object $entity): bool => ChatKitManagerRegistry::isBundleEntity($entity::class) && $manager->contains($entity)) as $entity) {
            $id = spl_object_id($entity);
            if (!isset($before[$id]) || $before[$id] !== $unitOfWork->getOriginalEntityData($entity) || $this->changed($manager, $entity)) {
                $stale[$id] = $entity;
            } else {
                $kept[$id] = $entity;
            }
        }
        // A kept row that points at a stale one goes too (it would drag it back as a new entity on the next flush).
        do {
            $more = false;
            foreach ($kept as $id => $entity) {
                foreach ($manager->getClassMetadata($entity::class)->associationMappings as $field => $mapping) {
                    $target = $mapping->isToOne() ? $manager->getClassMetadata($entity::class)->getFieldValue($entity, $field) : null;
                    if (is_object($target) && isset($stale[spl_object_id($target)])) {
                        $stale[$id] = $entity;
                        unset($kept[$id]);
                        $more = true;

                        break;
                    }
                }
            }
        } while ($more);
        foreach ($stale as $entity) {
            $manager->detach($entity);
        }
    }

    /**
     * Whether the row has changes the manager would write (the ORM's own comparison with the data it knows it by).
     */
    private function changed(EntityManagerInterface $manager, object $entity): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSet($manager->getClassMetadata($entity::class), $entity);

        return $unitOfWork->getEntityChangeSet($entity) !== [];
    }
}
