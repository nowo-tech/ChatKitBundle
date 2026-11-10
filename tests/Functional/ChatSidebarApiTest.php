<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatSidebarAction;
use Nowo\ChatKitBundle\Model\ChatSidebarItem;
use Nowo\ChatKitBundle\Model\ChatSidebarSection;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Sidebar\ChatSidebar;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingLogger;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestSecondSidebarProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestSidebarProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_intersect_key;
use function array_map;
use function assert;
use function implode;
use function mb_strlen;
use function range;
use function sprintf;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.25: `GET /chat/api/sidebar` — the host sections of ChatSidebarSectionProviderInterface for the viewer: ordered by
 * position, reserved and repeated keys left out, every conversation row checked for read access and filled with the
 * chat's own unread / mentions / muted, link and dialog URLs validated, texts cleaned; the dock configuration and the
 * two UiKit dialogs follow.
 */
final class ChatSidebarApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function sidebarProviders(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testConversationRowsGetTheChatsCountsAndAreCheckedForAccess(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $mia      = $this->user('Mia');
        $stranger = $this->user('Stranger Eve');
        $chat     = $this->get(ChatService::class);
        $acme     = $chat->openChannel($luis, $this->channel(TestChannelProvider::KEY));
        $chat->send($luis, $acme, 'hello @[Ana](' . $ana->getChatPublicId() . ')');
        $chat->send($luis, $acme, 'and again');
        $archive = $chat->openChannel($luis, $this->channel(TestChannelProvider::ARCHIVE_KEY));
        $chat->send($luis, $archive, 'old');
        $chat->mute($ana, $archive, new DateTimeImmutable('+1 day'));
        $foreign = $chat->openDirect($luis, $mia);

        $this->first()->sections = [
            new ChatSidebarSection('channels', "  Chan\u{202E}nels\n", 0, true, false, [
                new ChatSidebarItem('acme', 'acme', 'channel', $acme->getUuid(), null, 99, 99, true, 'The topic'),
                new ChatSidebarItem('archive', 'archive', 'private', $archive->getUuid()),
                // Not readable by Ana (a direct conversation of two other people): left out silently.
                new ChatSidebarItem('foreign', 'foreign', 'channel', $foreign->getUuid()),
                // Unknown uuid: left out.
                new ChatSidebarItem('gone', 'gone', 'channel', '00000000-0000-4000-8000-000000000000'),
                // Same key again: the first one stays.
                new ChatSidebarItem('acme', 'acme twice', 'channel', null, '/x'),
            ], [
                new ChatSidebarAction('browse', 'Browse channels', 'search', '/rooms', '/rooms/browse', 'wide'),
                new ChatSidebarAction('create', 'Create channel', 'tabler:x', null, 'http://localhost/rooms/new?x=1#f'),
            ]),
        ];

        $this->client->loginUser($ana);
        $sections = $this->sections();
        self::assertCount(1, $sections);
        $channels = $sections[0];
        self::assertSame('channels', $channels['key']);
        self::assertSame('Chan nels', $channels['label'], 'control and bidirectional characters never reach the dock');
        self::assertSame(['acme', 'archive'], array_column($channels['items'], 'key'));
        self::assertSame([
            'key'              => 'acme',
            'label'            => 'acme',
            'subtitle'         => 'The topic',
            'conversationUuid' => $acme->getUuid(),
            'url'              => null,
            'unread'           => 2,
            'mentions'         => 1,
            'muted'            => false,
            'icon'             => 'channel',
        ], $channels['items'][0], 'the chat\'s counts, never the provider\'s');
        self::assertSame(['unread' => 1, 'mentions' => 0, 'muted' => true, 'icon' => 'private'], array_intersect_key($channels['items'][1], ['unread' => 1, 'mentions' => 1, 'muted' => 1, 'icon' => 1]));

        self::assertSame(['key' => 'browse', 'label' => 'Browse channels', 'url' => '/rooms', 'dialogUrl' => '/rooms/browse', 'dialogSize' => 'wide', 'icon' => 'search'], $channels['actions'][0]);
        self::assertSame('/rooms/new?x=1#f', $channels['actions'][1]['dialogUrl'], 'an absolute URL on the request\'s origin becomes its path');
        self::assertNull($channels['actions'][1]['url']);
        self::assertNull($channels['actions'][1]['icon']);
        self::assertStringStartsWith('<svg', $channels['actions'][1]['iconSvg'], 'a UX Icons name is rendered on the server');

        // Mia reads the direct conversation; a stranger to the channels sees none of the rows, whatever the provider says.
        $this->client->loginUser($mia);
        self::assertSame(['acme', 'archive', 'foreign'], array_column($this->sections()[0]['items'], 'key'));
        $this->client->loginUser($stranger);
        self::assertSame([], $this->sections()[0]['items']);
        self::assertSame(['Ana', 'Mia', 'Stranger Eve'], $this->first()->viewers);
    }

    public function testSectionsAreOrderedAndFiltered(): void
    {
        $ana                     = $this->user('Ana');
        $this->first()->sections = [
            new ChatSidebarSection('late', 'Late', 50, false, true),
            new ChatSidebarSection('recent', 'Reserved'),
            new ChatSidebarSection('first', 'First', -5, true, true, [], [new ChatSidebarAction('a', 'A', null, '/a')]),
            new ChatSidebarSection('tie', 'Tie one', 50),
        ];
        $this->second()->sections = [
            new ChatSidebarSection('first', 'Repeated'),
            new ChatSidebarSection('conversations', 'Reserved too'),
            new ChatSidebarSection('tie-two', 'Tie two', 50),
            new ChatSidebarSection('zero', str_repeat('é', 100)),
        ];
        $this->client->loginUser($ana);
        $sections = $this->sections();
        self::assertSame(['first', 'zero', 'late', 'tie', 'tie-two'], array_column($sections, 'key'), 'position, then the providers\' order (priority first)');
        self::assertSame('First', $sections[0]['label']);
        self::assertTrue($sections[0]['collapsedByDefault']);
        self::assertSame(80, mb_strlen($sections[1]['label']));
        self::assertFalse($sections[2]['collapsible']);
        self::assertFalse($sections[2]['collapsedByDefault'], 'a section that cannot fold is never folded');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testLinksDialogsAndIconsAreValidated(): void
    {
        $ana                     = $this->user('Ana');
        $this->first()->sections = [new ChatSidebarSection('links', 'Links', 0, true, false, [
            new ChatSidebarItem('ok', 'OK', 'tabler:send', null, 'https://docs.example/x', 3, 1, true),
            new ChatSidebarItem('js', 'JS', 'channel', null, 'javascript:alert(1)'),
            new ChatSidebarItem('protocol', 'Protocol relative', 'channel', null, '//evil.example'),
            new ChatSidebarItem('icon', 'Icon', 'bad icon!', null, '/ok'),
            new ChatSidebarItem('missing', 'Missing UX icon', 'tabler:not-there', null, '/ok'),
        ], [
            new ChatSidebarAction('other', 'Other origin', 'plus', null, 'https://evil.example/fragment'),
            new ChatSidebarAction('other-link', 'Other origin, with a link', 'plus', 'https://docs.example', 'https://evil.example/fragment'),
            new ChatSidebarAction('userinfo', 'User info', null, null, 'http://user@localhost/x'),
            new ChatSidebarAction('root', 'Root', null, null, 'http://localhost'),
            new ChatSidebarAction('slashes', 'Slashes', null, null, 'http://localhost//evil.example'),
            new ChatSidebarAction('js', 'JS', null, 'javascript:alert(1)', 'javascript:alert(1)'),
            new ChatSidebarAction('other', 'Repeated', null, '/r'),
            new ChatSidebarAction('a1', '1', null, '/1'),
            new ChatSidebarAction('a2', '2', null, '/2'),
            new ChatSidebarAction('a3', '3', null, '/3'),
            new ChatSidebarAction('a4', '4', null, '/4'),
        ])];
        $this->client->loginUser($ana);
        $section = $this->sections()[0];
        self::assertSame(['ok', 'icon', 'missing'], array_column($section['items'], 'key'));
        self::assertSame(['unread' => 3, 'mentions' => 1, 'muted' => true], array_intersect_key($section['items'][0], ['unread' => 1, 'mentions' => 1, 'muted' => 1]), 'a link row shows the provider\'s counts');
        self::assertSame('channel', $section['items'][0]['icon']);
        self::assertStringContainsString('aria-hidden="true"', $section['items'][0]['iconSvg']);
        self::assertSame(['icon' => 'channel'], array_intersect_key($section['items'][1], ['icon' => 1, 'iconSvg' => 1]), 'an invalid name falls back to #');
        self::assertSame('OK', $section['items'][0]['label']);
        self::assertArrayNotHasKey('iconSvg', $section['items'][2], 'a UX icon the set does not have: the dock icon');
        self::assertSame(['other-link', 'root', 'slashes', 'a1', 'a2', 'a3'], array_column($section['actions'], 'key'), 'at most six actions; other origins, user info and javascript: are dropped');
        self::assertSame(['https://docs.example', null], [$section['actions'][0]['url'], $section['actions'][0]['dialogUrl']]);
        self::assertSame('/', $section['actions'][1]['dialogUrl']);
        self::assertSame('/evil.example', $section['actions'][2]['dialogUrl'], 'never a protocol-relative path');
    }

    public function testCapsAndTheSvgInlineIconSet(): void
    {
        $ana   = $this->user('Ana');
        $items = [];
        for ($i = 0; $i < 205; ++$i) {
            $items[] = new ChatSidebarItem('i' . $i, 'Item ' . $i, 'tabler:x', null, '/i/' . $i);
        }
        $sections = [new ChatSidebarSection('big', 'Big', 0, true, false, $items)];
        for ($i = 0; $i < 25; ++$i) {
            $sections[] = new ChatSidebarSection('s' . $i, 'S' . $i, 10);
        }
        $this->first()->sections = $sections;
        $this->client->loginUser($ana);
        $view = $this->sections();
        self::assertCount(ChatSidebar::MAX_SECTIONS, $view);
        self::assertCount(ChatSidebar::MAX_ITEMS, $view[0]['items']);

        $plain = new ChatSidebar([$this->first()], $this->get(ChatAccess::class), $this->get(ChatConversationRepository::class), $this->get(ChatMessageRepository::class), $this->get(ChatMessageMentionRepository::class), $this->get(ChatParticipantRepository::class), iconSet: 'svg_inline');
        self::assertArrayNotHasKey('iconSvg', $plain->view($ana, 'http://localhost')[0]['items'][0], 'svg_inline never renders UX icons');
    }

    public function testAThrowingProviderIsLoggedAndSkipped(): void
    {
        $ana                      = $this->user('Ana');
        $this->first()->failure   = new InvalidArgumentException('A sidebar item conversationUuid must be a lowercase uuid');
        $this->second()->sections = [new ChatSidebarSection('kept', 'Kept')];
        $this->client->loginUser($ana);
        self::assertSame(['kept'], array_column($this->sections(), 'key'), 'the other providers still show');

        $logger = new RecordingLogger();
        $direct = new ChatSidebar([$this->first(), $this->second()], $this->get(ChatAccess::class), $this->get(ChatConversationRepository::class), $this->get(ChatMessageRepository::class), $this->get(ChatMessageMentionRepository::class), $this->get(ChatParticipantRepository::class), logger: $logger);
        self::assertSame(['kept'], array_column($direct->view($ana, 'http://localhost'), 'key'));
        self::assertCount(1, $logger->lines);
        self::assertStringContainsString(TestSidebarProvider::class, implode(' ', $logger->lines));
    }

    public function testProvidersAreCountedWithoutBeingCreatedAndUuidsLookedUpByChunks(): void
    {
        $ana  = $this->user('Ana');
        $chat = $this->get(ChatService::class);
        $acme = $chat->openChannel($ana, $this->channel(TestChannelProvider::KEY));
        $deps = [$this->get(ChatAccess::class), $this->get(ChatConversationRepository::class), $this->get(ChatMessageRepository::class), $this->get(ChatMessageMentionRepository::class), $this->get(ChatParticipantRepository::class)];
        self::assertTrue($this->get(ChatSidebar::class)->hasProviders(), 'the tagged iterator counts');
        self::assertTrue((new ChatSidebar((function (): iterable {
            yield $this->first();
        })(), ...$deps))->hasProviders());
        self::assertFalse((new ChatSidebar((static function (): iterable {
            yield from [];
        })(), ...$deps))->hasProviders());

        self::assertSame([], $this->get(ChatConversationRepository::class)->findByUuids([]));

        // 8 sections × 200 unknown uuids, the readable conversation last: two IN queries, and it is found.
        $sections = [];
        for ($s = 0; $s < 8; ++$s) {
            $items = [];
            for ($i = 0; $i < 200; ++$i) {
                $items[] = new ChatSidebarItem('i' . $i, 'I', 'channel', sprintf('00000000-0000-4000-8000-%012d', $s * 1000 + $i));
            }
            $sections[] = new ChatSidebarSection('s' . $s, 'S', 0, true, false, $items);
        }
        $sections[]              = new ChatSidebarSection('last', 'Last', 1, true, false, [new ChatSidebarItem('acme', 'acme', 'channel', $acme->getUuid())]);
        $this->first()->sections = $sections;
        $this->client->loginUser($ana);
        $view = $this->sections();
        self::assertSame([], $view[0]['items']);
        self::assertSame(['acme'], array_column($view[8]['items'], 'key'));

        // A row beyond the 200 shown is never looked up (nor shown), and Unicode spaces never pass (audit KJ-I6).
        $over                    = array_map(static fn (int $i): ChatSidebarItem => new ChatSidebarItem('u' . $i, 'U', 'channel', null, '/u/' . $i), range(0, 199));
        $over[]                  = new ChatSidebarItem('late', 'Late', 'channel', $acme->getUuid());
        $this->first()->sections = [new ChatSidebarSection('over', 'Over', 0, true, false, $over), new ChatSidebarSection('space', 'Space', 0, true, false, [new ChatSidebarItem('ideo', 'Ideo', 'channel', null, "/a\u{3000}b")], [new ChatSidebarAction('ideo', 'Ideo', null, null, "/a\u{3000}b")])];
        $view                    = $this->sections();
        self::assertNotContains('late', array_column($view[0]['items'], 'key'));
        self::assertSame([[], []], [$view[1]['items'], $view[1]['actions']]);
    }

    public function testGuestsAndTheDockConfiguration(): void
    {
        $this->client->request(Request::METHOD_GET, '/chat/api/sidebar');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $ana = $this->user('Ana');
        $this->client->loginUser($ana);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['page' => true, 'dock' => false, 'endpoint' => '/chat/api/sidebar', 'dialogs' => ['md' => 'nowo-chat-sidebar-dialog', 'wide' => 'nowo-chat-sidebar-dialog-wide']], $config['sidebar']);
        self::assertArrayNotHasKey('openRecent', $config);
        self::assertSame('Direct messages and groups', $config['i18n']['sidebar_conversations']);

        // The two UiKit remote form dialogs (medium, wide) sit in the dock root.
        $medium = $crawler->filter('dialog#nowo-chat-sidebar-dialog');
        self::assertCount(1, $medium);
        self::assertSame('chat-sidebar-dialog-md', $medium->attr('data-testid'));
        self::assertNotNull($medium->attr('data-nowo-ui-remote-form'));
        self::assertStringNotContainsString('nowo-ui-dialog--wide', (string) $medium->attr('class'));
        self::assertStringContainsString('nowo-ui-dialog--wide', (string) $crawler->filter('dialog#nowo-chat-sidebar-dialog-wide')->attr('class'));
        self::assertCount(1, $crawler->filter('dialog#nowo-chat-sidebar-dialog-wide [data-nowo-ui-dialog-footer]'), 'footer outside the body');
        self::assertCount(1, $crawler->filter('dialog#nowo-chat-sidebar-dialog-wide .nowo-ui-dialog__close'), 'header with ×');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sections(): array
    {
        $this->client->request(Request::METHOD_GET, '/chat/api/sidebar');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        /** @var array{sections: list<array<string, mixed>>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body['sections'];
    }

    private function first(): TestSidebarProvider
    {
        return $this->get(TestSidebarProvider::class);
    }

    private function second(): TestSecondSidebarProvider
    {
        return $this->get(TestSecondSidebarProvider::class);
    }

    private function channel(string $key): ChatChannel
    {
        $channel = $this->get(TestChannelProvider::class)->find($key);
        self::assertInstanceOf(ChatChannel::class, $channel);

        return $channel;
    }
}
