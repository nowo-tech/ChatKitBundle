<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.4 (client API v1): what the host's ChatClientContextPolicyInterface learns about a conversation to tell whether it
 * belongs to a context: its public uuid, its kind (`direct`, `channel`, `support`), its channel key (channels only) and
 * the public ids of the people of a direct conversation. No message, title or entity.
 */
final readonly class ChatClientConversationRef
{
    /**
     * @param string $uuid The conversation's public uuid
     * @param string $kind `direct`, `channel` or `support`
     * @param string|null $channelKey The channel key, such as `project:<uuid>`; null for other kinds
     * @param list<string> $memberPublicIds The public ids of the two people of a direct conversation; empty for other kinds
     */
    public function __construct(
        public string $uuid,
        public string $kind,
        public ?string $channelKey = null,
        public array $memberPublicIds = [],
    ) {
    }
}
