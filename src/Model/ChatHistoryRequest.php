<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 1.36 (REQ-ARCH-001 rule 3): a request to read the previous texts of a message as ChatHistoryPolicyInterface sees it —
 * public ids and keys, never an entity. Built per call by the bundle and never stored (worker mode).
 */
final readonly class ChatHistoryRequest
{
    /**
     * @param string $viewerPublicId Public id of the user who asks
     * @param string $messageUuid Public uuid of the message
     * @param string|null $authorPublicId Public id of its author; null when the account is gone
     * @param string $conversationUuid Public uuid of its conversation
     * @param string $conversationKind `direct`, `channel` or `support`
     * @param string|null $channelKey Channel key (channels only)
     */
    public function __construct(
        public string $viewerPublicId,
        public string $messageUuid,
        public ?string $authorPublicId,
        public string $conversationUuid,
        public string $conversationKind,
        public ?string $channelKey,
    ) {
    }
}
