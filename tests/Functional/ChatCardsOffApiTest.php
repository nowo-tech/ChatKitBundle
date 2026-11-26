<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.6 (spec 259): without card or slot providers nothing changes — no `slots` in the dock configuration, no slot
 * dialog, the slot routes answer 404 and a `cards` field is refused. Audit ZCG-v-I1: the dock refuses an
 * `Idempotency-Key` in the client API's `client:` namespace in any letter case.
 */
final class ChatCardsOffApiTest extends DatabaseTestCase
{
    public function testNothingWithoutProvidersAndTheClientKeyNamespace(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        $client->loginUser($ana);
        $crawler = $client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('slots', $config);
        self::assertCount(0, $crawler->filter('[data-testid="chat-slot-dialog"]'));
        self::assertStringNotContainsString('data-slot-key', (string) $client->getResponse()->getContent());

        $base = '/chat/api/conversations/' . $dm->getUuid();
        foreach (['/slots', '/cards/note/n1'] as $path) {
            $client->request(Request::METHOD_GET, $base . $path);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $path);
        }
        $client->request(Request::METHOD_POST, $base . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json'], content: '{"body":"x","cards":[{"type":"note","id":"n1"}]}');
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('card_not_allowed', (string) $client->getResponse()->getContent());

        foreach (['client:abc', 'CLIENT:abc', 'Client:abc'] as $key) {
            $client->request(Request::METHOD_POST, $base . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key], content: '{"body":"x"}');
            self::assertSame(400, $client->getResponse()->getStatusCode(), $key);
            self::assertStringContainsString('idempotency_key_invalid', (string) $client->getResponse()->getContent());
        }
    }
}
