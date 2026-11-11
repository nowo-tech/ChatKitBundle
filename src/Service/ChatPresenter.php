<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Avatar\ChatAvatars;
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
     * @param ChatAvatars|null $avatars 1.16: profile picture URLs of the request; null gives null everywhere
     */
    public function __construct(
        private ChatMarkdownRenderer $markdown,
        private TranslatorInterface $translator,
        private ChatSupportTopics $topics,
        private ChatSupportPolicy $policy,
        private ?ChatReferences $references = null,
        private ?ChatAvatars $avatars = null,
    ) {
    }

    /**
     * 1.16: resolves the profile pictures of a response's people in one resolver call. Callers pass only people the
     * viewer may see (after the access filters); the shapes built afterwards read the memo.
     *
     * @param iterable<ChatUserInterface|null> $users The people of the response
     */
    public function primeAvatars(iterable $users): void
    {
        $this->avatars?->prime($users);
    }

    /**
     * 1.16: the profile picture URL of a person shown in a response (contacts, members, the dock's `me`).
     *
     * @param ChatUserInterface|null $user The person; null gives null
     *
     * @return string|null The URL, or null for the initials
     */
    public function avatarUrl(?ChatUserInterface $user): ?string
    {
        return $this->avatars?->urlOf($user);
    }

    /**
     * 1.16: whose picture a conversation summary shows: the peer of a direct thread, the requester of a support thread
     * seen by an agent; nobody for a channel or the requester's own support thread.
     *
     * @param ChatConversation $conversation The conversation
     * @param ChatUserInterface $viewer The user the summary is built for
     * @param ChatUserInterface|null $peer The direct peer; null otherwise or when gone
     * @param bool $viewerIsAgent Whether the viewer is a support agent
     *
     * @return ChatUserInterface|null The person, or null for no picture
     */
    public function avatarPerson(ChatConversation $conversation, ChatUserInterface $viewer, ?ChatUserInterface $peer, bool $viewerIsAgent): ?ChatUserInterface
    {
        if ($conversation->isDirect()) {
            return $peer;
        }
        $owner = $conversation->isSupport() ? $conversation->getSupportOwner() : null;

        return $viewerIsAgent && $owner instanceof ChatUserInterface && $owner->getId() !== $viewer->getId() ? $owner : null;
    }

    /**
     * Conversation summary for the dock list and header: title / subtitle / initials by kind, status, unread
     * count and a preview of the last message. Since 1.26.1 `lastMessageId` names that message: open windows compare it
     * with their newest one (`lastMessageAt` has second precision).
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
     * @param array{status: string, text: string|null, emoji: string|null}|null $peerStatus What the viewer sees of the direct peer's status (ChatPresence::forViewer()); null = offline, no text
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
        ?array $peerStatus = null,
    ): array {
        [$title, $subtitle, $initials] = match (true) {
            $conversation->isDirect() => [
                $peer?->getChatDisplayName() ?? $this->trans('chat.deleted_user'),
                $peer?->getChatEmail() ?? '',
                $peer?->getChatInitials() ?? '?',
            ],
            // 1.24: the channel's subtitle (topic) and icon, when the application gives them.
            $conversation->isChannel() => ['#' . ($channel->label ?? ''), $this->clip($channel?->subtitle, ChatChannel::SUBTITLE_MAX_LENGTH) ?? $this->trans('chat.channel'), $this->clip($channel?->icon, ChatChannel::ICON_MAX_LENGTH) ?? '#'],
            default                    => $this->supportTitle($conversation, $viewer, $viewerIsAgent),
        };

        return [
            'uuid'     => $conversation->getUuid(),
            'kind'     => $conversation->getKind()->value,
            'title'    => $title,
            'subtitle' => $subtitle,
            'initials' => $initials,
            // 1.16: the picture of the person the bubble shows (sanitised; null: the initials).
            'avatarUrl'      => $this->avatarUrl($this->avatarPerson($conversation, $viewer, $peer, $viewerIsAgent)),
            'peerUuid'       => $peer?->getChatPublicId(),
            'channelKey'     => $conversation->getChannelKey(),
            'supportStatus'  => $conversation->getSupportStatus()?->value,
            'supportContext' => $conversation->getSupportContext(),
            'ticket'         => $this->ticket($conversation),
            'canSend'        => $canSend,
            'peerOnline'     => $peerOnline,
            // 1.10: available, busy, away, dnd or offline (invisible people are offline), and the custom status.
            'peerStatus'      => $peerStatus['status'] ?? 'offline',
            'peerStatusText'  => $peerStatus['text'] ?? null,
            'peerStatusEmoji' => $peerStatus['emoji'] ?? null,
            'unread'          => $unread,
            'lastMessageAt'   => ($last?->getCreatedAt() ?? $conversation->getLastMessageAt())->format(DATE_ATOM),
            'lastMessageId'   => $last?->getId(),
            'lastMessage'     => $last instanceof ChatMessage ? [
                'preview' => match (true) {
                    $last->isDeleted(), $last->isBuzz() => '',
                    $last->isBot(), $last->isSystem()   => mb_substr($this->botText($last), 0, 90),
                    default                             => $this->plainText($last->getBody(), $viewer, 90),
                },
                'buzz'       => $last->isBuzz(),
                'pin'        => $last->isPinLine(),
                'deleted'    => $last->isDeleted(),
                'moderated'  => $last->isModerated(),
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
        $system   = $message->isSystem();
        $ids      = $message->isDeleted() || $system ? [] : ChatMentions::parse($message->getBody());
        $mentions = [];
        foreach ($ids as $uuid => $name) {
            $mentions[] = ['uuid' => $uuid, 'name' => $name];
        }

        // 1.24: `@channel` / `@here` (only stored when the application allowed it).
        $all = $message->isDeleted() ? null : $message->getMentionAll();
        $bot = $message->isBot();
        // #references: cards the viewer may open, the neutral chip for the rest; empty slots without a viewer.
        $filled = $bot || $system || $message->isDeleted() || !$this->references instanceof ChatReferences
            ? ['html' => $message->getBodyHtml(), 'references' => [], 'pending' => false]
            : $this->references->fill($message->getBodyHtml(), $message->getBody(), $viewer);

        return [
            'id'              => $message->getId(),
            'uuid'            => $message->getUuid(),
            'authorUuid'      => $author?->getChatPublicId(),
            'authorName'      => $bot ? $this->trans('chat.support_bot') : ($author?->getChatDisplayName() ?? $this->trans('chat.deleted_user')),
            'authorInitials'  => $bot ? '★' : ($author?->getChatInitials() ?? '?'),
            'authorAvatarUrl' => $bot ? null : $this->avatarUrl($author),
            'mine'            => $viewer instanceof ChatUserInterface && $message->isAuthoredBy($viewer),
            'kind'            => $message->getKind(),
            'html'            => match (true) {
                $bot => $this->botHtml($message),
                // 1.24: a system line is always text (its key translated, its parameters escaped with it).
                $system => '<p>' . $this->escape($this->botText($message)) . '</p>',
                default => $filled['html'],
            },
            'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
            'edited'    => $message->isEdited(),
            'deleted'   => $message->isDeleted(),
            'moderated' => $message->isModerated(),
            'reactions' => $reactions,
            // Members the message mentions (from its stored tokens, no query) and whether the viewer is one of them.
            'mentions'   => $mentions,
            'mentionsMe' => $viewer instanceof ChatUserInterface && (isset($ids[$viewer->getChatPublicId()]) || ($all !== null && !$message->isAuthoredBy($viewer))),
            // Cards of the references the viewer may open (the editor turns them back into tokens); `refsPending`:
            // a viewer-neutral payload the client fetches again for its user — its #reference slots, or a bot line,
            // translated in the locale of the request that posted it (an agent's), not the reader's.
            'references'  => $filled['references'],
            'refsPending' => $filled['pending'] || (($bot || $system) && !$viewer instanceof ChatUserInterface),
            // 1.10: pinned to the top of the conversation; a pin line names the message it is about.
            'pinned'        => $message->getPinnedAt() instanceof DateTimeImmutable,
            'pinnedMessage' => $message->getPinnedMessageUuid(),
        ] + ($all === null ? [] : ['mentionAll' => $all]);
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

        return '<p>' . ($emoji === '' ? '' : '<span class="nowo-chat-deco" aria-hidden="true">' . $this->escape($emoji) . '</span>')
            . $this->escape($this->botText($message)) . '</p>';
    }

    /**
     * 1.24: an application-given channel text for the dock: trimmed, without control characters, line separators,
     * bidirectional marks / overrides / isolates, zero-width spaces and BOMs (the zero-width joiner of emoji sequences
     * stays), at most `$max` characters; null when nothing is left.
     */
    private function clip(?string $text, int $max): ?string
    {
        $text = mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', ' ', (string) $text)), 0, $max);

        return $text === '' ? null : $text;
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
