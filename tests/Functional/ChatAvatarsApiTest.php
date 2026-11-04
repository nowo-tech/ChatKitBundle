<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Avatar\ChatAvatars;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\Chat\CountingAvatarResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function json_decode;
use function json_encode;
use function sort;

use const JSON_THROW_ON_ERROR;

/**
 * 1.16 profile pictures: exactly one resolver call per response, only for people the viewer may see, sanitised URLs,
 * null fields by default and when the resolver fails.
 */
final class ChatAvatarsApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    private CountingAvatarResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client   = $client;
        $this->resolver = $this->get(CountingAvatarResolver::class);
    }

    public function testEveryResponseMakesOneBatchedCallForThePeopleItShows(): void
    {
        $chat    = $this->get(ChatService::class);
        $tickets = $this->get(ChatSupportTickets::class);
        $agent   = $this->user('Agent Al');
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $bea     = $this->user('Bea');
        $cy      = $this->user('Cy');
        $sam     = $this->user('Stranger Sam');
        $this->user('Disabled Dan');
        $this->resolver->urls = [
            $agent->getChatPublicId() => 'https://cdn.example.com/al.webp',
            $luis->getChatPublicId()  => 'https://cdn.example.com/luis.webp',
            $bea->getChatPublicId()   => '/files/bea/s128',
            $cy->getChatPublicId()    => 'data:image/png;base64,iVBORw0KGgo=',
            $sam->getChatPublicId()   => '/files/sam/s128',
        ];
        $dms = [];
        foreach ([$luis, $bea, $cy] as $peer) {
            $dms[$peer->getChatPublicId()] = $chat->openDirect($agent, $peer);
            $chat->send($peer, $dms[$peer->getChatPublicId()], 'hello from ' . $peer->getChatDisplayName());
        }
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        foreach ([$luis, $bea, $cy] as $author) {
            $chat->send($author, $channel, 'in the channel');
        }
        $ticket = $tickets->open($sam);
        $tickets->chooseTopic($sam, $ticket, 'billing');
        $chat->send($sam, $ticket, 'help me');
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];

        $config = $this->login($agent);
        self::assertSame(['name' => 'Agent Al', 'initials' => 'A', 'avatarUrl' => 'https://cdn.example.com/al.webp'], $config['me']);
        $csrf = (string) $config['csrf'];

        // The list: the three peers and the requester (seen by an agent), together.
        $list   = $this->counted(fn (): array => $this->getJson('/chat/api/conversations'), [$luis, $bea, $cy, $sam]);
        $byUuid = array_column($list['conversations'], 'avatarUrl', 'uuid');
        self::assertSame('https://cdn.example.com/luis.webp', $byUuid[$dms[$luis->getChatPublicId()]->getUuid()]);
        self::assertSame('/files/bea/s128', $byUuid[$dms[$bea->getChatPublicId()]->getUuid()]);
        self::assertNull($byUuid[$dms[$cy->getChatPublicId()]->getUuid()], 'a data: URL is dropped');
        self::assertSame('/files/sam/s128', $byUuid[$ticket->getUuid()]);
        self::assertNull($byUuid[$channel->getUuid()], 'channels have no picture');

        // A page of messages: its three authors.
        $page = $this->counted(fn (): array => $this->getJson('/chat/api/conversations/' . $channel->getUuid() . '/messages'), [$luis, $bea, $cy]);
        self::assertSame(['https://cdn.example.com/luis.webp', '/files/bea/s128', null], array_column($page['messages'], 'authorAvatarUrl'));

        // One summary.
        $one = $this->counted(fn (): array => $this->getJson('/chat/api/conversations/' . $dms[$luis->getChatPublicId()]->getUuid()), [$luis]);
        self::assertSame('https://cdn.example.com/luis.webp', $one['conversation']['avatarUrl']);

        // The support inbox: the requester.
        $inbox = $this->counted(fn (): array => $this->getJson('/chat/api/support/inbox?status=all'), [$sam]);
        self::assertSame(['/files/sam/s128'], array_column($inbox['threads'], 'avatarUrl'));

        // An agent changes the topic: the summary's requester and the bot line (no author) in the same call.
        $topic = $this->counted(fn (): array => $this->send('/chat/api/support/' . $ticket->getUuid() . '/topic', $csrf, ['topic' => 'account']), [$sam]);
        self::assertSame('/files/sam/s128', $topic['conversation']['avatarUrl']);
        self::assertSame([null], array_column($topic['messages'], 'authorAvatarUrl'), 'bot lines have no picture');

        // Sending: the realtime event and the response share one call; the event carries the author's picture.
        $realtime->published = [];
        $sent                = $this->counted(fn (): array => $this->send('/chat/api/conversations/' . $dms[$luis->getChatPublicId()]->getUuid() . '/messages', $csrf, ['body' => 'hi Luis']), [$agent]);
        self::assertSame('https://cdn.example.com/al.webp', $sent['message']['authorAvatarUrl']);
        $events = array_values(array_filter($realtime->published, static fn (array $event): bool => $event['payload']['type'] === 'message'));
        self::assertCount(1, $events);
        self::assertSame('https://cdn.example.com/al.webp', $events[0]['payload']['message']['authorAvatarUrl']);

        // The full page (list and conversation): one call each.
        $this->counted(function (): array {
            $this->client->request(Request::METHOD_GET, '/chat/');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());

            return [];
        }, [$luis, $bea, $cy, $sam]);
        $this->counted(function () use ($channel): array {
            $this->client->request(Request::METHOD_GET, '/chat/' . $channel->getUuid());
            self::assertSame(200, $this->client->getResponse()->getStatusCode());

            return [];
        }, [$luis, $bea, $cy, $sam]);

        // The rejected value never leaves the server.
        self::assertStringNotContainsString('data:image', json_encode($realtime->published, JSON_THROW_ON_ERROR));
    }

    public function testContactsAndMembersAreResolvedAfterTheAccessFilters(): void
    {
        $chat                 = $this->get(ChatService::class);
        $ana                  = $this->user('Ana');
        $luis                 = $this->user('Luis');
        $bea                  = $this->user('Bea');
        $sam                  = $this->user('Stranger Sam');
        $dan                  = $this->user('Disabled Dan');
        $this->resolver->urls = [$luis->getChatPublicId() => '/files/luis/s128', $sam->getChatPublicId() => '/files/sam/s128', $dan->getChatPublicId() => '/files/dan/s128'];
        $channel              = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($ana, $channel, 'hello');
        $this->login($ana);

        // Strangers and unreachable people are filtered out before the resolver is asked.
        $contacts = $this->counted(fn (): array => $this->getJson('/chat/api/contacts'), [$bea, $luis]);
        self::assertSame(['/files/luis/s128'], array_values(array_filter(array_column($contacts['contacts'], 'avatarUrl'))));
        self::assertNotContains($sam->getChatPublicId(), $this->resolver->askedIds());
        self::assertNotContains($dan->getChatPublicId(), $this->resolver->askedIds());

        // Members: the channel's audience only (no stranger, no unreachable account), never the viewer.
        $members = $this->counted(fn (): array => $this->getJson('/chat/api/conversations/' . $channel->getUuid() . '/members'), [$bea, $luis]);
        self::assertSame(['Bea' => null, 'Luis' => '/files/luis/s128'], array_column($members['members'], 'avatarUrl', 'name'));
        self::assertNotContains($sam->getChatPublicId(), $this->resolver->askedIds());
        self::assertNotContains($dan->getChatPublicId(), $this->resolver->askedIds());

        // The requester's own draft: no person in the response, no call.
        $config = $this->login($sam);
        $own    = $this->send('/chat/api/support', (string) $config['csrf'], []);
        self::assertNull($own['conversation']['avatarUrl']);
        $this->counted(fn (): array => $this->send('/chat/api/support/' . $own['conversation']['uuid'] . '/topic', (string) $config['csrf'], ['topic' => 'billing']), []);
    }

    public function testDefaultsAreNullAndTheShapesOnlyGainTheNewFields(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'hello');
        $config = $this->login($ana);
        self::assertNull($config['me']['avatarUrl']);

        $row = $this->getJson('/chat/api/conversations')['conversations'][0];
        self::assertNull($row['avatarUrl']);
        self::assertSame([
            'archived', 'uuid', 'kind', 'title', 'subtitle', 'initials', 'avatarUrl', 'peerUuid', 'channelKey', 'supportStatus',
            'supportContext', 'ticket', 'canSend', 'peerOnline', 'peerStatus', 'peerStatusText', 'peerStatusEmoji', 'unread',
            'lastMessageAt', 'lastMessage', 'canPin',
        ], array_keys($row));
        $message = $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages'][0];
        self::assertNull($message['authorAvatarUrl']);
        self::assertSame([
            'id', 'uuid', 'authorUuid', 'authorName', 'authorInitials', 'authorAvatarUrl', 'mine', 'kind', 'html', 'createdAt',
            'edited', 'deleted', 'moderated', 'reactions', 'mentions', 'mentionsMe', 'references', 'refsPending', 'pinned',
            'pinnedMessage',
        ], array_keys($message));
        $contact = $this->getJson('/chat/api/contacts')['contacts'][0];
        self::assertSame(['uuid', 'name', 'email', 'initials', 'avatarUrl', 'source', 'via'], array_keys($contact));
        self::assertNull($contact['avatarUrl']);
    }

    public function testAFailingResolverGivesNullsAndTheChatKeepsWorking(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'hello');
        $this->resolver->urls = [$luis->getChatPublicId() => '/files/luis/s128'];
        $this->resolver->fail = true;
        $this->login($ana);

        $list = $this->counted(fn (): array => $this->getJson('/chat/api/conversations'), [$luis]);
        self::assertNull($list['conversations'][0]['avatarUrl']);
        self::assertNull($this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages'][0]['authorAvatarUrl']);
    }

    public function testAUrlThatIsNotUtf8IsDroppedAndNothingBreaks(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'hello');
        $this->resolver->urls = [
            $luis->getChatPublicId() => "/files/\xC3\x28.webp",
            $ana->getChatPublicId()  => "https://cdn.example.com/caf\xE9.webp",
        ];
        $config = $this->login($ana);
        self::assertNull($config['me']['avatarUrl']);

        // EE-L1: the list answers 200 (JsonResponse would throw on invalid UTF-8) with a null picture.
        $list = $this->getJson('/chat/api/conversations');
        self::assertNull($list['conversations'][0]['avatarUrl']);

        // The realtime event of a send is still published (and the response is 201).
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $sent                = $this->send('/chat/api/conversations/' . $dm->getUuid() . '/messages', (string) $config['csrf'], ['body' => 'still here']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertNull($sent['message']['authorAvatarUrl']);
        $events = array_values(array_filter($realtime->published, static fn (array $event): bool => $event['payload']['type'] === 'message'));
        self::assertCount(1, $events);
        self::assertNull($events[0]['payload']['message']['authorAvatarUrl']);
    }

    public function testPinningMakesOneCall(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $old  = $chat->send($luis, $dm, 'pinned earlier');
        $chat->pin($luis, $old, true);
        $new                  = $chat->send($luis, $dm, 'pin me');
        $this->resolver->urls = [$ana->getChatPublicId() => '/files/ana/s128', $luis->getChatPublicId() => '/files/luis/s128'];
        $config               = $this->login($ana);

        // EE-I1: the pin line (by Ana), the message and the pinned bar share one call.
        $pins = $this->counted(fn (): array => $this->send('/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $new->getUuid() . '/pin', (string) $config['csrf'], []), [$ana, $luis]);
        self::assertSame(['/files/luis/s128', '/files/luis/s128'], array_column($pins['pins'], 'authorAvatarUrl'));
    }

    /**
     * Runs one request with a fresh memo and asserts it asked the resolver once for exactly these people (none: no call).
     *
     * @param callable(): array<string, mixed> $request
     * @param list<TestUser> $people
     *
     * @return array<string, mixed>
     */
    private function counted(callable $request, array $people): array
    {
        $this->get(ChatAvatars::class)->reset();
        $this->resolver->calls = [];
        $result                = $request();
        if ($people === []) {
            self::assertSame([], $this->resolver->calls, 'no person, no call');

            return $result;
        }
        self::assertCount(1, $this->resolver->calls, 'one urlsFor() call per response');
        $expected = array_map(static fn (TestUser $user): string => $user->getChatPublicId(), $people);
        $asked    = $this->resolver->calls[0]['ids'];
        sort($expected);
        sort($asked);
        self::assertSame($expected, $asked);
        self::assertSame(ChatAvatars::SIZE, $this->resolver->calls[0]['size']);

        return $result;
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
    private function send(string $url, string $csrf, array $body): array
    {
        $this->client->request(Request::METHOD_POST, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

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
