<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;

use function in_array;

/**
 * Per-user state in a conversation — read cursor (receipts, unread counts) and mute.
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
    private ?DateTimeImmutable $mutedUntil = null;

    /** 1.10: the user closed (archived) the conversation in their list at that time; null = in the list. */
    #[ORM\Column(name: 'archived_at', nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    /** 1.11: the user's last automatic reply in this conversation (one per interval). */
    #[ORM\Column(name: 'last_auto_reply_at', nullable: true)]
    private ?DateTimeImmutable $lastAutoReplyAt = null;

    #[ORM\Column(name: 'joined_at')]
    private DateTimeImmutable $joinedAt;

    /**
     * Creates the participant row of a user in a conversation.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The participant
     * @param DateTimeImmutable|null $joinedAt Join time; null for now
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatConversation::class)]
        #[ORM\JoinColumn(name: 'conversation_id', nullable: false, onDelete: 'CASCADE')]
        private ChatConversation $conversation,
        #[ORM\ManyToOne(targetEntity: ChatUserInterface::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private ChatUserInterface $user,
        ?DateTimeImmutable $joinedAt = null,
    ) {
        $this->joinedAt = $joinedAt ?? new DateTimeImmutable();
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
     * The conversation of this row.
     *
     * @return ChatConversation The conversation
     */
    public function getConversation(): ChatConversation
    {
        return $this->conversation;
    }

    /**
     * The participant.
     *
     * @return ChatUserInterface The user
     */
    public function getUser(): ChatUserInterface
    {
        return $this->user;
    }

    /**
     * Id of the newest message the user has seen.
     *
     * @return int|null The read cursor, or null when nothing was read yet
     */
    public function getLastReadMessageId(): ?int
    {
        return $this->lastReadMessageId;
    }

    /**
     * The cursor only moves forward (an older "read" from a stale tab is ignored).
     *
     * @param int $messageId Id of the last message read
     *
     * @return bool True when the cursor moved
     */
    public function markReadUpTo(int $messageId): bool
    {
        if ($this->lastReadMessageId !== null && $messageId <= $this->lastReadMessageId) {
            return false;
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->lastReadMessageId = $messageId;

        return true;
    }

    /**
     * End of the mute.
     *
     * @return DateTimeImmutable|null The mute end, or null when not muted
     */
    public function getMutedUntil(): ?DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    /**
     * Whether the conversation is muted for the user at the given time.
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return bool True while muted
     */
    public function isMuted(DateTimeImmutable $now): bool
    {
        return $this->mutedUntil instanceof DateTimeImmutable && $this->mutedUntil > $now;
    }

    /**
     * Mutes the conversation until the given time; null unmutes.
     *
     * @param DateTimeImmutable|null $until Mute end, or null
     */
    public function muteUntil(?DateTimeImmutable $until): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->mutedUntil = $until;
    }

    /**
     * When the row was created.
     *
     * @return DateTimeImmutable The join time
     */
    public function getJoinedAt(): DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * When the user closed the conversation in their list (null = never, or reopened).
     *
     * @return DateTimeImmutable|null The time
     */
    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    /**
     * Closes (archives) the conversation for this user only, or reopens it with null. Nothing is deleted.
     *
     * @param DateTimeImmutable|null $at When it was closed; null reopens it
     */
    public function archive(?DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->archivedAt = $at;
    }

    /**
     * Whether the conversation stays out of the user's list: closed, and no message since — unless the user muted it
     * (it stays closed) or it is a resolved / closed support ticket (it stays closed whatever arrives).
     *
     * @param DateTimeImmutable $now Current time
     *
     * @return bool True while closed
     */
    public function isArchived(DateTimeImmutable $now): bool
    {
        if (!$this->archivedAt instanceof DateTimeImmutable) {
            return false;
        }
        $conversation = $this->conversation;
        if ($conversation->isSupport() && in_array($conversation->getSupportStatus(), [SupportStatus::Resolved, SupportStatus::Closed], true)) {
            return true;
        }

        // A message newer than the closing (the send also clears the mark; this covers messages from elsewhere).
        return $conversation->getLastMessageAt() <= $this->archivedAt || $this->isMuted($now);
    }

    /**
     * When the user last sent an automatic reply here.
     *
     * @return DateTimeImmutable|null The time
     */
    public function getLastAutoReplyAt(): ?DateTimeImmutable
    {
        return $this->lastAutoReplyAt;
    }

    /**
     * Records an automatic reply sent now.
     *
     * @param DateTimeImmutable $at When it was sent
     */
    public function markAutoReplied(DateTimeImmutable $at): void
    {
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->lastAutoReplyAt = $at;
    }
}
