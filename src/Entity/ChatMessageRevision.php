<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Repository\ChatMessageRevisionRepository;

/**
 * 1.36: a previous text of an edited message (`message_menu.edit_history`): the text it had before the edit and when
 * it was replaced. Only the author and moderators read the texts; the times are part of message info. The rendered
 * HTML is not stored (rendered for the reader at read time). The rows go with the message (purge, deletion, erasure).
 */
#[ORM\Entity(repositoryClass: ChatMessageRevisionRepository::class)]
#[ORM\Table(name: 'nowo_chat_message_revision')]
#[ORM\Index(name: 'idx_nowo_chat_revision_message', columns: ['message_id', 'id'])]
class ChatMessageRevision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Records the text a message had before an edit.
     *
     * @param ChatMessage $message The edited message
     * @param string $body Its text before the edit (Markdown)
     * @param DateTimeImmutable $editedAt When the text was replaced
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
        #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
        private ChatMessage $message,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(name: 'edited_at')]
        private DateTimeImmutable $editedAt,
    ) {
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null before the row is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The edited message.
     *
     * @return ChatMessage The message
     */
    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    /**
     * The text before the edit.
     *
     * @return string Markdown
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * When the text was replaced.
     *
     * @return DateTimeImmutable The edit time
     */
    public function getEditedAt(): DateTimeImmutable
    {
        return $this->editedAt;
    }
}
