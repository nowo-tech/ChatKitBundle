<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * 1.27 (private entity manager only, REQ-ARCH-001 rule 6): runs one ChatKit DBAL / bulk DQL write inside a savepoint
 * when a transaction is already open on the connection. A failing ChatKit statement then rolls back to the savepoint
 * only, so it never leaves the application's transaction aborted (PostgreSQL) and the application's own COMMIT keeps
 * its rows. When rolling back to the savepoint fails too (a connection lost, a transaction the server already ended),
 * the ORIGINAL exception is rethrown, never the rollback's.
 *
 * Outside a transaction, on a platform without savepoints, or when the statement goes through the application's manager
 * (the default mode), the write runs exactly as in 1.26.
 *
 * @internal
 */
final class ChatKitSavepoint
{
    /** Prefix of the savepoints the bundle opens (never rows of the private manager's unit of work). */
    public const string PREFIX = 'nowo_chat_kit_';

    /**
     * @template T
     *
     * @param EntityManagerInterface $manager the manager the statement belongs to: only the private manager gets a
     *                                        savepoint, and it records the transaction level the write now waits in
     * @param Closure(): T $write The statement
     *
     * @return T The statement's result
     */
    public static function write(EntityManagerInterface $manager, Closure $write): mixed
    {
        $connection = $manager->getConnection();
        if (!$manager instanceof ChatKitEntityManager || !$connection->isTransactionActive()) {
            return $write();
        }

        $result = self::inSavepoint($connection, $write);
        $manager->noteWrite();

        return $result;
    }

    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private static function inSavepoint(Connection $connection, Closure $write): mixed
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform->supportsSavepoints()) {
            return $write(); // @codeCoverageIgnore
        }

        $name = self::PREFIX . bin2hex(random_bytes(6));
        $connection->createSavepoint($name);
        try {
            $result = $write();
        } catch (Throwable $exception) {
            try {
                $connection->rollbackSavepoint($name);
            } catch (Throwable) {
                // The original failure is what the caller must see (and handle); the rollback's error would hide it.
            }

            throw $exception;
        }
        if ($platform->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($name);
        }

        return $result;
    }
}
