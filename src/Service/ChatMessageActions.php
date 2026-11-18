<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Event\ChatConversationMarkedUnreadEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSavedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageUnsavedEvent;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Realtime\ChatRecipients;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReadMarkRepository;
use Nowo\ChatKitBundle\Repository\ChatSavedMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function assert;
use function count;
use function usort;

use const DATE_ATOM;

/**
 * 1.35 (Beacon, owner decision D-20261118-02): the server side of the richer message menu — save for later (and the
 * Saved list), mark unread from here, and message info. Access (the conversation is visible to the user) is the
 * caller's job; every write is one unit of work through ChatKit's runner (REQ-RUNTIME-003), side effects (realtime,
 * events) follow the commit.
 *
 * Stateless: configuration and services only (safe in worker mode without a kernel reset).
 */
final readonly class ChatMessageActions
{
    /** Saved rows per page of the Saved list. */
    public const int SAVED_PAGE = 30;

    private TransactionRunnerInterface $transactions;

    /**
     * @param array{enabled?: bool, max_per_user?: int, dock_entry?: bool} $save `message_menu.save`
     * @param bool $markUnread `message_menu.mark_unread`
     * @param TransactionRunnerInterface|null $transactions ChatKit's runner; null uses the default one
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatSavedMessageRepository $saved,
        private ChatParticipantRepository $participants,
        private ChatMessageRepository $messages,
        private ChatConversationRepository $conversations,
        private ChatReadMarkRepository $marks,
        private ChatReadTimes $readTimes,
        private ChatRecipients $recipients,
        private ChatAccess $access,
        private ChatNotifier $notifier,
        private ?ChatAvatars $avatars = null,
        private ClockInterface $clock = new Clock(),
        private ?EventDispatcherInterface $events = null,
        private array $save = [],
        private bool $markUnread = true,
        ?TransactionRunnerInterface $transactions = null,
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * Whether "Save for later" is offered.
     */
    public function saveEnabled(): bool
    {
        return ($this->save['enabled'] ?? false) === true;
    }

    /**
     * Whether "Mark unread from here" is offered.
     */
    public function markUnreadEnabled(): bool
    {
        return $this->markUnread;
    }

    /**
     * Whether message info is offered.
     */
    public function infoEnabled(): bool
    {
        return $this->readTimes->infoEnabled();
    }

    /**
     * Saves or unsaves a message for the user. Idempotent: saving a saved message (or unsaving one that is not) changes
     * nothing and answers the same state.
     *
     * @param ChatUserInterface $user The user (who may read the conversation)
     * @param ChatMessage $message The message
     * @param bool $saved True to save, false to unsave
     *
     * @throws InvalidArgumentException `not_savable` for lines, bot messages and tombstones; `too_many_saved` over `max_per_user`
     *
     * @return bool Whether the message is saved now
     */
    public function setSaved(ChatUserInterface $user, ChatMessage $message, bool $saved): bool
    {
        $existing = $this->saved->findOneFor($message, $user);
        if ($saved) {
            if ($existing instanceof ChatSavedMessage) {
                return true;
            }
            if (!$message->isPinnable()) {
                throw new InvalidArgumentException('not_savable');
            }
            $max = $this->save['max_per_user'] ?? 500;
            // A soft cap: two saves racing at the limit may both pass (no data is lost; docs/CONCURRENCY.md).
            if ($max > 0 && $this->saved->countFor($user) >= $max) {
                throw new InvalidArgumentException('too_many_saved');
            }
            $this->saved->assertInsertable($user);
        } elseif (!$existing instanceof ChatSavedMessage) {
            return false;
        }
        $now     = $this->clock->now();
        $changed = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($user, $message, $saved, $now, $existing): bool {
            if ($saved) {
                // A save of the same message by another request at the same moment is kept (savepoint insert).
                return $this->saved->claim(ChatKitWrites::attach($this->entityManager, $message), $user, $now);
            }

            // An unsave racing another one deletes nothing the second time.
            return $this->saved->delete($existing);
        });
        if ($changed) {
            $this->notifier->saved($user, $message, $saved);
            $this->events?->dispatch($saved ? new ChatMessageSavedEvent($message, $user) : new ChatMessageUnsavedEvent($message, $user));
        }

        return $saved;
    }

    /**
     * A page of the user's Saved list, newest save first: only messages the user may still read, never deleted ones
     * (rows of a conversation the user lost access to are hidden, not removed: they come back with the access).
     *
     * @param ChatUserInterface $user The user
     * @param int|null $before Saved row id of the previous page's last entry; null for the first page
     *
     * @return array{rows: list<ChatSavedMessage>, next: int|null} The rows and the cursor of the next page (null: no more)
     */
    public function savedPage(ChatUserInterface $user, ?int $before): array
    {
        $rows    = [];
        $cursor  = $before;
        $visible = [];
        // Hidden rows do not shorten a page: read on (bounded) until a page is full or the list ends.
        for ($round = 0; $round < 10 && count($rows) <= self::SAVED_PAGE; ++$round) {
            $batch = $this->saved->page($user, $cursor, self::SAVED_PAGE + 1);
            foreach ($batch as $row) {
                $conversation = $row->getMessage()->getConversation();
                $key          = (string) $conversation->getId();
                $visible[$key] ??= $this->access->canView($conversation, $user);
                if ($visible[$key] && !$row->getMessage()->isDeleted()) {
                    $rows[] = $row;
                }
            }
            if (count($batch) <= self::SAVED_PAGE) {
                $cursor = null;

                break;
            }
            $cursor = (int) $batch[count($batch) - 1]->getId();
        }
        $more = count($rows) > self::SAVED_PAGE || $cursor !== null;
        $rows = array_slice($rows, 0, self::SAVED_PAGE);

        return ['rows' => $rows, 'next' => $more && $rows !== [] ? (int) $rows[count($rows) - 1]->getId() : null];
    }

    /**
     * Which messages of a page the user saved (one query).
     *
     * @param ChatUserInterface $user The user
     * @param list<ChatMessage> $messages The page
     *
     * @return list<string> Uuids of the saved ones
     */
    public function savedUuids(ChatUserInterface $user, array $messages): array
    {
        if (!$this->saveEnabled() || $messages === []) {
            return [];
        }
        $byId = [];
        foreach ($messages as $message) {
            $byId[(int) $message->getId()] = $message->getUuid();
        }

        return array_values(array_map(static fn (int $id): string => $byId[$id], $this->saved->savedAmong($user, array_keys($byId))));
    }

    /**
     * "Mark unread from here": moves the user's read marker to just before the message, so the unread badge comes back.
     * The receipt other people see does not move. Nothing happens when the marker is already before the message.
     *
     * @param ChatUserInterface $user The user (who may read the conversation)
     * @param ChatMessage $message The first message to show as unread
     *
     * @return int|null The read marker now (null: nothing read)
     */
    public function markUnreadFrom(ChatUserInterface $user, ChatMessage $message): ?int
    {
        $conversation = $message->getConversation();
        $before       = $this->messages->findIdBefore($conversation, (int) $message->getId());
        $member       = $this->participants->findOneFor($conversation, $user) instanceof ChatParticipant;
        if (!$member) {
            $this->participants->assertInsertable($user);
        }
        $now                = $this->clock->now();
        [$moved, $readUpTo] = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($user, $conversation, $before, $member, $now): array {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $this->conversations->lockActivity($conversation, $member);
            $participant = $this->participants->claim($conversation, $user, $now);
            $moved       = $participant->markUnreadFrom($before);

            return [$moved, $participant->getLastReadMessageId()];
        });
        if ($moved) {
            $this->notifier->unread($conversation, $user, $readUpTo);
            $this->events?->dispatch(new ChatConversationMarkedUnreadEvent($conversation, $user, $message->getUuid(), $readUpTo));
        }

        return $readUpTo;
    }

    /**
     * Whether the viewer may open the read info of a message: their own message, or any message for a moderator
     * (a support agent outside direct conversations); every message when `own_messages_only` is off.
     *
     * @param ChatUserInterface $viewer The viewer (who may read the conversation)
     * @param ChatMessage $message The message
     */
    public function mayReadInfo(ChatUserInterface $viewer, ChatMessage $message): bool
    {
        if ($message->isDeleted() || $message->getKind() !== ChatMessage::KIND_TEXT) {
            return false;
        }

        return !$this->readTimes->ownMessagesOnly() || $message->isAuthoredBy($viewer) || $this->isModerator($viewer, $message);
    }

    /**
     * Message info: who read the message (with the time, when both the viewer and the reader share read times) in
     * conversations up to `per_person_max_members` people, counts above it; and when it was last edited.
     *
     * @param ChatUserInterface $viewer The viewer ({@see self::mayReadInfo()} said yes)
     * @param ChatMessage $message The message
     *
     * @return array{mode: string, memberCount: int, readCount: int, readTimes: bool, readers: list<array{uuid: string, name: string, initials: string, avatarUrl: string|null, read: bool, readAt: string|null}>, editedAt: string|null}
     */
    public function info(ChatUserInterface $viewer, ChatMessage $message): array
    {
        $conversation = $message->getConversation();
        $author       = $message->getAuthor();
        $people       = array_values(array_filter(
            $this->recipients->of($conversation),
            static fn (ChatUserInterface $user): bool => !$user instanceof UnresolvedChatUser && !ChatUserReferences::same($user, $author),
        ));
        $receipts = [];
        foreach ($author instanceof ChatUserInterface ? $this->participants->findReaders($conversation, $author, 500) : [] as $participant) {
            $receipts[(string) ChatUserReferences::key($participant->getUser())] = $participant;
        }
        $read      = static fn (ChatUserInterface $user): ?ChatParticipant => ($row = $receipts[(string) ChatUserReferences::key($user)] ?? null) instanceof ChatParticipant && (int) $row->getReceiptMessageId() >= (int) $message->getId() ? $row : null;
        $readCount = count(array_filter($people, static fn (ChatUserInterface $user): bool => $read($user) instanceof ChatParticipant));
        $base      = ['memberCount' => count($people), 'readCount' => $readCount, 'editedAt' => $message->getEditedAt()?->format(DATE_ATOM)];
        if (count($people) > $this->readTimes->perPersonMax()) {
            return ['mode' => 'counts', 'readTimes' => false, 'readers' => []] + $base;
        }
        // Reciprocal: a viewer who does not share read times sees nobody's.
        $times = $this->readTimes->timesEnabled() && $this->readTimes->shares($viewer);
        $at    = [];
        if ($times) {
            $sharing = array_filter(array_map($read, $people), fn (?ChatParticipant $row): bool => $row instanceof ChatParticipant && $this->readTimes->shares($row->getUser()));
            $at      = $this->marks->readTimes(array_values(array_map(static fn (ChatParticipant $row): int => (int) $row->getId(), $sharing)), (int) $message->getId());
        }
        $this->avatars?->prime($people);
        $readers = array_map(function (ChatUserInterface $user) use ($read, $at): array {
            $row  = $read($user);
            $time = $row instanceof ChatParticipant ? ($at[(int) $row->getId()] ?? null) : null;

            return [
                'uuid'      => $user->getChatPublicId(),
                'name'      => $user->getChatDisplayName(),
                'initials'  => $user->getChatInitials(),
                'avatarUrl' => $this->avatars?->urlOf($user),
                'read'      => $row instanceof ChatParticipant,
                'readAt'    => $time instanceof DateTimeImmutable ? $time->format(DATE_ATOM) : null,
            ];
        }, $people);
        // Read first (most recent first), then the others by name.
        usort($readers, static fn (array $a, array $b): int => [$b['read'], $b['readAt'] ?? '', $a['name']] <=> [$a['read'], $a['readAt'] ?? '', $b['name']]);

        return ['mode' => 'people', 'readTimes' => $times, 'readers' => $readers] + $base;
    }

    /**
     * The user changed "Share read times": turning it off deletes every stored read time of theirs (one unit of work).
     *
     * @param ChatUserInterface $user The user
     * @param bool $share The new value
     *
     * @return int Read times deleted
     */
    public function shareReadTimesChanged(ChatUserInterface $user, bool $share): int
    {
        if ($share) {
            return 0;
        }

        return (int) ChatKitWrites::runRecovering($this->transactions, $this->entityManager, fn (): int => $this->readTimes->forget($user));
    }

    private function isModerator(ChatUserInterface $viewer, ChatMessage $message): bool
    {
        return !$message->getConversation()->isDirect() && $this->access->isSupportAgent($viewer);
    }
}
