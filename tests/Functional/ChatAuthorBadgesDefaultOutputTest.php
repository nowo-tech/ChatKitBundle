<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function json_encode;
use function preg_replace;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * 1.29 (Beacon spec 234, author badges): without a ChatAuthorBadgeProviderInterface the server-rendered page, the
 * message API and the realtime payloads are byte for byte what 1.28.0 produced. The golden files under
 * `fixtures/author-badges/` were captured on 1.28.0 (0c56fc6) before the feature existed; only random values (uuids,
 * times, CSRF tokens) are masked. The dock's `<template>` markup has its own golden file (DockTemplatesFixtureTest).
 */
class ChatAuthorBadgesDefaultOutputTest extends DatabaseTestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/author-badges/';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testWithoutAProviderThePageTheApiAndTheEventsAreUnchanged(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis <b>');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($luis, $channel, 'Hola **Ana**');
        $chat->send($ana, $channel, 'hi');
        $chat->buzz($luis, $channel);
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $chat->send($luis, $channel, 'live');

        $this->client->loginUser($ana);
        $this->client->request(Request::METHOD_GET, '/chat/' . $channel->getUuid());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertGolden($this->pageFixture(), (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $channel->getUuid() . '/messages');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertGolden('messages.json', (string) $this->client->getResponse()->getContent());

        $this->assertGolden('realtime.json', json_encode($realtime->published, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** The golden file of the page this kernel renders. */
    protected function pageFixture(): string
    {
        return 'page.html';
    }

    private function assertGolden(string $name, string $actual): void
    {
        $actual = $this->mask($actual);
        if (getenv('UPDATE_FIXTURES') === '1') {
            file_put_contents(self::FIXTURES . $name, $actual);
        }
        self::assertFileExists(self::FIXTURES . $name);
        self::assertSame(file_get_contents(self::FIXTURES . $name), $actual, 'Output changed without an author badge provider: ' . $name);
    }

    /** Masks the values that change from one run to the next. */
    private function mask(string $output): string
    {
        $output = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<uuid>', $output);
        $output = (string) preg_replace('/\d{4}-\d{2}-\d{2}(?:T| )\d{2}:\d{2}(?::\d{2})?(?:[+-]\d{2}:\d{2})?/', '<time>', $output);
        // CSRF tokens (form fields, data attributes, the dock configuration).
        $output = (string) preg_replace('/(_token\]"[^>]*value=")[^"]*"/', '$1<csrf>"', $output);
        $output = (string) preg_replace('/(csrf(?:-token)?=")[^"]*"/', '$1<csrf>"', $output);

        return (string) preg_replace('/(&quot;csrf&quot;(?:&#x3A;|:)&quot;|"csrf":")(?!&#x5C;|\\\\\/)[^&"]*/', '$1<csrf>', $output);
    }
}
