<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;

/**
 * One @mention of a conversation member in a message (ChatMentions keeps the rows in step with the message text).
 * Indexed by user for "messages that mention me"; `read_at` is set when the user reads the conversation past the
 * message or marks the mention read.
 */
#[ORM\Entity(repositoryClass: ChatMessageMentionRepository::class)]
#[ORM\Table(name: 'nowo_chat_message_mention')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_mention', columns: ['message_id', 'user_id'])]
#[ORM\Index(name: 'idx_nowo_chat_mention_user', columns: ['user_id', 'read_at', 'created_at'])]
class ChatMessageMention
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'read_at', nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    /**
     * Records that the message mentions the user.
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user The mentioned conversation member
     * @param DateTimeImmutable|null $createdAt Mention time (the message time); null for now
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
        #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
        private ChatMessage $message,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null before the mention is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The message that mentions the user.
     *
     * @return ChatMessage The message
     */
    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    /**
     * The mentioned user.
     *
     * @return ChatUserInterface The user
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * When the user was mentioned.
     *
     * @return DateTimeImmutable The mention time
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * When the user read the mention.
     *
     * @return DateTimeImmutable|null The read time, or null while unread
     */
    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    /**
     * Whether the user has not read the mention yet.
     *
     * @return bool True while unread
     */
    public function isUnread(): bool
    {
        return !$this->readAt instanceof DateTimeImmutable;
    }

    /**
     * Marks the mention read (keeps the first read time).
     *
     * @param DateTimeImmutable|null $at Read time; null for now
     */
    public function markRead(?DateTimeImmutable $at = null): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->readAt ??= $at ?? new DateTimeImmutable();
    }
}
