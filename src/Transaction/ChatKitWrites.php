<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\TransactionContract\TransactionRunnerInterface;

use function in_array;
use function is_scalar;
use function reset;

/**
 * 1.30 (Beacon spec 239): how every ChatKit write unit of work runs. The work goes through ChatKit's
 * {@see ChatKitTransactionRunner} (one transaction), the chat manager is flushed inside it, and side effects (realtime
 * publish, events, push, mails) stay with the caller, after it returned.
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
     * @param TransactionRunnerInterface $runner ChatKit's runner (it recovers the manager after a failure)
     * @param EntityManagerInterface $entityManager The manager the chat rows are written through
     * @param callable(): T $work The unit of work
     *
     * @return T What the work returned
     */
    public static function runRecovering(TransactionRunnerInterface $runner, EntityManagerInterface $entityManager, callable $work): mixed
    {
        return $runner->run(static function () use ($entityManager, $work): mixed {
            $result = $work();
            $entityManager->flush();

            return $result;
        });
    }

    /**
     * The managed instance of a ChatKit row the caller holds: the same object while the manager holds it (always,
     * unless an earlier ChatKit write of the request failed and detached the chat rows); otherwise the row loaded again
     * by id. Anything else (an unsaved object, a host object) is returned unchanged.
     *
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager The chat manager
     * @param T $entity The object the caller holds
     *
     * @throws ChatNotFoundException When the row was deleted meanwhile
     *
     * @return T The instance to write with
     */
    public static function attach(EntityManagerInterface $entityManager, object $entity): object
    {
        $class = ChatKitManagerRegistry::realClass($entity::class);
        if (!ChatKitManagerRegistry::isBundleEntity($class) || $entityManager->contains($entity)) {
            return $entity;
        }
        /** @var class-string $class */
        $metadata = $entityManager->getClassMetadata($class);
        $id       = $metadata->getIdentifierValues($entity);
        if ($id === [] || in_array(null, $id, true)) {
            return $entity;
        }
        /** @var T|null $fresh */
        $fresh = $entityManager->find($metadata->getName(), $id);
        if ($fresh === null) {
            $first = reset($id);

            throw new ChatNotFoundException('row', is_scalar($first) ? (string) $first : '');
        }

        return $fresh;
    }
}
