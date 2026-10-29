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
    /**
     * Builds the notifier from the recipient resolver, the publisher, the presenter and the push dispatcher.
     *
     * @param bool $pushEnabled Whether Web Push is configured
     * @param MessageBusInterface|null $bus Messenger bus for async push; null delivers inline
     * @param ChatDigestScheduler|null $digest Unread-email scheduler; null when the digest is disabled
     */
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
     * Publishes a new message to every recipient, schedules the unread digest for the others and queues
     * (or delivers inline) their Web Push.
     *
     * @param ChatMessage $message The stored message
     * @param array<string, list<string>> $reactions Reactions by emoji (public ids of the reacting users)
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
     * @param ChatMessage $message The changed message
     * @param array<string, list<string>> $reactions Reactions by emoji (public ids of the reacting users)
     */
    public function messageUpdated(ChatMessage $message, array $reactions = []): void
    {
        $this->messageEvent('message_updated', $message, $reactions);
    }

    /**
     * Read cursor: to the reader's other tabs / devices and, in a direct thread, to the other person (✓✓).
     * Channels do not fan read receipts out to every member; "Seen by" refreshes on catch-up.
     *
     * @param ChatConversation $conversation The conversation that was read
     * @param ChatUserInterface $reader The user who read it
     * @param int $messageId The new read cursor (last read message id)
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

    /**
     * Sends a "typing" ping to the other recipients. Ephemeral: never stored.
     *
     * @param ChatConversation $conversation The conversation being typed in
     * @param ChatUserInterface $typist The user who is typing
     */
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

    /**
     * A support assistant line: published live to the ticket's readers (no Web Push or digest — the application
     * notifies through the ticket events).
     *
     * @param ChatMessage $message The bot message
     */
    public function botMessage(ChatMessage $message): void
    {
        $this->messageEvent('message', $message, []);
    }

    /**
     * Tells every recipient that the support thread was resolved or reopened, so they reload it.
     *
     * @param ChatConversation $conversation The support thread
     */
    public function supportStatusChanged(ChatConversation $conversation): void
    {
        $this->publisher->publish('conversation', ['conversationUuid' => $conversation->getUuid()], $this->recipients->of($conversation));
    }

    /**
     * A user changed their chat status: the people with a direct conversation with them get what others may see
     * (`offline` and no text while invisible), the user's own tabs get the status they picked.
     *
     * @param ChatUserInterface $user The user whose status changed
     * @param array{status: string, text: string|null, emoji: string|null} $visible What others see
     * @param array{status: string, text: string|null, emoji: string|null, expiresAt: string|null} $own What the user picked
     * @param list<ChatUserInterface> $peers The user's direct contacts
     */
    public function statusChanged(ChatUserInterface $user, array $visible, array $own, array $peers): void
    {
        $this->publisher->publish('status', ['userUuid' => $user->getChatPublicId()] + $visible, $peers);
        $this->publisher->publish('my_status', ['status' => $own], [$user]);
    }

    /**
     * The pinned messages of a conversation changed (1.10): every reader reloads its pinned bar.
     *
     * @param ChatConversation $conversation The conversation
     */
    public function pinsChanged(ChatConversation $conversation): void
    {
        $this->publisher->publish('pins', ['conversationUuid' => $conversation->getUuid()], $this->recipients->of($conversation));
    }

    /**
     * The user closed or reopened conversations: their other tabs and devices reload the list.
     *
     * @param ChatUserInterface $user The user
     */
    public function listChanged(ChatUserInterface $user): void
    {
        $this->publisher->publish('list', [], [$user]);
    }

    /**
     * The user changed a chat preference: their other tabs and devices apply it at once.
     *
     * @param ChatUserInterface $user The user
     * @param array<string, mixed> $preferences ChatUserSettings::preferences()
     */
    public function preferencesChanged(ChatUserInterface $user, array $preferences): void
    {
        $this->publisher->publish('prefs', ['prefs' => $preferences], [$user]);
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
