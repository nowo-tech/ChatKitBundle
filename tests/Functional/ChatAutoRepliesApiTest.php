<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.11: automatic replies (set from the status menu: while away / busy / dnd or between two dates; once per
 * conversation per interval; never to an automatic message; @mentions in channels on request; never in support) and
 * quick replies (each person's snippets: create, change, delete, at most 50, never someone else's).
 */
final class ChatAutoRepliesApiTest extends DatabaseTestCase
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

    public function testAutomaticReplies(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');

        // Ana: "away" with an automatic reply (also for @mentions).
        $config = $this->login($ana);
        self::assertSame(['enabled' => false, 'text' => '', 'when' => 'status', 'from' => null, 'until' => null, 'mentions' => false], $config['autoReply']);
        $saved = $this->send(Request::METHOD_POST, '/chat/api/auto-reply', $config['csrf'], 0, ['enabled' => true, 'text' => 'Back on **Monday**', 'when' => 'status', 'mentions' => true]);
        self::assertSame('Back on **Monday**', $saved['autoReply']['text']);
        $this->send(Request::METHOD_POST, '/chat/api/status', $config['csrf'], 0, ['status' => 'away']);
        foreach ([['enabled' => true, 'text' => ''], ['enabled' => true, 'text' => str_repeat('x', 501)], ['enabled' => true, 'text' => 'x', 'when' => 'range'], ['enabled' => true, 'text' => 'x', 'when' => 'range', 'from' => '2026-11-02T10:00:00Z', 'until' => '2026-11-01T10:00:00Z'], ['text' => 5], ['when' => 'sometimes']] as $body) {
            $this->send(Request::METHOD_POST, '/chat/api/auto-reply', $config['csrf'], 422, $body);
        }

        // Luis writes twice: one automatic reply within the interval, marked, without push.
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($this->fresh($luis), $this->fresh($ana));
        $chat->send($this->fresh($luis), $dm, 'are you there?');
        $chat->send($this->fresh($luis), $this->fresh($dm), 'hello?');
        $this->login($this->fresh($luis));
        $messages = $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages'];
        self::assertSame(['text', 'auto', 'text'], array_column($messages, 'kind'));
        self::assertSame('<p>Back on <strong>Monday</strong></p>', $messages[1]['html']);
        self::assertSame($ana->getChatPublicId(), $messages[1]['authorUuid']);

        // No loops: Luis also has an automatic reply; Ana's never triggers his. Ana answering herself does.
        $luisSettings = $this->em->getRepository(ChatUserSettings::class)->forUser($this->fresh($luis));
        $luisSettings->setAutoReply(true, 'I am busy', 'range', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), false);
        $this->em->persist($luisSettings);
        $this->em->flush();
        self::assertCount(3, $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages']);
        $chat->send($this->fresh($ana), $this->fresh($dm), 'ok, later');
        self::assertSame('auto', $this->getJson('/chat/api/conversations/' . $dm->getUuid() . '/messages')['messages'][4]['kind']);

        // Channels: only @mentions, for who chose so.
        $channel = $chat->openChannel($this->fresh($luis), $this->get(ChatAccess::class)->channel(TestChannelProvider::KEY) ?? self::fail('channel'));
        $chat->send($this->fresh($luis), $channel, 'hi all');
        $chat->send($this->fresh($luis), $this->fresh($channel), 'ping @[Ana](' . $ana->getChatPublicId() . ')');
        $kinds = array_column($this->getJson('/chat/api/conversations/' . $channel->getUuid() . '/messages')['messages'], 'kind');
        self::assertSame(['text', 'text', 'auto'], $kinds);

        // Support: never.
        $support = $chat->openSupport($this->fresh($luis));
        $chat->send($this->fresh($luis), $support, 'help');
        self::assertSame(0, $this->em->getRepository(ChatMessage::class)->count(['conversation' => $support->getId(), 'kind' => ChatMessage::KIND_AUTO]));

        // Available again: no automatic reply (status mode).
        $this->login($this->fresh($ana));
        $config = $this->login($this->fresh($ana));
        $this->send(Request::METHOD_POST, '/chat/api/status', $config['csrf'], 0, ['status' => 'available']);
        $this->get(ChatPresence::class)->heartbeat($this->fresh($ana), true);
        $bea   = $this->user('Bea');
        $other = $chat->openDirect($bea, $this->fresh($ana));
        $chat->send($this->fresh($bea), $this->fresh($other), 'hi');
        self::assertSame(0, $this->em->getRepository(ChatMessage::class)->count(['conversation' => $other->getId(), 'kind' => ChatMessage::KIND_AUTO]));
        self::assertSame('Back on **Monday**', $this->get(ChatPersonalData::class)->export($this->fresh($ana))['settings']['auto_reply']['text']);
    }

    public function testQuickReplies(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $config = $this->login($ana);
        self::assertStringEndsWith('/api/quick-replies', $config['endpoints']['quickReplies']);
        self::assertSame(['quickReplies' => []], $this->getJson('/chat/api/quick-replies'));

        $created = $this->send(Request::METHOD_POST, '/chat/api/quick-replies', $config['csrf'], 201, ['title' => 'Thanks', 'body' => 'Thanks, I will look into it.'])['quickReply'];
        $this->send(Request::METHOD_POST, '/chat/api/quick-replies', $config['csrf'], 201, ['title' => 'Address', 'body' => 'Main St. 1']);
        self::assertSame(['Address', 'Thanks'], array_column($this->getJson('/chat/api/quick-replies')['quickReplies'], 'title'));
        $this->send(Request::METHOD_POST, '/chat/api/quick-replies', $config['csrf'], 422, ['title' => '', 'body' => 'x']);
        $this->send(Request::METHOD_POST, '/chat/api/quick-replies', $config['csrf'], 422, ['title' => 'x', 'body' => str_repeat('y', 2001)]);

        $changed = $this->send(Request::METHOD_PATCH, '/chat/api/quick-replies/' . $created['id'], $config['csrf'], 0, ['title' => 'Thanks!', 'body' => 'Thanks a lot.'])['quickReply'];
        self::assertSame(['id' => $created['id'], 'title' => 'Thanks!', 'body' => 'Thanks a lot.'], $changed);
        $this->send(Request::METHOD_PATCH, '/chat/api/quick-replies/' . $created['id'], $config['csrf'], 422, ['title' => 'x']);

        // Someone else's: 404.
        $other = $this->login($this->fresh($luis));
        $this->send(Request::METHOD_PATCH, '/chat/api/quick-replies/' . $created['id'], $other['csrf'], 404, ['title' => 'x', 'body' => 'y']);
        $this->send(Request::METHOD_DELETE, '/chat/api/quick-replies/' . $created['id'], $other['csrf'], 404);

        $config = $this->login($this->fresh($ana));
        self::assertSame(['deleted' => true], $this->send(Request::METHOD_DELETE, '/chat/api/quick-replies/' . $created['id'], $config['csrf']));
        self::assertSame([['title' => 'Address', 'text' => 'Main St. 1']], $this->get(ChatPersonalData::class)->export($this->fresh($ana))['quick_replies']);

        // At most 50.
        for ($i = 1; $i < ChatQuickReply::MAX_PER_USER; ++$i) {
            $this->em->persist(new ChatQuickReply($this->fresh($ana), 'n' . $i, 'b'));
        }
        $this->em->flush();
        self::assertSame('too_many_quick_replies', $this->send(Request::METHOD_POST, '/chat/api/quick-replies', $config['csrf'], 422, ['title' => 'x', 'body' => 'y'])['error']);

        $this->get(ChatPersonalData::class)->erase($this->fresh($ana));
        self::assertSame(0, $this->em->getRepository(ChatQuickReply::class)->count([]));
    }

    /**
     * @template T of ChatConversation|TestUser
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(ChatConversation|TestUser $entity): ChatConversation|TestUser
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
