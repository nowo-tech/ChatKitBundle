<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 2.5 (client API v1 writes): a client wrote through the API, after the write committed. For the host's audit log
 * (which token wrote what): ids only — never the token, the text, the emoji or a full IP. The chat's own events
 * (ChatMessageSentEvent, ChatMentionEvent, ChatThreadReplyEvent, ChatMessageEditedEvent, ChatMessageDeletedEvent) are
 * dispatched as for the web UI; this one only adds the client. A replay (same Idempotency-Key) is dispatched again with
 * `replayed: true` and wrote nothing. A listener must not throw.
 */
final class ChatClientApiWriteEvent extends Event
{
    public const string SEND            = 'send';
    public const string THREAD_REPLY    = 'thread_reply';
    public const string REACTION_ADD    = 'reaction_add';
    public const string REACTION_REMOVE = 'reaction_remove';
    public const string EDIT            = 'edit';
    public const string DELETE          = 'delete';

    /**
     * @param string $action One of the constants of this class
     * @param string $tokenId The token's id from the principal (never the token)
     * @param string $userPublicId The public id of the token's person
     * @param string $conversationUuid The conversation written to
     * @param string|null $messageUuid The message created or changed (for reactions: the message reacted to)
     * @param bool $replayed True when an earlier request with the same Idempotency-Key wrote it (nothing written now)
     * @param string|null $contextKey The request's context; null when the host has no tenants
     * @param string|null $ipPrefix The client's network: an IPv4 /24 or IPv6 /64; null when unknown
     */
    public function __construct(
        public readonly string $action,
        public readonly string $tokenId,
        public readonly string $userPublicId,
        public readonly string $conversationUuid,
        public readonly ?string $messageUuid,
        public readonly bool $replayed,
        public readonly ?string $contextKey = null,
        public readonly ?string $ipPrefix = null,
    ) {
    }
}
