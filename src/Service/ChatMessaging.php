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
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;

use function array_values;
use function in_array;

/**
 * 1.27: default ChatMessagingInterface (aliased to it): loads the conversation and the message by their public uuids,
 * compared byte for byte (REQ-SEC-006 item 2), authorizes the actor with the API's rules (ChatVoter::decide(),
 * ChatAccess; audit OJ-M2) and calls ChatServiceInterface (so a decoration of that interface applies here too); answers
 * with read-only views. A forbidden conversation or message is reported exactly like an unknown one
 * (ChatNotFoundException). Stateless.
 */
final readonly class ChatMessaging implements ChatMessagingInterface
{
    /** Interface actions => voter attributes. */
    private const array ATTRIBUTES = [
        self::VIEW     => ChatVoter::VIEW,
        self::SEND     => ChatVoter::SEND,
        self::MODERATE => ChatVoter::MODERATE,
        self::RESOLVE  => ChatVoter::RESOLVE,
        self::EDIT     => ChatVoter::EDIT,
        self::DELETE   => ChatVoter::DELETE,
        self::REACT    => ChatVoter::REACT,
    ];

    /**
     * @param ChatServiceInterface $chat The application service (entity form)
     * @param ChatConversationRepository $conversations Conversations by uuid
     * @param ChatMessageRepository $messages Messages by uuid
     * @param EntityManagerInterface $entityManager Saves a participant row created by join() (the bundle's private
     *                                              entity manager with `doctrine.private_entity_manager: true`)
     * @param ChatVoter $voter The API's authorization rules
     * @param ChatAccess $access Reachability, channels, direct peers and pins
     */
    public function __construct(
        private ChatServiceInterface $chat,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private EntityManagerInterface $entityManager,
        private ChatVoter $voter,
        private ChatAccess $access,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function can(ChatUserInterface $actor, string $conversationUuid, string $action, ?string $messageUuid = null): bool
    {
        $conversation = $this->find($conversationUuid);
        if (!$conversation instanceof ChatConversation) {
            return false;
        }
        if (in_array($action, [self::EDIT, self::DELETE, self::REACT], true)) {
            $message = $messageUuid === null ? null : $this->findMessage($conversation, $messageUuid);

            return $message instanceof ChatMessage && $this->voter->decide(self::ATTRIBUTES[$action], $message, $actor);
        }
        if ($action === self::PIN) {
            return $this->access->isReachable($actor) && $this->access->canPin($conversation, $actor);
        }

        return isset(self::ATTRIBUTES[$action]) && $this->voter->decide(self::ATTRIBUTES[$action], $conversation, $actor);
    }

    /**
     * {@inheritDoc}
     */
    public function openDirect(ChatUserInterface $actor, ChatUserInterface $other): ChatConversationView
    {
        if (!$this->access->canOpenDirect($actor, $other)) {
            throw new ChatNotFoundException('user', $other->getChatPublicId());
        }

        return ChatConversationView::of($this->chat->openDirect($actor, $other));
    }

    /**
     * {@inheritDoc}
     */
    public function openChannel(ChatUserInterface $actor, ChatChannel $channel): ChatConversationView
    {
        // The provider's own channel of exactly this key (look-alikes refused), then the actor's access to it.
        $known = $this->access->channel($channel->key);
        if (!$known instanceof ChatChannel || !$this->access->canUseChannel($actor, $channel->key)) {
            throw new ChatNotFoundException('channel', $channel->key);
        }

        return ChatConversationView::of($this->chat->openChannel($actor, $known));
    }

    /**
     * {@inheritDoc}
     */
    public function openSupport(ChatUserInterface $owner, ?array $context = null): ChatConversationView
    {
        if (!$this->access->isReachable($owner)) {
            throw new ChatNotFoundException('user', $owner->getChatPublicId());
        }

        return ChatConversationView::of($this->chat->openSupport($owner, $context));
    }

    /**
     * {@inheritDoc}
     */
    public function send(ChatUserInterface $author, string $conversationUuid, string $body): ChatMessageView
    {
        return ChatMessageView::of($this->chat->send($author, $this->conversation($author, $conversationUuid, ChatVoter::SEND), $body));
    }

    /**
     * {@inheritDoc}
     */
    public function buzz(ChatUserInterface $author, string $conversationUuid): ChatMessageView
    {
        return ChatMessageView::of($this->chat->buzz($author, $this->conversation($author, $conversationUuid, ChatVoter::SEND)));
    }

    /**
     * {@inheritDoc}
     */
    public function edit(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, string $body): ChatMessageView
    {
        $message = $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::EDIT);
        $this->chat->edit($message, $body);

        return ChatMessageView::of($message);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ChatUserInterface $by, string $conversationUuid, string $messageUuid): void
    {
        $this->chat->delete($by, $this->message($by, $conversationUuid, $messageUuid, ChatVoter::DELETE));
    }

    /**
     * {@inheritDoc}
     */
    public function toggleReaction(ChatUserInterface $user, string $conversationUuid, string $messageUuid, string $emoji): array
    {
        return $this->chat->toggleReaction($user, $this->message($user, $conversationUuid, $messageUuid, ChatVoter::REACT), $emoji);
    }

    /**
     * {@inheritDoc}
     */
    public function resolveSupport(ChatUserInterface $agent, string $conversationUuid): void
    {
        $this->chat->resolveSupport($agent, $this->conversation($agent, $conversationUuid, ChatVoter::RESOLVE));
    }

    /**
     * {@inheritDoc}
     */
    public function reopenSupport(ChatUserInterface $agent, string $conversationUuid): void
    {
        $this->chat->reopenSupport($agent, $this->conversation($agent, $conversationUuid, ChatVoter::RESOLVE));
    }

    /**
     * {@inheritDoc}
     */
    public function markRead(ChatUserInterface $user, string $conversationUuid, int $messageId): bool
    {
        return $this->chat->markRead($user, $this->conversation($user, $conversationUuid, ChatVoter::VIEW), $messageId);
    }

    /**
     * {@inheritDoc}
     */
    public function join(string $conversationUuid, ChatUserInterface $user): ChatParticipantView
    {
        $participant = $this->chat->participant($this->conversation($user, $conversationUuid, ChatVoter::VIEW), $user);
        $this->entityManager->flush();

        return ChatParticipantView::of($participant);
    }

    /**
     * {@inheritDoc}
     */
    public function pin(ChatUserInterface $user, string $conversationUuid, string $messageUuid, bool $pinned): void
    {
        $conversation = $this->find($conversationUuid);
        $message      = $conversation instanceof ChatConversation ? $this->findMessage($conversation, $messageUuid) : null;
        if (!$message instanceof ChatMessage || !$this->access->isReachable($user) || !$this->access->canPin($message->getConversation(), $user)) {
            throw new ChatNotFoundException('message', $messageUuid);
        }
        $this->chat->pin($user, $message, $pinned);
    }

    /**
     * {@inheritDoc}
     */
    public function postSystemLine(string $conversationUuid, string $body, ?string $domain = null, array $params = []): ChatMessageView
    {
        $conversation = $this->find($conversationUuid) ?? throw new ChatNotFoundException('conversation', $conversationUuid);

        return ChatMessageView::of($this->chat->postSystemLine($conversation, $body, $domain, $params));
    }

    /**
     * {@inheritDoc}
     */
    public function mute(ChatUserInterface $user, string $conversationUuid, ?DateTimeImmutable $until): ?ChatParticipantView
    {
        $participant = $this->chat->mute($user, $this->conversation($user, $conversationUuid, ChatVoter::VIEW), $until);

        return $participant instanceof ChatParticipant ? ChatParticipantView::of($participant) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function archive(ChatUserInterface $user, array $conversationUuids, bool $archived): void
    {
        $found = [];
        foreach ($this->conversations->findByUuids(array_values($conversationUuids)) as $conversation) {
            // REQ-SEC-006: only a row whose uuid is exactly one asked for; and only what the user may read (OJ-M2).
            if (in_array($conversation->getUuid(), $conversationUuids, true) && $this->voter->decide(ChatVoter::VIEW, $conversation, $user)) {
                $found[] = $conversation;
            }
        }
        if ($found !== []) {
            $this->chat->archive($user, $found, $archived);
        }
    }

    private function find(string $uuid): ?ChatConversation
    {
        $conversation = $this->conversations->findOneByUuid($uuid);

        return $conversation instanceof ChatConversation && $conversation->getUuid() === $uuid ? $conversation : null;
    }

    private function findMessage(ChatConversation $conversation, string $uuid): ?ChatMessage
    {
        $message = $this->messages->findOneInConversation($conversation, $uuid);

        return $message instanceof ChatMessage && $message->getUuid() === $uuid ? $message : null;
    }

    /**
     * The conversation the actor may `$attribute`; unknown and forbidden are the same answer.
     */
    private function conversation(ChatUserInterface $actor, string $uuid, string $attribute): ChatConversation
    {
        $conversation = $this->find($uuid);
        if (!$conversation instanceof ChatConversation || !$this->voter->decide($attribute, $conversation, $actor)) {
            throw new ChatNotFoundException('conversation', $uuid);
        }

        return $conversation;
    }

    /**
     * The message the actor may `$attribute`; the actor must be able to read its conversation first.
     */
    private function message(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, string $attribute): ChatMessage
    {
        $conversation = $this->conversation($actor, $conversationUuid, ChatVoter::VIEW);
        $message      = $this->findMessage($conversation, $messageUuid);
        if (!$message instanceof ChatMessage || !$this->voter->decide($attribute, $message, $actor)) {
            throw new ChatNotFoundException('message', $messageUuid);
        }

        return $message;
    }
}
