<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;

/**
 * 2.7 (spec 259 block B): one file of a chat message, stored through StorageKit (`storage_uuid`, context `chat`, context
 * id = the conversation uuid). The table exists with the mapping whatever `attachments.enabled` says (an empty table
 * while the feature is off).
 *
 * Every write goes through ChatAttachmentRepository (DBAL, compare-and-set on `state` and `state_token`); the entity is
 * the schema and the savepoint insert of a new upload, never a managed row that is changed later. The conversation and
 * message foreign keys are `SET NULL`: a purged message or conversation leaves the row (marked `deleted` in the same
 * unit) until its objects are removed after the commit.
 */
#[ORM\Entity(repositoryClass: ChatAttachmentRepository::class)]
#[ORM\Table(name: 'nowo_chat_attachment')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_attachment_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_attachment_idem', columns: ['uploader_id', 'idempotency_key'])]
#[ORM\Index(name: 'idx_nowo_chat_attachment_msg', columns: ['message_id', 'position'])]
#[ORM\Index(name: 'idx_nowo_chat_attachment_pending', columns: ['state', 'lease_until'])]
#[ORM\Index(name: 'idx_nowo_chat_attachment_drafts', columns: ['uploader_id', 'state', 'message_id'])]
#[ORM\Index(name: 'idx_nowo_chat_attachment_conv', columns: ['conversation_id', 'state', 'id'])]
#[ORM\Index(name: 'idx_nowo_chat_attachment_created', columns: ['created_at'])]
class ChatAttachment
{
    use PublicIdTrait;

    /** Longest idempotency key (the same rule as messages). */
    public const int MAX_KEY_LENGTH = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
    #[ORM\JoinColumn(name: 'message_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatMessage $message = null;

    #[ORM\Column(name: 'conversation_uuid', length: 36)]
    private string $conversationUuid;

    #[ORM\Column(name: 'state', length: 12)]
    private string $state;

    #[ORM\Column(name: 'state_token', length: 32, nullable: true)]
    private ?string $stateToken = null;

    #[ORM\Column(name: 'lease_until', nullable: true)]
    private ?DateTimeImmutable $leaseUntil = null;

    #[ORM\Column(name: 'scan_attempts', type: 'smallint', options: ['default' => 0])]
    private int $scanAttempts = 0;

    #[ORM\Column(name: 'scan_engine', length: 64, nullable: true)]
    private ?string $scanEngine = null;

    #[ORM\Column(name: 'scan_signature', length: 120, nullable: true)]
    private ?string $scanSignature = null;

    #[ORM\Column(name: 'scanned_at', nullable: true)]
    private ?DateTimeImmutable $scannedAt = null;

    #[ORM\Column(name: 'position', type: 'smallint', options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(name: 'attached_at', nullable: true)]
    private ?DateTimeImmutable $attachedAt = null;

    #[ORM\Column(name: 'deleted_at', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /** When the StorageKit objects of a blocked file were removed (null while they are still stored). */
    #[ORM\Column(name: 'objects_removed_at', nullable: true)]
    private ?DateTimeImmutable $objectsRemovedAt = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    /** `uploader` as a reference (`doctrine.user_reference: id` / `string` maps this instead of the relation). */
    private ?string $uploaderRef;

    /**
     * A new upload (`uploaded`, waiting for its scan).
     *
     * @param ChatConversation $conversation Where it was uploaded
     * @param ChatUserInterface $uploader Who uploaded it
     * @param string $storageUuid The StorageKit file
     * @param string $name Sanitized name
     * @param string $extension Lower-case extension
     * @param string $mediaType Allow-listed media type
     * @param string $kind ChatAttachmentKind value
     * @param int $sizeBytes Bytes
     * @param string $sha256 SHA-256 of the bytes
     * @param string|null $idempotencyKey The upload's `Idempotency-Key`
     * @param string|null $requestHash What the key promised (conversation, name, size)
     * @param DateTimeImmutable $createdAt Upload time
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
        #[ORM\JoinColumn(name: 'conversation_id', nullable: true, onDelete: 'SET NULL')]
        private ?ChatConversation $conversation,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'uploader_id', nullable: true, onDelete: 'SET NULL')]
        private ?ChatUserInterface $uploader,
        #[ORM\Column(name: 'storage_uuid', length: 36)]
        private string $storageUuid,
        #[ORM\Column(name: 'name', length: 255)]
        private string $name,
        #[ORM\Column(name: 'extension', length: 16)]
        private string $extension,
        #[ORM\Column(name: 'media_type', length: 127)]
        private string $mediaType,
        #[ORM\Column(name: 'kind', length: 16)]
        private string $kind,
        #[ORM\Column(name: 'size_bytes', type: 'bigint')]
        private int|string $sizeBytes,
        #[ORM\Column(name: 'sha256', length: 64)]
        private string $sha256,
        #[ORM\Column(name: 'idempotency_key', length: self::MAX_KEY_LENGTH, nullable: true)]
        private ?string $idempotencyKey,
        #[ORM\Column(name: 'request_hash', length: 64, nullable: true)]
        private ?string $requestHash,
        DateTimeImmutable $createdAt,
    ) {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->initUuid();
        $this->conversationUuid = (string) $conversation?->getUuid();
        $this->uploaderRef      = ChatUserReferences::reference($uploader);
        $this->state            = ChatAttachmentState::Uploaded->value;
        $this->createdAt        = $createdAt;
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null before the row is written
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The StorageKit file.
     */
    public function getStorageUuid(): string
    {
        return $this->storageUuid;
    }

    /**
     * The conversation uuid (the StorageKit context id).
     */
    public function getConversationUuid(): string
    {
        return $this->conversationUuid;
    }

    /**
     * The stored reference of `uploader` (its id as a string), readable without loading the user; null after an erasure.
     */
    public function getUploaderReference(): ?string
    {
        return $this->uploaderRef;
    }

    /**
     * The life state (ChatAttachmentState value).
     */
    public function getState(): string
    {
        return $this->state;
    }
}
