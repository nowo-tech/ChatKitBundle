<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Model;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatContact;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;

final class ChatModelTest extends TestCase
{
    public function testContactKeepsWhyTheUserIsReachable(): void
    {
        $user    = new TestUser('Ana', 'ana@example.test');
        $contact = new ChatContact($user, 'group', 'Backend');

        self::assertSame($user, $contact->user);
        self::assertSame('group', $contact->source);
        self::assertSame('Backend', $contact->via);
        self::assertSame('', (new ChatContact($user, 'search'))->via);
    }

    public function testChannel(): void
    {
        $channel = new ChatChannel('project:acme', 'Acme');

        self::assertSame('project:acme', $channel->key);
        self::assertSame('Acme', $channel->label);
    }
}
