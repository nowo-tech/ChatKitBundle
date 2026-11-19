<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

use function array_column;
use function array_map;
use function assert;
use function count;
use function http_build_query;
use function json_decode;
use function sort;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.37 (spec 002): message search through the page and the JSON endpoint — off by default, the permission matrix
 * (direct, channel, support, agents, strangers), removed text (deleted, erased, outside retention, hidden #references),
 * identical answers for forbidden and unknown filters and cursors, keyset paging, bounds, the rate limit and escaped
 * excerpts.
 */
final class ChatMessageSearchTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testOffByDefault'        => [],
            'testPagingByCursor'      => ['profiles' => ['default' => ['search' => ['enabled' => true, 'page_size' => 2]]]],
            'testRateLimit'           => ['profiles' => ['default' => ['search' => ['enabled' => true], 'limits' => ['searches_per_minute' => 2]]]],
            'testDockLinkCanBeHidden' => ['profiles' => ['default' => ['search' => ['enabled' => true, 'dock_entry' => false]]]],
            default                   => ['profiles' => ['default' => ['search' => ['enabled' => true]]]],
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testOffByDefault(): void
    {
        $ana = $this->user('Ana');
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, '/chat/search?q=hello');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=hello');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'not_found'], $this->decode());
        self::assertStringNotContainsString('data-part="message-search"', $this->renderDockTemplates());
    }

    public function testDockLinkCanBeHidden(): void
    {
        self::assertStringNotContainsString('data-part="message-search"', $this->renderDockTemplates());
    }

    public function testGuestsAndUnreachableUsersGetNothing(): void
    {
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=hello');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/search?q=hello');
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
        $this->login($this->user('Disabled Dan'));
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=hello');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/search?q=hello');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testPermissionMatrix(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $eve      = $this->user('Eve');
        $stranger = $this->user('Stranger Sam');
        $agent    = $this->user('Agent Alice');
        $chat     = $this->get(ChatService::class);

        $direct    = $chat->send($luis, $chat->openDirect($ana, $luis), 'The invoice for March is ready');
        $other     = $chat->send($luis, $chat->openDirect($luis, $eve), 'Private invoice between Luis and Eve');
        $channel   = $chat->send($ana, $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme')), 'Channel invoice for the project');
        $anaTicket = $chat->send($ana, $chat->openSupport($ana), 'Support invoice question from Ana');
        $eveTicket = $chat->send($eve, $chat->openSupport($eve), 'Support invoice question from Eve');
        $mine      = $chat->send($stranger, $chat->openDirect($stranger, $agent), 'Stranger invoice to the agent');
        $deleted   = $chat->send($ana, $chat->openDirect($ana, $eve), 'Deleted invoice draft');
        $chat->delete($ana, $deleted);
        $chat->send($ana, $this->conversationOf($direct), 'Nothing to see here');

        $found = fn (TestUser $user): array => $this->uuids($this->search($user, 'invoice'));

        self::assertSame($this->sorted([$direct, $channel, $anaTicket]), $found($ana));
        self::assertSame($this->sorted([$direct, $other, $channel]), $found($luis));
        self::assertSame($this->sorted([$other, $channel, $eveTicket]), $found($eve));
        self::assertSame($this->sorted([$mine]), $found($stranger));
        // An agent reads every ticket, the agent's own direct conversations, and the channel (agents are not strangers).
        self::assertSame($this->sorted([$channel, $anaTicket, $eveTicket, $mine]), $found($agent));

        // The row shape: titles as the viewer's list shows them, the author, plain text, a link to the message.
        $rows = $this->search($ana, 'march')['items'];
        self::assertCount(1, $rows);
        self::assertSame($direct->getUuid(), $rows[0]['uuid']);
        self::assertNull($rows[0]['threadRootUuid']);
        self::assertSame(['uuid' => $this->conversationOf($direct)->getUuid(), 'kind' => 'direct', 'title' => 'Luis'], $rows[0]['conversation']);
        self::assertSame(['name' => 'Luis', 'publicId' => $luis->getChatPublicId()], $rows[0]['author']);
        self::assertSame('The invoice for March is ready', $rows[0]['snippet']);
        self::assertSame('/chat/' . $this->conversationOf($direct)->getUuid() . '?message=' . $direct->getUuid(), $rows[0]['link']);
        // Every word must be there; a prefix matches.
        self::assertSame([$direct->getUuid()], $this->uuids($this->search($ana, 'INV mar')));
        self::assertSame([], $this->uuids($this->search($ana, 'invoice april')));
        // `%` and `_` are literal.
        self::assertSame([], $this->uuids($this->search($ana, 'in%ce')));
    }

    public function testFilters(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $eve    = $this->user('Eve');
        $chat   = $this->get(ChatService::class);
        $dm     = $chat->openDirect($ana, $luis);
        $hidden = $chat->openDirect($luis, $eve);
        $his    = $chat->send($luis, $dm, 'budget from Luis https://example.com/budget');
        $hers   = $chat->send($ana, $dm, 'budget from Ana');
        $chat->send($luis, $hidden, 'budget hidden');
        $channel = $chat->send($ana, $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme')), 'budget in the channel');

        self::assertSame($this->sorted([$his, $hers]), $this->uuids($this->search($ana, 'budget', ['conversation' => $dm->getUuid()])));
        self::assertSame($this->sorted([$hers, $channel]), $this->uuids($this->search($ana, 'budget', ['from' => 'me'])));
        self::assertSame([$his->getUuid()], $this->uuids($this->search($ana, 'budget', ['from' => $luis->getChatPublicId()])));
        self::assertSame([$his->getUuid()], $this->uuids($this->search($ana, 'budget', ['link' => '1'])));
        $today = (new DateTimeImmutable())->format('Y-m-d');
        self::assertCount(3, $this->search($ana, 'budget', ['after' => $today, 'before' => $today])['items']);
        self::assertSame([], $this->search($ana, 'budget', ['after' => (new DateTimeImmutable('+1 day'))->format('Y-m-d')])['items']);
        self::assertSame([], $this->search($ana, 'budget', ['before' => (new DateTimeImmutable('-1 day'))->format('Y-m-d')])['items']);
        // A malformed date is ignored.
        self::assertCount(3, $this->search($ana, 'budget', ['after' => 'yesterday'])['items']);

        // Oracle-free: a conversation the viewer may not read answers exactly like one that does not exist (or a bad id).
        $forbidden = $this->raw($ana, 'budget', ['conversation' => $hidden->getUuid()]);
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['conversation' => Uuid::v4()->toRfc4122()]));
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['conversation' => 'not-a-uuid']));
        self::assertSame('{"items":[],"next":null}', $forbidden);
        // An author the viewer shares nothing with, an unknown public id and a too-long value: the same.
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['from' => $eve->getChatPublicId()]));
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['from' => Uuid::v4()->toRfc4122()]));
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['from' => str_repeat('x', 65)]));
        // A cursor on a message the viewer may not read, an unknown one and a malformed one: the same.
        $hiddenMessage = $this->em->getRepository(ChatMessage::class)->findOneBy(['conversation' => $hidden->getId()]);
        assert($hiddenMessage instanceof ChatMessage);
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['cursor' => $hiddenMessage->getUuid()]));
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['cursor' => Uuid::v4()->toRfc4122()]));
        self::assertSame($forbidden, $this->raw($ana, 'budget', ['cursor' => 'nope']));
    }

    public function testRemovedTextIsNeverFound(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $old  = $chat->send($luis, $dm, 'archive record from long ago');
        $kept = $chat->send($luis, $dm, 'archive record from this year');
        $plan = $chat->send($ana, $dm, 'see the plan today');
        $chat->buzz($ana, $dm);
        $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.createdAt', ':old')->where('m.id = :id')
            ->setParameter('old', new DateTimeImmutable('-400 days'))->setParameter('id', $old->getId())->getQuery()->execute();
        // A stored #reference token only Ana may open (written while Luis could open it too, say, then revoked).
        $this->em->createQueryBuilder()->update(ChatMessage::class, 'm')->set('m.body', ':body')->where('m.id = :id')
            ->setParameter('body', 'see #[Secret plan](task:2) today')->setParameter('id', $plan->getId())->getQuery()->execute();
        // Outside the retention window (365 days by default): due for the purge, never found.
        self::assertSame([$kept->getUuid()], $this->uuids($this->search($ana, 'archive')));

        // A #reference only Ana may open: its title matches for Ana, never for Luis (he sees "Content not visible").
        self::assertSame([$plan->getUuid()], $this->uuids($this->search($ana, 'secret')));
        self::assertSame([], $this->uuids($this->search($luis, 'secret')));
        self::assertSame([$plan->getUuid()], $this->uuids($this->search($luis, 'today')));

        // Buzz lines are not text.
        self::assertSame([], $this->uuids($this->search($ana, 'buzz')));

        // GDPR erasure: Luis's messages become tombstones and are never found again.
        $this->get(ChatPersonalData::class)->erase($this->fresh($luis));
        self::assertSame([], $this->uuids($this->search($ana, 'archive')));
    }

    public function testPagingByCursor(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $sent = [];
        for ($i = 1; $i <= 5; ++$i) {
            $sent[] = $chat->send($luis, $dm, 'report number ' . $i)->getUuid();
            $chat->send($luis, $dm, 'filler ' . $i);
        }
        $seen   = [];
        $cursor = null;
        $pages  = 0;
        do {
            $page = $this->search($ana, 'report', $cursor === null ? [] : ['cursor' => $cursor]);
            self::assertLessThanOrEqual(2, count($page['items']));
            $seen   = [...$seen, ...array_column($page['items'], 'uuid')];
            $cursor = $page['next'];
            ++$pages;
        } while ($cursor !== null && $pages < 10);
        self::assertSame(3, $pages);
        self::assertSame(array_reverse($sent), $seen, 'newest first, every row once');

        // The page form: the next link carries the cursor and the text.
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=report');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $crawler->filter('[data-testid="chat-search-item"]'));
        $more = (string) $crawler->filter('[data-testid="chat-search-more"]')->attr('href');
        self::assertStringContainsString('q=report', $more);
        self::assertStringContainsString('cursor=', $more);
        $crawler = $this->client->request(Request::METHOD_GET, $more);
        self::assertCount(2, $crawler->filter('[data-testid="chat-search-item"]'));
    }

    public function testBounds(): void
    {
        $this->login($this->user('Ana'));
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=' . str_repeat('a', 201));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'query_too_long'], $this->decode());
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=%20a%20');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'query_too_short'], $this->decode());
        $this->client->request(Request::METHOD_GET, '/chat/api/search');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());

        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=' . str_repeat('a', 201));
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('The search text is too long.', $crawler->filter('[data-testid="chat-search-error"]')->text());
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=a');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('The search text is too short.', $crawler->filter('[data-testid="chat-search-error"]')->text());
        // No text: the form alone, no search, no budget spent.
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="chat-search-input"]'));
        self::assertCount(0, $crawler->filter('[data-testid="chat-search-empty"]'));
    }

    public function testRateLimit(): void
    {
        $this->login($this->user('Ana'));
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=hello');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/search?q=hello');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/api/search?q=hello');
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'rate_limited'], $this->decode());
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=hello');
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertSame('Too many searches. Wait a moment and try again.', $crawler->filter('[data-testid="chat-search-error"]')->text());
    }

    public function testExcerptsAreEscaped(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $chat->send($luis, $chat->openDirect($ana, $luis), 'Look <script>alert(1)</script> <img src=x onerror=alert(2)> payload **bold**');

        $items = $this->search($ana, 'payload')['items'];
        self::assertSame('Look <script>alert(1)</script> <img src=x onerror=alert(2)> payload bold', $items[0]['snippet'], 'plain text in the JSON');

        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=payload');
        $html    = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertSame('payload', $crawler->filter('[data-testid="chat-search-snippet"] mark')->text());
        self::assertCount(0, $crawler->filter('[data-testid="chat-search-snippet"] script, [data-testid="chat-search-snippet"] img'));
        // The dock header links to the page.
        self::assertStringContainsString('data-part="message-search"', $this->renderDockTemplates());
        // Nothing found: the empty notice.
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=nothinglikethis');
        self::assertCount(1, $crawler->filter('[data-testid="chat-search-empty"]'));
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{items: list<array<string, mixed>>, next: string|null}
     */
    private function search(TestUser $user, string $text, array $filters = []): array
    {
        /** @var array{items: list<array<string, mixed>>, next: string|null} $data */
        $data = json_decode($this->raw($user, $text, $filters), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<string, string> $filters
     */
    private function raw(TestUser $user, string $text, array $filters = []): string
    {
        $this->login($user);
        $this->client->request(Request::METHOD_GET, '/chat/api/search?' . http_build_query(['q' => $text] + $filters));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array{items: list<array<string, mixed>>} $page
     *
     * @return list<string>
     */
    private function uuids(array $page): array
    {
        /** @var list<string> $uuids */
        $uuids = array_column($page['items'], 'uuid');
        sort($uuids);

        return $uuids;
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @return list<string>
     */
    private function sorted(array $messages): array
    {
        $uuids = array_map(static fn (ChatMessage $message): string => $message->getUuid(), $messages);
        sort($uuids);

        return $uuids;
    }

    private function conversationOf(ChatMessage $message): ChatConversation
    {
        return $message->getConversation();
    }

    private function fresh(TestUser $user): TestUser
    {
        $managed = $this->em->find(TestUser::class, $user->getId());
        self::assertInstanceOf(TestUser::class, $managed);

        return $managed;
    }

    private function login(TestUser $user): void
    {
        $this->em->clear();
        $this->client->loginUser($this->fresh($user));
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
