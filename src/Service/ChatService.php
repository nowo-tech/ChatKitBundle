<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\AutoReply\ChatAutoReplier;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitRows;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\UnresolvedChatUser;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageEditedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Event\ChatThreadReplyEvent;
use Nowo\ChatKitBundle\Event\SupportTicketReopenedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketStatusChangedEvent;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRevisionRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatThreadReadRepository;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_map;
use function array_values;
use function assert;
use function count;
use function in_array;
use function is_string;
use function ksort;

/**
 * Conversation lifecycle and messages. Authorization is the caller's job (ChatVoter); this service only keeps
 * the data consistent (one conversation per pair / channel / support owner, participant rows, read cursors,
 * support reopen on a new owner message) and notifies.
 *
 * 1.24: the default implementation of ChatServiceInterface (aliased to it); type-hint the interface in your code.
 *
 * 1.30 (Beacon spec 239, load readiness): every write is one unit of work run through TransactionRunnerInterface
 * (ChatKit's one-attempt default, or the runner the host binds), and the realtime publish, events and automatic replies
 * happen after it committed. A storage failure therefore leaves no half-written message, no transaction open and no
 * closed entity manager; a transient one surfaces as TransientStorageFailure (409 + Retry-After on ChatKit's routes).
 * A conversation's activity is bumped first in the unit of work, under a row lock and only forward, so two messages in
 * the same conversation queue instead of deadlocking. A first opening (a direct conversation, a channel membership, a
 * read cursor) that loses an insert race to another request re-reads the row the other one wrote.
 */
