<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Event\ChatLinkAddedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkRemovedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkUpdatedEvent;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_slice;
use function assert;
use function count;
use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.19: pinned links (who manages them, validation, the limit, events, the `links` broadcast) and the Links panel (pinned
 * and shared links, search, provider filter, paging, members only), plus link cards in messages.
 */
final class ChatLinksApiTest extends DatabaseTestCase
{
    private const string TEAMS = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting_abc%40thread.v2/0';

    private KernelBrowser $client;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
        $dispatcher   = $this->get(EventDispatcherInterface::class);
        foreach ([ChatLinkAddedEvent::class, ChatLinkUpdatedEvent::class, ChatLinkRemovedEvent::class] as $class) {
            $dispatcher->addListener($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testLimitAndAdminsOnlyChannels' => ['profiles' => ['default' => ['links' => ['max_pinned' => 1, 'channel_managers' => 'admins']]]],
            'testDisabled'                   => ['profiles' => ['default' => ['links' => ['enabled' => false]]]],
            default                          => [],
        };
    }

    public function testPinnedLinksInADirectConversation(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        $base = '/chat/api/conversations/' . $dm->getUuid() . '/pinned-links';

        $config = $this->login($ana);
        $csrf   = $config['csrf'];
        self::assertSame('/chat/api/conversations/__uuid__/pinned-links', $config['endpoints']['pinnedLinks']);
        self::assertSame('/chat/api/conversations/__uuid__/pinned-links/__link__', $config['endpoints']['pinnedLink']);
        self::assertSame('/chat/api/conversations/__uuid__/links', $config['endpoints']['links']);
        self::assertSame(['enabled' => true, 'allowHttp' => false, 'maxPinned' => 20, 'titleMaxLength' => 120, 'urlMaxLength' => 2048], array_slice($config['links'], 0, 5));
        self::assertSame(['key' => 'teams', 'label' => 'Microsoft Teams', 'icon' => 'provider_teams', 'fallbackIcon' => 'video', 'hosts' => ['teams.microsoft.com', 'teams.live.com']], $config['links']['providers'][0]);
        self::assertSame(['links' => [], 'canManage' => true, 'max' => 20], $this->getJson($base));

        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $created             = $this->send(Request::METHOD_POST, $base, $csrf, 201, ['url' => self::TEAMS, 'title' => ' Daily stand-up ']);
        self::assertSame(['links'], $this->get(RecordingRealtime::class)->types(), 'open clients reload their links bar');
        $link = $created['link'];
        self::assertSame([self::TEAMS, 'Daily stand-up', 'Daily stand-up', 'teams.microsoft.com', 'teams', 'Ana', null], [$link['url'], $link['title'], $link['label'], $link['host'], $link['provider']['key'], $link['createdBy']['name'], $link['updatedAt']]);
        self::assertSame([$link['uuid']], array_column($created['links'], 'uuid'));
        self::assertInstanceOf(ChatLinkAddedEvent::class, $this->events[0]);

        // Luis sees it and manages it too; a title is optional.
        $csrfLuis = $this->login($luis)['csrf'];
        self::assertSame([$link['uuid']], array_column($this->getJson($base)['links'], 'uuid'));
        $changed = $this->send(Request::METHOD_PATCH, $base . '/' . $link['uuid'], $csrfLuis, 200, ['url' => 'https://example.com/board', 'title' => null]);
        self::assertSame([null, 'example.com', null], [$changed['link']['title'], $changed['link']['label'], $changed['link']['provider']]);
        self::assertNotNull($changed['link']['updatedAt']);
        $updated = $this->events[1];
        self::assertInstanceOf(ChatLinkUpdatedEvent::class, $updated);
        self::assertSame([self::TEAMS, 'Daily stand-up'], [$updated->previousUrl, $updated->previousTitle]);
        $this->send(Request::METHOD_PATCH, $base . '/' . $link['uuid'], $csrfLuis, 200, ['url' => 'https://example.com/board']);
        self::assertCount(2, $this->events, 'no change, no event');

        // Validation.
        foreach (['javascript:alert(1)', 'data:text/html,x', 'http://example.com/', 'https://u:p@example.com/', ''] as $bad) {
            self::assertSame('invalid_link_url', $this->send(Request::METHOD_POST, $base, $csrfLuis, 422, ['url' => $bad])['error']);
        }
        self::assertSame('invalid_link_title', $this->send(Request::METHOD_POST, $base, $csrfLuis, 422, ['url' => self::TEAMS, 'title' => str_repeat('x', 121)])['error']);
        self::assertSame('invalid_link_url', $this->send(Request::METHOD_PATCH, $base . '/' . $link['uuid'], $csrfLuis, 422, ['url' => 'javascript:x'])['error']);
        $this->client->request(Request::METHOD_POST, $base, server: ['HTTP_X_CSRF_TOKEN' => $csrfLuis, 'CONTENT_TYPE' => 'application/json'], content: '{not json');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_POST, $base, server: ['HTTP_X_CSRF_TOKEN' => $csrfLuis, 'CONTENT_TYPE' => 'application/json'], content: str_repeat(' ', 9000));
        self::assertSame(422, $this->client->getResponse()->getStatusCode(), 'oversized body counts as empty');
        $this->send(Request::METHOD_POST, $base, 'forged', 403, ['url' => self::TEAMS]);
        $this->send(Request::METHOD_DELETE, $base . '/00000000-0000-7000-8000-000000000000', $csrfLuis, 404);

