<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;

/**
 * One chat message. `body` keeps the author's Markdown, `bodyHtml` the sanitised render
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

    /**
     * Creates a text message, stamped with a new public uuid.
     *
     * @param ChatConversation $conversation The conversation it belongs to
     * @param ChatUserInterface|null $author The writer; null once the account is deleted
     * @param string $body The author's Markdown
     * @param string $bodyHtml The sanitized HTML render
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     */
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

    /**
     * Creates a buzz (a nudge shown as a system line, with an empty body).
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $author The user who buzzes
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @return self The new, unpersisted message
     */
    public static function buzz(ChatConversation $conversation, ChatUserInterface $author, ?DateTimeImmutable $createdAt = null): self
    {
        $message       = new self($conversation, $author, '', '', $createdAt);
        $message->kind = self::KIND_BUZZ;

        return $message;
    }

    /**
     * Doctrine identifier (also the cursor for paging and read receipts).
     *
     * @return int|null The id; null before the message is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Kind of the message.
     *
     * @return string `text` or `buzz`
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Whether the message is a buzz.
     *
     * @return bool True for a buzz
     */
    public function isBuzz(): bool
    {
        return $this->kind === self::KIND_BUZZ;
    }

    /**
     * The conversation the message belongs to.
     *
     * @return ChatConversation The conversation
     */
    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    /**
     * The writer.
     *
     * @return ChatUserInterface|null The author, or null once the account is deleted
     */
    public function getAuthor(): ?ChatUserInterface
    {
        return $this->author;
    }

    /**
     * Whether the given (persisted) user wrote the message.
     *
     * @param ChatUserInterface $user The user to check
     *
     * @return bool True when the user is the author
     */
    public function isAuthoredBy(ChatUserInterface $user): bool
    {
        return $this->author instanceof ChatUserInterface && $user->getId() !== null && $this->author->getId() === $user->getId();
    }

    /**
     * The author's Markdown (empty for a buzz or a deleted message).
     *
     * @return string The raw text
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The sanitized HTML render of the body.
     *
     * @return string The HTML
     */
    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    /**
     * Creation time.
     *
     * @return DateTimeImmutable When the message was sent
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Time of the last edit.
     *
     * @return DateTimeImmutable|null The edit time, or null when never edited
     */
    public function getEditedAt(): ?DateTimeImmutable
    {
        return $this->editedAt;
    }

    /**
     * Whether the message was edited.
     *
     * @return bool True when edited at least once
     */
    public function isEdited(): bool
    {
        return $this->editedAt instanceof DateTimeImmutable;
    }

    /**
     * Replaces the text and its render and stamps the edit time.
     *
     * @param string $body New Markdown
     * @param string $bodyHtml New sanitized HTML
     * @param DateTimeImmutable|null $at Edit time; null for now
     */
    public function edit(string $body, string $bodyHtml, ?DateTimeImmutable $at = null): void
    {
        $this->body     = $body;
        $this->bodyHtml = $bodyHtml;
        $this->editedAt = $at ?? new DateTimeImmutable();
    }

    /**
     * Whether the author may still edit or delete it (EDIT_WINDOW_SECONDS after creation).
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return bool True inside the window
     */
    public function isWithinEditWindow(DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $this->createdAt->getTimestamp() <= self::EDIT_WINDOW_SECONDS;
    }

    /**
     * Deletion time.
     *
     * @return DateTimeImmutable|null The deletion time, or null when not deleted
     */
    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Whether the message is a tombstone.
     *
     * @return bool True when deleted
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    /**
     * Who deleted the message.
     *
     * @return ChatUserInterface|null The deleter, or null when not deleted or the account is gone
     */
    public function getDeletedBy(): ?ChatUserInterface
    {
        return $this->deletedBy;
    }

    /**
     * Tombstone: the text is gone, the row stays so the thread keeps its shape.
     *
     * @param ChatUserInterface $by The user who deletes it
     * @param DateTimeImmutable|null $at Deletion time; null for now
     */
    public function delete(ChatUserInterface $by, ?DateTimeImmutable $at = null): void
    {
        $this->body      = '';
        $this->bodyHtml  = '';
        $this->deletedBy = $by;
        $this->deletedAt = $at ?? new DateTimeImmutable();
    }

    /**
     * True when a moderator (not the author) removed it ("Removed by a moderator").
     *
     * @return bool True for a moderated deletion
     */
    public function isModerated(): bool
    {
        return $this->isDeleted() && $this->deletedBy instanceof ChatUserInterface && !$this->isAuthoredBy($this->deletedBy);
    }
}
