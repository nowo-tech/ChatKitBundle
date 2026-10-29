<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use Nowo\ChatKitBundle\Model\ChatSupportTopic;

/**
 * The support ticket topics offered to users, in display order. The default implementation reads
 * `nowo_chat_kit.profiles.<profile>.support.topics`; an application aliases this interface to manage topics as data.
 */
interface ChatSupportTopicProviderInterface
{
    /**
     * Topics offered when a ticket opens, in display order (keys must be unique).
     *
     * @return list<ChatSupportTopic> The topics
     */
    public function topics(): array;
}
