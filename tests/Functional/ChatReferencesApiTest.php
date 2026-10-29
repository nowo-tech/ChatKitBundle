<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function count;

use const JSON_THROW_ON_ERROR;

/**
 * #references through the JSON API, per profile: the command menu and the search offer only what the conversation's
 * audience may open (a channel keeps its own items, a direct conversation what BOTH people may open), a forged or
 * foreign token is stored as plain text, and each reader sees a card or the neutral "Content not visible" chip —
 * never the title of an item they may not open.
 */
final class ChatReferencesApiTest extends DatabaseTestCase
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

    public function testChannelCommandsSearchValidationAndCardsPerViewer(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $noTasks  = $this->user('NoTasks Nora');
        $stranger = $this->user('Stranger Eve');

        $config = $this->login($ana);
        self::assertTrue($config['references']);
        self::assertStringContainsString('__uuid__', $config['endpoints']['references']);
        self::assertSame('Content not visible', $config['i18n']['reference_restricted']);
        $uuid = $this->requestJson(Request::METHOD_POST, '/chat/api/channel', ['channelKey' => TestChannelProvider::KEY], $config['csrf'])['conversation']['uuid'];
        $base = '/chat/api/conversations/' . $uuid . '/references';

        self::assertSame(['types' => [['key' => 'task', 'label' => 'Task', 'icon' => 'task']], 'references' => []], $this->getJson($base));
        self::assertSame(['1', '2', '4'], array_column($this->getJson($base . '?type=task')['references'], 'id'), 'the channel keeps its own project; the foreign type is dropped');
        $found = $this->getJson($base . '?type=*&q=deploy')['references'];
        self::assertSame([['type' => 'task', 'id' => '1', 'title' => 'Deploy the API', 'url' => '/tasks/1', 'subtitle' => 'Acme · Doing', 'badge' => 'High', 'tone' => 'success']], $found);
        self::assertSame([], $this->getJson($base . '?type=monitor')['references']);
        self::assertSame([], $this->getJson($base . '?type=issue')['references'], 'an unregistered type offers nothing');

        $realtime = $this->get(RecordingRealtime::class);
        $sent     = $this->requestJson(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', [
            'body' => 'see #[old](task:1), #[Secret](task:2), #[Forged](task:3), #[Nope](task:999), #[Ghost](wiki:1), #[Odd](task:4) `#[code](task:1)`',
        ], $config['csrf'])['message'];
        self::assertStringContainsString('<a class="nowo-chat-ref" href="/tasks/1" data-ref-type="task" title="Task · Deploy the API · Acme · Doing · High">', $sent['html']);
        self::assertStringContainsString('<span class="nowo-chat-ref__title">Deploy the API</span>', $sent['html'], 'the current title, not the typed one');
        self::assertStringContainsString('<span class="nowo-chat-ref__title">Secret plan</span>', $sent['html']);
        self::assertStringContainsString(', #Forged, #Nope, #Ghost, ', $sent['html'], 'foreign, unknown and unregistered tokens become text');
        self::assertStringContainsString('<span class="nowo-chat-ref" data-ref-type="task" title="', $sent['html'], 'an unsafe URL renders no link');
        self::assertStringContainsString('nowo-chat-ref__badge--neutral', $sent['html'], 'an unknown tone is neutral');
        self::assertStringContainsString('<code>#[code](task:1)</code>', $sent['html']);
        self::assertSame(['1', '2', '4'], array_column($sent['references'], 'id'));
        self::assertFalse($sent['refsPending']);

        // One realtime payload for every member: empty slots, no title, type or id.
        $events  = array_values(array_filter($realtime->published, static fn (array $event): bool => $event['payload']['type'] === 'message'));
        $payload = $events[count($events) - 1]['payload']['message'];
        self::assertTrue($payload['refsPending']);
        self::assertSame([], $payload['references']);
        self::assertStringNotContainsString('Secret', (string) json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame(3, substr_count($payload['html'], '<span class="nowo-chat-ref-slot"></span>'));

        // Luis (no right on task 2) and Nora (no task permission): the neutral chip, never the title.
        $this->login($luis);
        $message = $this->getJson('/chat/api/conversations/' . $uuid . '/messages')['messages'][0];
        self::assertStringContainsString('Deploy the API', $message['html']);
        self::assertStringNotContainsString('Secret', $message['html']);
        self::assertSame(1, substr_count($message['html'], 'nowo-chat-ref--restricted'));
        self::assertSame(['1', '4'], array_column($message['references'], 'id'));
        self::assertSame($message, $this->getJson('/chat/api/conversations/' . $uuid . '/messages/' . $message['uuid'])['message']);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $uuid . '/messages/00000000-0000-4000-8000-000000000000');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->login($noTasks);
        self::assertSame([], $this->getJson($base)['types'], 'no /task for someone who may open no task');
        self::assertSame([], $this->getJson($base . '?type=task&q=deploy')['references']);
        $html = $this->getJson('/chat/api/conversations/' . $uuid . '/messages')['messages'][0]['html'];
        self::assertSame(3, substr_count($html, 'nowo-chat-ref--restricted'));
        self::assertStringNotContainsString('Deploy', $html);
        self::assertStringNotContainsString('/tasks/', $html);
        self::assertStringContainsString('Content not visible', $html);

        // Someone who cannot read the conversation gets 404, like reading it.
        $this->login($stranger);
        $this->client->request(Request::METHOD_GET, $base . '?type=task');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDirectConversationOffersAndKeepsWhatBothMayOpen(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $config = $this->login($ana);
        $uuid   = $this->requestJson(Request::METHOD_POST, '/chat/api/direct', ['userUuid' => $luis->getChatPublicId()], $config['csrf'])['conversation']['uuid'];
        $base   = '/chat/api/conversations/' . $uuid . '/references';

        self::assertSame(['1', '3', '4'], array_column($this->getJson($base . '?type=task')['references'], 'id'), 'every project, but only what Luis may open too');
        $sent = $this->requestJson(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', ['body' => 'see #[Secret plan](task:2) and #[x](task:3)'], $config['csrf'])['message'];
        self::assertStringStartsWith('<p>see #Secret plan and <a class="nowo-chat-ref"', $sent['html'], 'an item Luis may not open is not posted');

        // Editing re-validates the tokens the same way.
        $edited = $this->requestJson(Request::METHOD_PATCH, '/chat/api/conversations/' . $uuid . '/messages/' . $sent['uuid'], ['body' => '#[a](task:2) #[b](task:1)'], $config['csrf'])['message'];
        self::assertStringStartsWith('<p>#a <a class="nowo-chat-ref" href="/tasks/1"', $edited['html']);
        // 1.10.1: a card alone in its paragraph is a full-width card; within text it stays a compact chip.
        $alone = $this->requestJson(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', ['body' => "#[a](task:1)\n\nand #[b](task:1) inline"], $config['csrf'])['message'];
        self::assertStringStartsWith('<p><a class="nowo-chat-ref nowo-chat-ref--block" href="/tasks/1"', $alone['html']);
        self::assertStringContainsString('<p>and <a class="nowo-chat-ref" href="/tasks/1"', $alone['html']);

        $nora = $this->user('NoTasks Nora');
        $uuid = $this->requestJson(Request::METHOD_POST, '/chat/api/direct', ['userUuid' => $nora->getChatPublicId()], $config['csrf'])['conversation']['uuid'];
        self::assertSame([], $this->getJson('/chat/api/conversations/' . $uuid . '/references')['types'], 'nothing both may open: no command');
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
