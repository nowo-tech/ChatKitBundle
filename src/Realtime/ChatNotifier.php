<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Realtime;

use Nowo\ChatKitBundle\Digest\ChatDigestScheduler;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Push\ChatPushDispatcher;
use Nowo\ChatKitBundle\Push\DeliverChatPushMessage;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns chat changes into realtime events (private per-user topics) and queues Web Push for the other
 * recipients (through Messenger when available, otherwise inline). Payloads are viewer-neutral; the client
 * derives "mine".
 */
final readonly class ChatNotifier
{
    public function __construct(
        private ChatRecipients $recipients,
        private ChatPublisher $publisher,
        private ChatPresenter $presenter,
        private ChatPushDispatcher $push,
        private bool $pushEnabled = false,
        private ?MessageBusInterface $bus = null,
        private ?ChatDigestScheduler $digest = null,
    ) {
    }

    /**
     * @param array<string, list<string>> $reactions
     */
    public function messageCreated(ChatMessage $message, array $reactions = []): void
    {
        $this->messageEvent('message', $message, $reactions);
        if ($message->isDeleted()) {
            return;
        }
        $others = array_values(array_filter(
            $this->recipients->of($message->getConversation()),
            static fn (ChatUserInterface $user): bool => $user->getId() !== $message->getAuthor()?->getId(),
        ));
        $this->digest?->schedule($others);
        if (!$this->pushEnabled) {
            return;
        }
        $others = array_map(static fn (ChatUserInterface $user): int => (int) $user->getId(), $others);
        if ($others === []) {
            return;
        }
        $command = new DeliverChatPushMessage((int) $message->getId(), $others);
        if ($this->bus instanceof MessageBusInterface) {
            $this->bus->dispatch($command);
        } else {
            ($this->push)($command);
        }
    }

    /**
     * Edit, delete or reaction change: the clients replace the message in place.
     *
     * @param array<string, list<string>> $reactions
     */
    public function messageUpdated(ChatMessage $message, array $reactions = []): void
    {
        $this->messageEvent('message_updated', $message, $reactions);
    }

    /**
     * Read cursor: to the reader's other tabs / devices and, in a direct thread, to the other person (✓✓).
     * Channels do not fan read receipts out to every member; "Seen by" refreshes on catch-up.
     */
    public function read(ChatConversation $conversation, ChatUserInterface $reader, int $messageId): void
    {
        $recipients = [$reader];
        if ($conversation->isDirect()) {
            $recipients = array_values(array_filter($this->recipients->of($conversation), $conversation->involvesDirectly(...)));
        }
        $this->publisher->publish('read', [
            'conversationUuid' => $conversation->getUuid(),
            'userUuid'         => $reader->getChatPublicId(),
            'userName'         => $reader->getChatDisplayName(),
            'messageId'        => $messageId,
        ], $recipients);
    }

    /** Ephemeral: never stored. */
    public function typing(ChatConversation $conversation, ChatUserInterface $typist): void
    {
        $this->publisher->publish('typing', [
            'conversationUuid' => $conversation->getUuid(),
            'userUuid'         => $typist->getChatPublicId(),
            'userName'         => $typist->getChatDisplayName(),
        ], array_values(array_filter(
            $this->recipients->of($conversation),
            static fn (ChatUserInterface $user): bool => $user->getId() !== $typist->getId(),
        )));
    }

    public function supportStatusChanged(ChatConversation $conversation): void
    {
        $this->publisher->publish('conversation', ['conversationUuid' => $conversation->getUuid()], $this->recipients->of($conversation));
    }

    /**
     * @param array<string, list<string>> $reactions
     */
    private function messageEvent(string $type, ChatMessage $message, array $reactions): void
    {
        $conversation = $message->getConversation();
        $this->publisher->publish($type, [
            'conversationUuid' => $conversation->getUuid(),
            'message'          => $this->presenter->message($message, null, $reactions),
        ], $this->recipients->of($conversation));
    }
}
