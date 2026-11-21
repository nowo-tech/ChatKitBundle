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
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

use function array_values;
use function assert;
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
    private TransactionRunnerInterface $transactions;

    private ChatConversationPurger $purger;

    /** Interface actions => voter attributes. */
    private const array ATTRIBUTES = [
        self::VIEW     => ChatVoter::VIEW,
        self::SEND     => ChatVoter::SEND,
        self::MODERATE => ChatVoter::MODERATE,
        self::RESOLVE  => ChatVoter::RESOLVE,
        self::EDIT     => ChatVoter::EDIT,
        self::DELETE   => ChatVoter::DELETE,
        self::REACT    => ChatVoter::REACT,
        // 1.35
        self::SAVE        => ChatVoter::SAVE,
        self::MARK_UNREAD => ChatVoter::MARK_UNREAD,
        self::INFO        => ChatVoter::INFO,
        // 1.36
        self::FORWARD => ChatVoter::FORWARD,
        self::THREAD  => ChatVoter::THREAD,
        self::HISTORY => ChatVoter::HISTORY,
    ];

    /**
     * @param ChatServiceInterface $chat The application service (entity form)
     * @param ChatConversationRepository $conversations Conversations by uuid
     * @param ChatMessageRepository $messages Messages by uuid
     * @param EntityManagerInterface $entityManager Saves a participant row created by join() (the bundle's private
     *                                              entity manager)
     * @param ChatVoter $voter The API's authorization rules
     * @param ChatAccess $access Reachability, channels, direct peers and pins
     * @param TransactionRunnerInterface|null $transactions 1.30: ChatKit's runner (join()); null builds the default
     * @param ChatParticipantRepository|null $participantRows 1.30: participant rows of join(); null asks the manager
     * @param ClockInterface $clock 1.30: join time of a new participant row
     * @param ChatMarkdownRenderer|null $markdown Renders the HTML of the views; null keeps the stored render
     * @param ChatConversationPurger|null $purger 2.0: deleteConversation() and purgeChannel(); null builds one without events
     */
    public function __construct(
        private ChatServiceInterface $chat,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private EntityManagerInterface $entityManager,
        private ChatVoter $voter,
        private ChatAccess $access,
        ?TransactionRunnerInterface $transactions = null,
        private ?ChatParticipantRepository $participantRows = null,
        private ClockInterface $clock = new Clock(),
        private ?ChatMarkdownRenderer $markdown = null,
        ?ChatConversationPurger $purger = null,
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
        $this->purger       = $purger ?? new ChatConversationPurger($entityManager, $conversations, $this->transactions);
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
        if (in_array($action, [self::EDIT, self::DELETE, self::REACT, self::SAVE, self::MARK_UNREAD, self::INFO, self::FORWARD, self::THREAD, self::HISTORY], true)) {
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
        return ChatMessageView::of($this->chat->send($author, $this->conversation($author, $conversationUuid, ChatVoter::SEND), $body), $this->markdown);
    }

    /**
     * {@inheritDoc}
     */
    public function buzz(ChatUserInterface $author, string $conversationUuid): ChatMessageView
    {
        return ChatMessageView::of($this->chat->buzz($author, $this->conversation($author, $conversationUuid, ChatVoter::SEND)), $this->markdown);
    }

    /**
     * {@inheritDoc}
     */
    public function edit(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, string $body): ChatMessageView
    {
        $message = $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::EDIT);
        $this->chat->edit($message, $body);

        return ChatMessageView::of($message, $this->markdown);
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
        $conversation = $this->conversation($user, $conversationUuid, ChatVoter::VIEW);
        // 1.28: the look-alike refusal before any write. 1.30: one unit of work; a row another request of the same person
        // wrote at the same moment is read (savepoint insert, audit QD-M2).
        $rows = $this->participantRows ?? $this->entityManager->getRepository(ChatParticipant::class);
        assert($rows instanceof ChatParticipantRepository);
        if (!$rows->findOneFor($conversation, $user) instanceof ChatParticipant) {
            $rows->assertInsertable($user);
        }
        $participant = ChatKitWrites::runRecovering($this->transactions, $this->entityManager, fn (): ChatParticipant => $rows->claim(ChatKitWrites::attach($this->entityManager, $conversation), $user, $this->clock->now()));

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

        return ChatMessageView::of($this->chat->postSystemLine($conversation, $body, $domain, $params), $this->markdown);
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

    /**
     * {@inheritDoc}
     */
    public function deleteConversation(string $conversationUuid): bool
    {
        return $this->purger->purgeConversation($conversationUuid);
    }

    /**
     * {@inheritDoc}
     */
    public function purgeChannel(string $channelKey): bool
    {
        return $this->purger->purgeChannel($channelKey);
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