        // Remove.
        $removed = $this->send(Request::METHOD_DELETE, $base . '/' . $link['uuid'], $csrfLuis);
        self::assertSame(['links' => [], 'canManage' => true, 'max' => 20], $removed);
        $event = $this->events[count($this->events) - 1];
        self::assertInstanceOf(ChatLinkRemovedEvent::class, $event);
        self::assertSame([$link['uuid'], 'https://example.com/board', null, 'Luis'], [$event->uuid, $event->url, $event->title, $event->by->getChatDisplayName()]);

        // Someone else: 404, never 403.
        $bea = $this->user('Bea');
        $this->login($bea);
        $this->client->request(Request::METHOD_GET, $base);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/links');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testChannelsAndTickets(): void
    {
        $ana      = $this->user('Ana');
        $agent    = $this->user('Agent Smith');
        $stranger = $this->user('Stranger Sam');
        $chat     = $this->get(ChatService::class);
        $channel  = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $ticket   = $chat->openSupport($ana);

        $csrf = $this->login($ana)['csrf'];
        self::assertSame(201, $this->request(Request::METHOD_POST, '/chat/api/conversations/' . $channel->getUuid() . '/pinned-links', $csrf, ['url' => 'https://meet.google.com/abc-defg-hij']), 'every channel member by default');
        self::assertFalse($this->getJson('/chat/api/conversations/' . $ticket->getUuid() . '/pinned-links')['canManage']);
        self::assertSame('links_forbidden', $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $ticket->getUuid() . '/pinned-links', $csrf, 403, ['url' => self::TEAMS])['error']);

