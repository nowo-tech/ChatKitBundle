<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Badge\ChatAuthorBadges;
use Nowo\ChatKitBundle\Enum\ChatAuthorBadgeVariant;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAuthorBadgeProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_filter;
use function array_values;
use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.29 (Beacon spec 234): author badges on a standard Symfony host (Doctrine user entity, `user_reference: relation`)
 * — the message API, the realtime events, the pins, the full page and the dock template; one provider call per
 * response; escaped everywhere.
 */
final class ChatAuthorBadgesTest extends DatabaseTestCase
{
    private const string SCRIPT = '<script>alert(1)</script>';

    private KernelBrowser $client;

    private TestAuthorBadgeProvider $provider;

    protected function authorBadges(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['limits' => ['send_per_minute' => 10_000, 'actions_per_minute' => 10_000, 'reads_per_minute' => 10_000]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client   = $client;
        $this->provider = $this->get(TestAuthorBadgeProvider::class);
    }

    public function testTheMessageApiCarriesTheBadgesOfThePageAuthorsInOneCall(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $bot     = $this->user('Deploy bot');
        $luis    = $this->user('Luis');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bot, $channel, 'deployed');
        $chat->send($luis, $channel, 'thanks');
        $chat->send($bot, $channel, 'again');
        $chat->send($ana, $channel, 'mine');
        $this->provider->badges = [(string) $bot->getId() => [new ChatAuthorBadge('Agent', ChatAuthorBadgeVariant::Agent, 'API token "deploy"')]];

        $this->client->loginUser($ana);
        $this->client->request(Request::METHOD_GET, '/page');
        $this->provider->clear();
        $messages = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/messages')['messages'];

        self::assertCount(1, $this->provider->calls, 'one provider call for the page');
        self::assertSame([(string) $bot->getId(), (string) $luis->getId(), (string) $ana->getId()], $this->provider->calls[0], 'distinct authors, by their stored key');
        self::assertSame([$bot->getChatPublicId(), $luis->getChatPublicId(), $ana->getChatPublicId()], $this->provider->publicIds[0]);
        $agent = [['label' => 'Agent', 'variant' => 'agent', 'title' => 'API token "deploy"']];
        self::assertSame([$agent, null, $agent, null], array_map(static fn (array $message): ?array => $message['authorBadges'] ?? null, $messages));
        self::assertSame('authorBadges', array_key_last($messages[0]), 'an additive field, after the 1.28 ones');
        self::assertArrayNotHasKey('authorBadges', $messages[1], 'no badge: the 1.28 shape');

        // One message (edit / reactions): one call of its own.
        $this->provider->clear();
        $one = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/messages/' . $messages[0]['uuid']);
        self::assertSame($agent, $one['message']['authorBadges']);
        self::assertCount(1, $this->provider->calls);
    }

    public function testRealtimeEventsCarryTheBadgesAndShareTheCallWithTheResponse(): void
    {
        $chat                   = $this->get(ChatService::class);
        $bot                    = $this->user('Deploy bot');
        $luis                   = $this->user('Luis');
        $channel                = $chat->openDirect($bot, $luis);
        $this->provider->badges = [(string) $bot->getId() => [new ChatAuthorBadge('Agent', 'agent')]];
        $csrf                   = $this->signIn($bot);
        $realtime               = $this->get(RecordingRealtime::class);
        $realtime->published    = [];
        $this->provider->clear();

        $sent = $this->send('/chat/api/conversations/' . $channel->getUuid() . '/messages', $csrf, ['body' => 'deployed']);
        self::assertSame([['label' => 'Agent', 'variant' => 'agent', 'title' => null]], $sent['message']['authorBadges']);
        $events = array_values(array_filter($realtime->published, static fn (array $event): bool => $event['payload']['type'] === 'message'));
        self::assertCount(1, $events);
        self::assertSame([['label' => 'Agent', 'variant' => 'agent', 'title' => null]], $events[0]['payload']['message']['authorBadges'], 'viewer-neutral payload, same badges');
        self::assertCount(1, $this->provider->calls, 'the event and the response share the request memo');

        // The pinned bar: one call for its authors.
        $this->send('/chat/api/conversations/' . $channel->getUuid() . '/messages/' . $sent['message']['uuid'] . '/pin', $csrf, []);
        $this->provider->clear();
        $pins = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/pins')['pins'];
        self::assertSame('Agent', $pins[0]['authorBadges'][0]['label']);
        self::assertSame([[(string) $bot->getId()]], $this->provider->calls);
    }

    public function testAScriptLabelIsTextInTheApiThePageAndTheEvents(): void
    {
        $chat                   = $this->get(ChatService::class);
        $ana                    = $this->user('Ana');
        $bot                    = $this->user('Deploy bot');
        $channel                = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $this->provider->badges = [(string) $bot->getId() => [new ChatAuthorBadge(self::SCRIPT, 'agent', '"><img src=x onerror=alert(1)>')]];
        $realtime               = $this->get(RecordingRealtime::class);
        $realtime->published    = [];
        $chat->send($bot, $channel, 'deployed');

        // The JSON keeps the text as is (the dock sets it with textContent; see badges.test.ts).
        $this->client->loginUser($ana);
        $messages = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/messages')['messages'];
        self::assertSame(self::SCRIPT, $messages[0]['authorBadges'][0]['label']);
        /** @var list<array{payload: array{message: array{authorBadges: list<array{label: string}>}}}> $published */
        $published = $realtime->published;
        self::assertSame(self::SCRIPT, $published[0]['payload']['message']['authorBadges'][0]['label']);

        // The full page escapes it.
        $this->client->request(Request::METHOD_GET, '/chat/' . $channel->getUuid());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('<p class="nowo-chat-msg__author">Deploy bot <span class="nowo-chat-author-badge nowo-chat-author-badge--agent" data-variant="agent" title="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;">&lt;script&gt;alert(1)&lt;/script&gt;</span></p>', $html);
    }

