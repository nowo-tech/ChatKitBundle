<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Repository\ChatReadMarkRepository;

/**
 * 1.35: when a participant's read receipt reached a message (`message_info.read_times`): one row per advance of the
 * receipt cursor (advances within a minute update the newest row). The time a message was read is the earliest row at
 * or past it. Written only for people who share their read times, in conversations small enough to list people
 * (`message_info.per_person_max_members`); purged with the messages it covers and with the participant row.
 */
#[ORM\Entity(repositoryClass: ChatReadMarkRepository::class)]
#[ORM\Table(name: 'nowo_chat_read_mark')]
#[ORM\Index(name: 'idx_nowo_chat_read_mark_upto', columns: ['participant_id', 'up_to_message_id'])]
#[ORM\Index(name: 'idx_nowo_chat_read_mark_at', columns: ['read_at'])]
class ChatReadMark
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Records that the participant's receipt reached a message.
     *
     * @param ChatParticipant $participant Whose receipt moved
     * @param int $upToMessageId The receipt cursor after the move (no FK: the purge removes the rows itself)
     * @param DateTimeImmutable $readAt When
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatParticipant::class)]
        #[ORM\JoinColumn(name: 'participant_id', nullable: false, onDelete: 'CASCADE')]
        private ChatParticipant $participant,
        #[ORM\Column(name: 'up_to_message_id')]
        private int $upToMessageId,
        #[ORM\Column(name: 'read_at')]
        private DateTimeImmutable $readAt,
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
     * Whose receipt moved.
     *
     * @return ChatParticipant The participant row
     */
    public function getParticipant(): ChatParticipant
    {
        return $this->participant;
    }

    /**
     * The receipt cursor after the move.
     *
     * @return int A message id
     */
    public function getUpToMessageId(): int
    {
        return $this->upToMessageId;
    }

    /**
     * When the receipt reached it.
     *
     * @return DateTimeImmutable The read time
     */
    public function getReadAt(): DateTimeImmutable
    {
        return $this->readAt;
    }

    /**
     * A later advance within the compaction window: this row now covers up to that message, at that time.
     *
     * @param int $upToMessageId The new receipt cursor (never lower)
     * @param DateTimeImmutable $readAt When
     */
    public function extend(int $upToMessageId, DateTimeImmutable $readAt): void
    {
        if ($upToMessageId <= $this->upToMessageId) {
            return;
        }
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->upToMessageId = $upToMessageId;
        // @igor-ignore - Doctrine entity state (one row), not a shared worker service.
        $this->readAt = $readAt;
    }
}
