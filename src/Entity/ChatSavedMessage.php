<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatSavedMessageRepository;

/**
 * 1.35: one message a user saved for later (`message_menu.save`). The row goes with the message (purge, deletion of
 * the conversation) and with the user's erasure; the Saved list hides it while the user may not read the conversation.
 */
#[ORM\Entity(repositoryClass: ChatSavedMessageRepository::class)]
#[ORM\Table(name: 'nowo_chat_saved_message')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_saved_message', columns: ['user_id', 'message_id'])]
#[ORM\Index(name: 'idx_nowo_chat_saved_message_list', columns: ['user_id', 'saved_at', 'id'])]
class ChatSavedMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'saved_at')]
    private DateTimeImmutable $savedAt;

    /** `user` as a reference (`doctrine.user_reference: id` / `string` maps this instead of the relation). */
    private ?string $userRef;

    /**
     * Saves a message for a user.
     *
     * @param ChatMessage $message The saved message
     * @param ChatUserInterface $user Who saved it
     * @param DateTimeImmutable|null $savedAt Save time; null for now
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
        #[ORM\JoinColumn(name: 'message_id', nullable: false, onDelete: 'CASCADE')]
        private ChatMessage $message,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        ?DateTimeImmutable $savedAt = null,
    ) {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->userRef = ChatUserReferences::reference($user);
        $this->savedAt = $savedAt ?? new DateTimeImmutable();
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
     * The saved message.
     *
     * @return ChatMessage The message
     */
    public function getMessage(): ChatMessage
    {
        return $this->message;
    }

    /**
     * Who saved it.
     *
     * @return ChatUserInterface The user
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * The stored reference of `user` (its id as a string), readable without loading the user; null when there is none.
     *
     * @return string|null The reference
     */
    public function getUserReference(): ?string
    {
        return $this->userRef ?? ChatUserReferences::reference($this->user);
    }

    /**
     * When the message was saved.
     *
     * @return DateTimeImmutable The save time
     */
    public function getSavedAt(): DateTimeImmutable
    {
        return $this->savedAt;
    }
}
