<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatRealtimeInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Port\ChatChannelSourceGate;
use Nowo\ChatKitBundle\Port\ChatParticipantResolverGate;
use Nowo\ChatKitBundle\Port\ChatRealtimeTransportGate;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Nowo\ChatKitBundle\Tests\Support\TwinPorts\TwinRealtime;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function in_array;
use function is_string;

use const JSON_THROW_ON_ERROR;

/**
 * 1.27 (REQ-ARCH-001 rules 1 and 3): the same host answers given through the framework-free twins
 * (tests/Support/TwinPorts: ChatChannelSourceInterface with its companions, ChatRealtimeTransportInterface,
 * ChatParticipantResolverInterface) produce exactly what the 1.26 ports produce: the dock configuration (page channel
 * included), the realtime endpoint, channel access and writes, the write and @channel policies, the voter, and the
 * guests and strangers refused. Each scenario runs once on a kernel with the 1.26 ports and once on a kernel with the
 * twins only; the observations are compared.
 */
final class ChatTwinPortsEquivalenceTest extends TestCase
{
    private string $lastUuid = '';

    public function testTheTwinsAnswerLikeTheDeprecatedPorts(): void
    {
        $legacy = $this->scenario(false);
        $twin   = $this->scenario(true);

        self::assertSame($legacy, $twin);
        self::assertSame(['key' => TestChannelProvider::KEY, 'label' => 'Acme'], $legacy['acmeConfig']['contextChannel']);
        self::assertNotSame($legacy['readerWrite'][0], $legacy['leadWrite'][0], 'the write policy tells the reader from the lead');
    }

