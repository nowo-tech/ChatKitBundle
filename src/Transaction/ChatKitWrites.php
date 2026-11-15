<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Transaction;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Throwable;

use function in_array;
use function is_object;
use function is_scalar;
use function reset;

/**
 * 1.30 (Beacon spec 239): how every ChatKit write unit of work runs. The work goes through
 * {@see TransactionRunnerInterface::run()} (one transaction; a host may bind a runner that retries), the chat manager is
 * flushed inside it (a host runner backed by another manager does not flush ChatKit's private one), and side effects
 * (realtime publish, events, push, mails) stay with the caller, after run() returned.
 *
 * A runner may replay the work after a transient failure, with the manager cleared or reset: the work receives
 * `$replay = true` from the second attempt on and re-attaches what it captured with {@see self::attach()}. The first
 * attempt runs exactly as before 1.30 (nothing is re-attached).
 *
 * Stateless.
 *
 * @internal
 */
final class ChatKitWrites
{
    /**
     * @template T
     *
     * @param TransactionRunnerInterface $runner The runner the host bound (ChatKit's default otherwise)
     * @param EntityManagerInterface $entityManager The manager the chat rows are written through
     * @param callable(bool): T $work The unit of work; receives true when it runs again
     * @param bool $insertRace Whether a unique key violation (another request inserted the same row first: a
     *                         participant, a reaction, a conversation) runs the work once more, as a replay that
     *                         reads that row; outside a caller's transaction only (inside one, its snapshot may not
     *                         show the row, so the violation surfaces)
     *
     * @throws UniqueConstraintViolationException When the work lost an insert race (after the retry, with `$insertRace`)
     *
     * @return T What the work returned
     */
    public static function runRecovering(TransactionRunnerInterface $runner, EntityManagerInterface $entityManager, callable $work, bool $insertRace = false): mixed
    {
        $attempt = 0;
        $unit    = static function () use ($entityManager, $work, &$attempt): mixed {
            $result = $work(++$attempt > 1);
            $entityManager->flush();

            return $result;
        };
        $outermost = !$entityManager->getConnection()->isTransactionActive();
        try {
            return $runner->run($unit);
        } catch (Throwable $failure) {
            if (!$failure instanceof UniqueConstraintViolationException || !$insertRace || !$outermost) {
                throw $failure;
            }

            // The runner recovered the manager and left nothing pending: the row the other request committed is read
            // on this attempt.
            return $runner->run($unit);
        }
    }

    /**
     * The managed instance of an entity a replayed unit of work captured before the manager was cleared: the same
     * object while the manager holds it; a ChatKit entity is loaded again by id (gone meanwhile: not found); a host
     * entity (the `relation` mode user) becomes the application manager's reference to the same id (the private
     * manager adopts host objects itself at flush). Anything else (an unsaved object, a user that is not an entity of
     * this manager) is returned unchanged.
     *
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager The chat manager
     * @param T $entity The captured object
     *
     * @throws ChatNotFoundException When a ChatKit row was deleted meanwhile
     *
     * @return T The instance to write with
     */
    public static function attach(EntityManagerInterface $entityManager, object $entity): object
    {
        if ($entityManager->contains($entity)) {
            return $entity;
        }
        /** @var class-string $class */
        $class = ChatKitManagerRegistry::realClass($entity::class);
        if ($entityManager->getMetadataFactory()->isTransient($class)) {
            return $entity;
        }
        $metadata = $entityManager->getClassMetadata($class);
        $id       = $metadata->getIdentifierValues($entity);
        if ($id === [] || in_array(null, $id, true)) {
            return $entity;
        }
        if (!ChatKitManagerRegistry::isBundleEntity($class)) {
            if ($entityManager instanceof ChatKitEntityManager) {
                // The private manager adopts host objects itself, at flush (by reference to the same id).
                return $entity;
            }
            /** @var T $reference */
            $reference = $entityManager->getReference($metadata->getName(), $id);

            return $reference;
        }
        /** @var T|null $fresh */
        $fresh = $entityManager->find($metadata->getName(), $id);
        if ($fresh === null) {
            $first = reset($id);

            throw new ChatNotFoundException('row', is_scalar($first) ? (string) $first : '');
        }

        return $fresh;
    }

    /**
     * For a "change the object, then save it" API (settings, quick replies): the object a replay writes. A stored row
     * is loaded again and gets the caller's field values (columns only; the identifier and associations stay as
     * stored), since the replayed attempt's changes were rolled back and the manager cleared. An object that was new
     * before the first attempt is persisted again as it is (it gets a new identifier), with the objects it points to
     * attached again.
     *
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager The chat manager
     * @param T $entity The object the caller changed
     * @param bool $stored Whether its row existed before the first attempt
     *
     * @throws ChatNotFoundException When the stored row was deleted meanwhile
     *
     * @return T The object to persist
     */
    public static function carry(EntityManagerInterface $entityManager, object $entity, bool $stored): object
    {
        if ($entityManager->contains($entity)) {
            return $entity;
        }
        $metadata = $entityManager->getClassMetadata($entity::class);
        if (!$stored) {
            // Persisted again as it is; what it points to (its user, its conversation) is attached again.
            foreach ($metadata->associationMappings as $field => $mapping) {
                $value = $mapping->isToOne() ? $metadata->getFieldValue($entity, $field) : null;
                if (is_object($value)) {
                    $metadata->setFieldValue($entity, $field, self::attach($entityManager, $value));
                }
            }

            return $entity;
        }
        $fresh = self::attach($entityManager, $entity);
        foreach ($metadata->getFieldNames() as $field) {
            if (!$metadata->isIdentifier($field)) {
                $metadata->setFieldValue($fresh, $field, $metadata->getFieldValue($entity, $field));
            }
        }

        return $fresh;
    }
}
