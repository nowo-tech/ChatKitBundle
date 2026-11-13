<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatParticipantView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;

use function array_values;
use function in_array;

/**
 * 1.27: default ChatMessagingInterface (aliased to it): loads the conversation and the message by their public uuids,
 * compared byte for byte (REQ-SEC-006 item 2), and calls ChatServiceInterface (so a decoration of that interface applies
 * here too); answers with read-only views. Stateless.
 */
final readonly class ChatMessaging implements ChatMessagingInterface
{
    /**
     * @param ChatServiceInterface $chat The application service (entity form)
     * @param ChatConversationRepository $conversations Conversations by uuid
     * @param ChatMessageRepository $messages Messages by uuid
     * @param EntityManagerInterface $entityManager Saves a participant row created by join() (the bundle's private
     *                                              entity manager with `doctrine.private_entity_manager: true`)
     */
    public function __construct(
        private ChatServiceInterface $chat,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function openDirect(ChatUserInterface $actor, ChatUserInterface $other): ChatConversationView
    {
        return ChatConversationView::of($this->chat->openDirect($actor, $other));
    }

    /**
     * {@inheritDoc}
     */
    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversationView
    {
        return ChatConversationView::of($this->chat->openChannel($actor, $channel));
    }

    /**
     * {@inheritDoc}
     */
    public function openSupport(ChatUserInterface $owner, ?array $context = null): ChatConversationView
    {
        return ChatConversationView::of($this->chat->openSupport($owner, $context));
    }

    /**
     * {@inheritDoc}
     */
    public function send(ChatUserInterface $author, string $conversationUuid, string $body): ChatMessageView
    {
        return ChatMessageView::of($this->chat->send($author, $this->conversation($conversationUuid), $body));
    }

    /**
     * {@inheritDoc}
     */
    public function buzz(ChatUserInterface $author, string $conversationUuid): ChatMessageView
    {
        return ChatMessageView::of($this->chat->buzz($author, $this->conversation($conversationUuid)));
    }

    /**
     * {@inheritDoc}
     */
    public function edit(string $conversationUuid, string $messageUuid, string $body): ChatMessageView
    {
        $message = $this->message($conversationUuid, $messageUuid);
        $this->chat->edit($message, $body);

        return ChatMessageView::of($message);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ChatUserInterface $by, string $conversationUuid, string $messageUuid): void
    {
        $this->chat->delete($by, $this->message($conversationUuid, $messageUuid));
    }

    /**
     * {@inheritDoc}
     */
    public function toggleReaction(ChatUserInterface $user, string $conversationUuid, string $messageUuid, string $emoji): array
    {
        return $this->chat->toggleReaction($user, $this->message($conversationUuid, $messageUuid), $emoji);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveSupport(ChatUserInterface $agent, string $conversationUuid): void
    {
        $this->chat->resolveSupport($agent, $this->conversation($conversationUuid));
    }

    /**
     * {@inheritDoc}
     */
    public function reopenSupport(ChatUserInterface $agent, string $conversationUuid): void
    {
        $this->chat->reopenSupport($agent, $this->conversation($conversationUuid));
    }

    /**
     * {@inheritDoc}
     */
    public function markRead(ChatUserInterface $user, string $conversationUuid, int $messageId): bool
    {
        return $this->chat->markRead($user, $this->conversation($conversationUuid), $messageId);
    }

    /**
     * {@inheritDoc}
     */
    public function join(string $conversationUuid, ChatUserInterface $user): ChatParticipantView
    {
        $participant = $this->chat->participant($this->conversation($conversationUuid), $user);
        $this->entityManager->flush();

        return ChatParticipantView::of($participant);
    }

    /**
     * {@inheritDoc}
     */
    public function pin(ChatUserInterface $user, string $conversationUuid, string $messageUuid, bool $pinned): void
    {
        $this->chat->pin($user, $this->message($conversationUuid, $messageUuid), $pinned);
    }

    /**
     * {@inheritDoc}
     */
    public function postSystemLine(string $conversationUuid, string $body, ?string $domain = null, array $params = []): ChatMessageView
    {
        return ChatMessageView::of($this->chat->postSystemLine($this->conversation($conversationUuid), $body, $domain, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function mute(ChatUserInterface $user, string $conversationUuid, ?DateTimeImmutable $until): ?ChatParticipantView
    {
        $participant = $this->chat->mute($user, $this->conversation($conversationUuid), $until);

        return $participant instanceof ChatParticipant ? ChatParticipantView::of($participant) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function archive(ChatUserInterface $user, array $conversationUuids, bool $archived): void
    {
        $found = [];
        foreach ($this->conversations->findByUuids(array_values($conversationUuids)) as $uuid => $conversation) {
            // REQ-SEC-006: only a row whose uuid is exactly one asked for (a folding collation may return a look-alike).
            if ($conversation->getUuid() === (string) $uuid && in_array($conversation->getUuid(), $conversationUuids, true)) {
                $found[] = $conversation;
            }
        }
        if ($found !== []) {
            $this->chat->archive($user, $found, $archived);
        }
    }

    private function conversation(string $uuid): ChatConversation
    {
        $conversation = $this->conversations->findOneByUuid($uuid);
        if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $uuid) {
            throw new ChatNotFoundException('conversation', $uuid);
        }

        return $conversation;
    }

    private function message(string $conversationUuid, string $messageUuid): ChatMessage
    {
        $message = $this->messages->findOneInConversation($this->conversation($conversationUuid), $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid) {
            throw new ChatNotFoundException('message', $messageUuid);
        }

        return $message;
    }
}
