<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

/**
 * `page_layout` pointing at a host layout that already includes the dock: the page extends it through the
 * `nowo_chat_kit_page_layout` global, appends the chat stylesheet and script to its blocks, and the dock does not
 * load them a second time.
 */
final class ChatPageLayoutTest extends DatabaseTestCase
{
    private const string LAYOUT = '@ChatKitTest/host_layout.html.twig';

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['page_layout' => self::LAYOUT]]];
    }

    public function testTheGlobalMirrorsTheConfiguredLayout(): void
    {
        self::assertSame(self::LAYOUT, $this->twig()->createTemplate('{{ nowo_chat_kit_page_layout }}')->render());
        // Without the controller's `layout` (and `read`) variables, the page extends the global.
        $html = $this->twig()->render('@NowoChatKitBundle/page.html.twig', ['conversations' => [], 'current' => null, 'messages' => [], 'can_send' => false]);
        self::assertStringContainsString('<link rel="stylesheet" href="/host.css">', $html);
        self::assertStringNotContainsString('data-nowo-chat-read-url', $html);
    }

    public function testThePageLoadsTheChatAssetsOnceWithTheDockInTheLayout(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $dm   = $chat->openDirect($ana, $this->user('Luis'));
        $chat->send($this->user('Luis'), $dm, 'Hola');

        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($ana);
        $crawler = $client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('link[href="/host.css"]'), 'the layout stylesheets block is kept (parent())');
        self::assertCount(1, $crawler->filter('link[href="/bundles/nowochatkit/css/nowo-chat.css"]'));
        self::assertCount(1, $crawler->filter('script[src="/bundles/nowochatkit/js/nowo-chat.js"]'));
        self::assertCount(1, $crawler->filter('head link[href="/bundles/nowochatkit/css/nowo-chat.css"]'));
        self::assertCount(1, $crawler->filter('[data-testid="chat-dock"]'));
    }
}
