<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use ReflectionClassConstant;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * 1.26: `GET /chat/api/csrf` gives the session's current token to a dock whose token the server refused (stale after a
 * session change). The worker checks (two people in turn, memory over many heartbeats) call the container's
 * `http_kernel` directly (`handle()` + `terminate()`, the session cookie by hand), as a FrankenPHP worker with
 * FRANKENPHP_RESET_KERNEL=false does: no kernel reboot and no `services_resetter` between requests (KernelBrowser,
 * used only to sign in, would run the resetter before each request). The token and the user always come from the
 * current request's session, nothing leaks from one person to the next, and the heartbeat path keeps a flat memory.
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
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertFalse($this->client->getResponse()->headers->has('Access-Control-Allow-Origin'));
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

    public function testNothingLeaksFromOnePersonToTheNextWithoutTheServicesResetter(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        // Two browsers (two sessions); from here on the same kernel serves them in turn, never reset.
        $people = [
            'ana'  => ['uuid' => $ana->getChatPublicId()] + $this->signIn($ana),
            'luis' => ['uuid' => $luis->getChatPublicId()] + $this->signIn($luis),
        ];

        for ($round = 0; $round < 50; ++$round) {
            foreach (['ana' => 'luis', 'luis' => 'ana'] as $me => $other) {
                $cookies = $people[$me]['cookies'];
                $answer  = $this->decodeResponse($this->raw(Request::METHOD_GET, '/chat/api/csrf', $cookies));
                self::assertSame($people[$me]['uuid'], $answer['userUuid'], 'the caller\'s own id, never the previous person\'s');
                self::assertIsString($answer['csrf']);
                self::assertSame(200, $this->raw(Request::METHOD_POST, '/chat/api/presence', $cookies, $answer['csrf'], '{"visible":true}')->getStatusCode());
                self::assertSame(200, $this->raw(Request::METHOD_POST, '/chat/api/presence', $cookies, $people[$me]['csrf'], '{"visible":true}')->getStatusCode());
                // The other person's token is worthless in this session.
                $refused = $this->raw(Request::METHOD_POST, '/chat/api/presence', $cookies, $people[$other]['csrf'], '{"visible":true}');
                self::assertSame(403, $refused->getStatusCode());
                self::assertSame(['ok' => false, 'error' => 'invalid_csrf'], $this->decodeResponse($refused));
            }
        }
    }

    public function testTheHeartbeatKeepsAFlatMemoryWithoutTheServicesResetter(): void
    {
        $this->user('Luis');
        $ana      = $this->signIn($this->user('Ana'));
        $realtime = $this->get(RecordingRealtime::class);
        $queries  = $this->get(QueryCounter::class);
        $clean    = static function () use ($realtime, $queries): void {
            // The test doubles' own records are not the kernel's memory.
            $realtime->published = [];
            $queries->clear();
        };
        // One heartbeat of the dock: presence, then a token refresh.
        $beat = function () use ($ana, $clean): void {
            self::assertSame(200, $this->raw(Request::METHOD_POST, '/chat/api/presence', $ana['cookies'], $ana['csrf'], '{"visible":true}')->getStatusCode());
            self::assertSame(200, $this->raw(Request::METHOD_GET, '/chat/api/csrf', $ana['cookies'])->getStatusCode());
            $clean();
        };
        // Memory after 100 warm-up steps, then after $first and $first + $more steps.
        $measure = static function (callable $step, int $first, int $more): array {
            for ($i = 0; $i < 100; ++$i) {
                $step();
            }
            gc_collect_cycles();
            $start = memory_get_usage();
            for ($i = 0; $i < $first; ++$i) {
                $step();
            }
            gc_collect_cycles();
            $middle = memory_get_usage();
            for ($i = 0; $i < $more; ++$i) {
                $step();
            }
            gc_collect_cycles();

            return [$middle - $start, memory_get_usage() - $middle];
        };

        // Read at run time: the installed Symfony decides (a static read is folded by PHPStan for the locked version).
        if ((new ReflectionClassConstant(Kernel::class, 'EXTRA_VERSION'))->getValue() === '') {
            // Stable Symfony (7.4, 8.1…): the framework keeps nothing per request, so the bound is absolute.
            [$grown] = $measure($beat, 1_000, 0);
            self::assertLessThan(64 * 1024, $grown, sprintf('1,000 heartbeats grew the memory by %d bytes', $grown));

            return;
        }

        // Symfony pre-releases (8.2.0-BETA1): the main firewall's kernel.request listener itself keeps about 0.7-1.5 KB
        // per request, anonymous requests too (not ChatKit: a host route with no bundle code grows the same). Compare
        // slopes (growth per request between 500 and 1,500 steps) with the same number of host requests behind the
        // same firewall.
        $baseline = function () use ($ana, $clean): void {
            for ($i = 0; $i < 2; ++$i) {
                self::assertSame(200, $this->raw(Request::METHOD_GET, '/signed-in', $ana['cookies'])->getStatusCode());
            }
            $clean();
        };
        [, $framework] = $measure($baseline, 500, 1_000);
        [, $bundle]    = $measure($beat, 500, 1_000);
        $extra         = ($bundle - $framework) / 1_000;

        self::assertLessThan(512, $extra, sprintf('the heartbeat keeps %.0f bytes more per beat than the framework alone (framework: %d, heartbeat: %d over 1,000)', $extra, $framework, $bundle));
    }

    /**
     * Signs a person in with the test browser (a new session) and loads a page with the dock.
     *
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function signIn(TestUser $user): array
    {
        $this->client->getCookieJar()->clear();
        $csrf = $this->login($user)['csrf'];
        self::assertIsString($csrf);
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        self::assertNotSame([], $cookies);

        return ['cookies' => $cookies, 'csrf' => $csrf];
    }

    /**
     * One request straight through the container's `http_kernel`: no kernel boot check, no services resetter.
     *
     * @param array<string, string> $cookies
     */
    private function raw(string $method, string $uri, array $cookies, ?string $csrf = null, ?string $body = null): Response
    {
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $server   = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($csrf === null ? [] : ['HTTP_X_CSRF_TOKEN' => $csrf]);
        $request  = Request::create($uri, $method, [], $cookies, [], $server, $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
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
