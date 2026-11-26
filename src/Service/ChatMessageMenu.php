<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatVoter;

use function array_map;

use const DATE_ATOM;

/**
 * 1.35: default ChatMessageActionsInterface (aliased to it): loads the conversation and the message by their public
 * uuids, compared byte for byte, authorizes with ChatVoter and calls ChatMessageActions. Stateless.
 */
final readonly class ChatMessageMenu implements ChatMessageActionsInterface
{
    /**
     * @param ChatMessageActions $actions The message menu's actions
     * @param ChatConversationRepository $conversations Conversations by uuid
     * @param ChatMessageRepository $messages Messages by uuid
     * @param ChatVoter $voter The API's authorization rules
     * @param ChatForwarder|null $forwarder 1.36: forward
     * @param ChatThreads|null $threads 1.36: threads
     * @param ChatEditHistory|null $history 1.36: edit history
     */
    public function __construct(
        private ChatMessageActions $actions,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatVoter $voter,
        private ?ChatForwarder $forwarder = null,
        private ?ChatThreads $threads = null,
        private ?ChatEditHistory $history = null,
        private ?ChatMarkdownRenderer $markdown = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function markSaved(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, bool $saved): bool
    {
        return $this->actions->markSaved($actor, $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::SAVE), $saved);
    }

    /**
     * {@inheritDoc}
     */
    public function savedMessages(ChatUserInterface $actor, ?int $before = null): array
    {
        if (!$this->actions->saveEnabled()) {
            return ['items' => [], 'next' => null];
        }
        $page = $this->actions->savedPage($actor, $before);

        return [
            'items' => array_map(fn (ChatSavedMessage $row): array => ['savedAt' => $row->getSavedAt()->format(DATE_ATOM), 'message' => ChatMessageView::of($row->getMessage(), $this->markdown)], $page['rows']),
            'next'  => $page['next'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function markUnreadFrom(ChatUserInterface $actor, string $conversationUuid, string $messageUuid): ?int
    {
        return $this->actions->markUnreadFrom($actor, $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::MARK_UNREAD));
    }

    /**
     * {@inheritDoc}
     */
    public function messageInfo(ChatUserInterface $actor, string $conversationUuid, string $messageUuid): array
    {
        return $this->actions->info($actor, $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::INFO));
    }

    /**
     * {@inheritDoc}
     */
    public function forward(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, array $targets, ?string $comment, string $idempotencyKey): array
    {
        $source = $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::FORWARD);
        if (!$this->forwarder instanceof ChatForwarder) {
            throw new ChatNotFoundException('message', $messageUuid);
        }
        $this->assertKey($idempotencyKey);

        return array_map(fn (ChatMessage $message): ChatMessageView => ChatMessageView::of($message, $this->markdown), $this->forwarder->forward($actor, $source, $targets, $comment, $idempotencyKey)[0]);
    }

    /**
     * {@inheritDoc}
     */
    public function thread(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, ?int $before = null): array
    {
        if ($this->threads?->enabled() !== true) {
            throw new ChatNotFoundException('message', $messageUuid);
        }
        $root = ChatThreads::rootOf($this->message($actor, $conversationUuid, $messageUuid, null));
        $page = $this->threads->page($actor, $root, $before, null);

        return ['root' => ChatMessageView::of($root, $this->markdown), 'replies' => array_map(fn (ChatMessage $message): ChatMessageView => ChatMessageView::of($message, $this->markdown), $page['replies']), 'hasMore' => $page['hasMore'], 'readUpTo' => $page['readUpTo']];
    }

    /**
     * {@inheritDoc}
     */
    public function replyInThread(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, string $body, string $idempotencyKey): ChatMessageView
    {
        $root = $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::THREAD);
        if (!$this->threads instanceof ChatThreads) {
            throw new ChatNotFoundException('message', $messageUuid);
        }
        $this->assertKey($idempotencyKey);

        return ChatMessageView::of($this->threads->reply($actor, $root, $body, $idempotencyKey)[0], $this->markdown);
    }

    /**
     * {@inheritDoc}
     */
    public function revisions(ChatUserInterface $actor, string $conversationUuid, string $messageUuid): array
    {
        $message = $this->message($actor, $conversationUuid, $messageUuid, ChatVoter::HISTORY);

        return $this->history?->revisions($actor, $message) ?? [];
    }

    /**
     * @throws InvalidArgumentException `idempotency_key_invalid` (2.6, audit ZCG-v-I1: also a key in the client API's
     *                                  `client:` namespace, in any letter case)
     */
    private function assertKey(string $key): void
    {
        if (!ChatIdempotency::isValidKey($key) || ChatIdempotency::isClientKey($key)) {
            throw new InvalidArgumentException('idempotency_key_invalid');
        }
    }

    /**
     * The message the actor may `$attribute` (null: read), in a conversation the actor may read; unknown and forbidden
     * are the same.
     */
    private function message(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, ?string $attribute): ChatMessage
    {
        $conversation = $this->conversations->findOneByUuid($conversationUuid);
        if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $conversationUuid || !$this->voter->decide(ChatVoter::VIEW, $conversation, $actor)) {
            throw new ChatNotFoundException('conversation', $conversationUuid);
        }
        $message = $this->messages->findOneInConversation($conversation, $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid || ($attribute !== null && !$this->voter->decide($attribute, $message, $actor))) {
            throw new ChatNotFoundException('message', $messageUuid);
        }

        return $message;
    }
}
