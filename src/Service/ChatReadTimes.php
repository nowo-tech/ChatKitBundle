<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReadMarkRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;

use function count;

/**
 * 1.35: read times of message info (`message_info.enabled` and `message_info.read_times`). A read time is personal
 * data: it is stored only for people who share their read times (the reciprocal "Share read times" preference, default
 * on), only in conversations small enough for message info to list people (`per_person_max_members`), and it is
 * deleted when the person stops sharing.
 *
 * Stateless (configuration only; the repositories hold the request's memo).
 */
final readonly class ChatReadTimes
{
    /**
     * @param array{enabled?: bool, read_times?: bool, per_person_max_members?: int, own_messages_only?: bool} $messageInfo `message_menu.message_info`
     */
    public function __construct(
        private ChatReadMarkRepository $marks,
        private ChatParticipantRepository $participants,
        private ChatUserSettingsRepository $settings,
        private ChatRecipients $recipients,
        private array $messageInfo = [],
    ) {
    }

    /**
     * Whether message info is offered.
     */
    public function infoEnabled(): bool
    {
        return ($this->messageInfo['enabled'] ?? false) === true;
    }

    /**
     * Whether read times are stored and shown at all.
     */
    public function timesEnabled(): bool
    {
        return $this->infoEnabled() && ($this->messageInfo['read_times'] ?? true) === true;
    }

    /**
     * Participants up to which message info lists people (counts above it).
     */
    public function perPersonMax(): int
    {
        return $this->messageInfo['per_person_max_members'] ?? 20;
    }

    /**
     * Whether read info is limited to the viewer's own messages (moderators see every message's).
     */
    public function ownMessagesOnly(): bool
    {
        return ($this->messageInfo['own_messages_only'] ?? true) === true;
    }

    /**
     * Whether the user shares read times (and so sees other people's): reciprocal.
     *
     * @param ChatUserInterface $user The user
     */
    public function shares(ChatUserInterface $user): bool
    {
        return $this->settings->forUser($user)->isShareReadTimes();
    }

    /**
     * Inside the caller's unit of work (the conversation row locked): stores when the receipt reached a message, when
     * read times are on, the reader shares them and the conversation is small enough.
     *
     * @param ChatParticipant $participant The reader's row (stored)
     * @param ChatUserInterface $reader The reader
     * @param int $receipt The receipt cursor after the move
     * @param DateTimeImmutable $now When
     */
    public function record(ChatParticipant $participant, ChatUserInterface $reader, int $receipt, DateTimeImmutable $now): void
    {
        // The people who may read it besides the reader (the same count message info compares with the size).
        if (!$this->timesEnabled() || !$this->shares($reader) || count($this->recipients->of($participant->getConversation())) - 1 > $this->perPersonMax()) {
            return;
        }
        $this->marks->record($participant, $receipt, $now);
    }

    /**
     * Inside the caller's unit of work: deletes every read time of the user (they stopped sharing, data minimisation).
     *
     * @param ChatUserInterface $user The user
     *
     * @return int Rows deleted
     */
    public function forget(ChatUserInterface $user): int
    {
        return $this->marks->deleteFor($this->participants->idsOfUser($user));
    }
}
