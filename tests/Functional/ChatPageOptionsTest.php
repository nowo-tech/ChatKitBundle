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
 * CK-1 and CK-4, on TestKernel (TwigBundle registered before the chat bundle): `web_ui.assets: false` leaves the bundle
 * asset tags out of the page, also with the dock in the layout, and `web_ui.page.full_height: true` adds the fill class.
 * The defaults are in ChatPageOptionsDefaultsTest.
 */
final class ChatPageOptionsTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['web_ui' => [
            'layout_template' => '@ChatKitTest/uikit_layout.html.twig',
            'assets'          => false,
            'page'            => ['full_height' => true],
        ]]]];
    }

    public function testThePageHasNoBundleAssetTag(): void
    {
        foreach ($this->pages() as $crawler) {
            self::assertCount(0, $crawler->filter('link[href*="nowo-chat"]'));
            self::assertCount(0, $crawler->filter('script[src*="nowo-chat"]'));
            self::assertCount(1, $crawler->filter('[data-nowo-chat-page-mode]'), 'the dock keeps its mount point');
        }
    }

    public function testThePageFillsTheContentArea(): void
    {
        foreach ($this->pages() as $crawler) {
            self::assertCount(1, $crawler->filter('.nowo-chat-page.nowo-chat-page--fill'));
        }
    }

    /**
     * @return iterable<string, Crawler>
     */
    private function pages(): iterable
    {
        $ana          = $this->user('Ana');
        $conversation = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        $client       = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $client->loginUser($ana);

        foreach (['/chat/', '/chat/' . $conversation->getUuid()] as $url) {
            $crawler = $client->request(Request::METHOD_GET, $url);
            self::assertSame(200, $client->getResponse()->getStatusCode());

            yield $url => $crawler;
        }
    }
}
