<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Model;

/**
 * 2.6 (spec 259): who writes where, for ChatComposerActionProviderInterface and ChatMessageMenuSlotProviderInterface.
 * Scalars only (REQ-ARCH-001).
 */
final readonly class ChatComposerScope
{
    /**
     * @param string $viewerPublicId The person
     * @param string $conversationUuid The conversation
     * @param string $conversationKind `direct`, `group`, `channel` or `support`
     * @param string|null $channelKey The channel key (channels only)
     * @param string|null $contextKey The client API context of the request; null on the web
     * @param bool $canSend Whether the person may write in the conversation (menu slots are also offered to readers)
     */
    public function __construct(
        public string $viewerPublicId,
        public string $conversationUuid,
        public string $conversationKind,
        public ?string $channelKey = null,
        public ?string $contextKey = null,
        public bool $canSend = true,
    ) {
    }
}
