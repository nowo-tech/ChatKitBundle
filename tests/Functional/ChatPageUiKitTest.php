<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

use function assert;

/**
 * REQ-UI-003: the chat page on a UiKit layout (`web_ui.layout_template`) that includes the dock — canonical blocks,
 * UiKit page header, chat assets once — and a one-block host override (`web_ui.templates.page`). The 1.x default
 * `page_layout` value counts as "no host layout".
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ChatPageUiKitTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'page_layout' => '@NowoChatKitBundle/page_layout.html.twig',
            'web_ui'      => [
                'layout_template' => '@ChatKitTest/uikit_layout.html.twig',
                'templates'       => ['page' => '@ChatKitTest/page_override.html.twig'],
            ],
        ]]];
    }

    public function testThePageRendersInTheUiKitLayoutWithOneOverriddenBlock(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('link[href="/host-uikit.css"]'), 'web_ui.layout_template is the layout');
        self::assertCount(1, $crawler->filter('[data-nowo-ui-region="header"] h1'));
        self::assertSame('Messages', trim($crawler->filter('[data-nowo-ui-region="header"] h1')->text()));
        self::assertSame('Luis', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('main[data-nowo-ui-bundle="NowoChatKitBundle"]'));
        // Host override of nowo_chat_page_list; the thread still comes from the bundle.
        self::assertSame('1 conversations', $crawler->filter('[data-host-list]')->text());
        self::assertCount(1, $crawler->filter('.nowo-chat-page__thread .nowo-chat-page__composer'));
        self::assertStringContainsString('nowo-ui-btn--primary', (string) $crawler->filter('.nowo-chat-page__composer button')->attr('class'));
        // Chat assets once (in the head), dock included by the layout without its own copies.
        self::assertCount(1, $crawler->filter('head link[href="/bundles/nowochatkit/css/nowo-chat.css"]'));
        self::assertCount(1, $crawler->filter('script[src="/bundles/nowochatkit/js/nowo-chat.js"]'));
        self::assertCount(1, $crawler->filter('[data-testid="chat-dock"][data-nowo-ui-bundle="NowoChatKitBundle"]'));
        self::assertCount(0, $crawler->filter('[data-nowo-chat-manual]'), 'the dock is started automatically by default');
    }

    private function open(): Crawler
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

        return $crawler;
    }
}
