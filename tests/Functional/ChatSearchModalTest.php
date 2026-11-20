<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Controller\ChatSearchController;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Twig\ChatDockExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function assert;
use function http_build_query;
use function preg_match;
use function sort;
use function strtolower;
use function trim;

/**
 * 1.40: the search modal of the conversation header — the results fragment (`GET /chat/search/results`) with the scope
 * "this conversation" (default) and "all my conversations", the same access rules as the page (another person's
 * conversation never appears, asked for by uuid or not), escaped excerpts, GET only, and the dock markup (header
 * button, UiKit dialog anatomy, FormKit form) only while `search.conversation_entry` is on.
 */
final class ChatSearchModalTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testOffWithSearch'        => [],
            'testConversationEntryOff' => ['profiles' => ['default' => ['search' => ['enabled' => true, 'conversation_entry' => false]]]],
            default                    => ['profiles' => ['default' => ['search' => ['enabled' => true]]]],
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

    public function testScopeCurrentAndAll(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');
        $chat = $this->get(ChatService::class);

        $withLuis = $chat->send($luis, $chat->openDirect($ana, $luis), 'Budget review on Monday');
        $withEve  = $chat->send($eve, $chat->openDirect($ana, $eve), 'Budget numbers attached');
        $foreign  = $chat->send($luis, $chat->openDirect($luis, $eve), 'Secret budget between Luis and Eve');
        $current  = $withLuis->getConversation()->getUuid();

        $this->login($ana);
        self::assertSame([$withLuis->getUuid()], $this->found(['q' => 'budget', 'conversation' => $current]), 'default scope: this conversation');
        self::assertSame([$withLuis->getUuid()], $this->found(['q' => 'budget', 'scope' => 'current', 'conversation' => $current]));
        self::assertSame($this->sorted([$withLuis, $withEve]), $this->found(['q' => 'budget', 'scope' => 'all', 'conversation' => $current]), 'all: every conversation of the viewer, never another one');

        // Another person's conversation, asked for by uuid: nothing, the same answer as an unknown conversation.
        $foreignConversation = $foreign->getConversation()->getUuid();
        self::assertSame([], $this->found(['q' => 'budget', 'scope' => 'current', 'conversation' => $foreignConversation]));
        self::assertSame([], $this->found(['q' => 'budget', 'scope' => 'current', 'conversation' => '0f8fad5b-d9cb-469f-a165-70867728950e']));
        self::assertNotContains($foreign->getUuid(), $this->found(['q' => 'secret', 'scope' => 'all']));
        // `current` without a conversation is the same as `all` (still only the viewer's conversations).
        self::assertSame($this->found(['q' => 'budget', 'scope' => 'all']), $this->found(['q' => 'budget', 'scope' => 'current']));
        self::assertSame($this->sorted([$withLuis, $withEve]), $this->found(['q' => 'budget']));
    }

    public function testFragmentMarkup(): void
    {
        $ana          = $this->user('Ana');
        $luis         = $this->user('Luis');
        $chat         = $this->get(ChatService::class);
        $hit          = $chat->send($luis, $chat->openDirect($ana, $luis), 'Look <script>alert(1)</script> <img src=x onerror=alert(2)> payload');
        $conversation = $hit->getConversation()->getUuid();

        $this->login($ana);
        $crawler = $this->fragment(['q' => 'payload', 'conversation' => $conversation]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('search-results', $this->client->getResponse()->headers->get(ChatSearchController::FRAGMENT_HEADER), 'the modal renders only this answer');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $html, 'a fragment, not a page');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $item = $crawler->filter('[data-testid="chat-msearch-item"]');
        self::assertCount(1, $item);
        self::assertSame($conversation, $item->attr('data-conversation'));
        self::assertSame($hit->getUuid(), $item->attr('data-message'));
        self::assertSame('/chat/' . $conversation . '?message=' . $hit->getUuid(), $item->attr('href'));
        self::assertSame('payload', $item->filter('mark')->text());
        self::assertCount(0, $item->filter('script, img'));

        $crawler = $this->fragment(['q' => 'nothinglikethis', 'conversation' => $conversation]);
        self::assertCount(1, $crawler->filter('[data-testid="chat-msearch-empty"]'));
        $crawler = $this->fragment(['q' => 'a', 'conversation' => $conversation]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame('The search text is too short.', $crawler->filter('[data-testid="chat-msearch-error"]')->text());
    }

    public function testGetOnlyAndSignedInOnly(): void
    {
        $this->client->request(Request::METHOD_GET, '/chat/search/results?q=hello');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->login($this->user('Ana'));
        $this->client->request(Request::METHOD_POST, '/chat/search/results?q=hello');
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testOffWithSearch(): void
    {
        $this->login($this->user('Ana'));
        $this->client->request(Request::METHOD_GET, '/chat/search/results?q=hello');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $html = $this->renderDockTemplates();
        self::assertStringNotContainsString('nowo-chat-window__search', $this->window($html));
        self::assertStringNotContainsString('data-nowo-chat-template="search-dialog"', $html);
    }

    public function testConversationEntryOff(): void
    {
        $this->login($this->user('Ana'));
        $html = $this->renderDockTemplates();
        self::assertStringNotContainsString('nowo-chat-window__search', $this->window($html));
        self::assertStringNotContainsString('data-nowo-chat-template="search-dialog"', $html);
        $this->client->request(Request::METHOD_GET, '/chat/search/results?q=hello');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the fragment follows search.enabled');
    }

    public function testDockMarkup(): void
    {
        $this->login($this->user('Ana'));
        $html   = $this->renderDockTemplates();
        $window = new Crawler($this->window($html));
        $button = $window->filter('[data-part="message-search"]');
        self::assertCount(1, $button);
        self::assertSame('a', $button->nodeName(), 'a link to /chat/search without the script');
        self::assertSame('/chat/search', $button->attr('href'));
        self::assertSame('Search in this conversation', $button->attr('aria-label'));

        $dialog = new Crawler($this->template($html, 'search-dialog'));
        $box    = $dialog->filter('dialog[data-testid="chat-search-dialog"]');
        self::assertStringContainsString('nowo-ui-confirm-dialog--anatomy', (string) $box->attr('class'));
        self::assertStringContainsString('nowo-ui-confirm-dialog--wide', (string) $box->attr('class'));
        self::assertSame('nowo-chat-msearch-title', $box->attr('aria-labelledby'));
        self::assertSame('Search messages', $dialog->filter('h2#nowo-chat-msearch-title')->text());
        self::assertCount(1, $dialog->filter('header [data-confirm-dialog-close]'), 'the × of the anatomy');
        $form = $dialog->filter('form[data-part="search-form"]');
        self::assertSame('get', strtolower((string) $form->attr('method')));
        self::assertSame(ChatDockExtension::SEARCH_FORM . '_form', $form->attr('id'));
        self::assertSame('current', $form->filter('select[name="nowo_chat_msearch[scope]"] option[selected]')->attr('value'));
        self::assertCount(1, $form->filter('input[name="nowo_chat_msearch[q]"]'));
        $filters = $form->filter('details[data-part="search-filters"]');
        self::assertNull($filters->attr('open'));
        self::assertSame('More filters', trim($filters->filter('summary')->text()));
        self::assertCount(1, $filters->filter('[name="nowo_chat_msearch[link]"]'));
        self::assertCount(0, $form->filter('[name="nowo_chat_msearch[_token]"]'), 'reads only: no CSRF token');
        $footer = $dialog->filter('footer button');
        self::assertCount(2, $footer);
        self::assertSame('chat-msearch-close', $footer->eq(0)->attr('data-testid'), 'Close first');
        self::assertSame('submit', $footer->eq(1)->attr('type'));
        self::assertSame(ChatDockExtension::SEARCH_FORM . '_form', $footer->eq(1)->attr('form'));
        self::assertStringContainsString('nowo-ui-btn--primary', (string) $footer->eq(1)->attr('class'));
        self::assertCount(0, $dialog->filter('.nowo-ui-dialog__body .nowo-ui-btn--primary'), 'no primary button in the body');
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string> Message uuids found
     */
    private function found(array $query): array
    {
        $crawler = $this->fragment($query);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $uuids = $crawler->filter('[data-testid="chat-msearch-item"]')->each(static fn (Crawler $node): string => (string) $node->attr('data-message'));
        sort($uuids);

        return $uuids;
    }

    /**
     * @param array<string, string> $query
     */
    private function fragment(array $query): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/chat/search/results?' . http_build_query($query));
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

    /** The `window` template (the conversation window) of the dock markup. */
    private function window(string $html): string
    {
        return $this->template($html, 'window');
    }

    /** The content of one `<template data-nowo-chat-template>` (string match: a parser may keep it out of the tree). */
    private function template(string $html, string $name): string
    {
        self::assertSame(1, preg_match('#<template data-nowo-chat-template="' . $name . '">(.*?)</template>#s', $html, $match), $name);

        return $match[1];
    }

    private function login(TestUser $user): void
    {
        $this->em->clear();
        $found = $this->em->find(TestUser::class, $user->getId());
        self::assertInstanceOf(TestUser::class, $found);
        $this->client->loginUser($found);
    }
}
