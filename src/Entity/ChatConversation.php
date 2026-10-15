<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;

/**
 * A conversation: one per user pair (direct), one per application channel key and one per user with the shared
 * support inbox. Unique keys enforce those cardinalities in the database.
 */
#[ORM\Entity(repositoryClass: ChatConversationRepository::class)]
#[ORM\Table(name: 'nowo_chat_conversation')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_direct', columns: ['direct_key'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_channel', columns: ['channel_key'])]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conversation_support', columns: ['support_owner_id'])]
#[ORM\Index(name: 'idx_nowo_chat_conversation_activity', columns: ['kind', 'last_message_at'])]
class ChatConversation
{
    use PublicIdTrait;

    public const int CHANNEL_KEY_MAX_LENGTH = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Direct only: "<lower user id>:<higher user id>". */
    #[ORM\Column(name: 'direct_key', length: 41, nullable: true)]
    private ?string $directKey = null;

    /** Channel only: the application key (ChatChannelProviderInterface), e.g. `project:<uuid>`. */
    #[ORM\Column(name: 'channel_key', length: self::CHANNEL_KEY_MAX_LENGTH, nullable: true)]
    private ?string $channelKey = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'support_owner_id', nullable: true, onDelete: 'CASCADE')]
    private ?ChatUserInterface $supportOwner = null;

    #[ORM\Column(name: 'support_status', length: 16, nullable: true, enumType: SupportStatus::class)]
    private ?SupportStatus $supportStatus = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatUserInterface $resolvedBy = null;

    #[ORM\Column(name: 'resolved_at', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Set from creation so list ordering needs no COALESCE and stays indexed. */
    #[ORM\Column(name: 'last_message_at')]
    private \DateTimeImmutable $lastMessageAt;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    private function __construct(
        #[ORM\Column(length: 16, enumType: ConversationKind::class)]
        private ConversationKind $kind,
    ) {
        $this->initUuid();
        $this->createdAt = new \DateTimeImmutable();
        $this->lastMessageAt = $this->createdAt;
    }

    public static function direct(ChatUserInterface $a, ChatUserInterface $b): self
    {
        $conversation = new self(ConversationKind::Direct);
        $conversation->directKey = self::directKeyFor($a, $b);

        return $conversation;
    }

    public static function channel(string $key): self
    {
        if ('' === $key || \strlen($key) > self::CHANNEL_KEY_MAX_LENGTH) {
            throw new \LogicException('A channel key must be 1–120 characters.');
        }
        $conversation = new self(ConversationKind::Channel);
        $conversation->channelKey = $key;

        return $conversation;
    }

    public static function support(ChatUserInterface $owner): self
    {
        $conversation = new self(ConversationKind::Support);
        $conversation->supportOwner = $owner;
        $conversation->supportStatus = SupportStatus::Open;

        return $conversation;
    }

    /** Order-independent key of a user pair; both users must be persisted. */
    public static function directKeyFor(ChatUserInterface $a, ChatUserInterface $b): string
    {
        $ids = [$a->getId(), $b->getId()];
        if (null === $ids[0] || null === $ids[1] || $ids[0] === $ids[1]) {
            throw new \LogicException('A direct conversation needs two different persisted users.');
        }
        sort($ids);

        return $ids[0].':'.$ids[1];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): ConversationKind
    {
        return $this->kind;
    }

    public function isDirect(): bool
    {
        return ConversationKind::Direct === $this->kind;
    }

    public function isChannel(): bool
    {
        return ConversationKind::Channel === $this->kind;
    }

    public function isSupport(): bool
    {
        return ConversationKind::Support === $this->kind;
    }

    public function getDirectKey(): ?string
    {
        return $this->directKey;
    }

    /**
     * Direct only: ids of both users.
     *
     * @return list<int>
     */
    public function directUserIds(): array
    {
        if (null === $this->directKey) {
            return [];
        }

        return array_map('intval', explode(':', $this->directKey));
    }

    /** Direct only: whether the user is one of the pair. */
    public function involvesDirectly(ChatUserInterface $user): bool
    {
        $id = $user->getId();

        return $this->isDirect() && null !== $id && \in_array($id, $this->directUserIds(), true);
    }

    /** Direct only: the other user's id, null when `$user` is not part of the pair. */
    public function peerIdOf(ChatUserInterface $user): ?int
    {
        if (!$this->involvesDirectly($user)) {
            return null;
        }
        [$low, $high] = $this->directUserIds();

        return $low === $user->getId() ? $high : $low;
    }

    public function getChannelKey(): ?string
    {
        return $this->channelKey;
    }

    public function getSupportOwner(): ?ChatUserInterface
    {
        return $this->supportOwner;
    }

    public function getSupportStatus(): ?SupportStatus
    {
        return $this->supportStatus;
    }

    public function isResolved(): bool
    {
        return SupportStatus::Resolved === $this->supportStatus;
    }

    public function resolve(ChatUserInterface $agent): void
    {
        $this->assertSupport();
        $this->supportStatus = SupportStatus::Resolved;
        $this->resolvedBy = $agent;
        $this->resolvedAt = new \DateTimeImmutable();
    }

    public function reopen(): void
    {
        $this->assertSupport();
        $this->supportStatus = SupportStatus::Open;
        $this->resolvedBy = null;
        $this->resolvedAt = null;
    }

    public function getResolvedBy(): ?ChatUserInterface
    {
        return $this->resolvedBy;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function touch(\DateTimeImmutable $at): void
    {
        $this->lastMessageAt = $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function assertSupport(): void
    {
        if (!$this->isSupport()) {
            throw new \LogicException('Only support conversations can be resolved or reopened.');
        }
    }
}
