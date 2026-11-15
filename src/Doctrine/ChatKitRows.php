<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

use function array_filter;
use function bin2hex;
use function count;
use function is_object;
use function random_bytes;
use function reset;

/**
 * 1.30 (Beacon spec 239, audit QD-M2): writes the row of a new ChatKit entity whose unique key another request may take
 * at the same moment (a participant, a reaction, a direct conversation), through DBAL inside a savepoint. When the unique
 * key refuses it, only the savepoint is rolled back: the caller's transaction, the entity manager and every entity the
 * request holds are untouched, and the caller reads the row the other request wrote. An ORM flush that hits the key
 * would close the entity manager instead.
 *
 * The entity is never managed: the caller loads the stored row afterwards. Its columns come from the mapping (fields,
 * enums, owning to-one associations), as the unit of work would write them; ids from a sequence are asked first.
 *
 * Inside a transaction only (the units of work of the ChatKit services). Stateless.
 *
 * @internal
 */
final class ChatKitRows
{
    /** Prefix of the savepoints: never a level of the private manager's unit of work. */
    private const string SAVEPOINT = ChatKitSavepoint::PREFIX . 'row_';

    /**
     * @param EntityManagerInterface $entityManager The manager the chat rows go through
     * @param object $entity A new ChatKit entity (not persisted)
     *
     * @return UniqueConstraintViolationException|null What refused the row (another request wrote it first); null when
     *                                                 this call wrote it
     */
    public static function insert(EntityManagerInterface $entityManager, object $entity): ?UniqueConstraintViolationException
    {
        $metadata   = $entityManager->getClassMetadata($entity::class);
        $connection = $entityManager->getConnection();
        $row        = [];
        $types      = [];
        $generator  = $metadata->idGenerator;
        if (!$generator->isPostInsertGenerator()) {
            $metadata->setIdentifierValues($entity, [$metadata->getSingleIdentifierFieldName() => $generator->generateId($entityManager, $entity)]);
        }
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if ($generator->isPostInsertGenerator() && $metadata->isIdentifier($field)) {
                continue;
            }
            // An enum field reads as its backed value (the ORM's enum reflection property).
            $row[$mapping->columnName]   = $metadata->getFieldValue($entity, $field);
            $types[$mapping->columnName] = $mapping->type;
        }
        foreach (array_filter($metadata->associationMappings, static fn (object $mapping): bool => $mapping instanceof ToOneOwningSideMapping) as $field => $mapping) {
            $target = $metadata->getFieldValue($entity, $field);
            $ids    = is_object($target) ? $entityManager->getClassMetadata($target::class)->getIdentifierValues($target) : [];
            foreach ($mapping->joinColumns as $joinColumn) {
                $row[$joinColumn->name] = count($ids) === 1 ? reset($ids) : ($ids[$joinColumn->referencedColumnName] ?? null);
            }
        }

        $savepoint = self::SAVEPOINT . bin2hex(random_bytes(4));
        $connection->createSavepoint($savepoint);
        try {
            $connection->insert($metadata->getTableName(), $row, $types);
        } catch (UniqueConstraintViolationException $lost) {
            $connection->rollbackSavepoint($savepoint);
            $connection->releaseSavepoint($savepoint);

            return $lost;
        }
        $connection->releaseSavepoint($savepoint);
        if ($entityManager instanceof ChatKitEntityManager) {
            // The private manager's row was written beside its unit of work, at this transaction level.
            $entityManager->noteWrite();
        }

        return null;
    }
}
