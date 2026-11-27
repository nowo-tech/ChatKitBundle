<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Nowo\ChatKitBundle\Event\ChatClientApiDeniedEvent;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestClientContextPolicy;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestClientPrincipalResolver;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.4: client API requests of the functional tests, straight through the container's `http_kernel` (one kernel, no
 * services resetter: FrankenPHP worker mode, kernel reset false), with a Bearer token and no cookie.
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait ClientApiCalls
{
    /** @var list<ChatClientApiDeniedEvent> */
    protected array $denied = [];

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected static function clientApiConfig(array $extra = []): array
    {
        return ['profiles' => ['default' => array_replace_recursive([
            'client_api' => ['principal_resolver' => TestClientPrincipalResolver::class, 'context_policy' => TestClientContextPolicy::class, 'context_header' => 'X-Beacon-Context'],
            'limits'     => ['reads_per_minute' => 100_000, 'actions_per_minute' => 100_000, 'send_per_minute' => 100_000],
            // Audit ZBW-L2: a context policy needs ids-only hub events.
            'realtime_events' => 'ids',
        ], $extra)]];
    }

    protected function listenToDenials(): void
    {
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(ChatClientApiDeniedEvent::class, function (ChatClientApiDeniedEvent $event): void {
            $this->denied[] = $event;
        });
    }

    protected function tokens(): TestClientPrincipalResolver
    {
        return $this->get(TestClientPrincipalResolver::class);
    }

    protected function contexts(): TestClientContextPolicy
    {
        return $this->get(TestClientContextPolicy::class);
    }

    /**
     * @param array<string, string> $headers HTTP headers (`Authorization`, `X-Beacon-Context` …)
     */
    protected function call(string $method, string $uri, ?string $token = null, ?string $context = 'personal', ?string $body = null, array $headers = [], string $ip = '203.0.113.9'): Response
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        if ($context !== null) {
            $server['HTTP_X_BEACON_CONTEXT'] = $context;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($uri, $method, [], [], [], $server, $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function ok(string $method, string $uri, string $token, ?string $context = 'personal', ?string $body = null): array
    {
        $response = $this->call($method, $uri, $token, $context, $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        return self::decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decode(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, string> $extra More fields of the body (`message`, `module` of a `module_forbidden`)
     */
    protected static function assertError(Response $response, int $status, string $error, array $extra = []): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['ok' => false, 'error' => $error] + $extra, self::decode($response));
    }
}
