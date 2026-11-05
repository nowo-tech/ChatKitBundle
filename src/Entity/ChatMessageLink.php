<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Repository\ChatMessageLinkRepository;

/**
 * 1.19: one link shared in a message (a link card, a Markdown link or a bare URL), the index behind the "Links" panel.
 * ChatLinks keeps the rows in step with the message text when it is sent or edited; a deleted or erased message loses
 * them; `nowo:chat:links:backfill` fills the index for messages written before 1.19. Never read without its message
 * (the panel only lists rows of messages that are not deleted, in conversations the reader may open).
 */
#[ORM\Entity(repositoryClass: ChatMessageLinkRepository::class)]
#[ORM\Table(name: 'nowo_chat_message_link')]
#[ORM\Index(name: 'idx_nowo_chat_message_link_msg', columns: ['message_id'])]
class ChatMessageLink
{
    public const string KIND_CARD = 'card';

    public const string KIND_LINK = 'link';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Records one link of a message.
     *
     * @param ChatMessage $message The message
     * @param string $url Normalised URL
     * @param string $host Its lowercase hostname
     * @param string|null $title The card title or the Markdown link text
     * @param string $kind `card` or `link`
     * @param string|null $provider Provider key at indexing time (null: generic)
     * @param DateTimeImmutable $createdAt The message time
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
        #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
        private ChatMessage $message,
        #[ORM\Column(length: 2048)]
        private string $url,
        #[ORM\Column(length: 255)]
        private string $host,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $title,
        #[ORM\Column(length: 8)]
        private string $kind,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $provider,
        #[ORM\Column(name: 'created_at')]
        private DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Doctrine identifier (the panel's paging cursor).
     *
     * @return int|null The id; null before it is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The message the link was shared in.
     *
     * @return ChatMessage The message
     */
    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    /**
     * The normalised URL.
     *
     * @return string The URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * The lowercase hostname.
     *
     * @return string The hostname
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * The card title or the Markdown link text.
     *
     * @return string|null The title, or null for a bare link
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * `card` (a link card) or `link` (a Markdown or bare link).
     *
     * @return string The kind
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * The provider key when it was indexed.
     *
     * @return string|null The key, or null for a generic link
     */
    public function getProvider(): ?string
    {
        return $this->provider;
    }

    /**
     * When the link was shared (the message time).
     *
     * @return DateTimeImmutable The time
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
