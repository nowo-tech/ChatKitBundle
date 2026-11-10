<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;

/**
 * 1.19: a link pinned to a conversation (a bookmark of a channel or a direct conversation, e.g. the Teams meeting of
 * the team), shown as a chip in the bar under the conversation header. The URL is normalised and validated by
 * ChatLinkUrls before it is stored; the title is optional. Shared content: when its author is erased the link stays and
 * `created_by_id` becomes null.
 */
#[ORM\Entity(repositoryClass: ChatConversationLinkRepository::class)]
#[ORM\Table(name: 'nowo_chat_conversation_link')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_conv_link_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_nowo_chat_conv_link_conv', columns: ['conversation_id'])]
class ChatConversationLink
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(length: 255)]
    private string $host;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $title;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    /** 1.24: `createdBy` as a reference (`doctrine.user_reference: id` maps this instead of the relation). */
    private ?string $createdByRef;

    /**
     * Pins a link to a conversation.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface|null $createdBy Who added it (null once erased)
     * @param string $url Normalised URL (ChatLinkUrls::normalize())
     * @param string $host Its lowercase hostname
     * @param string|null $title Optional title (ChatLinkUrls::title())
     * @param DateTimeImmutable|null $createdAt Creation time; null for now
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
        #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
        private ChatConversation $conversation,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?ChatUserInterface $createdBy,
        string $url,
        string $host,
        ?string $title,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->initUuid();
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->createdByRef = ChatUserReferences::reference($createdBy);
        $this->url          = $url;
        $this->host         = $host;
        $this->title        = $title;
        $this->createdAt    = $createdAt ?? new DateTimeImmutable();
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null before it is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The conversation the link is pinned to.
     *
     * @return ChatConversation The conversation
     */
    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    /**
     * Who added the link.
     *
     * @return ChatUserInterface|null The author, or null once erased
     */
    public function getCreatedBy(): ?ChatUserInterface
    {
        return $this->createdBy;
    }

    /**
     * 1.24: the stored reference of `createdBy` (its id as a string) — what `doctrine.user_reference: id` keeps instead of a
     * relation, readable without loading the user; null when there is none.
     *
     * @return string|null The reference
     */
    public function getCreatedByReference(): ?string
    {
        return $this->createdByRef ?? ChatUserReferences::reference($this->createdBy);
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
     * The lowercase hostname of the URL.
     *
     * @return string The hostname
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * The optional title.
     *
     * @return string|null The title, or null for none
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * When the link was added.
     *
     * @return DateTimeImmutable The creation time
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * When the link was last changed.
     *
     * @return DateTimeImmutable|null The change time, or null when never changed
     */
    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Changes the URL and the title.
     *
     * @param string $url Normalised URL
     * @param string $host Its lowercase hostname
     * @param string|null $title Optional title
     * @param DateTimeImmutable|null $at Change time; null for now
     */
    public function change(string $url, string $host, ?string $title, ?DateTimeImmutable $at = null): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->url = $url;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->host = $host;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->title = $title;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->updatedAt = $at ?? new DateTimeImmutable();
    }
}
