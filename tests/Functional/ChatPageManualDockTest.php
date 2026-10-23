<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

/**
 * A host that mounts its own dock: the dock root included with `nowo_chat_manual: true` carries
 * data-nowo-chat-manual (skipped by the automatic start of nowo-chat.js), and a page override that empties
 * `nowo_chat_page_script` drops the bundle script from the chat page.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatPageManualDockTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'web_ui' => [
                'layout_template' => '@ChatKitTest/uikit_layout_manual_dock.html.twig',
                'templates'       => ['page' => '@ChatKitTest/page_override_no_script.html.twig'],
            ],
        ]]];
    }

    public function testTheHostMountsItsOwnDockAndScript(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $chat->openDirect($ana, $this->user('Luis'));

        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($ana);
        $crawler = $client->request(Request::METHOD_GET, '/chat/');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        self::assertCount(1, $crawler->filter('[data-testid="chat-dock"][data-nowo-chat-config][data-nowo-chat-manual]'));
        self::assertCount(0, $crawler->filter('script[src="/bundles/nowochatkit/js/nowo-chat.js"]'));
        self::assertCount(1, $crawler->filter('script[src="/host-chat.js"]'));
        self::assertCount(1, $crawler->filter('head link[href="/bundles/nowochatkit/css/nowo-chat.css"]'));
    }

    public function testABundlerHostAddsRootAttributesAndLeavesOutTheBundleAssets(): void
    {
        $ana    = $this->user('Ana');
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($ana);
        $crawler = $client->request(Request::METHOD_GET, '/bundler-page');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $root = $crawler->filter('.nowo-chat');
        self::assertCount(1, $root);
        self::assertSame('nowo-chat host-dock', $root->attr('class'));
        self::assertSame('chat-dock', $root->attr('data-controller'));
        self::assertSame('chat-dock', $root->attr('data-testid'), 'the dock keeps its own attributes');
        self::assertSame('', $root->attr('data-flag'));
        self::assertNull($root->attr('onclick'));
        self::assertNotNull($root->attr('data-nowo-chat-manual'));
        self::assertCount(0, $crawler->filter('script, link'));
    }
}
