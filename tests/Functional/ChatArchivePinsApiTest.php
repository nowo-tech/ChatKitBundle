<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.10: closing conversations in one's own list (back when a message arrives, unless muted; resolved tickets stay
 * closed; "close all read") and pinned messages (who may pin, the limit, the pinned line, unpin on delete).
 */
final class ChatArchivePinsApiTest extends DatabaseTestCase
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

    public function testClosingConversations(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'hi');
        $other = $chat->openDirect($ana, $bea);
        $chat->send($bea, $other, 'hello');
        $realtime = $this->get(RecordingRealtime::class);

        $config = $this->login($ana);
        self::assertStringContainsString('__uuid__', $config['endpoints']['archive']);
        $csrf = $config['csrf'];
        $realtime->published = [];
        self::assertSame(['archived' => true], $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/archive', $csrf));
        self::assertSame(['list'], $this->get(RecordingRealtime::class)->types(), 'the other tabs reload the list');
        self::assertSame([$other->getUuid()], array_column($this->getJson('/chat/api/conversations')['conversations'], 'uuid'));
        $closed = $this->getJson('/chat/api/conversations?archived=1')['conversations'];
        self::assertSame([[$dm->getUuid(), true]], array_map(static fn (array $row): array => [$row['uuid'], $row['archived']], $closed));

        // Only for Ana: Luis still lists it.
        $this->login($luis);
        self::assertSame([$dm->getUuid()], array_column($this->getJson('/chat/api/conversations')['conversations'], 'uuid'));

        // A new message brings it back.
        $chat->send($luis, $dm, 'are you there?');
        $this->login($ana);
        self::assertContains($dm->getUuid(), array_column($this->getJson('/chat/api/conversations')['conversations'], 'uuid'));

        // Muted: it stays closed whatever arrives.
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/archive', $csrf);
        $chat->participant($dm, $ana)->muteUntil(new DateTimeImmutable('+1 day'));
        $this->em->flush();
        $chat->send($luis, $dm, 'still?');
        self::assertNotContains($dm->getUuid(), array_column($this->getJson('/chat/api/conversations')['conversations'], 'uuid'));

        // Reopen, then "close all read" closes only what has no unread messages.
        self::assertSame(['archived' => false], $this->send(Request::METHOD_DELETE, '/chat/api/conversations/' . $dm->getUuid() . '/archive', $csrf));
        $chat->markRead($ana, $other, (int) $chat->send($bea, $other, 'read me')->getId());
        self::assertSame(['archived' => 1], $this->send(Request::METHOD_POST, '/chat/api/conversations/archive-read', $csrf));
        self::assertSame([$dm->getUuid()], array_column($this->getJson('/chat/api/conversations')['conversations'], 'uuid'));

        // The page lists them apart.
        $page = $this->client->request(Request::METHOD_GET, '/chat/?archived=1');
        self::assertSame($other->getUuid(), $page->filter('[data-nowo-chat-unarchive]')->attr('data-nowo-chat-unarchive'));
        $page = $this->client->request(Request::METHOD_GET, '/chat/');
        self::assertSame($dm->getUuid(), $page->filter('[data-nowo-chat-archive]')->attr('data-nowo-chat-archive'));

        // A foreign conversation: 404; no CSRF: 403.
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $chat->openDirect($luis, $bea)->getUuid() . '/archive', $csrf, 404);
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/archive', 'forged', 403);
    }

    public function testResolvedTicketsStayClosed(): void
    {
        $ana     = $this->user('Ana');
        $agent   = $this->user('Agent Smith');
        $chat    = $this->get(ChatService::class);
        $support = $chat->openSupport($ana);
        $chat->send($ana, $support, 'help');
        $chat->resolveSupport($agent, $support);

        $csrf = $this->login($ana)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $support->getUuid() . '/archive', $csrf);
        $chat->send($agent, $support, 'anything else?');
        self::assertNotContains($support->getUuid(), array_column($this->getJson('/chat/api/conversations')['conversations'], 'uuid'));
    }

    public function testPinnedMessages(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $chat     = $this->get(ChatService::class);
        $dm       = $chat->openDirect($ana, $luis);
        $message  = $chat->send($luis, $dm, 'the deploy key is in the vault');
        $realtime = $this->get(RecordingRealtime::class);

        $csrf = $this->login($ana)['csrf'];
        $url  = '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $message->getUuid() . '/pin';
        self::assertSame(['pins' => [], 'canPin' => true], $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/pins'));
        $realtime->published = [];
        $pins = $this->send(Request::METHOD_POST, $url, $csrf);
        self::assertTrue($pins['canPin']);
        self::assertSame([$message->getUuid()], array_column($pins['pins'], 'uuid'));
        self::assertTrue($pins['pins'][0]['pinned']);
        self::assertSame(['message_updated', 'message', 'pins'], $this->get(RecordingRealtime::class)->types());
        $line = $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages'];
        self::assertSame(['pin', $message->getUuid()], [$line[1]['kind'], $line[1]['pinnedMessage']]);
        self::assertTrue($this->getJson('/chat/api/conversations')['conversations'][0]['lastMessage']['pin']);
        $this->send(Request::METHOD_POST, $url, $csrf);
        self::assertCount(2, $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages'], 'pinning twice adds no line');

        // A line cannot be pinned; the export lists Ana's pin.
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $line[1]['uuid'] . '/pin', $csrf, 422);
        self::assertSame($message->getUuid(), $this->get(ChatPersonalData::class)->export($ana)['pins'][0]['message_uuid']);

        // Luis unpins; deleting a pinned message unpins it too.
        $csrfLuis = $this->login($luis)['csrf'];
        self::assertSame([], $this->send(Request::METHOD_DELETE, $url, $csrfLuis)['pins']);
        $this->send(Request::METHOD_POST, $url, $csrfLuis);
        $chat->delete($luis, $message);
        $this->login($ana);
        self::assertSame([], $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/pins')['pins']);
    }

    public function testWhoMayPinAndTheLimit(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $chat    = $this->get(ChatService::class);
        $support = $chat->openSupport($ana);
        $asked   = $chat->send($ana, $support, 'help');

        // Support: agents pin, the requester does not (403); channels: nobody without a pin policy.
        $csrf = $this->login($ana)['csrf'];
        self::assertFalse($this->getJson('/chat/api/conversations/' . $support->getUuid() . '/pins')['canPin']);
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $support->getUuid() . '/messages/' . $asked->getUuid() . '/pin', $csrf, 403);
        $channel = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation'];
        self::assertFalse($channel['canPin']);
        $csrfAgent = $this->login($agent)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $support->getUuid() . '/messages/' . $asked->getUuid() . '/pin', $csrfAgent);

        // Someone who cannot read the conversation: 404.
        $dm   = $chat->openDirect($ana, $luis);
        $note = $chat->send($ana, $dm, 'private');
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $note->getUuid() . '/pin', $csrfAgent, 404);

        // The limit (20 by default).
        $csrf = $this->login($ana)['csrf'];
        for ($i = 0; $i < 20; ++$i) {
            $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $chat->send($ana, $dm, 'm' . $i)->getUuid() . '/pin', $csrf);
        }
        self::assertSame('too_many_pins', $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $note->getUuid() . '/pin', $csrf, 422)['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function login(TestUser $user): array
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

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
    private function send(string $method, string $url, string $csrf, int $status = 0, array $body = []): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $code = $this->client->getResponse()->getStatusCode();
        if ($status !== 0) {
            self::assertSame($status, $code, $url);
        } else {
            self::assertLessThan(300, $code, (string) $this->client->getResponse()->getContent());
        }

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