    public function testTheTwinKernelAsksThroughTheGates(): void
    {
        $kernel = new TestKernel([], false, false, false, true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            assert($container instanceof ContainerInterface);
            self::assertInstanceOf(ChatChannelSourceGate::class, $container->get(ChatChannelProviderInterface::class));
            self::assertInstanceOf(ChatRealtimeTransportGate::class, $container->get(ChatRealtimeInterface::class));
            self::assertInstanceOf(ChatParticipantResolverGate::class, $container->get(CurrentChatUserResolverInterface::class));
        } finally {
            $kernel->shutdown();
        }
        if (TestKernel::twinPorts()) {
            return;
        }
        $legacy = new TestKernel();
        $legacy->boot();
        try {
            $container = $legacy->getContainer()->get('test.service_container');
            assert($container instanceof ContainerInterface);
            self::assertInstanceOf(TestChannelProvider::class, $container->get(ChatChannelProviderInterface::class));
            self::assertInstanceOf(RecordingRealtime::class, $container->get(ChatRealtimeInterface::class));
            self::assertInstanceOf(SecurityChatUserResolver::class, $container->get(CurrentChatUserResolverInterface::class));
        } finally {
            $legacy->shutdown();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(bool $twin): array
    {
        $kernel = new TestKernel([], false, false, false, $twin);
        $kernel->boot();
        try {
            $em = $kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
            assert($em instanceof EntityManagerInterface);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $cache = $kernel->getContainer()->get('cache.app');
            assert($cache instanceof CacheItemPoolInterface);
            $cache->clear();
            $client = $kernel->getContainer()->get('test.client');
            assert($client instanceof KernelBrowser);
            $client->disableReboot();

            $users = [];
            foreach (['Lead Ana', 'Reader Luis', 'Stranger Eve'] as $name) {
                $users[$name] = new TestUser($name, strtolower(explode(' ', $name)[1]) . '@example.test');
                $em->persist($users[$name]);
            }
            $em->flush();

            $out = [];
            $client->request(Request::METHOD_GET, '/page');
            $out['guestDock'] = str_contains((string) $client->getResponse()->getContent(), 'data-nowo-chat-config');
            $client->request(Request::METHOD_GET, '/chat/api/conversations');
            $out['guestApi'] = $client->getResponse()->getStatusCode();

            $client->loginUser($users['Stranger Eve']);
            $out['strangerAcme'] = $this->normalize($this->config($client, '/projects/acme/page'), $users);

            $client->loginUser($users['Reader Luis']);
            $readerCsrf         = (string) $this->config($client, '/page')['csrf'];
            $out['readerOpen']  = $this->call($client, Request::METHOD_POST, '/chat/api/channel', $readerCsrf, ['channelKey' => TestChannelProvider::KEY], $users);
            $channelUuid        = $this->lastUuid;
            $out['readerWrite'] = $this->call($client, Request::METHOD_POST, '/chat/api/conversations/' . $channelUuid . '/messages', $readerCsrf, ['body' => 'hello'], $users);

            $client->loginUser($users['Lead Ana']);
            $config            = $this->config($client, '/projects/acme/page');
            $csrf              = (string) $config['csrf'];
            $out['acmeConfig'] = $this->normalize($config, $users);
            $out['pageConfig'] = $this->normalize($this->config($client, '/page'), $users)['contextChannel'];
            $out['realtime']   = $this->call($client, Request::METHOD_GET, '/chat/api/realtime', $csrf, null, $users);
            $out['leadWrite']  = $this->call($client, Request::METHOD_POST, '/chat/api/conversations/' . $channelUuid . '/messages', $csrf, ['body' => '@channel ship it'], $users);
            $out['archive']    = $this->call($client, Request::METHOD_POST, '/chat/api/channel', $csrf, ['channelKey' => 'room:archive'], $users)[0];
            $out['unknown']    = $this->call($client, Request::METHOD_POST, '/chat/api/channel', $csrf, ['channelKey' => 'project:ACME'], $users)[0];
            $out['list']       = array_map(static fn (array $c): array => [$c['kind'], $c['title'], $c['unread']], $this->call($client, Request::METHOD_GET, '/chat/api/conversations', $csrf, null, $users)[1]['conversations']);

            if ($twin) {
                $container = $kernel->getContainer()->get('test.service_container');
                assert($container instanceof ContainerInterface);
                $realtime = $container->get(TwinRealtime::class);
                assert($realtime instanceof TwinRealtime);
                self::assertSame('/chat/api/realtime', $realtime->lastRequest?->path, 'the twin got the request as a value');
            }

            return $out;
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function config(KernelBrowser $client, string $page): array
    {
        $crawler = $client->request(Request::METHOD_GET, $page);
        if ($crawler->filter('[data-nowo-chat-config]')->count() === 0) {
            return [];
        }

        /* @var array<string, mixed> */
        return json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, TestUser> $users
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(KernelBrowser $client, string $method, string $url, string $csrf, ?array $body, array $users): array
    {
        $client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $content = (string) $client->getResponse()->getContent();
        /** @var array<string, mixed> $json */
        $json = json_validate($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : ['body' => 'not json'];
        $uuid = $json['conversation']['uuid'] ?? null;
        if (is_string($uuid)) {
            $this->lastUuid = $uuid;
        }

        return [$client->getResponse()->getStatusCode(), $this->normalize($json, $users)];
    }

    /**
     * Replaces the values that differ between two kernels by construction (uuids, ids, tokens, times).
     *
     * @param array<array-key, mixed> $value
     * @param array<string, TestUser> $users
     *
     * @return array<array-key, mixed>
     */
    private function normalize(array $value, array $users): array
    {
        $names = [];
        foreach ($users as $name => $user) {
            $names[$user->getChatPublicId()] = 'user:' . $name;
        }
        array_walk_recursive($value, static function (mixed &$item, int|string $key) use ($names): void {
            if (is_string($item) && isset($names[$item])) {
                $item = $names[$item];
            } elseif (in_array($key, ['csrf', 'uuid', 'id', 'token', 'expiresAt', 'createdAt', 'lastMessageAt', 'editedAt', 'lastMessageId', 'conversationUuid', 'topic', 'at', 'time'], true) || (is_string($item) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/', $item) === 1)) {
                $item = '*';
            }
        });

        return $value;
    }
}
