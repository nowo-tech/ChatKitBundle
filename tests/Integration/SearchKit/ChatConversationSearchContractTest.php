<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\SearchKit;

use Nowo\ChatKitBundle\Tests\Support\SearchKit\ConversationSearchContractCase;

/**
 * 1.38: the SearchKit contract of the "Conversations" group (`chat_channels`) on in-memory SQLite.
 */
final class ChatConversationSearchContractTest extends ConversationSearchContractCase
{
    protected function realDatabase(): bool
    {
        return false;
    }
}
