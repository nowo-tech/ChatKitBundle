<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;

/**
 * one chat message. `body` keeps the author's Markdown, `bodyHtml` the sanitised render
 * (ChatMarkdownRenderer); a deleted message keeps its row as a tombstone (body cleared).
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'nowo_chat_message')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_message_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_nowo_chat_message_unread', columns: ['conversation_id', 'id', 'author_id', 'deleted_at'])]
#[ORM\Index(name: 'idx_nowo_chat_message_created', columns: ['created_at'])]
class ChatMessage
{
    use PublicIdTrait;

    /** Composer limit, enforced again on the server. */
    public const int MAX_LENGTH = 4000;

    /** The author may edit or delete for 15 minutes. */
    public const int EDIT_WINDOW_SECONDS = 900;

    public const string KIND_TEXT = 'text';
    public const string KIND_BUZZ = 'buzz';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `text`, or `buzz` (a nudge shown as a system line; body empty). */
    #[ORM\Column(length: 16, options: ['default' => 'text'])]
    private string $kind = self::KIND_TEXT;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'edited_at', nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    #[ORM\Column(name: 'deleted_at', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'deleted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatUserInterface $deletedBy = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
        #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
        private ChatConversation $conversation,
        /** Null once the author's account is deleted ("Deleted user"). */
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
        private ?ChatUserInterface $author,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(name: 'body_html', type: 'text')]
        private string $bodyHtml,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->initUuid();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public static function buzz(ChatConversation $conversation, ChatUserInterface $author, ?DateTimeImmutable $createdAt = null): self
    {
        $message       = new self($conversation, $author, '', '', $createdAt);
        $message->kind = self::KIND_BUZZ;

        return $message;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function isBuzz(): bool
    {
        return $this->kind === self::KIND_BUZZ;
    }

    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    public function getAuthor(): ?ChatUserInterface
    {
        return $this->author;
    }

    public function isAuthoredBy(ChatUserInterface $user): bool
    {
        return $this->author instanceof ChatUserInterface && $user->getId() !== null && $this->author->getId() === $user->getId();
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditedAt(): ?DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function isEdited(): bool
    {
        return $this->editedAt instanceof DateTimeImmutable;
    }

    public function edit(string $body, string $bodyHtml, ?DateTimeImmutable $at = null): void
    {
        $this->body     = $body;
        $this->bodyHtml = $bodyHtml;
        $this->editedAt = $at ?? new DateTimeImmutable();
    }

    public function isWithinEditWindow(DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $this->createdAt->getTimestamp() <= self::EDIT_WINDOW_SECONDS;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    public function getDeletedBy(): ?ChatUserInterface
    {
        return $this->deletedBy;
    }

    /** Tombstone: the text is gone, the row stays so the thread keeps its shape. */
    public function delete(ChatUserInterface $by, ?DateTimeImmutable $at = null): void
    {
        $this->body      = '';
        $this->bodyHtml  = '';
        $this->deletedBy = $by;
        $this->deletedAt = $at ?? new DateTimeImmutable();
    }

    /** True when a moderator (not the author) removed it ("Removed by a moderator"). */
    public function isModerated(): bool
    {
        return $this->isDeleted() && $this->deletedBy instanceof ChatUserInterface && !$this->isAuthoredBy($this->deletedBy);
    }
}
