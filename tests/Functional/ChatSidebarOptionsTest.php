<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.25: `web_ui.dock.sidebar_sections` and `web_ui.page.open_recent` without a section provider: the dock and the page
 * are grouped (the bundle's own sections only, no endpoint), `openRecent` reaches the dock, the dialogs are rendered.
 */
final class ChatSidebarOptionsTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'web_ui' => ['dock' => ['sidebar_sections' => true], 'page' => ['open_recent' => true]],
            'limits' => ['reads_per_minute' => 1],
        ]]];
    }

    public function testTheOptionsReachTheDock(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $client->loginUser($this->user('Ana'));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($config['openRecent']);
        self::assertSame(['page' => true, 'dock' => true, 'endpoint' => null, 'dialogs' => ['md' => 'nowo-chat-sidebar-dialog', 'wide' => 'nowo-chat-sidebar-dialog-wide']], $config['sidebar']);
        self::assertCount(2, $crawler->filter('dialog[data-nowo-ui-remote-form]'));

        // No provider: no section; the read budget applies (1 per minute here).
        $client->request(Request::METHOD_GET, '/chat/api/sidebar');
        self::assertSame('{"sections":[]}', $client->getResponse()->getContent());
        $client->request(Request::METHOD_GET, '/chat/api/sidebar');
        self::assertSame(429, $client->getResponse()->getStatusCode());
    }
}
