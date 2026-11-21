<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\HostEntities\HostEntitiesAcrossManagersCases;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.0 (audit lesson W1-2): host entities and chat rows across the application's and ChatKit's private managers, on
 * SQLite ({@see HostEntitiesAcrossManagersCases}; the same cases run on the servers in tests/Database).
 */
#[Group('private-manager')]
final class HostEntitiesAcrossManagersTest extends DatabaseTestCase
{
    use HostEntitiesAcrossManagersCases;
}
