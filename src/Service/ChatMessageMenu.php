<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

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
     */
    public function __construct(
        private ChatMessageActions $actions,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatVoter $voter,
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
            'items' => array_map(static fn (ChatSavedMessage $row): array => ['savedAt' => $row->getSavedAt()->format(DATE_ATOM), 'message' => ChatMessageView::of($row->getMessage())], $page['rows']),
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
     * The message the actor may `$attribute`, in a conversation the actor may read; unknown and forbidden are the same.
     */
    private function message(ChatUserInterface $actor, string $conversationUuid, string $messageUuid, string $attribute): ChatMessage
    {
        $conversation = $this->conversations->findOneByUuid($conversationUuid);
        if (!$conversation instanceof ChatConversation || $conversation->getUuid() !== $conversationUuid || !$this->voter->decide(ChatVoter::VIEW, $conversation, $actor)) {
            throw new ChatNotFoundException('conversation', $conversationUuid);
        }
        $message = $this->messages->findOneInConversation($conversation, $messageUuid);
        if (!$message instanceof ChatMessage || $message->getUuid() !== $messageUuid || !$this->voter->decide($attribute, $message, $actor)) {
            throw new ChatNotFoundException('message', $messageUuid);
        }

        return $message;
    }
}
