<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.24 (Beacon spec 184): `web_ui.channels_url` adds the "Browse or create channels" link to the dock's picker and
 * `channelsUrl` to the dock configuration; the header action slots render nothing by default.
 */
final class ChatDockChannelHooksTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['web_ui' => ['channels_url' => '/rooms?tab="all"']]]];
    }

    public function testTheChannelsLink(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($this->user('Ana'));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/rooms?tab="all"', $config['channelsUrl']);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/rooms?tab=&quot;all&quot;" data-part="channels-link"', $html, 'escaped in the attribute');
        self::assertStringContainsString('Browse or create channels', $html);
        self::assertStringNotContainsString('channels-link', $this->renderDockTemplates(), 'no URL, no link');
    }
}
