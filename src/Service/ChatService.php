<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\AutoReply\ChatAutoReplier;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
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
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function count;
use function in_array;
use function is_string;

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
 * closed entity manager; a transient one surfaces as TransientStorageFailure (503 + Retry-After on ChatKit's routes).
 * A conversation's activity is bumped first in the unit of work, under a row lock and only forward, so two messages in
 * the same conversation queue instead of deadlocking. A first opening (a direct conversation, a channel membership, a
 * read cursor) that loses an insert race to another request re-reads the row the other one wrote.
 */
final readonly class ChatService implements ChatServiceInterface
{
    private const string CHANNEL_INSERT_SAVEPOINT = 'nowo_chat_channel_insert';

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
        // 1.28: the look-alike refusal before any write (nothing pending); 1.30: one unit of work, and a membership
        // another request of the same person wrote first is read again instead of failing (idempotent).
        $participant = $this->participant($conversation, $actor);
        $this->write(function (bool $replay) use (&$conversation, &$participant, $actor, $channel): void {
            if ($replay) {
                $conversation = $this->conversations->findChannel($channel->key) ?? throw new ChatNotFoundException('channel', $channel->key);
                $participant  = $this->participant($conversation, ChatKitWrites::attach($this->entityManager, $actor));
            }
        }, true);

        return $conversation;
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
            $conversation = $this->write(function (bool $replay) use ($conversation, $context): ChatConversation {
                $conversation = $replay ? ChatKitWrites::attach($this->entityManager, $conversation) : $conversation;
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
        $body                       = $this->normalize($body);
        $body                       = $this->links?->normalizeCards($body) ?? $body;
        $body                       = $this->references?->validate($conversation, $author, $body) ?? $body;
        [$body, $mentioned, $group] = $this->mentions?->resolve($conversation, $author, $body) ?? [$body, [], null];
        // 1.28 (audit PV-D7): the author's participant row first, so a look-alike refusal comes before any write (the
        // mention sync may flush the message early).
        $participant = $this->participant($conversation, $author);
        $now         = $this->clock->now();
        // 1.30: one unit of work (message, mentions, links, activity, support status, read cursor, reopened lists).
        [$message, $mentioned, $reopened] = $this->write(function (bool $replay) use ($author, $conversation, $body, $mentioned, $group, $participant, $now): array {
            if ($replay) {
                [$conversation, $author, $participant] = $this->again($conversation, $author);
                $mentioned                             = array_map(fn (ChatUserInterface $user): ChatUserInterface => ChatKitWrites::attach($this->entityManager, $user), $mentioned);
            }
            $this->bump($conversation, $now);
            $message = new ChatMessage($conversation, $author, $body, $this->markdown->render($body), $now);
            $message->mentionAll($group);
            $this->entityManager->persist($message);
            // 1.19: the message's links go to the index of the Links panel (flushed with the message).
            $this->links?->index($message);
            $mentioned = $this->mentions?->sync($message, $mentioned) ?? [];
            $reopened  = $this->supportTransition($conversation, $author);
            $this->store($message, $participant);

            return [$message, $mentioned, $reopened];
        }, true);
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
     * A nudge, stored as a `buzz` line so offline recipients see it too.
     *
     * @param ChatUserInterface $author The user who buzzes
     * @param ChatConversation $conversation The conversation
     *
     * @return ChatMessage The stored buzz message
     */
    public function buzz(ChatUserInterface $author, ChatConversation $conversation): ChatMessage
    {
        // 1.28 (audit PV-L7): the author's participant row first, as in send(), so a look-alike refusal leaves no buzz
        // pending in the unit of work for a later flush (the next request of a worker) to store.
        $participant = $this->participant($conversation, $author);
        $now         = $this->clock->now();
        $message     = $this->write(function (bool $replay) use ($author, $conversation, $participant, $now): ChatMessage {
            if ($replay) {
                [$conversation, $author, $participant] = $this->again($conversation, $author);
            }
            $this->bump($conversation, $now);
            $message = ChatMessage::buzz($conversation, $author, $now);
            $this->entityManager->persist($message);
            $this->store($message, $participant);

            return $message;
        }, true);
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
        $html              = $this->markdown->render($body);
        $now               = $this->clock->now();
        [$message, $added] = $this->write(function (bool $replay) use ($message, $body, $html, $now, $mentioned, $group): array {
            if ($replay) {
                $message   = ChatKitWrites::attach($this->entityManager, $message);
                $mentioned = array_map(fn (ChatUserInterface $user): ChatUserInterface => ChatKitWrites::attach($this->entityManager, $user), $mentioned);
            }
            $message->edit($body, $html, $now);
            $message->mentionAll($group);
            $added = $this->mentions?->sync($message, $mentioned) ?? [];
            $this->links?->index($message);

            return [$message, $added];
        });
        $this->notifier->messageUpdated($message, $this->reactionsOf($message));
        if ($added !== []) {
            $this->events?->dispatch(new ChatMentionEvent($message, $added, $message->getConversation(), true));
        }
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
        $message   = $this->write(function (bool $replay) use ($by, $message, $now): ChatMessage {
            if ($replay) {
                $message = ChatKitWrites::attach($this->entityManager, $message);
                $by      = ChatKitWrites::attach($this->entityManager, $by);
            }
            $message->delete($by, $now);
            $message->pin(null, null);
            foreach ($this->reactionRepository->findBy(['message' => $message]) as $reaction) {
                $this->entityManager->remove($reaction);
            }
            $this->mentionRows?->removeFor($message);
            // 1.19: a deleted message leaves the Links panel.
            $this->links?->forget($message);

            return $message;
        });
        $this->notifier->messageUpdated($message);
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
        $existing = $this->reactionRepository->findOneFor($message, $user, $emoji);
        if (!$existing instanceof ChatReaction) {
            // 1.28: refused before the insert when the read found a look-alike's reaction (folding user column).
            $this->reactionRepository->assertInsertable($user);
        }
        $now     = $this->clock->now();
        $message = $this->write(function (bool $replay) use ($user, $message, $emoji, $existing, $now): ChatMessage {
            if ($replay) {
                // 1.30: a replay (or a lost insert race) reads the reaction again: toggling stays one step.
                $message  = ChatKitWrites::attach($this->entityManager, $message);
                $user     = ChatKitWrites::attach($this->entityManager, $user);
                $existing = $this->reactionRepository->findOneFor($message, $user, $emoji);
            }
            if ($existing instanceof ChatReaction) {
                $this->entityManager->remove($existing);
            } else {
                $this->entityManager->persist(new ChatReaction($message, $user, $emoji, $now));
            }

            return $message;
        }, true);
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
        $now          = $this->clock->now();
        $conversation = $this->write(function (bool $replay) use ($agent, $conversation, $now): ChatConversation {
            if ($replay) {
                $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
                $agent        = ChatKitWrites::attach($this->entityManager, $agent);
            }
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
        $conversation = $this->write(function (bool $replay) use ($conversation): ChatConversation {
            $conversation = $replay ? ChatKitWrites::attach($this->entityManager, $conversation) : $conversation;
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
        $participant = $this->participant($conversation, $user);
        $now         = $this->clock->now();
        // 1.30: the cursor and the mention rows it marks read in one unit of work; a first cursor written by another
        // request of the same person at the same moment is read again (idempotent).
        $moved = $this->write(function (bool $replay) use ($user, $conversation, $messageId, $participant, $now): bool {
            if ($replay) {
                [$conversation, $user, $participant] = $this->again($conversation, $user);
            }
            $moved = $participant->markReadUpTo($messageId);
            if ($moved) {
                $this->mentionRows?->markReadUpTo($user, $conversation, $messageId, $now);
            }

            return $moved;
        }, true);
        if ($moved) {
            $this->notifier->read($conversation, $user, $messageId);
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
        if (!$message->isPinnable()) {
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
        [$message, $line] = $this->write(function (bool $replay) use ($user, $message, $pinned, $now): array {
            if ($replay) {
                $message = ChatKitWrites::attach($this->entityManager, $message);
                $user    = ChatKitWrites::attach($this->entityManager, $user);
            }
            $conversation = $message->getConversation();
            $line         = null;
            if ($pinned) {
                $this->bump($conversation, $now);
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
        $line = $this->write(function (bool $replay) use ($conversation, $body, $domain, $params, $now): ChatMessage {
            $conversation = $replay ? ChatKitWrites::attach($this->entityManager, $conversation) : $conversation;
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
        $participant = $until instanceof DateTimeImmutable ? $this->participant($conversation, $user) : $this->participants->findOneFor($conversation, $user);
        if (!$participant instanceof ChatParticipant) {
            return null;
        }

        return $this->write(function (bool $replay) use ($user, $conversation, $until, $participant): ChatParticipant {
            if ($replay) {
                [, , $participant] = $this->again($conversation, $user);
            }
            $participant->muteUntil($until);

            return $participant;
        }, true);
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
        $now  = $this->clock->now();
        $rows = [];
        foreach ($conversations as $conversation) {
            // 1.28: every look-alike refusal before the first write.
            $rows[] = $this->participant($conversation, $user);
        }
        $this->write(function (bool $replay) use ($user, $conversations, $archived, $now, $rows): void {
            if ($replay) {
                $rows = array_map(fn (ChatConversation $conversation): ChatParticipant => $this->again($conversation, $user)[2], $conversations);
            }
            foreach ($rows as $participant) {
                $participant->archive($archived ? $now : null);
            }
        }, true);
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
        // The author has read everything up to their own message.
        $participant->markReadUpTo((int) $message->getId());
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
        $this->notifier->messageCreated($message);
        $this->events?->dispatch(new ChatMessageSentEvent($message));
    }

    /**
     * 1.30: the first statement of a unit of work that adds a line to the conversation. It locks the conversation row
     * (SELECT … FOR UPDATE where the platform has it) before any insert takes a shared foreign-key lock on it, so two
     * messages of one conversation queue instead of deadlocking (MySQL / MariaDB), and moves the activity time forward
     * only: a concurrent message that committed a later time is never set back.
     */
    private function bump(ChatConversation $conversation, DateTimeImmutable $now): void
    {
        $stored = $this->conversations->lockActivity($conversation);
        if (!$stored instanceof DateTimeImmutable || $now > $stored) {
            $conversation->touch($now);
        }
    }

    /**
     * 1.30: what a replayed unit of work (or the retry after a lost insert race) works with: the conversation and user
     * re-attached to the cleared manager and the user's participant row read (or created) again.
     *
     * @return array{0: ChatConversation, 1: ChatUserInterface, 2: ChatParticipant}
     */
    private function again(ChatConversation $conversation, ChatUserInterface $user): array
    {
        $conversation = ChatKitWrites::attach($this->entityManager, $conversation);
        $user         = ChatKitWrites::attach($this->entityManager, $user);

        return [$conversation, $user, $this->participant($conversation, $user)];
    }

    /**
     * 1.30: runs one write unit of work through the transaction runner (flushed inside it). With `$insertRace`, a unique
     * key violation (another request inserted the same participant row, reaction or conversation first) runs the work
     * once more as a replay, which reads that row.
     *
     * @template T
     *
     * @param callable(bool): T $work
     *
     * @throws UniqueConstraintViolationException When the work lost an insert race
     *
     * @return T
     */
    private function write(callable $work, bool $insertRace = false): mixed
    {
        return ChatKitWrites::runRecovering($this->transactions, $this->entityManager, $work, $insertRace);
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
        // of work as a value. 1.30: a connection-only runner instead of Connection::transactional(), whose
        // rollback failed after a nested deadlock (savepoint gone, 1305) and hid the deadlock: the connection is repaired
        // and a transient failure surfaces as TransientStorageFailure (503). The row goes in through DBAL, so the
        // manager has nothing of this unit to flush or discard.
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
     * Writes the channel's row through DBAL: a flush that hits the unique key closes the EntityManager, and losing to
     * another first opening of the same channel is not an error. The savepoint keeps the transaction usable after that
     * loss (Postgres aborts it otherwise).
     *
     * @return UniqueConstraintViolationException|null What stopped the insert; null when this call wrote the row
     */
    private function insertChannel(ChatChannel $channel): ?UniqueConstraintViolationException
    {
        $conversation = ChatConversation::channel($channel->key, $this->clock->now());
        $connection   = $this->entityManager->getConnection();
        $metadata     = $this->entityManager->getClassMetadata(ChatConversation::class);
        $idColumn     = $metadata->getSingleIdentifierColumnName();
        // A sequence has to be asked before the insert: its column has no default. An identity column fills itself.
        $id = $metadata->idGenerator->isPostInsertGenerator() ? [] : [$idColumn => $metadata->idGenerator->generateId($this->entityManager, $conversation)];
        $connection->createSavepoint(self::CHANNEL_INSERT_SAVEPOINT);
        try {
            $connection->insert($metadata->getTableName(), $id + [
                $metadata->getColumnName('uuid')          => $conversation->getUuid(),
                $metadata->getColumnName('kind')          => $conversation->getKind()->value,
                $metadata->getColumnName('channelKey')    => $channel->key,
                $metadata->getColumnName('lastMessageAt') => $conversation->getLastMessageAt(),
                $metadata->getColumnName('createdAt')     => $conversation->getCreatedAt(),
            ], [
                $idColumn                                 => Types::INTEGER,
                $metadata->getColumnName('lastMessageAt') => Types::DATETIME_IMMUTABLE,
                $metadata->getColumnName('createdAt')     => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException $lost) {
            $connection->rollbackSavepoint(self::CHANNEL_INSERT_SAVEPOINT);
            $connection->releaseSavepoint(self::CHANNEL_INSERT_SAVEPOINT);

            return $lost;
        }
        $connection->releaseSavepoint(self::CHANNEL_INSERT_SAVEPOINT);

        return null;
    }

    /**
     * Persist a new conversation with its participant rows, in one unit of work. Two simultaneous first messages to the
     * same pair hit the unique key: the loser's unit of work is rolled back and the caller reads the winner's row
     * (1.30; before, the loser answered 500).
     *
     * 1.28 (audit PV-L2, `string` mode): a participant whose reference may fold onto an earlier one's (`Bob` next to
     * `bob`) is written only after the others are stored and a read showed the user column keeps them apart. On a
     * column that folds that read returns the look-alike's row and ChatUserLookAlikeException is thrown: the two
     * inserts would otherwise clash inside one flush and close the entity manager. On exact columns both rows are
     * written, one flush later. Audit PV-D1: both phases run in one unit of work (a savepoint inside the caller's own
     * transaction), rolled back on the refusal, so nothing half-written stays (1.30: the runner then discards the
     * manager's pending changes too). Audit PV-L6: pending changes of the caller are flushed in a unit of work of
     * their own first, so a refusal never rolls them back.
     *
     * @param callable(ChatUserInterface ...): ChatConversation $make Builds the new conversation (once per attempt)
     * @param list<ChatUserInterface> $participants
     *
     * @throws ChatUserLookAlikeException When the participant column folds two of the participants into one
     * @throws UniqueConstraintViolationException When another request created the same conversation first
     */
    private function create(callable $make, array $participants): ChatConversation
    {
        $first = [];
        $later = [];
        foreach ($participants as $user) {
            if ($this->participants->mayClash($user, $first)) {
                $later[] = $user;
            } else {
                $first[] = $user;
            }
        }
        if ($later !== []) {
            // Audit PV-L6: the caller's pending work is written first, outside what a refusal rolls back.
            $this->write(static fn (): null => null);
        }

        return $this->write(function (bool $replay) use ($make, $participants, $first, $later): ChatConversation {
            if ($replay) {
                $attach       = fn (ChatUserInterface $user): ChatUserInterface => ChatKitWrites::attach($this->entityManager, $user);
                $participants = array_map($attach, $participants);
                $first        = array_map($attach, $first);
                $later        = array_map($attach, $later);
            }
            $conversation = $make(...$participants);
            $this->entityManager->persist($conversation);
            foreach ($first as $user) {
                $this->entityManager->persist(new ChatParticipant($conversation, $user, $this->clock->now()));
            }
            if ($later === []) {
                return $conversation;
            }
            $this->entityManager->flush();
            foreach ($later as $user) {
                // A folding column answers the look-alike's row: findOneFor() reports it and assertInsertable() refuses.
                $this->participants->findOneFor($conversation, $user);
                $this->participants->assertInsertable($user);
            }
            foreach ($later as $user) {
                $this->entityManager->persist(new ChatParticipant($conversation, $user, $this->clock->now()));
            }

            return $conversation;
        });
    }
}
