<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.6 (spec 259): who asks a card provider, where. Scalars only (REQ-ARCH-001): the person's public id (resolve the
 * person in the host's own directory), the conversation, the client API context (null on the web) and whether the
 * request comes through the client API (Beacon Desktop).
 */
final readonly class ChatCardScope
{
    /**
     * @param string $viewerPublicId The author (canShare) or the reader (render)
     * @param string $conversationUuid The conversation of the message
     * @param string $conversationKind `direct`, `group`, `channel` or `support`
     * @param string|null $channelKey The channel key (channels only)
     * @param string|null $contextKey The client API context of the request; null on the web
     * @param bool $api Whether the request comes through the client API
     */
    public function __construct(
        public string $viewerPublicId,
        public string $conversationUuid,
        public string $conversationKind,
        public ?string $channelKey = null,
        public ?string $contextKey = null,
        public bool $api = false,
    ) {
    }

    /**
     * Cache key of the scope (one render per card and scope in a request).
     */
    public function key(): string
    {
        return $this->viewerPublicId . "\n" . $this->conversationUuid . "\n" . ($this->contextKey ?? '') . "\n" . ($this->api ? '1' : '0');
    }
}
