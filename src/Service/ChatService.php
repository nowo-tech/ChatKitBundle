<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketReopenedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketStatusChangedEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;
use function is_string;

/**
 * Conversation lifecycle and messages. Authorization is the caller's job (ChatVoter); this service only keeps
 * the data consistent (one conversation per pair / channel / support owner, participant rows, read cursors,
 * support reopen on a new owner message) and notifies.
 */
final readonly class ChatService
{
    /**
     * Builds the service from the entity manager, repositories, renderer, notifier and the offered reactions.
     *
     * @param list<string> $reactions emojis offered for reactions (anything else is rejected)
     * @param ClockInterface $clock Clock for every timestamp it writes (conversations, participants, messages, reactions, resolution)
     * @param EventDispatcherInterface|null $events Receives the chat events; null dispatches none
     * @param ChatMentions|null $mentions Validates and stores @mentions; null keeps mention tokens as written
     * @param ChatMessageMentionRepository|null $mentionRows Mention rows (removed with a deleted message, read with the thread)
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
     * Returns the channel's conversation (created on first use) and makes sure the actor has a participant row.
     *
     * @param ChatUserInterface $actor The user who opens it
     * @param ChatChannel $channel The application channel
     *
     * @return ChatConversation The channel conversation
     */
    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversation
    {
        $conversation = $this->conversations->findChannel($channel->key)
            ?? $this->create(ChatConversation::channel($channel->key, $this->clock->now()), []);
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
     * Stores a new message (rendered to HTML) with its @mentions of conversation members, bumps the conversation
     * activity, moves the support status (the owner's message reopens a resolved ticket and turns a waiting one open;
     * an agent's reply turns an open ticket to waiting for the user), notifies and dispatches ChatMentionEvent when
     * it mentions someone.
     *
     * @param ChatUserInterface $author The writer
     * @param ChatConversation $conversation The conversation
     * @param string $body Raw Markdown text
     *
     * @throws InvalidArgumentException `empty` or `too_long`
     *
     * @return ChatMessage The stored message
     */
    public function send(ChatUserInterface $author, ChatConversation $conversation, string $body): ChatMessage
    {
        $body               = $this->normalize($body);
        [$body, $mentioned] = $this->mentions?->resolve($conversation, $author, $body) ?? [$body, []];
        $now                = $this->clock->now();
        $message            = new ChatMessage($conversation, $author, $body, $this->markdown->render($body), $now);
        $this->entityManager->persist($message);
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
     * @throws InvalidArgumentException `empty` or `too_long`
     */
    public function edit(ChatMessage $message, string $body): void
    {
        $body               = $this->normalize($body);
        $author             = $message->getAuthor();
        [$body, $mentioned] = $author instanceof ChatUserInterface && $this->mentions instanceof ChatMentions
            ? $this->mentions->resolve($message->getConversation(), $author, $body)
            : [$body, []];
        $message->edit($body, $this->markdown->render($body), $this->clock->now());
        $added = $this->mentions?->sync($message, $mentioned) ?? [];
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
        foreach ($this->reactionRepository->findBy(['message' => $message]) as $reaction) {
            $this->entityManager->remove($reaction);
        }
        $this->mentionRows?->removeFor($message);
        $this->entityManager->flush();
        $this->notifier->messageUpdated($message);
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
        $participant = $this->participants->findOneBy(['conversation' => $conversation, 'user' => $user]);
        if (!$participant instanceof ChatParticipant) {
            $participant = new ChatParticipant($conversation, $user, $this->clock->now());
            $this->entityManager->persist($participant);
        }

        return $participant;
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
        $isOwner = $conversation->getSupportOwner()?->getId() === $author->getId();
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
        $this->notifier->messageCreated($message);
        $this->events?->dispatch(new ChatMessageSentEvent($message));

        return $message;
    }

    /**
     * Persist a new conversation with its participant rows. Two simultaneous first messages to the same pair
     * hit the unique key and one request fails; the client retries the open call, which then finds it.
     *
     * @param list<ChatUserInterface> $participants
     */
    private function create(ChatConversation $conversation, array $participants): ChatConversation
    {
        $this->entityManager->persist($conversation);
        foreach ($participants as $user) {
            $this->entityManager->persist(new ChatParticipant($conversation, $user, $this->clock->now()));
        }
        $this->entityManager->flush();

        return $conversation;
    }
}
