<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use const DATE_ATOM;

/**
 * JSON shapes for the dock. Message HTML is the server-sanitised render; every other string is plain text the
 * client inserts with textContent.
 */
final readonly class ChatPresenter
{
    public const string DOMAIN = 'NowoChatKitBundle';

    /**
     * Builds the presenter from the Markdown renderer (previews) and the translator (fallback titles).
     *
     * @param ChatMarkdownRenderer $markdown Builds plain-text previews of the last message
     * @param TranslatorInterface $translator Translates fallback titles in the bundle domain
     */
    public function __construct(
        private ChatMarkdownRenderer $markdown,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Conversation summary for the dock list and header: title / subtitle / initials by kind, status, unread
     * count and a preview of the last message.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $viewer The user the summary is built for
     * @param ChatUserInterface|null $peer The other person of a direct thread; null otherwise or when gone
     * @param ChatChannel|null $channel The channel of a channel conversation; null otherwise
     * @param bool $canSend Whether the viewer may write
     * @param int $unread Unread messages for the viewer
     * @param ChatMessage|null $last The newest message; null when none is known
     * @param bool $viewerIsAgent Whether the viewer is a support agent (support thread titles)
     * @param bool $peerOnline Whether the direct peer is online
     *
     * @return array<string, mixed> The JSON shape
     */
    public function conversation(
        ChatConversation $conversation,
        ChatUserInterface $viewer,
        ?ChatUserInterface $peer,
        ?ChatChannel $channel,
        bool $canSend,
        int $unread = 0,
        ?ChatMessage $last = null,
        bool $viewerIsAgent = false,
        bool $peerOnline = false,
    ): array {
        [$title, $subtitle, $initials] = match (true) {
            $conversation->isDirect() => [
                $peer?->getChatDisplayName() ?? $this->trans('chat.deleted_user'),
                $peer?->getChatEmail() ?? '',
                $peer?->getChatInitials() ?? '?',
            ],
            $conversation->isChannel() => ['#' . ($channel->label ?? ''), $this->trans('chat.channel'), '#'],
            default                    => $this->supportTitle($conversation, $viewer, $viewerIsAgent),
        };

        return [
            'uuid'           => $conversation->getUuid(),
            'kind'           => $conversation->getKind()->value,
            'title'          => $title,
            'subtitle'       => $subtitle,
            'initials'       => $initials,
            'peerUuid'       => $peer?->getChatPublicId(),
            'channelKey'     => $conversation->getChannelKey(),
            'supportStatus'  => $conversation->getSupportStatus()?->value,
            'supportContext' => $conversation->getSupportContext(),
            'canSend'        => $canSend,
            'peerOnline'     => $peerOnline,
            'unread'         => $unread,
            'lastMessageAt'  => ($last?->getCreatedAt() ?? $conversation->getLastMessageAt())->format(DATE_ATOM),
            'lastMessage'    => $last instanceof ChatMessage ? [
                'preview'    => $last->isDeleted() || $last->isBuzz() ? '' : $this->markdown->plainText($last->getBody(), 90),
                'buzz'       => $last->isBuzz(),
                'deleted'    => $last->isDeleted(),
                'mine'       => $last->isAuthoredBy($viewer),
                'authorName' => $last->getAuthor()?->getChatDisplayName(),
            ] : null,
        ];
    }

    /**
     * Message shape for the dock (sanitized HTML, author, flags and reactions).
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface|null $viewer The reader (sets `mine`); null for viewer-neutral realtime payloads
     * @param array<string, list<string>> $reactions emoji => public ids of the people who reacted
     *
     * @return array<string, mixed> The JSON shape
     */
    public function message(ChatMessage $message, ?ChatUserInterface $viewer, array $reactions = []): array
    {
        $author = $message->getAuthor();

        return [
            'id'             => $message->getId(),
            'uuid'           => $message->getUuid(),
            'authorUuid'     => $author?->getChatPublicId(),
            'authorName'     => $author?->getChatDisplayName() ?? $this->trans('chat.deleted_user'),
            'authorInitials' => $author?->getChatInitials() ?? '?',
            'mine'           => $viewer instanceof ChatUserInterface && $message->isAuthoredBy($viewer),
            'kind'           => $message->getKind(),
            'html'           => $message->getBodyHtml(),
            'createdAt'      => $message->getCreatedAt()->format(DATE_ATOM),
            'edited'         => $message->isEdited(),
            'deleted'        => $message->isDeleted(),
            'moderated'      => $message->isModerated(),
            'reactions'      => $reactions,
        ];
    }

    /**
     * @return array{string, string, string}
     */
    private function supportTitle(ChatConversation $conversation, ChatUserInterface $viewer, bool $viewerIsAgent): array
    {
        $owner = $conversation->getSupportOwner();
        if ($viewerIsAgent && $owner instanceof ChatUserInterface && $owner->getId() !== $viewer->getId()) {
            return [$owner->getChatDisplayName(), $this->trans('chat.support_thread'), $owner->getChatInitials()];
        }

        return [$this->trans('chat.support_title'), $this->trans('chat.support_hint'), '★'];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], self::DOMAIN);
    }
}