    public function testThePageRendersTheBadgesThroughItsBlockWithOneCall(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $bot     = $this->user('Deploy bot');
        $luis    = $this->user('Luis');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bot, $channel, 'deployed');
        $chat->send($luis, $channel, 'thanks');
        $chat->send($bot, $channel, 'again');
        $this->provider->badges = [(string) $bot->getId() => [new ChatAuthorBadge('Agent', 'agent'), new ChatAuthorBadge('Beta', 'warning', 'Preview')]];

        $this->client->loginUser($ana);
        $this->client->request(Request::METHOD_GET, '/page');
        $this->provider->clear();
        $crawler = $this->client->request(Request::METHOD_GET, '/chat/' . $channel->getUuid());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->provider->calls);
        self::assertSame(['Agent', 'Beta', 'Agent', 'Beta'], $crawler->filter('.nowo-chat-page__messages .nowo-chat-author-badge')->each(static fn ($node): string => $node->text()));
        self::assertSame(['Deploy bot Agent Beta', 'Luis', 'Deploy bot Agent Beta'], $crawler->filter('.nowo-chat-page__messages .nowo-chat-msg__author')->each(static fn ($node): string => $node->text()));
        self::assertSame('Preview', $crawler->filter('.nowo-chat-author-badge--warning')->first()->attr('title'));
    }

    public function testTheDockTemplateOfABadgeIsRenderedOnlyWithAProvider(): void
    {
        $html = $this->renderDockTemplates();
        self::assertStringContainsString('<template data-nowo-chat-template="author-badge"><span class="nowo-chat-author-badge" data-part="label"></span></template>', $html);
        self::assertTrue($this->get(ChatAuthorBadges::class)->enabled());
    }

    public function testAFailingProviderGivesNoBadgeAndTheChatKeepsWorking(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $bot     = $this->user('Deploy bot');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bot, $channel, 'deployed');
        $this->provider->badges = [(string) $bot->getId() => [new ChatAuthorBadge('Agent', 'agent')]];
        $this->provider->fail   = true;

        $this->client->loginUser($ana);
        $messages = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/messages')['messages'];
        self::assertArrayNotHasKey('authorBadges', $messages[0]);
        $this->client->request(Request::METHOD_GET, '/chat/' . $channel->getUuid());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testUnaskedKeysAndValuesThatAreNotBadgesAreIgnored(): void
    {
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $bot     = $this->user('Deploy bot');
        $luis    = $this->user('Luis');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($luis, $channel, 'hello');
        $this->provider->badges = [(string) $luis->getId() => ['<b>raw</b>', new ChatAuthorBadge('Guest', 'neutral')]];
        $this->provider->extra  = [(string) $bot->getId() => [new ChatAuthorBadge('Agent', 'agent')]];

        $this->client->loginUser($ana);
        $this->client->request(Request::METHOD_GET, '/page');
        $this->provider->clear();
        $messages = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/messages')['messages'];
        self::assertSame([['label' => 'Guest', 'variant' => 'neutral', 'title' => null]], $messages[0]['authorBadges'], 'a string is not a badge');

        self::assertSame([[(string) $luis->getId()]], $this->provider->calls, 'the bot was not asked; its unasked key is ignored');
        self::assertCount(1, $messages);
    }

    public function testThePageOfMessagesDoesNotQueryPerAuthor(): void
    {
        $few  = $this->messagesPageQueries(2);
        $many = $this->messagesPageQueries(12);
        self::assertSame($few, $many, 'the badges add no query per author');
        self::assertCount(1, $this->provider->calls);
        self::assertCount(12, $this->provider->calls[0]);
    }

    /** SQL statements of one page of messages written by `$authors` people who all have a badge. */
    private function messagesPageQueries(int $authors): int
    {
        $this->tearDown();
        $this->setUp();
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        for ($i = 0; $i < $authors; ++$i) {
            $author                                            = $this->user('Bot ' . $i);
            $this->provider->badges[(string) $author->getId()] = [new ChatAuthorBadge('Agent', 'agent')];
            $chat->send($author, $channel, 'run ' . $i);
        }
        $this->em->clear();
        $this->client->loginUser($ana);
        $this->client->request(Request::METHOD_GET, '/page');
        $counter = $this->get(QueryCounter::class);
        $counter->clear();
        $this->provider->clear();
        $messages = $this->json('/chat/api/conversations/' . $channel->getUuid() . '/messages')['messages'];
        self::assertCount($authors, array_column($messages, 'authorBadges'));

        return $counter->count();
    }

    private function signIn(TestUser $user): string
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array{csrf: string} $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config['csrf'];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $url, string $csrf, array $body): array
    {
        $this->client->request(Request::METHOD_POST, $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
