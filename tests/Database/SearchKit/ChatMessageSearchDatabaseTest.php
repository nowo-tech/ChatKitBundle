<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database\SearchKit;

use Nowo\ChatKitBundle\Tests\Support\SearchKit\MessageSearchContractCase;

/**
 * 1.38: the SearchKit contract of the "Messages" group on a real server (MySQL 8.4, MariaDB 11.4, PostgreSQL 16: job "Load readiness", `CHAT_KIT_RACE_DATABASE_URL`).
 */
final class ChatMessageSearchDatabaseTest extends MessageSearchContractCase
{
    protected function realDatabase(): bool
    {
        return true;
    }
}
