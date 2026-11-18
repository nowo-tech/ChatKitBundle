<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatThreadReadRepository;

/**
 * 1.36: a user's place in a thread (`message_menu.threads`): the newest reply they read, and whether they follow it.
 * The root's author, everyone who replied and everyone a reply mentions follow it; their new replies show as unread on
 * the root. The row goes with the root (purge, deletion) and with the user's erasure.
 */
#[ORM\Entity(repositoryClass: ChatThreadReadRepository::class)]
#[ORM\Table(name: 'nowo_chat_thread_read')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_thread_read', columns: ['root_message_id', 'user_id'])]
class ChatThreadRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'last_read_reply_id', nullable: true)]
    private ?int $lastReadReplyId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $following = true;

    #[ORM\Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    /** `user` as a reference (`doctrine.user_reference: id` / `string` maps this instead of the relation). */
    private ?string $userRef;

    /**
     * Creates the row of a user in a thread.
     *
     * @param ChatMessage $root The root of the thread
     * @param ChatUserInterface $user The user
     * @param DateTimeImmutable $now When
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatMessage::class)]
        #[ORM\JoinColumn(name: 'root_message_id', nullable: false, onDelete: 'CASCADE')]
        private ChatMessage $root,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        DateTimeImmutable $now,
    ) {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->userRef   = ChatUserReferences::reference($user);
        $this->updatedAt = $now;
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
     * The root of the thread.
     */
    public function getRoot(): ChatMessage
    {
        return $this->root;
    }

    /**
     * The user.
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * The stored reference of `user`, readable without loading the user; null when there is none.
     */
    public function getUserReference(): ?string
    {
        return $this->userRef ?? ChatUserReferences::reference($this->user);
    }

    /**
     * The newest reply the user read (null: none).
     */
    public function getLastReadReplyId(): ?int
    {
        return $this->lastReadReplyId;
    }

    /**
     * Whether the user follows the thread (new replies are unread for them).
     */
    public function isFollowing(): bool
    {
        return $this->following;
    }

    /**
     * When the row last changed.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
