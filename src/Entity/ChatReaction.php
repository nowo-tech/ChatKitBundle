<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;

/**
 * One emoji reaction of one user on one message.
 */
#[ORM\Entity(repositoryClass: ChatReactionRepository::class)]
#[ORM\Table(name: 'nowo_chat_reaction')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_reaction', columns: ['message_id', 'user_id', 'emoji'])]
class ChatReaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at')]
    private DateTimeImmutable $createdAt;

    /**
     * Creates a reaction of a user on a message, now.
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface $user The reacting user
     * @param string $emoji The emoji (one of the offered reactions)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
        #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
        private ChatMessage $message,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        #[ORM\Column(length: 32)]
        private string $emoji,
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Doctrine identifier.
     *
     * @return int|null The id; null before the reaction is persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The message reacted to.
     *
     * @return ChatMessage The message
     */
    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    /**
     * The reacting user.
     *
     * @return ChatUserInterface The user
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * The reaction emoji.
     *
     * @return string The emoji
     */
    public function getEmoji(): string
    {
        return $this->emoji;
    }

    /**
     * When the reaction was added.
     *
     * @return DateTimeImmutable The creation time
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
