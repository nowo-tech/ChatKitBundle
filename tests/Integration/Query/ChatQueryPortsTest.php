<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Query;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Query\ChatQueryPortsCases;

/**
 * 2.2 (DDD-host checklist B7/B8): the host query ports on SQLite, users stored as relations (the default
 * `doctrine.user_reference: relation`). See {@see ChatQueryPortsCases}.
 */
final class ChatQueryPortsTest extends DatabaseTestCase
{
    use ChatQueryPortsCases;
}
