<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;

use function array_chunk;
use function array_diff;
use function array_keys;
use function array_map;
use function in_array;
use function is_object;
use function spl_object_id;
use function str_starts_with;

/**
 * 2.0: keeps ChatKit's private entity manager in step with its own DQL bulk writes. A bulk DELETE or UPDATE bypasses
 * the unit of work: without this, a row the statement deleted stays managed (a later `find()` in the same request, or
 * in the next request of a FrankenPHP worker with the kernel reset off, answers the deleted instance) and a row it
 * updated keeps its old values in memory.
 *
 * - {@see self::forgetGone()}: detaches the managed instances whose rows no longer exist (one `SELECT id … IN` per class
 *   that has managed instances; nothing when the manager holds none), and releases the managed rows that point at them:
 *   a nullable pointer becomes null in memory and in the unit of work's original data (the database set it to null
 *   already: SET NULL or the statement did it), a row whose required pointer leads to a gone row is gone too.
 * - {@see self::refresh()} / {@see self::refreshManaged()}: read again the managed instances a bulk UPDATE changed.
 *
 * Never clears the manager: unrelated managed rows (and any pending change of theirs) stay. Stateless.
 *
 * @internal
 */
final class ChatKitManagedRows
{
    /** Every ChatKit row a conversation, a message or a user can take with it. */
    public const array ALL = [
        ChatConversation::class,
        ChatMessage::class,
        ChatParticipant::class,
        ChatReadMark::class,
        ChatConversationLink::class,
        ChatMessageMention::class,
        ChatReaction::class,
        ChatSavedMessage::class,
        ChatMessageLink::class,
        ChatMessageRevision::class,
        ChatThreadRead::class,
        ChatIdempotencyKey::class,
        ChatQuickReply::class,
        ChatUserSettings::class,
    ];

    /** The rows that hang on a conversation (what deleting one takes with it). */
    public const array CONVERSATION_ROWS = [
        ChatConversation::class,
        ChatMessage::class,
        ChatParticipant::class,
        ChatReadMark::class,
        ChatConversationLink::class,
        ChatMessageMention::class,
        ChatReaction::class,
        ChatSavedMessage::class,
        ChatMessageLink::class,
        ChatMessageRevision::class,
        ChatThreadRead::class,
    ];

    private const int CHUNK = 500;

    /**
     * Detaches the managed instances of these classes whose rows are gone, then releases the managed rows that point
     * at them.
     *
     * @param EntityManagerInterface $entityManager ChatKit's manager
     * @param list<class-string> $classes The classes a bulk DELETE may have removed rows of
     *
     * @return int The instances detached
     */
    public static function forgetGone(EntityManagerInterface $entityManager, array $classes): int
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $gone       = [];
        foreach ($classes as $class) {
            $metadata = $entityManager->getClassMetadata($class);
            $held     = $unitOfWork->getIdentityMap()[$metadata->rootEntityName] ?? [];
            if ($held === []) {
                continue;
            }
            $ids      = array_map('strval', array_keys($held));
            $existing = [];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach ($entityManager->createQueryBuilder()->select('x.id')->from($class, 'x')->where('x.id IN (:ids)')
                    ->setParameter('ids', $chunk)->getQuery()->getSingleColumnResult() as $id) {
                    $existing[] = (string) $id;
                }
            }
            foreach (array_diff($ids, $existing) as $id) {
                $gone[] = $held[$id];
            }
        }

        return self::forget($entityManager, $gone);
    }

    /**
     * Detaches these instances (rows the caller deleted) and releases the managed rows that point at them.
     *
     * @param EntityManagerInterface $entityManager ChatKit's manager
     * @param list<object> $gone Instances whose rows no longer exist
     *
     * @return int The instances detached (the rows that pointed at them with a required pointer included)
     */
    public static function forget(EntityManagerInterface $entityManager, array $gone): int
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $detached   = 0;
        while ($gone !== []) {
            $ids = [];
            foreach ($gone as $entity) {
                $ids[spl_object_id($entity)] = true;
                if ($entityManager->contains($entity)) {
                    $entityManager->detach($entity);
                    ++$detached;
                }
            }
            $gone = [];
            foreach ($unitOfWork->getIdentityMap() as $root => $entities) {
                if (!str_starts_with($root, 'Nowo\\ChatKitBundle\\Entity\\')) {
                    continue;
                }
                $metadata = $entityManager->getClassMetadata($root);
                foreach ($entities as $entity) {
                    if ($unitOfWork->isUninitializedObject($entity)) {
                        continue;
                    }
                    foreach ($metadata->associationMappings as $field => $mapping) {
                        // ChatKit's associations are all owning to-one sides (no collections).
                        $target = $mapping instanceof ToOneOwningSideMapping ? $metadata->getFieldValue($entity, $field) : null;
                        if (!$mapping instanceof ToOneOwningSideMapping || !is_object($target) || !isset($ids[spl_object_id($target)])) {
                            continue;
                        }
                        $nullable = !in_array(false, array_map(static fn (JoinColumnMapping $column): bool => $column->nullable !== false, $mapping->joinColumns), true);
                        if (!$nullable) {
                            // A required pointer to a gone row: this row went with it (FK cascade or the same statement).
                            $gone[] = $entity;

                            break;
                        }
                        $metadata->setFieldValue($entity, $field, null);
                        $unitOfWork->setOriginalEntityProperty(spl_object_id($entity), $field, null);
                    }
                }
            }
        }

        return $detached;
    }

    /**
     * Reads again the managed instances of these rows (a bulk UPDATE changed them); rows the manager does not hold are
     * not loaded.
     *
     * @param EntityManagerInterface $entityManager ChatKit's manager
     * @param class-string $class The entity class
     * @param iterable<int|string> $ids Row ids
     */
    public static function refresh(EntityManagerInterface $entityManager, string $class, iterable $ids): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ($ids as $id) {
            $row = $unitOfWork->tryGetById($id, $class);
            if (is_object($row)) {
                $entityManager->refresh($row);
            }
        }
    }

    /**
     * Reads again every managed instance of the class that `$filter` accepts (a bulk UPDATE by condition changed them).
     *
     * @template T of object
     *
     * @param EntityManagerInterface $entityManager ChatKit's manager
     * @param class-string<T> $class The entity class
     * @param (callable(T): bool)|null $filter Which instances to read again; null for all
     */
    public static function refreshManaged(EntityManagerInterface $entityManager, string $class, ?callable $filter = null): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ($unitOfWork->getIdentityMap()[$entityManager->getClassMetadata($class)->rootEntityName] ?? [] as $row) {
            /** @var T $row */
            if (!$unitOfWork->isUninitializedObject($row) && ($filter === null || $filter($row))) {
                $entityManager->refresh($row);
            }
        }
    }
}
