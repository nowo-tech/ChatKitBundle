<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Support\ChatSupportPolicy;
use Nowo\ChatKitBundle\Support\ChatSupportTopics;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

use const DATE_ATOM;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

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
     * @param ChatSupportTopics $topics Topic labels of support tickets and bot messages
     * @param ChatSupportPolicy $policy Readable ticket numbers and the read-only rule
     * @param ChatReferences|null $references Fills #reference cards for the viewer; null leaves the slots empty
     */
    public function __construct(
        private ChatMarkdownRenderer $markdown,
        private TranslatorInterface $translator,
        private ChatSupportTopics $topics,
        private ChatSupportPolicy $policy,
        private ?ChatReferences $references = null,
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
            'ticket'         => $this->ticket($conversation),
            'canSend'        => $canSend,
            'peerOnline'     => $peerOnline,
            'unread'         => $unread,
            'lastMessageAt'  => ($last?->getCreatedAt() ?? $conversation->getLastMessageAt())->format(DATE_ATOM),
            'lastMessage'    => $last instanceof ChatMessage ? [
                'preview' => match (true) {
                    $last->isDeleted(), $last->isBuzz() => '',
                    $last->isBot()                      => mb_substr($this->botText($last), 0, 90),
                    default                             => $this->plainText($last->getBody(), $viewer, 90),
                },
                'buzz'       => $last->isBuzz(),
                'deleted'    => $last->isDeleted(),
                'mine'       => $last->isAuthoredBy($viewer),
                'authorName' => $last->getAuthor()?->getChatDisplayName(),
            ] : null,
        ];
    }

    /**
     * Message shape for the dock (sanitized HTML with the viewer's #reference cards, author, flags, reactions and.
     *
     * @mentions).
     *
     * @param ChatMessage $message The message
     * @param ChatUserInterface|null $viewer The reader (sets `mine`); null for viewer-neutral realtime payloads
     * @param array<string, list<string>> $reactions emoji => public ids of the people who reacted
     *
     * @return array<string, mixed> The JSON shape
     */
    public function message(ChatMessage $message, ?ChatUserInterface $viewer, array $reactions = []): array
    {
        $author   = $message->getAuthor();
        $ids      = $message->isDeleted() ? [] : ChatMentions::parse($message->getBody());
        $mentions = [];
        foreach ($ids as $uuid => $name) {
            $mentions[] = ['uuid' => $uuid, 'name' => $name];
        }

        $bot = $message->isBot();
        // #references: cards the viewer may open, the neutral chip for the rest; empty slots without a viewer.
        $filled = $bot || $message->isDeleted() || !$this->references instanceof ChatReferences
            ? ['html' => $message->getBodyHtml(), 'references' => [], 'pending' => false]
            : $this->references->fill($message->getBodyHtml(), $message->getBody(), $viewer);

        return [
            'id'             => $message->getId(),
            'uuid'           => $message->getUuid(),
            'authorUuid'     => $author?->getChatPublicId(),
            'authorName'     => $bot ? $this->trans('chat.support_bot') : ($author?->getChatDisplayName() ?? $this->trans('chat.deleted_user')),
            'authorInitials' => $bot ? '★' : ($author?->getChatInitials() ?? '?'),
            'mine'           => $viewer instanceof ChatUserInterface && $message->isAuthoredBy($viewer),
            'kind'           => $message->getKind(),
            'html'           => $bot ? $this->botHtml($message) : $filled['html'],
            'createdAt'      => $message->getCreatedAt()->format(DATE_ATOM),
            'edited'         => $message->isEdited(),
            'deleted'        => $message->isDeleted(),
            'moderated'      => $message->isModerated(),
            'reactions'      => $reactions,
            // Members the message mentions (from its stored tokens, no query) and whether the viewer is one of them.
            'mentions'   => $mentions,
            'mentionsMe' => $viewer instanceof ChatUserInterface && isset($ids[$viewer->getChatPublicId()]),
            // Cards of the references the viewer may open (the editor turns them back into tokens); `refsPending`:
            // a viewer-neutral payload the client fetches again for its user — its #reference slots, or a bot line,
            // translated in the locale of the request that posted it (an agent's), not the reader's.
            'references'  => $filled['references'],
            'refsPending' => $filled['pending'] || ($bot && !$viewer instanceof ChatUserInterface),
        ];
    }

    /**
     * Ticket block of a support conversation: readable number, topic, status, assignee, dates and the read-only flag.
     *
     * @param ChatConversation $conversation The conversation
     *
     * @return array<string, mixed>|null The block; null for other kinds (a draft has a null `number`)
     */
    public function ticket(ChatConversation $conversation): ?array
    {
        if (!$conversation->isSupport()) {
            return null;
        }
        $status   = ($conversation->getSupportStatus() ?? SupportStatus::Open)->value;
        $assignee = $conversation->getAssignee();
        $owner    = $conversation->getSupportOwner();

        return [
            'uuid'          => $conversation->getUuid(),
            'number'        => $this->policy->format($conversation->getTicketNumber()),
            'topic'         => $conversation->getTicketTopic(),
            'topicLabel'    => $this->topics->label($conversation->getTicketTopic()),
            'topicEmoji'    => $this->topics->topicEmoji($conversation->getTicketTopic()),
            'status'        => $status,
            'statusLabel'   => $this->trans('chat.support_status_' . $status),
            'statusEmoji'   => $this->topics->statusEmoji($status),
            'requester'     => $owner instanceof ChatUserInterface ? ['uuid' => $owner->getChatPublicId(), 'name' => $owner->getChatDisplayName()] : null,
            'assignee'      => $assignee instanceof ChatUserInterface ? ['uuid' => $assignee->getChatPublicId(), 'name' => $assignee->getChatDisplayName()] : null,
            'createdAt'     => $conversation->getCreatedAt()->format(DATE_ATOM),
            'lastMessageAt' => $conversation->getLastMessageAt()->format(DATE_ATOM),
            'resolvedAt'    => $conversation->getResolvedAt()?->format(DATE_ATOM),
            'readOnly'      => $this->policy->isReadOnly($conversation),
        ];
    }

    /**
     * Text of a bot message for the current locale (its key translated, `%topic%` as the topic label).
     *
     * @param ChatMessage $message A bot message
     *
     * @return string The text
     */
    public function botText(ChatMessage $message): string
    {
        $meta   = $message->getMeta() ?? [];
        $params = [];
        foreach ($meta['params'] ?? [] as $name => $value) {
            $params['%' . $name . '%'] = $value;
        }
        $topic = $meta['topic'] ?? null;
        if (is_string($topic)) {
            $params['%topic%'] = $this->topics->label($topic);
        }

        return $this->topics->text($message->getBody(), $meta['domain'] ?? null, $params);
    }

    /**
     * HTML of a bot message: its text in a paragraph, after the line's decorative emoji (`aria-hidden`) when any.
     */
    private function botHtml(ChatMessage $message): string
    {
        $topic = $message->getMeta()['topic'] ?? null;
        $emoji = $this->topics->botEmoji($message->getBody(), is_string($topic) ? $topic : null);

        return '<p>' . ($emoji === '' ? '' : '<span class="nowo-chat-emoji" aria-hidden="true">' . $this->escape($emoji) . '</span>')
            . $this->escape($this->botText($message)) . '</p>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array{string, string, string}
     */
    private function supportTitle(ChatConversation $conversation, ChatUserInterface $viewer, bool $viewerIsAgent): array
    {
        $owner  = $conversation->getSupportOwner();
        $number = $this->policy->format($conversation->getTicketNumber());
        $ticket = $number === null ? null : $number . ' · ' . $this->topics->label($conversation->getTicketTopic());
        if ($viewerIsAgent && $owner instanceof ChatUserInterface && $owner->getId() !== $viewer->getId()) {
            return [$owner->getChatDisplayName(), $ticket ?? $this->trans('chat.support_thread'), $owner->getChatInitials()];
        }

        return $ticket === null
            ? [$this->trans('chat.support_title'), $this->trans('chat.support_hint'), '★']
            : [$ticket, $this->trans('chat.support_title'), '★'];
    }

    /**
     * Plain text of a message for the viewer (#references as the viewer may see them).
     */
    private function plainText(string $body, ChatUserInterface $viewer, int $maxLength): string
    {
        return $this->references instanceof ChatReferences
            ? $this->references->plainText($this->markdown, $body, $viewer, $maxLength)
            : $this->markdown->plainText($body, $maxLength);
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], self::DOMAIN);
    }
}
