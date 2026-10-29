<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;

use function is_string;

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
    public const string KIND_BOT  = 'bot';

    /** 1.10: "X pinned a message" (a system line; `meta.message` = the pinned message uuid). */
    public const string KIND_PIN = 'pin';

    /** 1.11: an automatic reply of its author (shown with an "Automatic reply" marker; never answered automatically). */
    public const string KIND_AUTO = 'auto';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** `text`, `buzz` (a nudge shown as a system line; body empty) or `bot` (support assistant; body is a translation key). */
    #[ORM\Column(length: 16, options: ['default' => 'text'])]
    private string $kind = self::KIND_TEXT;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    /**
     * Bot messages only: how to render the body (`domain` of the key, `params`, optional `topic` key).
     *
     * @var array{domain?: string|null, params?: array<string, string>, topic?: string|null, message?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(name: 'edited_at', nullable: true)]
    private ?DateTimeImmutable $editedAt = null;

    #[ORM\Column(name: 'deleted_at', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'deleted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatUserInterface $deletedBy = null;

    /** 1.10: pinned to the top of its conversation since then (shared by every participant); null = not pinned. */
    #[ORM\Column(name: 'pinned_at', nullable: true)]
    private ?DateTimeImmutable $pinnedAt = null;

    #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
    #[ORM\JoinColumn(name: 'pinned_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?ChatUserInterface $pinnedBy = null;

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
     * Creates the "X pinned a message" line (1.10), translated for each reader by the client.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $author Who pinned
     * @param self $pinned The pinned message
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @return self The new, unpersisted message
     */
    public static function pinLine(ChatConversation $conversation, ChatUserInterface $author, self $pinned, ?DateTimeImmutable $createdAt = null): self
    {
        $message       = new self($conversation, $author, '', '', $createdAt);
        $message->kind = self::KIND_PIN;
        $message->meta = ['message' => $pinned->getUuid()];

        return $message;
    }

    /**
     * Creates an automatic reply of the user (1.11).
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $author Whose automatic reply it is
     * @param string $body The Markdown text
     * @param string $bodyHtml Its sanitized render
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @return self The new, unpersisted message
     */
    public static function autoReply(ChatConversation $conversation, ChatUserInterface $author, string $body, string $bodyHtml, ?DateTimeImmutable $createdAt = null): self
    {
        $message       = new self($conversation, $author, $body, $bodyHtml, $createdAt);
        $message->kind = self::KIND_AUTO;

        return $message;
    }

    /**
     * Whether this is an automatic reply.
     *
     * @return bool True for an automatic reply
     */
    public function isAuto(): bool
    {
        return $this->kind === self::KIND_AUTO;
    }

    /**
     * Whether this is a "pinned a message" line.
     *
     * @return bool True for a pin line
     */
    public function isPinLine(): bool
    {
        return $this->kind === self::KIND_PIN;
    }

    /**
     * Whether people may pin it: a text message, not deleted (lines, bot messages and tombstones are not).
     *
     * @return bool True when it can be pinned
     */
    public function isPinnable(): bool
    {
        return $this->kind === self::KIND_TEXT && !$this->isDeleted();
    }

    /**
     * Pins the message (or unpins it with a null time).
     *
     * @param ChatUserInterface|null $by Who pinned it; null to unpin
     * @param DateTimeImmutable|null $at When; null to unpin
     */
    public function pin(?ChatUserInterface $by, ?DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->pinnedBy = $at instanceof DateTimeImmutable ? $by : null;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->pinnedAt = $at;
    }

    /**
     * When the message was pinned.
     *
     * @return DateTimeImmutable|null The time; null when not pinned
     */
    public function getPinnedAt(): ?DateTimeImmutable
    {
        return $this->pinnedAt;
    }

    /**
     * Who pinned the message.
     *
     * @return ChatUserInterface|null The user; null when not pinned or the account is gone
     */
    public function getPinnedBy(): ?ChatUserInterface
    {
        return $this->pinnedBy;
    }

    /**
     * Uuid of the message a pin line is about.
     *
     * @return string|null The uuid; null for other kinds
     */
    public function getPinnedMessageUuid(): ?string
    {
        $uuid = $this->meta['message'] ?? null;

        return $this->isPinLine() && is_string($uuid) ? $uuid : null;
    }

    /**
     * Creates a support assistant message (no author): `$body` is a translation key in `$domain` rendered for each
     * reader, or plain text when `$domain` is null. A `%topic%` parameter is rendered as the topic label of `$topic`.
     *
     * @param ChatConversation $conversation The support conversation
     * @param string $body Translation key (or plain text when `$domain` is null)
     * @param string|null $domain Translation domain of the key; null for plain text
     * @param array<string, string> $params Translation parameters (`%name%` => value)
     * @param string|null $topic Topic key shown as `%topic%`; null for none
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     *
     * @return self The new, unpersisted message
     */
    public static function bot(ChatConversation $conversation, string $body, ?string $domain, array $params = [], ?string $topic = null, ?DateTimeImmutable $createdAt = null): self
    {
        $message       = new self($conversation, null, $body, '', $createdAt);
        $message->kind = self::KIND_BOT;
        $message->meta = ['domain' => $domain, 'params' => $params, 'topic' => $topic];

        return $message;
    }

    /**
     * Whether the message comes from the support assistant.
     *
     * @return bool True for a bot message
     */
    public function isBot(): bool
    {
        return $this->kind === self::KIND_BOT;
    }

    /**
     * Bot messages only: rendering data (`domain`, `params`, `topic`).
     *
     * @return array{domain?: string|null, params?: array<string, string>, topic?: string|null}|null The data; null for other kinds
     */
    public function getMeta(): ?array
    {
        return $this->meta;
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
     * @return string `text`, `buzz` or `bot`
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
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->body = $body;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->bodyHtml = $bodyHtml;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
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
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->body = '';
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->bodyHtml = '';
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->deletedBy = $by;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
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
