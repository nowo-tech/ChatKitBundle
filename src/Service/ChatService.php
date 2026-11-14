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
 */
final readonly class ChatService implements ChatServiceInterface
{
    private const string CHANNEL_INSERT_SAVEPOINT = 'nowo_chat_channel_insert';

    /** 1.28 (audit PV-D1): the savepoint of a new conversation between look-alikes inside the caller's transaction. */
    private const string LOOK_ALIKE_SAVEPOINT = 'nowo_chat_look_alike_create';

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
    ) {
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

        return $this->create(ChatConversation::direct($actor, $other, $this->clock->now()), [$actor, $other]);
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
        $this->participant($conversation, $actor);
        $this->entityManager->flush();

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
            ?? $this->create(ChatConversation::support($owner, $this->clock->now()), [$owner]);
        if ($context !== null) {
            $conversation->setSupportContext(is_string($context['url'] ?? null) ? $context['url'] : null, is_string($context['label'] ?? null) ? $context['label'] : null);
            $this->entityManager->flush();
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
        $now                        = $this->clock->now();
        $message                    = new ChatMessage($conversation, $author, $body, $this->markdown->render($body), $now);
        $message->mentionAll($group);
        $this->entityManager->persist($message);
        // 1.19: the message's links go to the index of the Links panel (flushed with the message).
        $this->links?->index($message);
        $mentioned = $this->mentions?->sync($message, $mentioned) ?? [];
        $conversation->touch($now);
        $reopened = $this->supportTransition($conversation, $author);
        $this->publish($message, $author);
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
        // 1.11: automatic replies of the other person (direct) or of the people mentioned (channels).
        $this->autoReplies?->afterSend($message, array_values($mentioned));

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
        $message = ChatMessage::buzz($conversation, $author, $this->clock->now());
        $this->entityManager->persist($message);
        $conversation->touch($message->getCreatedAt());

        return $this->publish($message, $author);
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
        $message->edit($body, $this->markdown->render($body), $this->clock->now());
        $message->mentionAll($group);
        $added = $this->mentions?->sync($message, $mentioned) ?? [];
        $this->links?->index($message);
        $this->entityManager->flush();
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
        $message->delete($by, $this->clock->now());
        // A deleted message leaves the pinned bar.
        $wasPinned = $message->getPinnedAt() instanceof DateTimeImmutable;
        $message->pin(null, null);
        foreach ($this->reactionRepository->findBy(['message' => $message]) as $reaction) {
            $this->entityManager->remove($reaction);
        }
        $this->mentionRows?->removeFor($message);
        // 1.19: a deleted message leaves the Links panel.
        $this->links?->forget($message);
        $this->entityManager->flush();
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
        if ($existing instanceof ChatReaction) {
            $this->entityManager->remove($existing);
        } else {
            // 1.28: refused before the insert when the read found a look-alike's reaction (folding user column).
            $this->reactionRepository->assertInsertable($user);
            $this->entityManager->persist(new ChatReaction($message, $user, $emoji, $this->clock->now()));
        }
        $this->entityManager->flush();
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
        $conversation->resolve($agent, $this->clock->now());
        $this->entityManager->flush();
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
        $conversation->reopen();
        $this->entityManager->flush();
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
        $moved = $this->participant($conversation, $user)->markReadUpTo($messageId);
        $this->entityManager->flush();
        if ($moved) {
            $this->mentionRows?->markReadUpTo($user, $conversation, $messageId, $this->clock->now());
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
        $now  = $this->clock->now();
        $line = null;
        $message->pin($user, $pinned ? $now : null);
        if ($pinned) {
            $line = ChatMessage::pinLine($conversation, $user, $message, $now);
            $this->entityManager->persist($line);
            $conversation->touch($now);
        }
        $this->entityManager->flush();
        $this->notifier->messageUpdated($message);
        if ($line instanceof ChatMessage) {
            $this->notifier->botMessage($line);
        }
        $this->notifier->pinsChanged($conversation);
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
        $line = ChatMessage::system($conversation, $body, $domain, $params, $now);
        $this->entityManager->persist($line);
        $conversation->touch($now);
        $this->entityManager->flush();
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
        $participant->muteUntil($until);
        $this->entityManager->flush();

        return $participant;
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
        $now = $this->clock->now();
        foreach ($conversations as $conversation) {
            $this->participant($conversation, $user)->archive($archived ? $now : null);
        }
        $this->entityManager->flush();
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

    private function publish(ChatMessage $message, ChatUserInterface $author): ChatMessage
    {
        $participant = $this->participant($message->getConversation(), $author);
        $this->entityManager->flush();
        // The author has read everything up to their own message.
        $participant->markReadUpTo((int) $message->getId());
        $this->entityManager->flush();
        // 1.10: a new message brings the conversation back to the lists of those who closed it (not muted ones; a
        // resolved or closed ticket stays closed).
        $conversation = $message->getConversation();
        if (!$conversation->isSupport() || !in_array($conversation->getSupportStatus(), [SupportStatus::Resolved, SupportStatus::Closed], true)) {
            $this->participants->reopenArchived($conversation, $this->clock->now());
        }
        $this->notifier->messageCreated($message);
        $this->events?->dispatch(new ChatMessageSentEvent($message));

        return $message;
    }

    /**
     * The first opening of a channel: the event and the insert in one transaction, then the row, whoever wrote it.
     *
     * @throws ChatChannelDeniedException When a listener refused and the channel still does not exist
     * @throws UniqueConstraintViolationException When another opening won and its row cannot be read here (a caller's own transaction on a repeatable read)
     */
    private function createChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversation
    {
        $opening = new ChatChannelOpeningEvent($channel, $actor);
        $failure = null;
        $lost    = null;
        // A listener's `SELECT … FOR UPDATE` lasts until this commit. The connection's transaction, not the EntityManager's:
        // that one flushes pending changes whatever happened, and closes the EntityManager on any exception. What a listener
        // flushed stays even if it throws afterwards, so its exception leaves the closure as a value.
        // @igor-ignore Runs and ends inside this call; the connection does not keep it.
        $this->entityManager->getConnection()->transactional(function () use ($opening, $channel, &$failure, &$lost): void {
            try {
                $this->events?->dispatch($opening);
            } catch (Throwable $thrown) {
                $failure = $thrown;

                return;
            }
            if (!$opening->isDenied()) {
                $lost = $this->insertChannel($channel);
            }
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
     * loss (Postgres aborts it otherwise). It is explicit because a nested DBAL 3 transaction uses one only when the host
     * enabled `use_savepoints`, and otherwise makes the outer commit fail.
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
     * Persist a new conversation with its participant rows. Two simultaneous first messages to the same pair
     * hit the unique key and one request fails; the client retries the open call, which then finds it.
     *
     * 1.28 (audit PV-L2, `string` mode): a participant whose reference may fold onto an earlier one's (`Bob` next to
     * `bob`) is written only after the others are stored and a read showed the user column keeps them apart. On a
     * column that folds that read returns the look-alike's row and ChatUserLookAlikeException is thrown: the two
     * inserts would otherwise clash inside one flush and close the entity manager. On exact columns both rows are
     * written, one flush later. Audit PV-D1: both phases run in one transaction (a savepoint inside the caller's own),
     * rolled back on the refusal, and the conversation and rows written by the first phase are detached, so nothing
     * half-written stays and no cleanup statement can hide the refusal.
     *
     * @param list<ChatUserInterface> $participants
     *
     * @throws ChatUserLookAlikeException When the participant column folds two of the participants into one
     */
    private function create(ChatConversation $conversation, array $participants): ChatConversation
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
        if ($later === []) {
            $this->entityManager->persist($conversation);
            foreach ($first as $user) {
                $this->entityManager->persist(new ChatParticipant($conversation, $user, $this->clock->now()));
            }
            $this->entityManager->flush();

            return $conversation;
        }

        $connection = $this->entityManager->getConnection();
        $savepoint  = $connection->isTransactionActive() ? self::LOOK_ALIKE_SAVEPOINT : null;
        if ($savepoint === null) {
            $connection->beginTransaction();
        } else {
            $connection->createSavepoint($savepoint);
        }
        $rows = [];
        try {
            $this->entityManager->persist($conversation);
            foreach ($first as $user) {
                $this->entityManager->persist($rows[] = new ChatParticipant($conversation, $user, $this->clock->now()));
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
            $this->entityManager->flush();
        } catch (ChatUserLookAlikeException $refused) {
            if ($savepoint === null) {
                $connection->rollBack();
            } else {
                $connection->rollbackSavepoint($savepoint);
                $connection->releaseSavepoint($savepoint);
            }
            foreach ([...$rows, $conversation] as $entity) {
                $this->entityManager->detach($entity);
            }

            throw $refused;
        } catch (Throwable $failure) {
            // Anything else (a lost race on the pair, a closed connection): the transaction is the caller's to see fail.
            if ($savepoint === null && $connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $failure;
        }
        if ($savepoint === null) {
            $connection->commit();
        } else {
            $connection->releaseSavepoint($savepoint);
        }

        return $conversation;
    }
}
