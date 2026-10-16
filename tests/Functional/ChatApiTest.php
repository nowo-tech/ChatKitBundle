<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * The JSON API end to end: dock config, contacts, open / send / edit / delete / react / read, cursor pages,
 * unread counts, channel, support, buzz, realtime token, presence, CSRF and IDOR (foreign UUID → 404).
 */
final class ChatApiTest extends DatabaseTestCase
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

    public function testGuestsGetNoDockAndNoApi(): void
    {
        $this->client->request(Request::METHOD_GET, '/page');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('nowo-chat', (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/chat/api/conversations');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testDirectConversationFlow(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $stranger = $this->user('Stranger Eve');

        $config = $this->login($ana);
        self::assertSame($ana->getChatPublicId(), $config['userUuid']);
        self::assertSame('Messaging', $config['i18n']['launcher']);
        self::assertStringContainsString('__uuid__', $config['endpoints']['messages']);
        self::assertStringContainsString('__message__', $config['endpoints']['message']);
        self::assertNull($config['contextChannel']);
        self::assertSame(['sound' => 'pop', 'buzz' => true, 'presence' => true], $config['prefs']);
        $csrf = $config['csrf'];

        // Contacts: the policy hides the stranger.
        self::assertSame(['Luis'], array_column($this->getJson('/chat/api/contacts')['contacts'], 'name'));
        self::assertSame([], $this->getJson('/chat/api/contacts?q=zzz')['contacts']);

        // CSRF and reachability.
        $this->postJson('/chat/api/direct', ['userUuid' => $luis->getChatPublicId()], 'bad');
        $this->assertStatus(403);
        $this->postJson('/chat/api/direct', ['userUuid' => $stranger->getChatPublicId()], $csrf);
        $this->assertStatus(404);

        $conversation = $this->postJson('/chat/api/direct', ['userUuid' => $luis->getChatPublicId()], $csrf)['conversation'];
        self::assertSame('direct', $conversation['kind']);
        self::assertSame('Luis', $conversation['title']);
        self::assertTrue($conversation['canSend']);
        $uuid = $conversation['uuid'];

        // Send: validation, Markdown.
        $this->postJson('/chat/api/conversations/' . $uuid . '/messages', ['body' => '   '], $csrf);
        $this->assertStatus(422);
        $sent = $this->postJson('/chat/api/conversations/' . $uuid . '/messages', ['body' => 'Hola **Luis** <script>'], $csrf)['message'];
        $this->assertStatus(201);
        self::assertTrue($sent['mine']);
        self::assertSame('<p>Hola <strong>Luis</strong> &lt;script&gt;</p>', $sent['html']);

        // Edit within the window; a malformed body counts as empty.
        $edited = $this->requestJson(Request::METHOD_PATCH, '/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'], ['body' => 'Hola Luis'], $csrf)['message'];
        self::assertTrue($edited['edited']);
        self::assertSame('<p>Hola Luis</p>', $edited['html']);
        $this->client->request(Request::METHOD_PATCH, '/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'], server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: '{not json');
        $this->assertStatus(422);

        // Luis: unread, reads, reacts, replies.
        $luisCsrf = $this->login($luis)['csrf'];
        $list     = $this->getJson('/chat/api/conversations');
        self::assertSame(1, $list['unreadTotal']);
        self::assertSame('Ana', $list['conversations'][0]['title']);
        self::assertSame('Hola Luis', $list['conversations'][0]['lastMessage']['preview']);
        $page = $this->getJson('/chat/api/conversations/' . $uuid . '/messages');
        self::assertCount(1, $page['messages']);
        self::assertFalse($page['messages'][0]['mine']);
        self::assertNull($page['readUpTo']);
        $this->postJson('/chat/api/conversations/' . $uuid . '/read', ['messageId' => 999_999], $luisCsrf);
        self::assertSame(0, $this->getJson('/chat/api/conversations')['unreadTotal'], 'read is capped at the newest message');
        self::assertSame(['👍' => [$luis->getChatPublicId()]], $this->postJson('/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'] . '/reactions', ['emoji' => '👍'], $luisCsrf)['reactions']);
        $this->postJson('/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'] . '/reactions', ['emoji' => 'x'], $luisCsrf);
        $this->assertStatus(422);
        $this->requestJson(Request::METHOD_PATCH, '/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'], ['body' => 'hijack'], $luisCsrf);
        $this->assertStatus(404, 'only the author edits');
        $reply = $this->postJson('/chat/api/conversations/' . $uuid . '/messages', ['body' => 'Hey'], $luisCsrf)['message'];
        $after = $this->getJson('/chat/api/conversations/' . $uuid . '/messages?after=' . $sent['id']);
        self::assertSame([$reply['id']], array_column($after['messages'], 'id'));
        self::assertSame($uuid, $this->getJson('/chat/api/conversations/' . $uuid)['conversation']['uuid']);

        // Ana sees Luis's read cursor and the reaction, then deletes her message.
        $csrf = $this->login($ana)['csrf'];
        $page = $this->getJson('/chat/api/conversations/' . $uuid . '/messages');
        self::assertSame([['userUuid' => $luis->getChatPublicId(), 'name' => 'Luis', 'messageId' => $reply['id']]], $page['readers']);
        self::assertSame(['👍' => [$luis->getChatPublicId()]], $page['messages'][0]['reactions']);
        $deleted = $this->requestJson(Request::METHOD_DELETE, '/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'], [], $csrf)['message'];
        self::assertTrue($deleted['deleted']);
        self::assertSame('', $deleted['html']);

        // Buzz: direct only, then too soon.
        $this->postJson('/chat/api/conversations/' . $uuid . '/buzz', [], $csrf);
        $this->assertStatus(201);
        $this->postJson('/chat/api/conversations/' . $uuid . '/buzz', [], $csrf);
        $this->assertStatus(429);

        // Typing, presence, realtime token.
        self::assertSame(['ok' => true], $this->postJson('/chat/api/conversations/' . $uuid . '/typing', [], $csrf));
        $this->postJson('/chat/api/conversations/' . $uuid . '/typing', [], 'bad');
        $this->assertStatus(403);
        self::assertSame(['online' => []], $this->postJson('/chat/api/presence', ['visible' => true], $csrf));
        $realtime = $this->getJson('/chat/api/realtime');
        self::assertTrue($realtime['enabled']);
        self::assertSame('/nowo-chat/users/' . $ana->getChatPublicId(), $realtime['topic']);
        self::assertStringStartsWith('token:/nowo-chat/users/' . $ana->getChatPublicId() . '@', $realtime['token']);

        // IDOR: the stranger cannot read or post.
        $strangerCsrf = $this->login($stranger)['csrf'];
        foreach (['/chat/api/conversations/' . $uuid . '/messages', '/chat/api/conversations/' . $uuid] as $url) {
            $this->client->request(Request::METHOD_GET, $url);
            $this->assertStatus(404);
        }
        $this->postJson('/chat/api/conversations/' . $uuid . '/messages', ['body' => 'x'], $strangerCsrf);
        $this->assertStatus(404);
        $this->postJson('/chat/api/conversations/' . $uuid . '/read', ['messageId' => 1], $strangerCsrf);
        $this->assertStatus(404);
        $this->requestJson(Request::METHOD_DELETE, '/chat/api/conversations/' . $uuid . '/messages/' . $reply['uuid'], [], $strangerCsrf);
        $this->assertStatus(404);
        self::assertSame([], $this->getJson('/chat/api/conversations')['conversations']);
    }

    public function testChannelSupportAndReadOnly(): void
    {
        $ana      = $this->user('Ana');
        $agent    = $this->user('Agent Smith');
        $stranger = $this->user('Stranger Eve');

        $config = $this->login($ana, '/projects/acme/page');
        self::assertSame(['key' => TestChannelProvider::KEY, 'label' => 'Acme'], $config['contextChannel']);
        $csrf = $config['csrf'];

        $channel = $this->postJson('/chat/api/channel', ['channelKey' => TestChannelProvider::KEY], $csrf)['conversation'];
        self::assertSame('channel', $channel['kind']);
        self::assertSame('#Acme', $channel['title']);
        $this->postJson('/chat/api/channel', ['channelKey' => 'project:unknown'], $csrf);
        $this->assertStatus(404);
        $this->postJson('/chat/api/conversations/' . $channel['uuid'] . '/messages', ['body' => 'Release today'], $csrf);
        $this->postJson('/chat/api/conversations/' . $channel['uuid'] . '/buzz', [], $csrf);
        $this->assertStatus(422);

        $support = $this->postJson('/chat/api/support', ['context' => ['url' => '/projects/acme/page', 'label' => 'Acme']], $csrf)['conversation'];
        self::assertSame(['url' => '/projects/acme/page', 'label' => 'Acme'], $support['supportContext']);
        self::assertSame('support', $support['kind']);
        $this->client->request(Request::METHOD_GET, '/chat/api/support/inbox');
        $this->assertStatus(404, 'only agents see the inbox');
        self::assertSame('Support', $support['title']);
        $this->postJson('/chat/api/conversations/' . $support['uuid'] . '/messages', ['body' => 'Broken export'], $csrf);
        $this->postJson('/chat/api/conversations/' . $support['uuid'] . '/resolve', [], $csrf);
        $this->assertStatus(404, 'only agents resolve');

        // The agent sees the channel and the support thread, resolves it.
        $agentConfig = $this->login($agent);
        self::assertTrue($agentConfig['isAgent']);
        $titles = array_column($this->getJson('/chat/api/conversations')['conversations'], 'title');
        self::assertContains('Ana', $titles);
        self::assertContains('#Acme', $titles);
        $inbox = $this->getJson('/chat/api/support/inbox?status=open&q=ana');
        self::assertSame(['Ana'], array_column($inbox['threads'], 'title'));
        self::assertSame(1, $inbox['open']);
        self::assertStringEndsWith('/chat/api/support/inbox', $agentConfig['endpoints']['supportInbox']);
        $resolved = $this->postJson('/chat/api/conversations/' . $support['uuid'] . '/resolve', [], $agentConfig['csrf'])['conversation'];
        self::assertSame('resolved', $resolved['supportStatus']);
        self::assertSame('open', $this->postJson('/chat/api/conversations/' . $support['uuid'] . '/reopen', [], $agentConfig['csrf'])['conversation']['supportStatus']);
        self::assertSame('open', $this->postJson('/chat/api/conversations/' . $support['uuid'] . '/reopen', [], $agentConfig['csrf'])['conversation']['supportStatus'], 'reopening an open thread is a no-op');

        // A direct thread the policy no longer allows is read-only (stranger ↔ Ana, opened by the agent route).
        $strangerCsrf = $this->login($stranger)['csrf'];
        $this->postJson('/chat/api/channel', ['channelKey' => TestChannelProvider::KEY], $strangerCsrf);
        $this->assertStatus(404);
        $this->postJson('/chat/api/direct', ['userUuid' => $agent->getChatPublicId()], $strangerCsrf);
        $this->assertStatus(404, 'strangers cannot open threads with agents either');
    }

    public function testLinkPreviewsPerViewer(): void
    {
        $ana      = $this->user('Ana');
        $stranger = $this->user('Stranger Eve');
        self::assertTrue($this->login($ana)['linkPreviews']);

        $query = '?url[]=/projects/acme/issues/7&url[]=javascript:alert(1)&url[]=//evil.test/x&url[]=https://elsewhere.test/a&url[]=/projects/acme/issues/7';
        self::assertSame(['/projects/acme/issues/7' => ['title' => 'Acme · 7', 'subtitle' => 'Issue', 'badge' => 'open']], $this->getJson('/chat/api/link-previews' . $query)['previews']);
        self::assertSame([], $this->getJson('/chat/api/link-previews')['previews']);
        self::assertSame([], $this->getJson('/chat/api/link-previews?url=/projects/acme/issues/7')['previews'], 'url must be a list');
        self::assertSame([], $this->getJson('/chat/api/link-previews?url[]=' . str_repeat('a', 600))['previews']);

        $this->login($stranger);
        self::assertSame([], $this->getJson('/chat/api/link-previews' . $query)['previews'], 'nothing the viewer cannot see');
    }

    public function testRateLimitsAndOversizedBodies(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $csrf = $this->login($ana)['csrf'];
        $uuid = $this->postJson('/chat/api/direct', ['userUuid' => $luis->getChatPublicId()], $csrf)['conversation']['uuid'];

        $this->client->request(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => str_repeat('a', 40_000)], JSON_THROW_ON_ERROR));
        $this->assertStatus(422, 'oversized body counts as empty');

        $status = 0;
        for ($i = 0; $i < 31 && $status !== 429; ++$i) {
            $this->postJson('/chat/api/conversations/' . $uuid . '/messages', ['body' => 'm' . $i], $csrf);
            $status = $this->client->getResponse()->getStatusCode();
        }
        self::assertSame(429, $status, 'send limit');
        for ($i = 0; $i < 31; ++$i) {
            $this->postJson('/chat/api/conversations/' . $uuid . '/typing', [], $csrf);
        }
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'typing over the limit is dropped silently');
    }

    public function testPagesPresenceAndContext(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $stranger = $this->user('Stranger Eve');
        $chat     = $this->get(ChatService::class);
        $dm       = $chat->openDirect($ana, $luis);
        for ($i = 1; $i <= 35; ++$i) {
            $this->em->persist(new ChatMessage($dm, $i % 2 ? $ana : $luis, 'm' . $i, '<p>m' . $i . '</p>'));
        }
        $this->em->flush();

        $csrf   = $this->login($ana)['csrf'];
        $newest = $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages');
        self::assertTrue($newest['hasMore']);
        self::assertCount(30, $newest['messages']);
        self::assertSame('<p>m35</p>', $newest['messages'][29]['html']);
        $older = $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages?before=' . $newest['messages'][0]['id']);
        self::assertFalse($older['hasMore']);
        self::assertCount(5, $older['messages']);
        $catchUp = $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages?after=' . $older['messages'][0]['id']);
        self::assertTrue($catchUp['hasMore']);
        self::assertSame('<p>m2</p>', $catchUp['messages'][0]['html']);

        // Luis is online (heartbeat) and both show presence.
        $this->get(ChatPresence::class)->heartbeat($luis, true);
        self::assertSame(['online' => [$luis->getChatPublicId()]], $this->postJson('/chat/api/presence', ['visible' => false], $csrf));
        self::assertTrue($this->getJson('/chat/api/conversations')['conversations'][0]['peerOnline']);

        // A body announced larger than the limit is ignored.
        $this->client->request(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '999999'], content: '{"body":"hi"}');
        $this->assertStatus(422);

        // The stranger on the project page gets no channel shortcut.
        self::assertNull($this->login($stranger, '/projects/acme/page')['contextChannel']);
    }

    /**
     * Logs in and loads a page with the dock; returns its config.
     *
     * @return array<string, mixed>
     */
    private function login(TestUser $user, string $page = '/page'): array
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, $page);
        $dock    = $crawler->filter('[data-nowo-chat-config]');
        self::assertCount(1, $dock);
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
    private function postJson(string $url, array $body, string $csrf): array
    {
        return $this->requestJson(Request::METHOD_POST, $url, $body, $csrf);
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

    private function assertStatus(int $status, string $message = ''): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $message . ' ' . $this->client->getResponse()->getContent());
    }
}
