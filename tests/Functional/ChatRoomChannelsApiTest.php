<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
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
 * 1.24 (Beacon spec 184): read-only channels (ChatChannel::$readOnly and ChatChannelWritePolicyInterface, enforced on
 * the server), the channel subtitle and icon, system lines (server only, text, no reactions / edits / pins), the mute
 * route and @channel / @here behind ChatChannelMentionPolicyInterface and a per-writer budget.
 */
final class ChatRoomChannelsApiTest extends DatabaseTestCase
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

    public function testAReadOnlyChannelIsReadNeverWritten(): void
    {
        $ana     = $this->user('Ana');
        $chat    = $this->get(ChatService::class);
        $archive = $this->channel(TestChannelProvider::ARCHIVE_KEY);
        $room    = $chat->openChannel($ana, $archive);
        $old     = $chat->send($ana, $room, 'from before the archive');

        $csrf    = $this->login($ana)['csrf'];
        $summary = $this->getJson('/chat/api/conversations/' . $room->getUuid())['conversation'];
        self::assertFalse($summary['canSend']);
        self::assertFalse($summary['canPin']);
        self::assertSame('#Archive', $summary['title']);
        self::assertSame('Old  work', $summary['subtitle'], 'bidirectional overrides and line breaks never reach the dock');
        self::assertSame('📦', $summary['initials'], 'the icon replaces the # bubble');
        self::assertSame('from before the archive', strip_tags((string) $this->getJson('/chat/api/conversations/' . $room->getUuid() . '/messages')['messages'][0]['html']), 'still readable');

        $base = '/chat/api/conversations/' . $room->getUuid();
        self::assertSame(['ok' => false, 'error' => 'read_only'], $this->send(Request::METHOD_POST, $base . '/messages', $csrf, 403, ['body' => 'no']));
        $this->send(Request::METHOD_POST, $base . '/typing', $csrf, 404);
        $this->send(Request::METHOD_PATCH, $base . '/messages/' . $old->getUuid(), $csrf, 404, ['body' => 'edited']);
        $this->send(Request::METHOD_POST, $base . '/messages/' . $old->getUuid() . '/reactions', $csrf, 404, ['emoji' => '👍']);
        self::assertSame(['ok' => false, 'error' => 'pin_forbidden'], $this->send(Request::METHOD_POST, $base . '/messages/' . $old->getUuid() . '/pin', $csrf, 403));
    }

    public function testTheWritePolicyKeepsReadersReading(): void
    {
        $ana    = $this->user('Ana');
        $reader = $this->user('Reader Rui');
        $chat   = $this->get(ChatService::class);
        $acme   = $chat->openChannel($ana, $this->channel(TestChannelProvider::KEY));
        $chat->send($ana, $acme, 'announcement');

        $csrf    = $this->login($reader)['csrf'];
        $summary = $this->getJson('/chat/api/conversations/' . $acme->getUuid())['conversation'];
        self::assertFalse($summary['canSend']);
        self::assertSame('Channel', $summary['subtitle'], 'no subtitle: the default one');
        self::assertSame('#', $summary['initials']);
        self::assertSame(['ok' => false, 'error' => 'read_only'], $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $acme->getUuid() . '/messages', $csrf, 403, ['body' => 'me too']));

        $csrf = $this->login($ana)['csrf'];
        self::assertTrue($this->getJson('/chat/api/conversations/' . $acme->getUuid())['conversation']['canSend']);
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $acme->getUuid() . '/messages', $csrf, 201, ['body' => 'hello']);
    }

    public function testSystemLinesAreServerOnlyText(): void
    {
        $ana      = $this->user('Ana');
        $chat     = $this->get(ChatService::class);
        $acme     = $chat->openChannel($ana, $this->channel(TestChannelProvider::KEY));
        $realtime = $this->get(RecordingRealtime::class);

        $realtime->published = [];
        $line                = $chat->postSystemLine($acme, '%actor% renamed the channel', null, ['actor' => '<script>alert(1)</script>']);
        self::assertTrue($line->isSystem());
        self::assertNull($line->getAuthor());
        self::assertFalse($line->isPinnable());
        self::assertSame(['message'], $realtime->types(), 'the readers get it in realtime');
        $translated = $chat->postSystemLine($acme, 'chat.channel', 'NowoChatKitBundle');

        try {
            $chat->postSystemLine($acme, '   ');
            self::fail('an empty line is refused');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('empty', $exception->getMessage());
        }

        $csrf     = $this->login($ana)['csrf'];
        $messages = $this->getJson('/chat/api/conversations/' . $acme->getUuid() . '/messages')['messages'];
        self::assertSame(['system', 'system'], array_column($messages, 'kind'));
        self::assertNull($messages[0]['authorUuid']);
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt; renamed the channel</p>', $messages[0]['html'], 'escaped, never HTML');
        self::assertSame('<p>Channel</p>', $messages[1]['html'], 'a key is translated for the reader');
        self::assertSame([], $messages[0]['mentions']);
        self::assertSame('Channel', $this->getJson('/chat/api/conversations')['conversations'][0]['lastMessage']['preview']);

        $base = '/chat/api/conversations/' . $acme->getUuid() . '/messages/' . $translated->getUuid();
        $this->send(Request::METHOD_POST, $base . '/reactions', $csrf, 404, ['emoji' => '👍']);
        $this->send(Request::METHOD_PATCH, $base, $csrf, 404, ['body' => 'mine now']);
        $this->send(Request::METHOD_DELETE, $base, $csrf, 404);
    }

    public function testMuteRoute(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $stranger = $this->user('Stranger Sam');
        $chat     = $this->get(ChatService::class);
        $dm       = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'one');
        $chat->send($luis, $dm, 'two');
        $acme = $chat->openChannel($luis, $this->channel(TestChannelProvider::KEY));
        $chat->send($luis, $acme, 'three');

        $config = $this->login($ana);
        $csrf   = $config['csrf'];
        self::assertSame('/chat/api/conversations/__uuid__/mute', $config['endpoints']['mute']);
        $url = '/chat/api/conversations/' . $dm->getUuid() . '/mute';
        self::assertSame(3, $this->getJson('/chat/api/conversations')['unreadTotal']);
        self::assertNull($this->getJson('/chat/api/conversations/' . $dm->getUuid())['conversation']['mutedUntil']);

        $this->client->request(Request::METHOD_POST, $url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'CSRF header required');

        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        self::assertSame(['mutedUntil' => '9999-12-31T23:59:59+00:00'], $this->send(Request::METHOD_POST, $url, $csrf));
        self::assertSame(['list'], $realtime->types(), 'the other tabs reload the list');
        $list = $this->getJson('/chat/api/conversations');
        self::assertSame(1, $list['unreadTotal'], 'a muted conversation leaves the total');
        $row = array_values(array_filter($list['conversations'], static fn (array $row): bool => $row['uuid'] === $dm->getUuid()))[0];
        self::assertSame([2, '9999-12-31T23:59:59+00:00'], [$row['unread'], $row['mutedUntil']], 'and keeps its own count');
        self::assertSame('9999-12-31T23:59:59+00:00', $this->getJson('/chat/api/conversations/' . $dm->getUuid())['conversation']['mutedUntil']);

        $timed = $this->send(Request::METHOD_POST, $url, $csrf, 200, ['minutes' => 60]);
        self::assertGreaterThan(time() + 3500, strtotime((string) $timed['mutedUntil']));
        foreach ([0, 525_601, '60', 1.5] as $minutes) {
            self::assertSame(['ok' => false, 'error' => 'invalid_mute'], $this->send(Request::METHOD_POST, $url, $csrf, 422, ['minutes' => $minutes]));
        }
        self::assertSame(['mutedUntil' => null], $this->send(Request::METHOD_DELETE, $url, $csrf));
        self::assertSame(3, $this->getJson('/chat/api/conversations')['unreadTotal']);
        $participant = $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $dm->getId(), 'user' => $ana->getId()]);
        self::assertInstanceOf(ChatParticipant::class, $participant);
        self::assertNull($participant->getMutedUntil());

        // Only conversations the caller may read.
        $csrf = $this->login($stranger)['csrf'];
        $this->send(Request::METHOD_POST, $url, $csrf, 404);
    }

    public function testGroupMentionsFollowThePolicyAndTheBudget(): void
    {
        $chat     = $this->get(ChatService::class);
        $lead     = $this->user('Lead Lia');
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $stranger = $this->user('Stranger Sam');
        $acme     = $chat->openChannel($lead, $this->channel(TestChannelProvider::KEY));

        $all = $chat->send($lead, $acme, 'Release at 5 @channel');
        self::assertSame(ChatMessage::MENTION_CHANNEL, $all->getMentionAll());
        $this->assertMentioned([$ana, $luis], $all);
        self::assertSame(3, $this->get(TestChannelProvider::class)->lastMemberCount, 'the policy gets the member count');

        // Nobody else may: the words stay text.
        $plain = $chat->send($ana, $acme, 'hey @channel and @here');
        self::assertNull($plain->getMentionAll());
        $this->assertMentioned([], $plain);

        // @here: the members online now.
        $this->get(ChatPresence::class)->heartbeat($luis, true);
        $here = $chat->send($lead, $acme, '@here quick one');
        self::assertSame(ChatMessage::MENTION_HERE, $here->getMentionAll());
        $this->assertMentioned([$luis], $here);

        // Code, e-mail addresses and words are not group mentions; nor is a direct conversation.
        foreach (['`@channel`', 'ops@channel.test', 'x@here', '@channels'] as $body) {
            self::assertNull($chat->send($lead, $acme, $body)->getMentionAll(), $body);
        }
        self::assertNull($chat->send($lead, $chat->openDirect($lead, $ana), '@channel')->getMentionAll());

        // An edit that keeps it spends nothing; one that drops it removes the mention rows.
        $chat->edit($all, 'Release at 6 @channel');
        self::assertSame(ChatMessage::MENTION_CHANNEL, $all->getMentionAll());
        $chat->edit($all, 'Release at 6');
        $this->em->flush();
        self::assertNull($all->getMentionAll());
        $this->assertMentioned([], $all);

        // The budget (6 per hour): two spent above, four more, then plain text.
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame(ChatMessage::MENTION_CHANNEL, $chat->send($lead, $acme, '@channel ' . $i)->getMentionAll());
        }
        $over = $chat->send($lead, $acme, '@channel again');
        self::assertNull($over->getMentionAll());
        $this->assertMentioned([], $over);

        // The readers see it as a mention of them.
        $this->login($ana);
        $messages = $this->getJson('/chat/api/conversations/' . $acme->getUuid() . '/messages')['messages'];
        $byUuid   = array_column($messages, null, 'uuid');
        $grouped  = array_values(array_filter($messages, static fn (array $message): bool => isset($message['mentionAll'])));
        self::assertNotSame([], $grouped);
        self::assertTrue($grouped[0]['mentionsMe']);
        self::assertArrayNotHasKey('mentionAll', $byUuid[$plain->getUuid()], 'the payload only gains the key when set');
        self::assertFalse($byUuid[$plain->getUuid()]['mentionsMe']);
        self::assertSame(0, $this->countMentionsOf($stranger));
    }

    private function channel(string $key): ChatChannel
    {
        $channel = $this->get(TestChannelProvider::class)->find($key);
        self::assertInstanceOf(ChatChannel::class, $channel);

        return $channel;
    }

    /**
     * @param list<TestUser> $expected
     */
    private function assertMentioned(array $expected, ChatMessage $message): void
    {
        $ids = $this->get(ChatMessageMentionRepository::class)->userIdsOf($message);
        sort($ids);
        $want = array_map(static fn (TestUser $user): int => (int) $user->getId(), $expected);
        sort($want);
        self::assertSame($want, $ids);
    }

    private function countMentionsOf(TestUser $user): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM ' . ChatMessageMention::class . ' m WHERE m.user = :user')
            ->setParameter('user', $user)->getSingleScalarResult();
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
            self::assertSame($status, $code, $url . ' ' . $this->client->getResponse()->getContent());
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
