<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Closure;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Security\ChatAccess;
use ReflectionProperty;
use SensitiveParameter;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function is_string;

/**
 * 1.24, `doctrine.user_reference: id` only (the extension removes this listener in the default `relation` mode).
 *
 * While metadata loads, every relation of a bundle entity to ChatUserInterface becomes a string column of the same
 * name (`author_id`, `user_id`, …, no foreign key) mapped on the entity's `<relation>Ref` property, with an index where
 * the relation mode had one through its foreign key. After rows load, the users behind those references are loaded in
 * one ChatDirectoryInterface::findByIds() call per batch (through ChatAccess, memoized per request) and set on the
 * entities; a reference the directory no longer knows gives null where the relation is nullable and an
 * UnresolvedChatUser elsewhere.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata, priority: 16)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class UserReferenceListener implements ResetInterface
{
    /**
     * User relations of the bundle entities: class => relation => nullable.
     *
     * @var array<class-string, array<string, bool>>
     */
    public const array RELATIONS = [
        ChatConversation::class     => ['supportOwner' => true, 'resolvedBy' => true, 'assignee' => true],
        ChatConversationLink::class => ['createdBy' => true],
        ChatMessage::class          => ['author' => true, 'deletedBy' => true, 'pinnedBy' => true],
        ChatMessageMention::class   => ['user' => false],
        ChatParticipant::class      => ['user' => false],
        ChatQuickReply::class       => ['user' => false],
        ChatReaction::class         => ['user' => false],
        ChatUserSettings::class     => ['user' => false],
    ];

    /**
     * Indexes the relation mode got from its foreign keys and no mapped index covers (column => index name).
     *
     * @var array<class-string, array<string, string>>
     */
    public const array INDEXES = [
        ChatConversation::class     => ['resolved_by_id' => 'idx_nowo_chat_conv_resolved_by'],
        ChatConversationLink::class => ['created_by_id' => 'idx_nowo_chat_conv_link_by'],
        ChatMessage::class          => ['author_id' => 'idx_nowo_chat_msg_author', 'deleted_by_id' => 'idx_nowo_chat_msg_deleted_by', 'pinned_by_id' => 'idx_nowo_chat_msg_pinned_by'],
        ChatReaction::class         => ['user_id' => 'idx_nowo_chat_reaction_user'],
    ];

    /**
     * 1.27: reference columns that are not relations (class => field => column), mapped in id mode only.
     *
     * @var array<class-string, array<string, string>>
     */
    public const array EXTRA_FIELDS = [
        ChatConversation::class => ['directUserLow' => 'direct_user_low', 'directUserHigh' => 'direct_user_high'],
    ];

    /** @var WeakMap<object, true> Entities whose users are set already. */
    private WeakMap $resolved;

    /**
     * @param Closure(): ChatAccess $access Lazy ChatAccess (it depends on the host's directory, which may need the entity manager)
     * @param string $secret Key of the UnresolvedChatUser public ids (`kernel.secret`)
     */
    public function __construct(
        private readonly Closure $access,
        #[SensitiveParameter]
        private readonly string $secret = '',
        private readonly ChatUserReferences $refs = new ChatUserReferences(ChatUserReferences::ID),
    ) {
        $this->resolved = new WeakMap();
    }

    /**
     * Forgets which entities were resolved (worker mode: nothing outlives the request).
     */
    public function reset(): void
    {
        // @igor-ignore Cleared at every request boundary (ChatRequestBoundaryListener) and by the services resetter.
        $this->resolved = new WeakMap();
    }

    /**
     * Replaces the user relations of a bundle entity with string reference columns.
     *
     * @param LoadClassMetadataEventArgs $event Doctrine metadata event
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        $class    = $metadata->getName();
        if (!isset(self::RELATIONS[$class])) {
            return;
        }
        $table = $metadata->table;
        foreach (array_keys(self::RELATIONS[$class]) as $relation) {
            $column   = $metadata->getSingleAssociationJoinColumnName($relation);
            $nullable = $metadata->associationMappings[$relation]->joinColumns[0]->nullable ?? true;
            unset($metadata->associationMappings[$relation]);
            $metadata->mapField([
                'fieldName'  => $relation . 'Ref',
                'columnName' => $column,
                'type'       => 'string',
                'length'     => ChatUserReferences::REFERENCE_LENGTH,
                'nullable'   => $nullable,
            ]);
            $index = self::INDEXES[$class][$column] ?? null;
            if ($index !== null) {
                $table['indexes'][$index] = ['columns' => [$column]];
            }
        }
        // 1.27: the two references of a direct pair of string references (ChatUserReferenceInterface); `string` mode only,
        // so an integer-id host in `id` mode needs no new column (audit OJ-M3).
        foreach ($this->refs->isStringMode() ? self::EXTRA_FIELDS[$class] ?? [] : [] as $field => $column) {
            $metadata->mapField([
                'fieldName'  => $field,
                'columnName' => $column,
                'type'       => 'string',
                'length'     => ChatUserReferences::REFERENCE_LENGTH,
                'nullable'   => true,
            ]);
        }
        $metadata->setPrimaryTable($table);
    }

    /**
     * 1.27 (audit OJ-L1): a new chat row may only point at users of the mode's kind (string references in `string`
     * mode, integer ids in `id` mode), so a reference `"42"` never meets the integer id 42.
     *
     * @param PrePersistEventArgs $event Doctrine persist event
     */
    public function prePersist(PrePersistEventArgs $event): void
    {
        $this->checkKinds($event->getObject());
    }

    /**
     * Same check when a user field of a chat row changes (assignee, resolver, pinner, …).
     *
     * @param PreUpdateEventArgs $event Doctrine update event
     */
    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $this->checkKinds($event->getObject());
    }

    private function checkKinds(object $entity): void
    {
        foreach (array_keys(self::RELATIONS[$entity::class] ?? []) as $relation) {
            $property = $this->property($entity::class, $relation);
            $user     = $property->isInitialized($entity) ? $property->getValue($entity) : null;
            if ($user instanceof ChatUserInterface) {
                $this->refs->assertKind($user);
            }
        }
    }

    /**
     * Sets the users of a loaded bundle entity — and of every other bundle entity of the same batch — from their
     * references, in one directory call.
     *
     * @param PostLoadEventArgs $event Doctrine load event
     */
    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!isset(self::RELATIONS[$entity::class]) || !$this->needsUsers($entity)) {
            return;
        }
        $pending = $this->pending($event->getObjectManager(), $entity);
        $refs    = [];
        foreach ($pending as $entity) {
            foreach (array_keys(self::RELATIONS[$entity::class]) as $relation) {
                $reference = $this->property($entity::class, $relation . 'Ref')->getValue($entity);
                if (is_string($reference)) {
                    $refs[$reference] = $reference;
                }
            }
        }
        // 1.27: string references too (ChatUserReferenceInterface); ChatAccess re-checks every answer byte for byte.
        $users = $refs === [] ? [] : ($this->access)()->usersByIds(array_values(array_map(strval(...), $refs)));
        foreach ($pending as $entity) {
            foreach (self::RELATIONS[$entity::class] as $relation => $nullable) {
                $reference = $this->property($entity::class, $relation . 'Ref')->getValue($entity);
                $user      = is_string($reference) ? ($users[$reference] ?? null) : null;
                if (!$user instanceof ChatUserInterface && is_string($reference) && !$nullable) {
                    $user = new UnresolvedChatUser($reference, $this->secret);
                }
                $this->property($entity::class, $relation)->setValue($entity, $user);
            }
            // @igor-ignore A WeakMap of the request's entities, cleared by reset().
            $this->resolved[$entity] = true;
        }
    }

    /**
     * The loaded entity and every other initialized bundle entity of the unit of work whose users are not set yet.
     *
     * @return list<object>
     */
    private function pending(EntityManagerInterface $entityManager, object $entity): array
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $pending    = [$entity];
        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            if (!isset(self::RELATIONS[$class])) {
                continue;
            }
            foreach ($entities as $other) {
                if ($other !== $entity && !$unitOfWork->isUninitializedObject($other) && $this->needsUsers($other)) {
                    $pending[] = $other;
                }
            }
        }

        return $pending;
    }

    /**
     * Whether a loaded entity still waits for its users: not resolved by this listener yet, and a relation is unset (a
     * row just loaded) or null while its reference is not (entities created in this request carry their users).
     */
    private function needsUsers(object $entity): bool
    {
        if (isset($this->resolved[$entity])) {
            return false;
        }
        foreach (array_keys(self::RELATIONS[$entity::class]) as $relation) {
            $property = $this->property($entity::class, $relation);
            if (!$property->isInitialized($entity) || ($property->getValue($entity) === null && $this->property($entity::class, $relation . 'Ref')->getValue($entity) !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string $class
     */
    private function property(string $class, string $name): ReflectionProperty
    {
        return new ReflectionProperty($class, $name);
    }

    /**
     * Whether the entity's users were set by this listener (tests and diagnostics).
     *
     * @param object $entity A bundle entity
     *
     * @return bool True once resolved
     */
    public function isResolved(object $entity): bool
    {
        return isset($this->resolved[$entity]);
    }

    /**
     * Metadata of a bundle class in id mode has no relation to ChatUserInterface left (tests and diagnostics).
     *
     * @param ClassMetadata<object> $metadata The class metadata
     *
     * @return list<string> The reference fields of the class
     */
    public static function referenceFields(ClassMetadata $metadata): array
    {
        return array_values(array_filter($metadata->getFieldNames(), static fn (string $field): bool => str_ends_with($field, 'Ref')));
    }
}
