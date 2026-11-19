<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database\SearchKit;

use Nowo\ChatKitBundle\Tests\Support\SearchKit\ConversationSearchContractCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.38: the SearchKit contract of the "Conversations" group (`chat_channels`) on a real server (MySQL 8.4, MariaDB 11.4, PostgreSQL 16: job "Load readiness", `CHAT_KIT_RACE_DATABASE_URL`).
 *
 * Group `searchkit`: SearchKit's kit supports DBAL 4 only; the DBAL 3 CI cells exclude it.
 */
#[Group('searchkit')]
final class ChatConversationSearchDatabaseTest extends ConversationSearchContractCase
{
    protected function realDatabase(): bool
    {
        return true;
    }
}
