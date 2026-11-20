<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function trim;

/**
 * 1.39.1: UiKit page anatomy of the message search page (Beacon phone report) — the page header (title, back link),
 * the primary "Search" button, the filters disclosure (closed unless a filter is set), the results area with its empty
 * states, a back link that never leaves the site, and the section class that no longer collides with the dock's
 * search field. Who may search what is covered by {@see ChatMessageSearchTest}.
 */
final class ChatMessageSearchPageTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['search' => ['enabled' => true]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testPageAnatomyBeforeSearching(): void
    {
        $this->login($this->user('Ana'));
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Search messages', trim($crawler->filter('h1')->text()));
        $back = $crawler->filter('[data-testid="chat-search-back"]');
        self::assertCount(1, $back);
        self::assertSame('/chat/', $back->attr('href'));
        self::assertStringContainsString('Back', $back->text());

        $section = $crawler->filter('[data-testid="chat-search"]');
        self::assertStringContainsString('nowo-chat-search-page', (string) $section->attr('class'));
        self::assertDoesNotMatchRegularExpression('/(^|\s)nowo-chat-search(\s|$)/', (string) $section->attr('class'), 'never the dock search-field class');

        $form = $crawler->filter('form[role="search"]');
        self::assertCount(1, $form);
        self::assertSame('Words to find', trim($form->filter('label[for="q"]')->text()));
        $submit = $form->filter('[data-testid="chat-search-submit"]');
        self::assertSame('submit', $submit->attr('type'));
        self::assertSame('Search', trim($submit->text()));
        self::assertStringContainsString('nowo-ui-btn--primary', (string) $submit->attr('class'));

        $filters = $crawler->filter('details[data-testid="chat-search-filters"]');
        self::assertCount(1, $filters);
        self::assertNull($filters->attr('open'), 'closed while no filter is set');
        self::assertSame('Filters', trim($filters->filter('summary')->text()));
        foreach (['from', 'after', 'before', 'link'] as $name) {
            self::assertCount(1, $filters->filter('[name="' . $name . '"]'), $name . ' inside the disclosure');
        }

        $area = $crawler->filter('[data-testid="chat-search-area"]');
        self::assertCount(1, $area);
        self::assertCount(1, $area->filter('[data-testid="chat-search-start"]'));
        self::assertStringContainsString('Search your messages', $area->text());
        self::assertCount(0, $area->filter('[data-testid="chat-search-empty"]'));
    }

    public function testEmptyStateAndOpenFiltersAfterSearching(): void
    {
        $this->login($this->user('Ana'));
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=nothinglikethis&link=1');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $empty = $crawler->filter('[data-testid="chat-search-area"] [data-testid="chat-search-empty"]');
        self::assertCount(1, $empty);
        self::assertStringContainsString('No results', $empty->text());
        self::assertStringContainsString('No message matches these words.', $empty->text());
        self::assertCount(0, $crawler->filter('[data-testid="chat-search-start"]'));
        self::assertNotNull($crawler->filter('details[data-testid="chat-search-filters"]')->attr('open'), 'open when a filter is set');

        foreach (['from=me', 'after=2026-01-01', 'before=2026-12-31'] as $filter) {
            $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?' . $filter);
            self::assertNotNull($crawler->filter('details[data-testid="chat-search-filters"]')->attr('open'), $filter);
        }
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?from=&link=0');
        self::assertNull($crawler->filter('details[data-testid="chat-search-filters"]')->attr('open'), 'default values keep it closed');
    }

    public function testResultsListInTheResultsArea(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $chat->send($luis, $chat->openDirect($ana, $luis), 'The quarterly roadmap is ready');

        $this->login($ana);
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search?q=roadmap');

        self::assertCount(1, $crawler->filter('[data-testid="chat-search-area"] [data-testid="chat-search-item"]'));
        self::assertCount(0, $crawler->filter('[data-testid="chat-search-start"], [data-testid="chat-search-empty"]'));
    }

    public function testBackGoesToSameOriginReferrerOnly(): void
    {
        $this->login($this->user('Ana'));

        self::assertSame('/chat/saved?x=1', $this->backFor('http://localhost/chat/saved?x=1'));
        foreach ([
            'https://evil.example/chat',
            'http://localhost.evil.example/chat',
            'http://localhost@evil.example/chat',
            '//evil.example/chat',
            'http://localhost//evil.example/',
            'http://localhost/\\evil.example',
            'javascript:alert(1)',
            'http://localhost/chat/search?q=hello',
            '',
        ] as $referer) {
            self::assertSame('/chat/', $this->backFor($referer), $referer);
        }
    }

    private function backFor(string $referer): ?string
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/search', server: $referer === '' ? [] : ['HTTP_REFERER' => $referer]);

        return $this->backLink($crawler)->attr('href');
    }

    private function backLink(Crawler $crawler): Crawler
    {
        $back = $crawler->filter('[data-testid="chat-search-back"]');
        self::assertCount(1, $back);

        return $back;
    }

    private function login(TestUser $user): void
    {
        $this->em->clear();
        $found = $this->em->find(TestUser::class, $user->getId());
        self::assertInstanceOf(TestUser::class, $found);
        $this->client->loginUser($found);
    }
}
