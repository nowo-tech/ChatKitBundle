<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;

/**
 * 2.0: read-only snapshot of one @mention of a user, for a host "Mentions" page (ChatMentionInboxInterface): scalars,
 * public ids and the message view, no Doctrine entity and no user object. Taken when it is built; it never changes
 * afterwards.
 */
final readonly class ChatMentionView
{
    /**
     * @param int $id Mention id (markMentionRead() / markMentionsRead())
     * @param bool $unread Whether the user has not read it yet
     * @param DateTimeImmutable $createdAt When the user was mentioned
     * @param DateTimeImmutable|null $readAt When the user read it; null while unread
     * @param ChatMessageView $message The message that mentions the user (conversation uuid, kind and channel key included)
     * @param string|null $authorDisplayName The author's display name; null for a bot or system line or once the account is gone
     * @param string|null $peerReference Direct conversations: the other person's user key (the host user id as a
     *                                   string, or the string reference); null for other kinds
     */
    public function __construct(
        public int $id,
        public bool $unread,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $readAt,
        public ChatMessageView $message,
        public ?string $authorDisplayName,
        public ?string $peerReference,
    ) {
    }

    /**
     * Snapshot of a mention, seen by the mentioned user.
     *
     * @param ChatMessageMention $mention The mention
     * @param ChatUserInterface $user The mentioned user (the direct peer is the other person)
     *
     * @return self The view
     */
    public static function of(ChatMessageMention $mention, ChatUserInterface $user): self
    {
        $message = $mention->getMessage();

        return new self(
            (int) $mention->getId(),
            $mention->isUnread(),
            $mention->getCreatedAt(),
            $mention->getReadAt(),
            ChatMessageView::of($message),
            $message->getAuthor()?->getChatDisplayName(),
            $message->getConversation()->peerKeyOf($user),
        );
    }
}
