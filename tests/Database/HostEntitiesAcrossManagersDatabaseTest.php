<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\HostEntities\HostEntitiesAcrossManagersCases;

/**
 * 2.0 (audit lesson W1-2): host entities and chat rows across the application's and ChatKit's private managers, on the
 * server of CHAT_KIT_RACE_DATABASE_URL (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, DBAL 4 and 3: job "Load
 * readiness"), with the server's foreign keys enforced ({@see HostEntitiesAcrossManagersCases}).
 */
final class HostEntitiesAcrossManagersDatabaseTest extends LoadDatabaseTestCase
{
    use HostEntitiesAcrossManagersCases;
}
