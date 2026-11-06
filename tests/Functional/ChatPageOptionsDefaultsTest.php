<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

/**
 * CK-1 and CK-4, a host that changes nothing: the page keeps both asset tags and has no fill class.
 */
final class ChatPageOptionsDefaultsTest extends DatabaseTestCase
{
    public function testThePageKeepsItsAssetsAndHeight(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser($this->user('Ana'));
        $crawler = $client->request(Request::METHOD_GET, '/chat/');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('link[href*="nowo-chat"]'));
        self::assertCount(1, $crawler->filter('script[src*="nowo-chat"]'));
        self::assertCount(1, $crawler->filter('.nowo-chat-page'));
        self::assertCount(0, $crawler->filter('.nowo-chat-page--fill'));
    }
}