final readonly class ChatService implements ChatServiceInterface
{
    private TransactionRunnerInterface $transactions;

    /**
     * Builds the service from the entity manager, repositories, renderer, notifier and the offered reactions.
     *
     * @param list<string> $reactions emojis offered for reactions (anything else is rejected)
     * @param ClockInterface $clock Clock for every timestamp it writes (conversations, participants, messages, reactions, resolution)
     * @param EventDispatcherInterface|null $events Receives the chat events; null dispatches none
     * @param ChatMentions|null $mentions Validates and stores @mentions; null keeps mention tokens as written
     * @param ChatMessageMentionRepository|null $mentionRows Mention rows (removed with a deleted message, read with the thread)
     * @param ChatReferences|null $references Validates #reference tokens; null keeps them as written
     * @param ChatLinks|null $links 1.19: validates the link cards and keeps the link index; null keeps cards as written and indexes nothing
     * @param TransactionRunnerInterface|null $transactions 1.30: runs every write unit of work; null uses ChatKit's default runner
     * @param ChatReadTimes|null $readTimes 1.35: stores read times for message info; null stores none
     * @param ChatThreadReadRepository|null $threadReads 1.36: thread followers and read cursors; null records none
     * @param ChatMessageRevisionRepository|null $revisions 1.36: previous texts of edited messages; null keeps none
     * @param ChatMenuOptions|null $menuOptions 1.36: whether edit history is kept (`message_menu.edit_history`)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatConversationRepository $conversations,
        private ChatParticipantRepository $participants,
        private ChatReactionRepository $reactionRepository,
        private ChatMarkdownRenderer $markdown,
        private ChatNotifier $notifier,
        private array $reactions = [],
        private ClockInterface $clock = new Clock(),
        private ?EventDispatcherInterface $events = null,
        private ?ChatMentions $mentions = null,
        private ?ChatMessageMentionRepository $mentionRows = null,
        private ?ChatReferences $references = null,
        private int $maxPins = 20,
        private ?ChatMessageRepository $messageRepository = null,
        private ?ChatAutoReplier $autoReplies = null,
        private ?ChatLinks $links = null,
        ?TransactionRunnerInterface $transactions = null,
        private ?ChatReadTimes $readTimes = null,
        private ?ChatThreadReadRepository $threadReads = null,
        private ?ChatMessageRevisionRepository $revisions = null,
        private ?ChatMenuOptions $menuOptions = null,
    ) {
        // 1.30: the container passes the runner bound to TransactionRunnerInterface; direct construction gets the default.
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * Returns the direct conversation of the two users, creating it (with both participant rows) on first use.
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatUserInterface $other The other person
     *
     * @return ChatConversation The direct conversation
     */
    public function openDirect(ChatUserInterface $actor, ChatUserInterface $other): ChatConversation
    {
        $conversation = $this->conversations->findDirect($actor, $other);
        if ($conversation instanceof ChatConversation) {
            return $conversation;
        }

        try {
            return $this->create(fn (ChatUserInterface ...$users): ChatConversation => ChatConversation::direct($users[0], $users[1], $this->clock->now()), [$actor, $other]);
        } catch (UniqueConstraintViolationException $lost) {
            // 1.30: another request opened the pair's conversation at the same moment: open that one.
            return $this->conversations->findDirect($actor, $other) ?? throw $lost;
        }
    }

    /**
     * Returns the channel's conversation (created on first use) and makes sure the actor has a participant row. This is
     * the only place a channel conversation is created: ChatChannelOpeningEvent (1.21) runs before it, in the same
     * transaction, once per attempt, and a channel already in use always opens.
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatChannel $channel The application channel
     *
     * @throws ChatChannelDeniedException When a listener refuses to create it; nothing is written
     * @throws LogicException When the channel key breaks the ChatChannelKey format rule; nothing is written
     *
     * @return ChatConversation The channel conversation
     */
    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversation
    {
        ChatChannelKey::assertValid($channel->key);
        $conversation = $this->conversations->findChannel($channel->key) ?? $this->createChannel($actor, $channel);
        // 1.28: the look-alike refusal before any write. 1.30: one unit of work; a membership another request of the
        // same person wrote first is read instead (the insert runs in a savepoint: nothing else is undone, audit QD-M2).
        $this->checkMember($conversation, $actor);

        return $this->write(function () use ($conversation, $actor): ChatConversation {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $this->participants->claim($conversation, $actor, $this->clock->now());

            return $conversation;
        });
    }

    /**
     * The owner's latest support conversation (created on first use). `$context` (`url`, `label`) records where the
     * problem was reported from, for the agents. Since 1.8 the dock opens support through ChatSupportTickets::open()
     * (active ticket or the intake draft).
     *
     * @param ChatUserInterface $owner The user asking for support
     * @param array{url?: mixed, label?: mixed}|null $context Page the request comes from; null keeps the stored one
     *
     * @return ChatConversation The support thread
     */
    public function openSupport(ChatUserInterface $owner, ?array $context = null): ChatConversation
    {
        $conversation = $this->conversations->findSupportFor($owner)
            ?? $this->create(fn (ChatUserInterface ...$users): ChatConversation => ChatConversation::support($users[0], $this->clock->now()), [$owner]);
        if ($context !== null) {
            $conversation = $this->write(function () use ($conversation, $context): ChatConversation {
                $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
                // @igor-ignore - Doctrine entity (one row), not a shared worker service.
                $conversation->setSupportContext(is_string($context['url'] ?? null) ? $context['url'] : null, is_string($context['label'] ?? null) ? $context['label'] : null);

                return $conversation;
            });
        }

        return $conversation;
    }

    /**
     * Stores a new message (rendered to HTML) with its validated #references (ChatReferences) and its @mentions of conversation members, bumps the conversation
     * activity, moves the support status (the owner's message reopens a resolved ticket and turns a waiting one open;
     * an agent's reply turns an open ticket to waiting for the user), notifies and dispatches ChatMentionEvent when
     * it mentions someone.
     *
     * @param ChatUserInterface $author The writer
     * @param ChatConversation $conversation The conversation
     * @param string $body Raw Markdown text
     *
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link` (1.19: a link card with a link that is not accepted)
     *
     * @return ChatMessage The stored message
     */
    public function send(ChatUserInterface $author, ChatConversation $conversation, string $body): ChatMessage
    {
        return $this->sendWith($author, $conversation, $body);
    }

    /**
     * 1.36: send() with the statements an `Idempotency-Key` adds to its unit of work (post()).
     *
     * @param ChatUserInterface $author The writer
     * @param ChatConversation $conversation The conversation
     * @param string $body Raw Markdown text
     * @param (Closure(): bool)|null $before First statement of the unit; false aborts it (ChatIdempotencyConflict)
     * @param (Closure(list<ChatMessage>): void)|null $after Last statement of the unit
     *
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link`
     *
     * @return ChatMessage The stored message
     *
     * @internal
     */
    public function sendWith(ChatUserInterface $author, ChatConversation $conversation, string $body, ?Closure $before = null, ?Closure $after = null): ChatMessage
    {
        [[$message, $mentioned]] = $this->postAll($author, [new ChatDraft($conversation, $body)], $before, $after);
        // 1.11: automatic replies of the other person (direct) or of the people mentioned (channels), after the commit.
        try {
            $this->autoReplies?->afterSend($message, array_values($mentioned));
        } catch (TransientStorageFailure) {
            // 1.30: the message is committed. Automatic replies are best effort: a transient failure of theirs must not
            // report the sent message as failed (the client would send it again).
        }

        return $message;
    }

    /**
     * 1.36: writes several messages in one unit of work — the main timeline, thread replies (`ChatDraft::$threadRoot`),
     * forwards (`ChatDraft::$forwardOf`: the text is copied as given, nobody is mentioned) — with the same pipeline as
     * send(): mentions, references, links, support status, read cursors; realtime, Web Push and events after the commit.
     * The conversations are locked in id order. `$before` runs first in the unit (an idempotency claim: returning false
     * rolls the unit back and throws ChatIdempotencyConflict), `$after` last with the new messages.
     *
     * A thread reply stays out of the main timeline: it does not move the conversation's activity, the author's read
     * cursor or the closed lists; its root counts it, and its followers (the root's author, the repliers, the people it
     * mentions) are notified. No automatic reply answers a reply or a forward.
     *
     * @param ChatUserInterface $author The writer
     * @param list<ChatDraft> $drafts The messages, in order
     * @param (Closure(): bool)|null $before First statement of the unit; false aborts it
     * @param (Closure(list<ChatMessage>): void)|null $after Last statement of the unit
     *
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link`; nothing is written
     * @throws ChatIdempotencyConflict When `$before` refused the unit; nothing is written
     *
     * @return list<ChatMessage> The stored messages, in order
     *
     * @internal
     */
    public function post(ChatUserInterface $author, array $drafts, ?Closure $before = null, ?Closure $after = null): array
    {
        return array_map(static fn (array $row): ChatMessage => $row[0], $this->postAll($author, $drafts, $before, $after));
    }

    /**
     * A nudge, stored as a `buzz` line so offline recipients see it too.
     *
     * @param ChatUserInterface $author The user who buzzes
     * @param ChatConversation $conversation The conversation
     *
     * @return ChatMessage The stored buzz message
     */
    public function buzz(ChatUserInterface $author, ChatConversation $conversation): ChatMessage
    {
        // 1.28 (audit PV-L7): a look-alike refusal comes before any write, so nothing stays pending.
        $this->checkMember($conversation, $author);
        $now     = $this->clock->now();
        $message = $this->write(function () use ($author, $conversation, $now): ChatMessage {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $this->bump($conversation, $now);
            $participant = $this->participants->claim($conversation, $author, $now);
            $message     = ChatMessage::buzz($conversation, $author, $now);
            $this->entityManager->persist($message);
            $this->store($message, $participant);

            return $message;
        });
        $this->announce($message);

        return $message;
    }

    /**
     * Replaces the message text (re-rendered) and its @mentions, marks it edited, notifies the recipients and
     * dispatches ChatMentionEvent for the people the edit mentions for the first time.
     *
     * @param ChatMessage $message The message to edit
     * @param string $body New raw Markdown text
     *
     * @throws InvalidArgumentException `empty`, `too_long` or `invalid_link`
     */
    public function edit(ChatMessage $message, string $body): void
    {
        $body   = $this->normalize($body);
        $body   = $this->links?->normalizeCards($body) ?? $body;
        $author = $message->getAuthor();
        // A reference the author may no longer open (or a forged one) is kept as plain text.
        $body                       = $author instanceof ChatUserInterface ? ($this->references?->validate($message->getConversation(), $author, $body) ?? $body) : $body;
        [$body, $mentioned, $group] = $author instanceof ChatUserInterface && $this->mentions instanceof ChatMentions
            ? $this->mentions->resolve($message->getConversation(), $author, $body, $message->getMentionAll())
            : [$body, [], null];
        // 1.36: the same text again (a replay, a save without change) is no edit.
        if ($body === $message->getBody()) {
            return;
        }
        $html                                   = $this->markdown->render($body);
        $now                                    = $this->clock->now();
        $history                                = $this->menuOptions?->historyEnabled() === true ? $this->revisions : null;
        [$message, $added, $revision, $changed] = $this->write(function () use ($message, $body, $html, $now, $mentioned, $group, $history): array {
            $message = ChatKitWrites::attach($this->entityManager, $message);
            // The conversation's row first, as every unit of work that writes its rows (one lock order: audit QD-L2).
            $this->conversations->lockActivity($message->getConversation());
            // 1.36: the text another edit committed meanwhile is the previous text (read under the lock).
            $this->entityManager->refresh($message);
            if ($body === $message->getBody() || $message->isDeleted()) {
                return [$message, [], null, false];
            }
            $revision = $history?->record($message, $message->getBody(), $now, $this->menuOptions?->maxRevisions() ?? 20);
            $message->edit($body, $html, $now);
            $message->mentionAll($group);
            $added = $this->mentions?->sync($message, $mentioned) ?? [];
            $this->links?->index($message);

            return [$message, $added, $revision, true];
        });
        if (!$changed) {
            return;
        }
        $this->notifier->messageUpdated($message, $this->reactionsOf($message));
        if ($added !== []) {
            $this->events?->dispatch(new ChatMentionEvent($message, $added, $message->getConversation(), true));
        }
        $this->events?->dispatch(new ChatMessageEditedEvent($message, $revision?->getId()));
    }

    /**
     * Turns the message into a tombstone, removes its reactions, notifies and dispatches ChatMessageDeletedEvent
     * (moderated when the deleter is not the author).
     *
     * @param ChatUserInterface $by The user who deletes it
     * @param ChatMessage $message The message
     */
    public function delete(ChatUserInterface $by, ChatMessage $message): void
    {
        // A deleted message leaves the pinned bar.
        $wasPinned = $message->getPinnedAt() instanceof DateTimeImmutable;
        $now       = $this->clock->now();
        $message   = $this->write(function () use ($by, $message, $now): ChatMessage {
            $message = ChatKitWrites::attach($this->entityManager, $message);
            $this->conversations->lockActivity($message->getConversation());
            $message->delete($by, $now);
            $message->pin(null, null);
            foreach ($this->reactionRepository->findBy(['message' => $message]) as $reaction) {
                $this->entityManager->remove($reaction);
            }
            $this->mentionRows?->removeFor($message);
            // 1.19: a deleted message leaves the Links panel.
            $this->links?->forget($message);
            // 1.36: a deleted message keeps no text (its previous ones go too), and a deleted reply leaves the count.
            $this->revisions?->deleteFor([(int) $message->getId()]);
            $root = $message->getThreadRoot();
            if ($root instanceof ChatMessage) {
                $this->entityManager->flush();
                $this->messageRepository?->countReply($root, -1, null);
                $this->entityManager->refresh($root);
            }

            return $message;
        });
        $this->notifier->messageUpdated($message);
        $root = $message->getThreadRoot();
        if ($root instanceof ChatMessage) {
            $this->notifier->messageUpdated($root, $this->reactionsOf($root));
            $this->notifier->thread($root, $this->threadReads?->followers($root) ?? []);
        }
        if ($wasPinned) {
            $this->notifier->pinsChanged($message->getConversation());
        }
        $this->events?->dispatch(new ChatMessageDeletedEvent($message, $by, $message->isModerated()));
    }

    /**
     * Adds the reaction, or removes it when the user had already reacted with that emoji.
     *
     * @param ChatUserInterface $user The reacting user
     * @param ChatMessage $message The message
     * @param string $emoji One of the offered emojis
     *
     * @throws InvalidArgumentException `reaction` when the emoji is not offered
     *
     * @return array<string, list<string>> the message's reactions after the change
     */
    public function toggleReaction(ChatUserInterface $user, ChatMessage $message, string $emoji): array
    {
        if (!in_array($emoji, $this->reactions, true)) {
            throw new InvalidArgumentException('reaction');
        }
        if (!$this->reactionRepository->findOneFor($message, $user, $emoji) instanceof ChatReaction) {
            // 1.28: refused before any write when the read found a look-alike's reaction (folding user column).
            $this->reactionRepository->assertInsertable($user);
        }
        $now     = $this->clock->now();
        $message = $this->write(function () use ($user, $message, $emoji, $now): ChatMessage {
            $message = ChatKitWrites::attach($this->entityManager, $message);
            $this->conversations->lockActivity($message->getConversation());
            // Read again under the lock: a toggle of the same reaction from another tab is one step, not two.
            $existing = $this->reactionRepository->findOneFor($message, $user, $emoji);
            if ($existing instanceof ChatReaction) {
                $this->entityManager->remove($existing);
            } else {
                // 1.30 (audit QD-M2): a reaction another request added at the same moment is kept (savepoint insert).
                $this->reactionRepository->claim($message, $user, $emoji, $now);
            }

            return $message;
        });
        $reactions = $this->reactionsOf($message);
        $this->notifier->messageUpdated($message, $reactions);

        return $reactions;
    }

    /**
     * Marks the support thread resolved by the agent, notifies and dispatches ChatSupportStatusChangedEvent.
     *
     * @param ChatUserInterface $agent The support agent
     * @param ChatConversation $conversation The support thread
     */
    public function resolveSupport(ChatUserInterface $agent, ChatConversation $conversation): void
    {
        // 1.30 (audit QD-M1): the refusal before the unit of work.
        $this->assertSupport($conversation);
        $now          = $this->clock->now();
        $conversation = $this->write(function () use ($agent, $conversation, $now): ChatConversation {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $conversation->resolve($agent, $now);

            return $conversation;
        });
        $this->notifier->supportStatusChanged($conversation);
        $this->events?->dispatch(new ChatSupportStatusChangedEvent($conversation, $agent));
    }

    /**
     * An agent reopens a resolved support thread (no-op when it is open).
     *
     * @param ChatUserInterface $agent The support agent
     * @param ChatConversation $conversation The support thread
     */
    public function reopenSupport(ChatUserInterface $agent, ChatConversation $conversation): void
    {
        if (!$conversation->isResolved()) {
            return;
        }
        $this->assertSupport($conversation);
        $conversation = $this->write(function () use ($conversation): ChatConversation {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $conversation->reopen();

            return $conversation;
        });
        $this->notifier->supportStatusChanged($conversation);
        $this->events?->dispatch(new ChatSupportStatusChangedEvent($conversation, $agent));
    }

    /**
     * Moves the user's read cursor forward to the message (marking the user's mentions up to it read) and publishes
     * the read receipt when it moved.
     *
     * @param ChatUserInterface $user The reader
     * @param ChatConversation $conversation The conversation
     * @param int $messageId Id of the last message read
     *
     * @return bool True when the cursor moved (it never goes back)
     */
    public function markRead(ChatUserInterface $user, ChatConversation $conversation, int $messageId): bool
    {
        $member = $this->checkMember($conversation, $user);
        $now    = $this->clock->now();
        // 1.30: the cursor and the mention rows it marks read in one unit of work. The conversation's row is locked
        // first, shared (readers do not wait for each other) unless the cursor's row must be created, so it never meets a
        // new message's unit of work in the other order (audit QD-L2).
        // 1.35: the receipt cursor (what other people see) moves only past what they saw already; after "Mark unread from
        // here" a read that catches up again reaches only the reader's own tabs.
        [$moved, $receiptMoved] = $this->write(function () use ($user, $conversation, $messageId, $member, $now): array {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $this->conversations->lockActivity($conversation, $member);
            // Audit TK-7: the participant row is read again under an exclusive lock, so a "mark unread" racing this read
            // in another tab is applied before or after it, never lost.
            $participant = $this->participants->claim($conversation, $user, $now);
            $this->entityManager->refresh($participant, LockMode::PESSIMISTIC_WRITE);
            $receipt = $participant->getReceiptMessageId();
            $moved   = $participant->markReadUpTo($messageId);
            if ($moved) {
                $this->mentionRows?->markReadUpTo($user, $conversation, $messageId, $now);
            }
            $receiptMoved = $moved && ($receipt === null || $messageId > $receipt);
            if ($receiptMoved) {
                $this->readTimes?->record($participant, $user, $messageId, $now);
            }

            return [$moved, $receiptMoved];
        });
        if ($receiptMoved) {
            $this->notifier->read($conversation, $user, $messageId);
        } elseif ($moved) {
            $this->notifier->readOwn($conversation, $user, $messageId);
        }

        return $moved;
    }

    /**
     * Participant row of the user, created on first use (channels, support agents in support threads).
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $user The user
     *
     * @return ChatParticipant The existing or newly persisted (not flushed) row
     */
    public function participant(ChatConversation $conversation, ChatUserInterface $user): ChatParticipant
    {
        $participant = $this->participants->findOneFor($conversation, $user);
        if (!$participant instanceof ChatParticipant) {
            // 1.28: refused before the insert when the read found a look-alike's row (folding user column).
            $this->participants->assertInsertable($user);
            $participant = new ChatParticipant($conversation, $user, $this->clock->now());
            $this->entityManager->persist($participant);
        }

        return $participant;
    }

    /**
     * Pins a message to the top of its conversation (1.10) and posts the "X pinned a message" line, or unpins it.
     * Access (ChatAccess::canPin) is the caller's job.
     *
     * @param ChatUserInterface $user Who pins or unpins
     * @param ChatMessage $message A text message, not deleted
     * @param bool $pinned True to pin, false to unpin
     *
     * @throws InvalidArgumentException `not_pinnable` for lines, bot messages and tombstones; `too_many_pins` over the limit
     */
    public function pin(ChatUserInterface $user, ChatMessage $message, bool $pinned): void
    {
        // 1.36: a thread reply is not pinned to the conversation.
        if (!$message->isPinnable() || $message->getThreadRoot() instanceof ChatMessage) {
            throw new InvalidArgumentException('not_pinnable');
        }
        if ($pinned === ($message->getPinnedAt() instanceof DateTimeImmutable)) {
            return;
        }
        $conversation = $message->getConversation();
        if ($pinned && $this->messageRepository instanceof ChatMessageRepository && count($this->messageRepository->findPinned($conversation, $this->maxPins)) >= $this->maxPins) {
            throw new InvalidArgumentException('too_many_pins');
        }
        $now              = $this->clock->now();
        [$message, $line] = $this->write(function () use ($user, $message, $pinned, $now): array {
            $message      = ChatKitWrites::attach($this->entityManager, $message);
            $conversation = $message->getConversation();
            $line         = null;
            if ($pinned) {
                $this->bump($conversation, $now);
            } else {
                $this->conversations->lockActivity($conversation);
            }
            $message->pin($user, $pinned ? $now : null);
            if ($pinned) {
                $line = ChatMessage::pinLine($conversation, $user, $message, $now);
                $this->entityManager->persist($line);
            }

            return [$message, $line];
        });
        $this->notifier->messageUpdated($message);
        if ($line instanceof ChatMessage) {
            $this->notifier->botMessage($line);
        }
        $this->notifier->pinsChanged($message->getConversation());
    }

    /**
     * 1.24: posts a system line in the conversation (no author: e.g. "Ana renamed the channel"), bumps its activity and
     * tells its readers. Server only: no API route posts one, so call it from your own use cases after your own access
     * checks. `$body` is a translation key of `$domain` translated for each reader (plain text when `$domain` is null);
     * `$params` fill its `%name%` placeholders. The dock shows it as text, never as HTML.
     *
     * @param ChatConversation $conversation The conversation
     * @param string $body Translation key, or plain text when `$domain` is null (at most ChatMessage::MAX_LENGTH characters)
     * @param string|null $domain Translation domain of the key; null for plain text
     * @param array<string, string> $params Translation parameters (`name` => value, without the `%`)
     *
     * @throws InvalidArgumentException `empty` or `too_long`
     *
     * @return ChatMessage The stored system line
     */
    public function postSystemLine(ChatConversation $conversation, string $body, ?string $domain = null, array $params = []): ChatMessage
    {
        $body = $this->normalize($body);
        $now  = $this->clock->now();
        $line = $this->write(function () use ($conversation, $body, $domain, $params, $now): ChatMessage {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $this->bump($conversation, $now);
            $line = ChatMessage::system($conversation, $body, $domain, $params, $now);
            $this->entityManager->persist($line);

            return $line;
        });
        $this->notifier->botMessage($line);

        return $line;
    }

    /**
     * 1.24: mutes the conversation for the user until the given time (no Web Push, no e-mail digest, left out of
     * group mentions, not counted in the dock's total badge, no sound; its own unread count stays), or unmutes it with
     * null. Unmuting a conversation the user has no participant row in changes nothing (audit KC-L4: no row is created). Access is the caller's job (ChatVoter::VIEW).
     *
     * @param ChatUserInterface $user The user
     * @param ChatConversation $conversation The conversation
     * @param DateTimeImmutable|null $until Mute end; null to unmute
     *
     * @return ChatParticipant|null The user's participant row; null when unmuting without one
     */
    public function mute(ChatUserInterface $user, ChatConversation $conversation, ?DateTimeImmutable $until): ?ChatParticipant
    {
        $member = $until instanceof DateTimeImmutable ? $this->checkMember($conversation, $user) : $this->participants->findOneFor($conversation, $user) instanceof ChatParticipant;
        if (!$member && !$until instanceof DateTimeImmutable) {
            return null;
        }
        $now = $this->clock->now();

        return $this->write(function () use ($user, $conversation, $until, $member, $now): ChatParticipant {
            $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
            $this->conversations->lockActivity($conversation, $member);
            $participant = $this->participants->claim($conversation, $user, $now);
            $participant->muteUntil($until);

            return $participant;
        });
    }

    /**
     * Closes (archives) conversations in the user's list, or reopens them; only for that user, nothing is deleted.
     *
     * @param ChatUserInterface $user The user
     * @param list<ChatConversation> $conversations The conversations
     * @param bool $archived True to close, false to reopen
     */
    public function archive(ChatUserInterface $user, array $conversations, bool $archived): void
    {
        foreach ($conversations as $conversation) {
            // 1.28: every look-alike refusal before the first write.
            $this->checkMember($conversation, $user);
        }
        // One lock order for several conversations: by id.
        usort($conversations, static fn (ChatConversation $a, ChatConversation $b): int => $a->getId() <=> $b->getId());
        $now = $this->clock->now();
        $this->write(function () use ($user, $conversations, $archived, $now): void {
            foreach ($conversations as $conversation) {
                $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
                $this->conversations->lockActivity($conversation);
                $this->participants->claim($conversation, $user, $now)->archive($archived ? $now : null);
            }
        });
    }

    /**
     * 1.36: the pipeline of send() for several drafts (post()).
     *
     * @param list<ChatDraft> $drafts
     * @param (Closure(): bool)|null $before
     * @param (Closure(list<ChatMessage>): void)|null $after
     *
     * @return list<array{ChatMessage, array<int|string, ChatUserInterface>}> Each message with the people it mentions
     */
    private function postAll(ChatUserInterface $author, array $drafts, ?Closure $before, ?Closure $after): array
    {
        $prepared = [];
        foreach ($drafts as $draft) {
            $conversation = $draft->conversation;
            $forward      = $draft->forwardOf instanceof ChatMessage;
            $body         = $this->normalize($draft->body);
            $body         = $this->links?->normalizeCards($body) ?? $body;
            // A forward keeps its #reference tokens: they are resolved for each reader of the target (the neutral chip
            // for whoever may not open them), and it mentions nobody.
            $body                       = $forward ? $body : ($this->references?->validate($conversation, $author, $body) ?? $body);
            [$body, $mentioned, $group] = $forward ? [$body, [], null] : ($this->mentions?->resolve($conversation, $author, $body) ?? [$body, [], null]);
            // 1.28 (audit PV-D7): a look-alike refusal of the author's participant row comes before any write.
            $this->checkMember($conversation, $author);
            // 1.30 (audit QD-I2): rendered before the unit of work, not under the conversation's row lock.
            $prepared[] = [$draft, $body, $this->markdown->render($body), $mentioned, $group];
        }
        $now = $this->clock->now();
        // 1.30: one unit of work (messages, mentions, links, activity, support status, read cursors, reopened lists).
        $written = $this->write(function () use ($author, $prepared, $now, $before, $after): array {
            if ($before instanceof Closure && $before() !== true) {
                throw new ChatIdempotencyConflict();
            }
            // One lock order for several conversations: by id. A conversation that gets a main-timeline message moves
            // its activity forward; one that only gets thread replies is locked without it.
            $locks = [];
            foreach ($prepared as [$draft]) {
                $id         = (int) $draft->conversation->getId();
                $locks[$id] = ($locks[$id] ?? false) || !$draft->threadRoot instanceof ChatMessage;
            }
            ksort($locks);
            $attached = [];
            foreach ($locks as $id => $main) {
                $conversation = null;
                foreach ($prepared as [$draft]) {
                    if ((int) $draft->conversation->getId() === $id) {
                        $conversation = ChatKitWrites::attach($this->entityManager, $draft->conversation);

                        break;
                    }
                }
                assert($conversation instanceof ChatConversation);
                $main ? $this->bump($conversation, $now) : $this->conversations->lockActivity($conversation);
                $attached[$id] = $conversation;
            }
            $rows = [];
            foreach ($prepared as [$draft, $body, $html, $mentioned, $group]) {
                $conversation = $attached[(int) $draft->conversation->getId()];
                $participant  = $this->participants->claim($conversation, $author, $now);
                $message      = new ChatMessage($conversation, $author, $body, $html, $now);
                $message->mentionAll($group);
                $root = $draft->threadRoot instanceof ChatMessage ? ChatKitWrites::attach($this->entityManager, $draft->threadRoot) : null;
                if ($root instanceof ChatMessage) {
                    $message->replyIn($root);
                }
                if ($draft->forwardOf instanceof ChatMessage) {
                    $message->forwardOf(ChatKitWrites::attach($this->entityManager, $draft->forwardOf));
                }
                $this->entityManager->persist($message);
                // 1.19: the message's links go to the index of the Links panel (flushed with the message).
                $this->links?->index($message);
                $mentioned = $this->mentions?->sync($message, $mentioned) ?? [];
                $reopened  = !$root instanceof ChatMessage && $this->supportTransition($conversation, $author);
                if ($root instanceof ChatMessage) {
                    $this->storeReply($message, $mentioned, $now);
                } else {
                    $this->store($message, $participant);
                }
                $rows[] = [$message, $mentioned, $reopened];
            }
            if ($after instanceof Closure) {
                $after(array_map(static fn (array $row): ChatMessage => $row[0], $rows));
            }

            return $rows;
        });
        $result = [];
        foreach ($written as [$message, $mentioned, $reopened]) {
            $conversation = $message->getConversation();
            $this->announce($message);
            if ($reopened) {
                $this->events?->dispatch(new ChatSupportStatusChangedEvent($conversation, $author));
                if ($conversation->isTicket()) {
                    $this->events?->dispatch(new SupportTicketStatusChangedEvent($conversation, SupportStatus::Resolved, $author));
                    $this->events?->dispatch(new SupportTicketReopenedEvent($conversation, $author));
                }
            }
            if ($mentioned !== []) {
                $this->events?->dispatch(new ChatMentionEvent($message, $mentioned, $conversation));
            }
            $result[] = [$message, $mentioned];
        }

        return $result;
    }

    /**
     * 1.36: inside the unit of work of a thread reply: stores it, counts it on its root (one statement, under the
     * conversation's lock), and records the followers — the author (who has read it), the root's author and the people
     * it mentions.
     *
     * @param array<int|string, ChatUserInterface> $mentioned
     */
    private function storeReply(ChatMessage $reply, array $mentioned, DateTimeImmutable $now): void
    {
        $this->entityManager->flush();
        $root = $reply->getThreadRoot();
        assert($root instanceof ChatMessage);
        $this->messageRepository?->countReply($root, 1, $now);
        $this->entityManager->refresh($root);
        $author = $reply->getAuthor();
        assert($author instanceof ChatUserInterface);
        $followers  = array_values($mentioned);
        $rootAuthor = $root->getAuthor();
        if ($rootAuthor instanceof ChatUserInterface && !$rootAuthor instanceof UnresolvedChatUser) {
            $followers[] = $rootAuthor;
        }
        if ($this->threadReads instanceof ChatThreadReadRepository) {
            $this->threadReads->readUpTo($this->threadReads->claim($root, $author, $now), (int) $reply->getId(), $now);
            foreach ($followers as $follower) {
                if (!ChatUserReferences::same($follower, $author)) {
                    $this->threadReads->claim($root, $follower, $now);
                }
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function reactionsOf(ChatMessage $message): array
    {
        return $this->reactionRepository->summaryFor([(int) $message->getId()])[(int) $message->getId()] ?? [];
    }

    /**
     * Support status after a new message; true when the owner reopened a resolved thread (events to dispatch).
     */
    private function supportTransition(ChatConversation $conversation, ChatUserInterface $author): bool
    {
        if (!$conversation->isSupport()) {
            return false;
        }
        $isOwner = ChatUserReferences::same($conversation->getSupportOwner(), $author);
        $status  = $conversation->getSupportStatus();
        if ($isOwner && $status === SupportStatus::Resolved) {
            $conversation->reopen();

            return true;
        }
        if ($isOwner && $status === SupportStatus::Waiting) {
            $conversation->changeSupportStatus(SupportStatus::Open, $author, $this->clock->now());
        } elseif (!$isOwner && $status === SupportStatus::Open && $conversation->isTicket()) {
            $conversation->changeSupportStatus(SupportStatus::Waiting, $author, $this->clock->now());
        }

        return false;
    }

    private function normalize(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", ChatMarkdownRenderer::clean($body)));
        if ($body === '') {
            throw new InvalidArgumentException('empty');
        }
        if (mb_strlen($body) > ChatMessage::MAX_LENGTH) {
            throw new InvalidArgumentException('too_long');
        }

        return $body;
    }

    /**
     * Inside the unit of work of a new message: stores it (its id), moves the author's read cursor onto it and brings
     * the conversation back to the lists of those who closed it.
     */
    private function store(ChatMessage $message, ChatParticipant $participant): void
    {
        // The id of the new message (the author's read cursor points at it).
        $this->entityManager->flush();
        // The author has read everything up to their own message (1.35: and the read time of what they had not read yet).
        if ($participant->markReadUpTo((int) $message->getId())) {
            $this->readTimes?->record($participant, $participant->getUser(), (int) $message->getId(), $message->getCreatedAt());
        }
        // 1.10: a new message brings the conversation back to the lists of those who closed it (not muted ones; a
        // resolved or closed ticket stays closed).
        $conversation = $message->getConversation();
        if (!$conversation->isSupport() || !in_array($conversation->getSupportStatus(), [SupportStatus::Resolved, SupportStatus::Closed], true)) {
            $this->participants->reopenArchived($conversation, $message->getCreatedAt());
        }
    }

    /**
     * After the commit: the realtime publish and ChatMessageSentEvent of a new message.
     */
    private function announce(ChatMessage $message): void
    {
        $root = $message->getThreadRoot();
        if (!$root instanceof ChatMessage) {
            $this->notifier->messageCreated($message);
            $this->events?->dispatch(new ChatMessageSentEvent($message));

            return;
        }
        // 1.36: every reader's open thread gets the reply and the root its count; Web Push only to the followers.
        $followers = $this->threadReads?->followers($root) ?? [];
        $this->notifier->messageCreated($message, [], $followers);
        $this->notifier->messageUpdated($root, $this->reactionsOf($root));
        $this->notifier->thread($root, $followers);
        $this->events?->dispatch(new ChatMessageSentEvent($message));
        $this->events?->dispatch(new ChatThreadReplyEvent($root, $message));
    }

    /**
     * 1.30: the first statement of a unit of work that adds a line to the conversation. It locks the conversation row
     * (exclusive) before any insert takes a shared foreign-key lock on it, so two messages of one conversation queue
     * instead of deadlocking (MySQL / MariaDB), and moves the activity time forward only: a concurrent message that
     * committed a later time is never set back.
     */
    private function bump(ChatConversation $conversation, DateTimeImmutable $now): void
    {
        $stored = $this->conversations->lockActivity($conversation);
        if (!$stored instanceof DateTimeImmutable || $now > $stored) {
            $conversation->touch($now);
        }
    }

    /**
     * Before any write (1.28): whether the user has a participant row; without one, a look-alike's row read in the same
     * column refuses the insert that would clash with it (nothing is pending when it throws).
     *
     * @throws ChatUserLookAlikeException When the user's row would fold onto a look-alike's
     *
     * @return bool Whether the row exists
     */
    private function checkMember(ChatConversation $conversation, ChatUserInterface $user): bool
    {
        if ($this->participants->findOneFor($conversation, $user) instanceof ChatParticipant) {
            return true;
        }
        $this->participants->assertInsertable($user);

        return false;
    }

    /**
     * 1.30 (audit QD-M1): the support-only refusal of resolve / reopen, before the unit of work.
     */
    private function assertSupport(ChatConversation $conversation): void
    {
        if (!$conversation->isSupport()) {
            throw new LogicException('Only support conversations can be resolved or reopened.');
        }
    }

    /**
     * 1.30: runs one write unit of work through ChatKit's runner (flushed inside it).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function write(callable $work): mixed
    {
        return ChatKitWrites::runRecovering($this->transactions, $this->entityManager, $work);
    }

    /**
     * The first opening of a channel: the event and the insert in one unit of work (ChatChannelOpeningEvent once per
     * attempt), then the row, whoever wrote it.
     *
     * @throws ChatChannelDeniedException When a listener refused and the channel still does not exist
     * @throws UniqueConstraintViolationException When another opening won and its row cannot be read here (a caller's own transaction on a repeatable read)
     */
    private function createChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversation
    {
        // A listener's `SELECT … FOR UPDATE` lasts until this commit. The connection's transaction, not the
        // EntityManager's: that one flushes pending changes whatever happened (audit GZ-L2: a refusal never flushes
        // what is pending). What a listener flushed stays even if it throws afterwards, so its exception leaves the unit
        // of work as a value. 1.30: a connection-only runner instead of Connection::transactional(), whose rollback
        // failed after a nested deadlock (savepoint gone, 1305) and hid the deadlock: the connection is repaired and a
        // transient failure surfaces as TransientStorageFailure (409 + Retry-After). The row goes in through DBAL, so the manager has
        // nothing of this unit to flush or discard.
        $runner = ChatKitTransactionRunner::forConnection($this->entityManager->getConnection());
        // @igor-ignore - The runner calls the closure once, now, and keeps nothing (stateless, per call).
        [$opening, $failure, $lost] = $runner->run(function () use ($actor, $channel): array {
            $opening = new ChatChannelOpeningEvent($channel, $actor);
            try {
                $this->events?->dispatch($opening);
            } catch (Throwable $thrown) {
                return [$opening, $thrown, null];
            }

            return [$opening, null, $opening->isDenied() ? null : $this->insertChannel($channel)];
        });
        if ($failure instanceof Throwable) {
            throw $failure;
        }
        // Read after the commit: inside the transaction a repeatable read may not see the row of whoever got there first.
        // A channel that now exists opens for this person too, even if the limit was reached by creating it.
        $conversation = $this->conversations->findChannel($channel->key);
        if ($conversation instanceof ChatConversation) {
            return $conversation;
        }
        if ($opening->isDenied()) {
            throw new ChatChannelDeniedException($channel->key, $opening->reason());
        }

        throw $lost ?? new LogicException('The channel row was written but cannot be read.');
    }

    /**
     * Writes the channel's row through DBAL in a savepoint (ChatKitRows): losing to another first opening of the same
     * channel is not an error and leaves the transaction usable (Postgres aborts it otherwise).
     *
     * @return UniqueConstraintViolationException|null What stopped the insert; null when this call wrote the row
     */
    private function insertChannel(ChatChannel $channel): ?UniqueConstraintViolationException
    {
        return ChatKitRows::insert($this->entityManager, ChatConversation::channel($channel->key, $this->clock->now()));
    }

    /**
     * A new conversation with its participant rows, in one unit of work.
     *
     * 1.30 (audit QD-M2): the conversation's row goes in through a savepoint (ChatKitRows). Two simultaneous first
     * messages to the same pair hit its unique key: the loser's unit of work is rolled back with nothing else undone
     * (no manager reset, the caller's entities stay managed) and the caller reads the winner's row.
     *
     * 1.28 (audit PV-L2, `string` mode): the participant rows are claimed one by one; the read before each insert finds
     * a look-alike's row on a column that folds (`Bob` next to `bob`), and ChatUserLookAlikeException is thrown. Audit
     * PV-D1: the whole unit is rolled back on the refusal (a savepoint inside the caller's own transaction) and only
     * ChatKit's rows are detached. Audit PV-L6: ChatKit's runner flushes the caller's pending changes on their own
     * first, so a refusal never rolls them back.
     *
     * @param callable(ChatUserInterface ...): ChatConversation $make Builds the new conversation
     * @param list<ChatUserInterface> $participants
     *
     * @throws ChatUserLookAlikeException When the participant column folds two of the participants into one
     * @throws UniqueConstraintViolationException When another request created the same conversation first
     */
    private function create(callable $make, array $participants): ChatConversation
    {
        return $this->write(function () use ($make, $participants): ChatConversation {
            $new  = $make(...$participants);
            $lost = ChatKitRows::insert($this->entityManager, $new);
            if ($lost instanceof UniqueConstraintViolationException) {
                throw $lost;
            }
            $conversation = $this->conversations->findOneByUuid($new->getUuid()) ?? throw new LogicException('The conversation row was written but cannot be read.');
            $now          = $this->clock->now();
            foreach ($participants as $user) {
                $this->participants->claim($conversation, $user, $now);
            }

            return $conversation;
        });
    }
}
