<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Query\ChatQueryPortsCases;

/**
 * 2.2 (DDD-host checklist B7/B8): the host query ports on the server of CHAT_KIT_RACE_DATABASE_URL (MySQL 8.4, MariaDB
 * 11.4 and PostgreSQL 16, DBAL 4 and 3: job "Load readiness"). On MySQL / MariaDB the uuid and channel-key columns fold
 * case in SQL, so the look-alike cases prove the PHP re-check (house rule LQ). See {@see ChatQueryPortsCases}.
 */
final class ChatQueryPortsDatabaseTest extends LoadDatabaseTestCase
{
    use ChatQueryPortsCases;
}
