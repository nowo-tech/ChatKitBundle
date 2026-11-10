<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function gc_collect_cycles;
use function memory_get_usage;

use const JSON_THROW_ON_ERROR;

/**
 * 1.26: `GET /chat/api/csrf` gives the session's current token to a dock whose token the server refused (stale after a
 * session change). The kernel is never rebooted between requests, as in a FrankenPHP worker without kernel reset: the
 * token and the user always come from the current request's session, nothing leaks from one person to the next, and
 * the heartbeat path keeps a flat memory over many requests.
 */
final class ChatCsrfRefreshTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['limits' => ['actions_per_minute' => 10_000, 'reads_per_minute' => 10_000]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testGuestsGetNoToken(): void
    {
        $this->client->request(Request::METHOD_GET, '/chat/api/csrf');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDockConfigNamesTheEndpoint(): void
    {
        self::assertSame('/chat/api/csrf', $this->login($this->user('Ana'))['endpoints']['csrf']);
    }

    public function testARefusedTokenIsReplacedByTheSessionsCurrentOne(): void
    {
        $ana    = $this->user('Ana');
        $config = $this->login($ana);

        // The session's token (masked differently on every call, BREACH), for the signed-in person, never cached.
        $answer = $this->getJson('/chat/api/csrf');
        self::assertSame($ana->getChatPublicId(), $answer['userUuid']);
        self::assertIsString($answer['csrf']);
        self::assertNotSame('', $answer['csrf']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $this->presence($config['csrf']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the page\'s token is still valid');
        $this->presence($answer['csrf']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'the refreshed one too: the same session token');

        // A stale token (the page's, after the session lost it) is refused; the refreshed one is accepted.
        $this->presence('stale-token');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'invalid_csrf'], $this->decode());
        $this->presence($this->getJson('/chat/api/csrf')['csrf']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testNothingLeaksFromOnePersonToTheNextInTheSameKernel(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');

        $this->login($ana);
        $forAna = $this->getJson('/chat/api/csrf');
        self::assertSame($ana->getChatPublicId(), $forAna['userUuid']);

        // Another person signs in in the same browser (a new session), the same kernel serves the request.
        $this->client->getCookieJar()->clear();
        $this->login($luis);
        $forLuis = $this->getJson('/chat/api/csrf');
        self::assertSame($luis->getChatPublicId(), $forLuis['userUuid'], 'the dock sees another person signed in and asks for a reload');

        // Ana's token is worthless in Luis's session, Luis's own works.
        $this->presence($forAna['csrf']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->presence($forLuis['csrf']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheHeartbeatKeepsAFlatMemoryOverManyRequests(): void
    {
        $this->user('Luis');
        $csrf     = $this->login($this->user('Ana'))['csrf'];
        $realtime = $this->get(RecordingRealtime::class);
        $queries  = $this->get(QueryCounter::class);
        $beat     = function () use ($csrf, $realtime, $queries): void {
            $this->presence($csrf);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->client->request(Request::METHOD_GET, '/chat/api/csrf');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            // The test doubles' own records and the test browser's history are not the kernel's memory.
            $realtime->published = [];
            $queries->clear();
            $this->client->getHistory()->clear();
        };
        $run = static function (int $times) use ($beat): int {
            for ($i = 0; $i < $times; ++$i) {
                $beat();
            }
            gc_collect_cycles();

            return memory_get_usage();
        };
        $run(100);
        $first  = $run(500);
        $second = $run(500);
        $third  = $run(500);

        // A leak grows with every request; warm caches do not. 500 more heartbeats, twice: under 256 KiB each time.
        self::assertLessThan(256 * 1024, $second - $first, 'heartbeats 600-1,100 must not grow the memory');
        self::assertLessThan(256 * 1024, $third - $second, 'heartbeats 1,100-1,600 must not grow the memory');
    }

    /**
     * Logs in and loads a page with the dock; returns its config.
     *
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

    private function presence(string $csrf): void
    {
        $this->client->request(Request::METHOD_POST, '/chat/api/presence', server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: '{"visible":true}');
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
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
