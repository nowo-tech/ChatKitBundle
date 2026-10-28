<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * @mentions through the JSON API: the members list (same access as reading the conversation, never the caller,
 * filtered and sorted by name) and a sent message with a mention chip, `mentions` and `mentionsMe`.
 */
final class ChatMentionsApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testMembersAndMentionInAChannel(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->user('Bea');
        $stranger = $this->user('Stranger Eve');

        $config = $this->login($ana);
        self::assertStringContainsString('__uuid__', $config['endpoints']['members']);
        self::assertSame('Mention someone', $config['i18n']['mention_list']);
        $channel = $this->requestJson(Request::METHOD_POST, '/chat/api/channel', ['channelKey' => TestChannelProvider::KEY], $config['csrf'])['conversation'];
        $uuid    = $channel['uuid'];

        self::assertSame(['Bea', 'Luis'], array_column($this->getJson('/chat/api/conversations/' . $uuid . '/members')['members'], 'name'), 'sorted, without the caller or strangers');
        $members = $this->getJson('/chat/api/conversations/' . $uuid . '/members?q=LU')['members'];
        self::assertSame([['uuid' => $luis->getChatPublicId(), 'name' => 'Luis', 'initials' => $luis->getChatInitials()]], $members);

        $sent = $this->requestJson(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', ['body' => 'hi @[Luis](' . $luis->getChatPublicId() . ') and @[Eve](' . $stranger->getChatPublicId() . ')'], $config['csrf'])['message'];
        self::assertSame('<p>hi <span class="nowo-chat-mention" data-mention="' . $luis->getChatPublicId() . '">@Luis</span> and @Eve</p>', $sent['html']);
        self::assertSame([['uuid' => $luis->getChatPublicId(), 'name' => 'Luis']], $sent['mentions']);
        self::assertFalse($sent['mentionsMe']);

        $this->login($luis);
        self::assertTrue($this->getJson('/chat/api/conversations/' . $uuid . '/messages')['messages'][0]['mentionsMe']);

        // Someone who cannot read the conversation gets 404, like reading it.
        $this->login($stranger);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $uuid . '/members');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function login(TestUser $user): array
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        $dock    = $crawler->filter('[data-nowo-chat-config]');
        if ($dock->count() === 0) {
            return [];
        }
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $dock->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $body, string $csrf): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
