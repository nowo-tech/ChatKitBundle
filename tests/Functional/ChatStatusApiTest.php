<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
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
 * Chat status and chat preferences through the JSON API (1.10): the status a user picks is what their direct contacts
 * see (list, presence, members, page), an invisible user is offline without text, a viewer who hides their own presence
 * sees no dots but still the text, idle means away, and one preference is saved at a time from an allow-list.
 */
final class ChatStatusApiTest extends DatabaseTestCase
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

    public function testStatusIsWhatContactsSee(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($ana, $dm, 'hi');
        $realtime = $this->get(RecordingRealtime::class);

        // Ana sets "busy" with a custom status; her dock and her other tabs get it, Luis gets what others see.
        $csrf = $this->login($ana)['csrf'];
        $this->get(ChatPresence::class)->heartbeat($ana, true);
        $realtime->published = [];
        $status              = $this->post('/chat/api/status', ['status' => 'busy', 'text' => 'In a meeting', 'emoji' => '📅', 'clearAfter' => '1h'], $csrf);
        self::assertSame('busy', $status['status']['status']);
        self::assertSame('In a meeting', $status['status']['text']);
        self::assertNotNull($status['status']['expiresAt']);
        self::assertSame(['status', 'my_status'], $realtime->types());
        self::assertSame(['type' => 'status', 'userUuid' => $ana->getChatPublicId(), 'status' => 'busy', 'text' => 'In a meeting', 'emoji' => '📅'], $this->get(RecordingRealtime::class)->published[0]['payload']);
        self::assertSame('busy', $this->login($ana)['status']['status']);

        // Luis sees it in his list, his heartbeat answer and the page header.
        $csrfLuis = $this->login($luis)['csrf'];
        $this->get(ChatPresence::class)->heartbeat($luis, true);
        $row = $this->getJson('/chat/api/conversations')['conversations'][0];
        self::assertSame(['busy', 'In a meeting', '📅', true], [$row['peerStatus'], $row['peerStatusText'], $row['peerStatusEmoji'], $row['peerOnline']]);
        $presence = $this->post('/chat/api/presence', ['visible' => true], $csrfLuis);
        self::assertSame(['status' => 'busy', 'text' => 'In a meeting', 'emoji' => '📅'], $presence['statuses'][$ana->getChatPublicId()]);
        $page = $this->client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid());
        self::assertSame('busy', $page->filter('.nowo-chat-page__peer-status')->attr('data-status'));
        self::assertStringContainsString('In a meeting', $page->filter('.nowo-chat-page__peer-status')->text());
        self::assertSame('available', $page->filter('[data-nowo-chat-status-open]')->attr('data-status'), 'his own status button');

        // Idle (no input in any tab): an available user shows as away; busy stays busy.
        $this->login($ana);
        $this->post('/chat/api/presence', ['visible' => true, 'idle' => true], $csrf);
        $this->post('/chat/api/status', ['status' => 'available'], $csrf);
        $this->login($luis);
        self::assertSame(['status' => 'away', 'text' => null, 'emoji' => null], $this->post('/chat/api/presence', ['visible' => true], $csrfLuis)['statuses'][$ana->getChatPublicId()]);

        // Invisible: offline to everyone, without the custom text — the real state never leaks.
        $this->login($ana);
        $realtime->published = [];
        $this->post('/chat/api/status', ['status' => 'invisible', 'text' => 'secret plans'], $csrf);
        self::assertSame(['type' => 'status', 'userUuid' => $ana->getChatPublicId(), 'status' => 'offline', 'text' => null, 'emoji' => null], $this->get(RecordingRealtime::class)->published[0]['payload']);
        self::assertSame('invisible', $this->get(RecordingRealtime::class)->published[1]['payload']['status']['status'], 'her own tabs know');
        $this->login($luis);
        $answer = $this->post('/chat/api/presence', ['visible' => true], $csrfLuis);
        self::assertSame([], $answer['online']);
        self::assertSame(['status' => 'offline', 'text' => null, 'emoji' => null], $answer['statuses'][$ana->getChatPublicId()]);
        $row = $this->getJson('/chat/api/conversations')['conversations'][0];
        self::assertSame(['offline', null, false], [$row['peerStatus'], $row['peerStatusText'], $row['peerOnline']]);

        // A viewer who hides their own presence sees no dots, but still the custom text.
        $this->login($ana);
        $this->post('/chat/api/status', ['status' => 'dnd', 'text' => 'Focus time'], $csrf);
        $this->login($luis);
        $this->post('/chat/api/settings', ['key' => 'presenceVisible', 'value' => false], $csrfLuis);
        $row = $this->getJson('/chat/api/conversations')['conversations'][0];
        self::assertSame(['offline', 'Focus time'], [$row['peerStatus'], $row['peerStatusText']]);
    }

    public function testMembersCarryTheirStatus(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($luis);
        $settings->setStatus('away', 'Lunch', '🍝');
        $this->em->persist($settings);
        $this->em->flush();
        $this->get(ChatPresence::class)->heartbeat($luis, true);

        $config  = $this->login($ana);
        $channel = $this->post('/chat/api/channel', ['channelKey' => TestChannelProvider::KEY], $config['csrf'])['conversation'];
        $members = $this->getJson('/chat/api/conversations/' . $channel['uuid'] . '/members?q=lu')['members'];
        self::assertSame(['away', 'Lunch', '🍝'], [$members[0]['status'], $members[0]['statusText'], $members[0]['statusEmoji']]);
    }

    public function testInvalidStatusAndSettingsAreRefused(): void
    {
        $ana  = $this->user('Ana');
        $csrf = $this->login($ana)['csrf'];

        foreach ([['status' => 'sleeping'], ['status' => 'busy', 'clearAfter' => 'fortnight'], ['status' => 'busy', 'text' => 42], ['status' => 'busy', 'emoji' => ['x']], ['status' => 'busy', 'text' => str_repeat('x', 81)], [], ['status' => 'busy', 'clearAfter' => 5]] as $body) {
            $this->post('/chat/api/status', $body, $csrf, 422);
        }
        foreach ([['key' => 'pushEnabled', 'value' => true], ['key' => 'volume', 'value' => 'loud'], ['key' => 7, 'value' => true], ['value' => true]] as $body) {
            $this->post('/chat/api/settings', $body, $csrf, 422);
        }
        $this->post('/chat/api/settings', ['key' => 'volume', 'value' => 10], 'forged', 403);
        $this->post('/chat/api/status', ['status' => 'busy'], 'forged', 403);
    }

    public function testOnePreferenceAtATime(): void
    {
        $ana      = $this->user('Ana');
        $csrf     = $this->login($ana)['csrf'];
        $realtime = $this->get(RecordingRealtime::class);

        $prefs = $this->post('/chat/api/settings', ['key' => 'soundBuzz', 'value' => 'knock'], $csrf)['prefs'];
        self::assertSame('knock', $prefs['sounds']['buzz']);
        self::assertSame(['prefs'], $realtime->types(), 'the other tabs apply it');
        $this->post('/chat/api/settings', ['key' => 'sendKey', 'value' => 'ctrl_enter'], $csrf);
        $this->post('/chat/api/settings', ['key' => 'volume', 'value' => 40], $csrf);

        $this->em->clear();
        $stored = $this->em->getRepository(ChatUserSettings::class)->findOneBy(['user' => $ana->getId()]) ?? self::fail('stored');
        self::assertSame(['knock', 'ctrl_enter', 40], [$stored->getSoundFor('buzz'), $stored->getSendKey(), $stored->getVolume()]);
        self::assertSame('ctrl_enter', $this->login($ana)['prefs']['sendKey']);
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
    private function post(string $url, array $body, string $csrf, int $status = 0): array
    {
        $this->client->request(Request::METHOD_POST, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $code = $this->client->getResponse()->getStatusCode();
        if ($status !== 0) {
            self::assertSame($status, $code, json_encode($body, JSON_THROW_ON_ERROR));
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
