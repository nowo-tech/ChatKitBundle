<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 1.36 (REQ-ARCH-001 rule 3): one target of a forward as ChatForwardPolicyInterface sees it — public ids and keys,
 * never an entity. Built per call by the bundle and never stored (worker mode).
 */
final readonly class ChatForwardRequest
{
    /**
     * @param string $actorPublicId Public id of the user who forwards
     * @param string $sourceMessageUuid Public uuid of the forwarded message
     * @param string $sourceConversationUuid Public uuid of its conversation
     * @param string $sourceConversationKind `direct`, `channel` or `support`
     * @param string|null $sourceChannelKey Channel key of the source conversation (channels only)
     * @param string|null $targetConversationUuid Public uuid of the target conversation; null for a direct conversation not opened yet
     * @param string $targetConversationKind `direct`, `channel` or `support`
     * @param string|null $targetChannelKey Channel key of the target (channels only)
     * @param string|null $recipientPublicId Public id of the other person of a direct target; null otherwise
     */
    public function __construct(
        public string $actorPublicId,
        public string $sourceMessageUuid,
        public string $sourceConversationUuid,
        public string $sourceConversationKind,
        public ?string $sourceChannelKey,
        public ?string $targetConversationUuid,
        public string $targetConversationKind,
        public ?string $targetChannelKey,
        public ?string $recipientPublicId,
    ) {
    }
}
