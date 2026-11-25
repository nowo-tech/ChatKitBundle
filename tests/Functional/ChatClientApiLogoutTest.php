<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Event\ChatClientLoggedOutEvent;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestClientTokenRevoker;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function array_column;
use function array_map;
use function assert;
use function gc_collect_cycles;
use function hash;
use function memory_get_usage;

/**
 * 2.4.1 (client API v1, Beacon Desktop): `POST <prefix>/chat/v1/logout` revokes the presented token through the host's
 * revoker and only it, answers 204 with no body for every token the resolver does not accept (already revoked,
 * expired, unknown: never an oracle), lets a token the person may no longer use for reading still sign out, refuses
 * other methods, and dispatches ChatClientLoggedOutEvent (ids only) after the revocation.
 */
final class ChatClientApiLogoutTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string LOGOUT = '/api/chat/v1/logout';

    /** Rounds of the worker-mode loop (two people sign in and out in turn on one kernel). */
    private const int ROUNDS = 300;

    /** @var list<ChatClientLoggedOutEvent> */
    private array $loggedOut = [];

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig(['client_api' => [
            'token_revoker' => TestClientTokenRevoker::class,
            'limits'        => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000],
        ]]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->listenToDenials();
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(ChatClientLoggedOutEvent::class, function (ChatClientLoggedOutEvent $event): void {
            $this->loggedOut[] = $event;
        });
    }

    public function testLogoutRevokesThePresentedTokenOnly(): void
    {
        $ana = $this->user('Ana');
        $this->tokens()->grant('ana-desktop', $ana, id: 'tok-desktop');
        $this->tokens()->grant('ana-laptop', $ana, id: 'tok-laptop');
        self::assertTrue($this->ok('GET', '/api/chat/v1/me', 'ana-desktop')['features']['serverLogout']);

        $response = $this->call('POST', self::LOGOUT, 'ana-desktop', 'personal', null, [], '198.51.100.20');
        self::assertNoContent($response);

        // The token is refused on the very next request; the person's other device keeps working.
        self::assertError($this->call('GET', '/api/chat/v1/me', 'ana-desktop'), 401, 'invalid_token');
        self::assertSame(200, $this->call('GET', '/api/chat/v1/me', 'ana-laptop')->getStatusCode());
        $revoker = $this->get(TestClientTokenRevoker::class);
        self::assertSame(['ana-desktop'], array_column($revoker->calls, 'bearer'));
        self::assertSame('198.51.100.20', $revoker->calls[0]['request']->clientIp);
        self::assertSame('personal', $revoker->calls[0]['request']->requestedContext);

        self::assertCount(1, $this->loggedOut);
        self::assertSame('tok-desktop', $this->loggedOut[0]->tokenId);
        self::assertSame($ana->getChatPublicId(), $this->loggedOut[0]->userPublicId);
        self::assertTrue($this->loggedOut[0]->revoked);
        self::assertSame('198.51.100.0/24', $this->loggedOut[0]->ipPrefix);
    }

    public function testReadTrafficNeverStarvesTheRevocation(): void
    {
        // Audit ZCB-L1: with the token's read budget spent, logout still answers 204 and revokes.
        $this->tokens()->grant('busy', $this->user('Ana'), id: 'tok-busy-revoke');
        $services = $this->kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $limiter = $services->get('limiter.nowo_chat_client_token');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $limiter->create('nowo_chat_client_token_' . hash('sha256', 'tok-busy-revoke'))->consume(100_000);
        self::assertError($this->call('GET', '/api/chat/v1/me', 'busy'), 429, 'rate_limited');

        self::assertNoContent($this->call('POST', self::LOGOUT, 'busy'));
        self::assertSame(['busy'], array_column($this->get(TestClientTokenRevoker::class)->calls, 'bearer'));
        self::assertTrue($this->loggedOut[0]->revoked);
        self::assertArrayNotHasKey('busy', $this->tokens()->tokens);
    }

    public function testEveryOtherTokenGetsTheSame204(): void
    {
        $ana = $this->user('Ana');
        $this->tokens()->grant('ana-token', $ana);
        self::assertNoContent($this->call('POST', self::LOGOUT, 'ana-token'));
        $revoker = $this->get(TestClientTokenRevoker::class);
        $denied  = $this->denied;

        // Already revoked (a retry, a second device), unknown or expired (the resolver answers null for both), a token
        // of another family the resolver refuses: the same empty 204, nothing revoked, no event, no refusal event.
        $first = $this->call('POST', self::LOGOUT, 'ana-token');
        self::assertNoContent($first);
        foreach (['ana-token', 'never-issued', 'expired-token', 'time-family-token'] as $token) {
            $response = $this->call('POST', self::LOGOUT, $token);
            self::assertNoContent($response);
            self::assertSame($first->headers->all('cache-control'), $response->headers->all('cache-control'));
        }
        self::assertCount(1, $revoker->calls);
        self::assertCount(1, $this->loggedOut);
        self::assertSame($denied, $this->denied);
    }

    public function testAPersonWhoMayNotReadMayStillSignOut(): void
    {
        $ana = $this->user('Ana');
        $bea = $this->user('Bea');
        $this->tokens()->grant('write-only', $ana, [ChatClientPrincipal::SCOPE_WRITE], null, 'tok-w');
        $this->tokens()->grant('bound', $bea, [ChatClientPrincipal::SCOPE_READ], 'org:acme', 'tok-bound');
        $this->tokens()->grant('inactive', $this->user('Luis'), id: 'tok-inactive');
        $this->get(TestAccessPolicy::class)->unreachable[$this->tokensUser('inactive')] = true;

        // No chat:read, a bound context whose membership is gone (and a context header that is not even valid), an
        // account that cannot use the chat: every one of them gives up its own token.
        self::assertNoContent($this->call('POST', self::LOGOUT, 'write-only'));
        self::assertNoContent($this->call('POST', self::LOGOUT, 'bound', 'not a context'));
        self::assertNoContent($this->call('POST', self::LOGOUT, 'inactive', null));
        self::assertSame(['tok-w', 'tok-bound', 'tok-inactive'], array_map(static fn (ChatClientLoggedOutEvent $event): string => $event->tokenId, $this->loggedOut));
        self::assertSame([], $this->tokens()->tokens);
        self::assertSame([], $this->denied);
    }

    public function testOnlyPostWithABearerToken(): void
    {
        $this->tokens()->grant('ana-token', $this->user('Ana'));
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->call($method, self::LOGOUT, 'ana-token')->getStatusCode(), $method);
        }
        // Without a Bearer token there is no token to sign out: 401 as on every route.
        $missing = $this->call('POST', self::LOGOUT);
        self::assertError($missing, 401, 'invalid_token');
        self::assertSame('Bearer', $missing->headers->get('WWW-Authenticate'));
        self::assertError($this->call('POST', self::LOGOUT, null, 'personal', null, ['Authorization' => 'Basic YW5hOng=']), 401, 'invalid_token');
        self::assertSame(['invalid_token', 'invalid_token'], array_map(static fn ($event): string => $event->reason, $this->denied));
        self::assertSame([], $this->get(TestClientTokenRevoker::class)->calls);
        self::assertSame(200, $this->call('GET', '/api/chat/v1/me', 'ana-token')->getStatusCode());
    }

    public function testAFailedRevocationIsNeverReportedAsASignOut(): void
    {
        $this->tokens()->grant('ana-token', $this->user('Ana'));
        $revoker           = $this->get(TestClientTokenRevoker::class);
        $revoker->failNext = true;

        self::assertSame(500, $this->call('POST', self::LOGOUT, 'ana-token')->getStatusCode());
        self::assertSame([], $this->loggedOut);
        self::assertSame(200, $this->call('GET', '/api/chat/v1/me', 'ana-token')->getStatusCode());
        // The client retries.
        self::assertNoContent($this->call('POST', self::LOGOUT, 'ana-token'));
        self::assertCount(1, $this->loggedOut);
    }

    public function testSignInAndOutOnOneKernel(): void
    {
        // FrankenPHP worker mode (kernel reset false): nothing of one logout reaches the next request.
        $ana      = $this->user('Ana');
        $bea      = $this->user('Bea');
        $revoker  = $this->get(TestClientTokenRevoker::class);
        $baseline = 0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $this->tokens()->grant('ana-' . $round, $ana, id: 'ana-' . $round);
            $this->tokens()->grant('bea-' . $round, $bea, id: 'bea-' . $round);
            self::assertNoContent($this->call('POST', self::LOGOUT, 'ana-' . $round));
            self::assertSame(401, $this->call('GET', '/api/chat/v1/me', 'ana-' . $round)->getStatusCode(), 'round ' . $round);
            self::assertSame(200, $this->call('GET', '/api/chat/v1/me', 'bea-' . $round)->getStatusCode(), 'round ' . $round . ': Bea is not signed out');
            self::assertNoContent($this->call('POST', self::LOGOUT, 'bea-' . $round));
            self::assertSame(['ana-' . $round, 'bea-' . $round], array_map(static fn (ChatClientLoggedOutEvent $event): string => $event->tokenId, $this->loggedOut), 'round ' . $round);
            self::assertSame([$ana->getChatPublicId(), $bea->getChatPublicId()], array_map(static fn (ChatClientLoggedOutEvent $event): string => $event->userPublicId, $this->loggedOut));
            self::assertSame([], $this->tokens()->tokens);
            // The test doubles' own records are not the kernel's state.
            $this->loggedOut          = [];
            $revoker->calls           = [];
            $this->tokens()->requests = [];
            $this->denied             = [];
            if ($round === 30) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + 4 * 1024 * 1024, memory_get_usage(), 'memory stays flat over the rounds on one kernel');
    }

    private static function assertNoContent(Response $response): void
    {
        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', (string) $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    private function tokensUser(string $token): string
    {
        return $this->tokens()->tokens[$token]['user'];
    }
}
