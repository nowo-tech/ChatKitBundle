<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\HostEntities\HostStiUserCases;
use PHPUnit\Framework\Attributes\Group;

/**
 * 2.0 (audit lesson W1-2): a single-table-inheritance `user_class` across the managers, on SQLite
 * ({@see HostStiUserCases}; the same cases run on the servers in tests/Database).
 */
#[Group('private-manager')]
final class HostStiUserAcrossManagersTest extends DatabaseTestCase
{
    use HostStiUserCases;

    protected function chatKitConfig(): array
    {
        return self::stiConfig();
    }
}