        $csrf = $this->login($agent)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $ticket->getUuid() . '/pinned-links', $csrf, 201, ['url' => self::TEAMS]);

        $this->login($stranger);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $channel->getUuid() . '/pinned-links');
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'pinned links are for members only');
    }

    public function testLinksPanel(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $this->get(ChatLinks::class)->add($ana, $dm, 'https://zoom.us/j/1', 'Retro');
        $card = $chat->send($luis, $dm, 'Today: !link[Daily](' . self::TEAMS . ')');
        $chat->send($ana, $dm, 'Docs at [the wiki](https://wiki.example/start) and www.example.org/x');
        $gone = $chat->send($luis, $dm, 'old https://old.example/');
        $chat->delete($luis, $gone);
        $panelUrl = '/chat/api/conversations/' . $dm->getUuid() . '/links';

        $this->login($ana);
        $panel = $this->getJson($panelUrl);
        self::assertSame(['Retro'], array_column($panel['pinned'], 'label'));
        self::assertSame(['https://www.example.org/x', 'https://wiki.example/start', self::TEAMS], array_column($panel['links'], 'url'), 'newest first, deleted messages left out');
        $teams = $panel['links'][2];
        self::assertSame(['card', 'Daily', 'teams', $card->getUuid(), (int) $card->getId(), 'Luis'], [$teams['kind'], $teams['label'], $teams['provider']['key'], $teams['messageUuid'], $teams['messageId'], $teams['author']['name']]);
        self::assertSame(['link', null, 'www.example.org'], [$panel['links'][0]['kind'], $panel['links'][0]['title'], $panel['links'][0]['label']]);
        self::assertFalse($panel['hasMore']);
        self::assertNull($panel['next']);
        self::assertTrue($panel['canManage']);
        self::assertSame(['teams', 'meet', 'zoom', 'jitsi', 'webex'], array_column($panel['providers'], 'key'));

        // Search and provider filter (pinned links too).
        self::assertSame([['https://wiki.example/start'], []], [array_column($this->getJson($panelUrl . '?q=WIKI')['links'], 'url'), $this->getJson($panelUrl . '?q=WIKI')['pinned']]);
        self::assertSame([], $this->getJson($panelUrl . '?q=50%25')['links'], 'wildcards are literal');
        self::assertSame([[self::TEAMS], []], [array_column($this->getJson($panelUrl . '?provider=teams')['links'], 'url'), $this->getJson($panelUrl . '?provider=teams')['pinned']]);
        self::assertSame(['Retro'], array_column($this->getJson($panelUrl . '?provider=zoom')['pinned'], 'label'));
        self::assertCount(2, $this->getJson($panelUrl . '?provider=other')['links']);
        self::assertSame([], $this->getJson($panelUrl . '?provider=other')['pinned']);
        self::assertSame(['Retro'], array_column($this->getJson($panelUrl . '?q=zoom.us')['pinned'], 'label'));
        self::assertSame(['Retro'], array_column($this->getJson($panelUrl . '?q=retro')['pinned'], 'label'));

        // Editing re-indexes; a card with a refused link is refused.
        [$luis, $card] = [$this->fresh($luis), $this->fresh($card)];
        $chat->edit($card, 'Moved: https://meet.google.com/abc-defg-hij');
        $edited = $this->getJson($panelUrl)['links'];
        self::assertSame(['https://meet.google.com/abc-defg-hij', 'meet', 'link'], [$edited[0]['url'], $edited[0]['provider']['key'], $edited[0]['kind']], 'the edit replaced the card; its links count as shared now');
        self::assertCount(3, $edited);
        $csrf = $this->login($this->fresh($ana))['csrf'];
        self::assertSame('invalid_link', $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages', $csrf, 422, ['body' => '!link[x](http://example.com/)'])['error']);

        // Paging.
        for ($i = 0; $i < 31; ++$i) {
            $chat->send($this->fresh($ana), $this->fresh($dm), 'https://page.example/' . $i);
        }
        $first = $this->getJson($panelUrl . '?q=page.example');
        self::assertCount(30, $first['links']);
        self::assertTrue($first['hasMore']);
        self::assertSame($first['links'][29]['id'], $first['next']);
        $second = $this->getJson($panelUrl . '?q=page.example&before=' . $first['next']);
        self::assertSame(['https://page.example/0'], array_column($second['links'], 'url'));
        self::assertSame([], $second['pinned'], 'pinned links come with the first page only');
    }

    public function testLimitAndAdminsOnlyChannels(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatService::class);
        $dm      = $chat->openDirect($ana, $luis);
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        $csrf = $this->login($ana)['csrf'];
        $base = '/chat/api/conversations/' . $dm->getUuid() . '/pinned-links';
        $this->send(Request::METHOD_POST, $base, $csrf, 201, ['url' => self::TEAMS]);
        self::assertSame('too_many_links', $this->send(Request::METHOD_POST, $base, $csrf, 422, ['url' => 'https://zoom.us/j/2'])['error']);
        self::assertSame('links_forbidden', $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $channel->getUuid() . '/pinned-links', $csrf, 403, ['url' => self::TEAMS])['error'], 'admins: nobody without a policy');
        self::assertFalse($this->getJson('/chat/api/conversations/' . $channel->getUuid() . '/links')['canManage']);
    }

    public function testDisabled(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'https://example.com/');

        $config = $this->login($ana);
        self::assertNull($config['links']);
        self::assertArrayNotHasKey('pinnedLinks', $config['endpoints']);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/links');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->get(ChatLinks::class)->index($this->fresh($chat->send($this->fresh($ana), $this->fresh($dm), 'https://x.example/'))));
    }

    /**
     * @template T of ChatConversation|ChatMessage|TestUser
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(ChatConversation|ChatMessage|TestUser $entity): ChatConversation|ChatMessage|TestUser
    {
        $managed = $this->em->find($entity::class, $entity->getId());
        self::assertNotNull($managed);

        return $managed;
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
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $url, string $csrf, array $body): int
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, string $csrf, int $status = 0, array $body = []): array
    {
        $code = $this->request($method, $url, $csrf, $body);
        if ($status !== 0) {
            self::assertSame($status, $code, $url . ' ' . (string) $this->client->getResponse()->getContent());
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
