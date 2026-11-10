<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.25 defaults (no section provider, both options off): the dock configuration and markup are the 1.24 ones — no
 * `sidebar`, no `openRecent`, no dialogs — so the page and the dock keep the 1.24 list.
 */
final class ChatSidebarDefaultsTest extends DatabaseTestCase
{
    public function testNothingChangesWithoutAProviderOrAnOption(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($this->user('Ana'));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('sidebar', $config);
        self::assertArrayNotHasKey('openRecent', $config);
        self::assertCount(0, $crawler->filter('dialog'));
    }
}
