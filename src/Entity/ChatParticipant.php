<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;

/**
 * per-user state in a conversation — read cursor (receipts, unread counts) and mute.
 * Direct and support participants are created with the conversation; channel rows lazily on first read.
 * Authorization never relies on this row (ChatVoter re-checks the access policy on every request).
 */
#[ORM\Entity(repositoryClass: ChatParticipantRepository::class)]
#[ORM\Table(name: 'nowo_chat_participant')]
#[ORM\UniqueConstraint(name: 'uniq_nowo_chat_participant', columns: ['conversation_id', 'user_id'])]
#[ORM\Index(name: 'idx_nowo_chat_participant_user', columns: ['user_id'])]
class ChatParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Id of the newest message the user has seen (no FK: purges must not cascade into read state). */
    #[ORM\Column(name: 'last_read_message_id', nullable: true)]
    private ?int $lastReadMessageId = null;

    #[ORM\Column(name: 'muted_until', nullable: true)]
    private ?\DateTimeImmutable $mutedUntil = null;

    #[ORM\Column(name: 'joined_at')]
    private \DateTimeImmutable $joinedAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
        #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
        private ChatConversation $conversation,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
    ) {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    public function getLastReadMessageId(): ?int
    {
        return $this->lastReadMessageId;
    }

    /** The cursor only moves forward (an older "read" from a stale tab is ignored). */
    public function markReadUpTo(int $messageId): bool
    {
        if (null !== $this->lastReadMessageId && $messageId <= $this->lastReadMessageId) {
            return false;
        }
        $this->lastReadMessageId = $messageId;

        return true;
    }

    public function getMutedUntil(): ?\DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    public function isMuted(\DateTimeImmutable $now): bool
    {
        return $this->mutedUntil instanceof \DateTimeImmutable && $this->mutedUntil > $now;
    }

    public function muteUntil(?\DateTimeImmutable $until): void
    {
        $this->mutedUntil = $until;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
