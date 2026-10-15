<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * Conversation lifecycle and messages. Authorization is the caller's job (ChatVoter); this service only keeps
 * the data consistent (one conversation per pair / channel / support owner, participant rows, read cursors,
 * support reopen on a new owner message) and notifies.
 */
final readonly class ChatService
{
    /**
     * @param list<string> $reactions emojis offered for reactions (anything else is rejected)
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
    ) {
    }

    public function openDirect(ChatUserInterface $actor, ChatUserInterface $other): ChatConversation
    {
        $conversation = $this->conversations->findDirect($actor, $other);
        if ($conversation instanceof ChatConversation) {
            return $conversation;
        }

        return $this->create(ChatConversation::direct($actor, $other), [$actor, $other]);
    }

    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversation
    {
        $conversation = $this->conversations->findChannel($channel->key)
            ?? $this->create(ChatConversation::channel($channel->key), []);
        $this->participant($conversation, $actor);
        $this->entityManager->flush();

        return $conversation;
    }

    public function openSupport(ChatUserInterface $owner): ChatConversation
    {
        return $this->conversations->findSupportFor($owner)
            ?? $this->create(ChatConversation::support($owner), [$owner]);
    }

    /**
     * @throws \InvalidArgumentException `empty` or `too_long`
     */
    public function send(ChatUserInterface $author, ChatConversation $conversation, string $body): ChatMessage
    {
        $body = $this->normalize($body);
        $now = $this->clock->now();
        $message = new ChatMessage($conversation, $author, $body, $this->markdown->render($body), $now);
        $this->entityManager->persist($message);
        $conversation->touch($now);
        if ($conversation->isSupport() && $conversation->isResolved() && $conversation->getSupportOwner()?->getId() === $author->getId()) {
            $conversation->reopen();
        }

        return $this->publish($message, $author);
    }

    /** A nudge, stored as a `buzz` line so offline recipients see it too. */
    public function buzz(ChatUserInterface $author, ChatConversation $conversation): ChatMessage
    {
        $message = ChatMessage::buzz($conversation, $author, $this->clock->now());
        $this->entityManager->persist($message);
        $conversation->touch($message->getCreatedAt());

        return $this->publish($message, $author);
    }

    /**
     * @throws \InvalidArgumentException `empty` or `too_long`
     */
    public function edit(ChatMessage $message, string $body): void
    {
        $body = $this->normalize($body);
        $message->edit($body, $this->markdown->render($body), $this->clock->now());
        $this->entityManager->flush();
        $this->notifier->messageUpdated($message, $this->reactionsOf($message));
    }

    public function delete(ChatUserInterface $by, ChatMessage $message): void
    {
        $message->delete($by, $this->clock->now());
        foreach ($this->reactionRepository->findBy(['message' => $message]) as $reaction) {
            $this->entityManager->remove($reaction);
        }
        $this->entityManager->flush();
        $this->notifier->messageUpdated($message);
    }

    /**
     * Adds the reaction, or removes it when the user had already reacted with that emoji.
     *
     * @return array<string, list<string>> the message's reactions after the change
     *
     * @throws \InvalidArgumentException `reaction` when the emoji is not offered
     */
    public function toggleReaction(ChatUserInterface $user, ChatMessage $message, string $emoji): array
    {
        if (!\in_array($emoji, $this->reactions, true)) {
            throw new \InvalidArgumentException('reaction');
        }
        $existing = $this->reactionRepository->findOneFor($message, $user, $emoji);
        if ($existing instanceof ChatReaction) {
            $this->entityManager->remove($existing);
        } else {
            $this->entityManager->persist(new ChatReaction($message, $user, $emoji));
        }
        $this->entityManager->flush();
        $reactions = $this->reactionsOf($message);
        $this->notifier->messageUpdated($message, $reactions);

        return $reactions;
    }

    public function resolveSupport(ChatUserInterface $agent, ChatConversation $conversation): void
    {
        $conversation->resolve($agent);
        $this->entityManager->flush();
        $this->notifier->supportStatusChanged($conversation);
    }

    public function markRead(ChatUserInterface $user, ChatConversation $conversation, int $messageId): bool
    {
        $moved = $this->participant($conversation, $user)->markReadUpTo($messageId);
        $this->entityManager->flush();
        if ($moved) {
            $this->notifier->read($conversation, $user, $messageId);
        }

        return $moved;
    }

    /** Participant row of the user, created on first use (channels, support agents in support threads). */
    public function participant(ChatConversation $conversation, ChatUserInterface $user): ChatParticipant
    {
        $participant = $this->participants->findOneBy(['conversation' => $conversation, 'user' => $user]);
        if (!$participant instanceof ChatParticipant) {
            $participant = new ChatParticipant($conversation, $user);
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

    private function normalize(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", ChatMarkdownRenderer::clean($body)));
        if ('' === $body) {
            throw new \InvalidArgumentException('empty');
        }
        if (mb_strlen($body) > ChatMessage::MAX_LENGTH) {
            throw new \InvalidArgumentException('too_long');
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
            $this->entityManager->persist(new ChatParticipant($conversation, $user));
        }
        $this->entityManager->flush();

        return $conversation;
    }
}
