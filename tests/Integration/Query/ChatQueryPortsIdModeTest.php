<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Query;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Query\ChatQueryPortsCases;

/**
 * 2.2 (DDD-host checklist B7/B8): the host query ports on SQLite with users stored by id (`doctrine.user_reference: id`:
 * no relation, no foreign key; the users come from the directory). See {@see ChatQueryPortsCases}.
 */
final class ChatQueryPortsIdModeTest extends DatabaseTestCase
{
    use ChatQueryPortsCases;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['user_reference' => 'id']]]];
    }
}
