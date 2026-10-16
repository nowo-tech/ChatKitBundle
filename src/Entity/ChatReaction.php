<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;

/**
 * one emoji reaction of one user on one message.
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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
