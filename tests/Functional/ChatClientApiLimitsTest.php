<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Event\ChatClientLoggedOutEvent;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestClientPrincipalResolver;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function count;
use function in_array;
use function json_encode;

/**
 * 2.4 (client API v1): the budgets (per IP, per token, and the person's own budget shared with the web UI) answer 429
 * with Retry-After; a host without a context policy (the token's own context only, the API mounted at the root); and
 * `realtime_events: ids` (Q-BD-13): no message body reaches the hub.
 */
final class ChatClientApiLimitsTest extends DatabaseTestCase
{
    use ClientApiCalls;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'realtime_events' => 'ids',
            'client_api'      => [
                'principal_resolver' => TestClientPrincipalResolver::class,
                'prefix'             => '',
                'limits'             => ['per_ip_per_minute' => 4, 'per_token_per_minute' => 2],
            ],
            'limits' => ['reads_per_minute' => 3, 'actions_per_minute' => 100],
        ]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->listenToDenials();
    }

    public function testThePerIpBudgetComesBeforeTheToken(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->tokens()->grant('t-' . $name, $this->user('User ' . $name));
            self::assertSame(200, $this->call('GET', '/chat/v1/me', 't-' . $name, null, null, [], '198.51.100.7')->getStatusCode());
        }
        $limited = $this->call('GET', '/chat/v1/me', 'unknown', null, null, [], '198.51.100.7');
        self::assertError($limited, 429, 'rate_limited');
        self::assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        self::assertSame('rate_limited', $this->denied[0]->reason);
        self::assertNull($this->denied[0]->tokenId);
        self::assertSame('198.51.100.0/24', $this->denied[0]->ipPrefix);

        // IPv6: one budget per /64.
        foreach (['2001:db8::1', '2001:db8::2', '2001:db8::3', '2001:db8::4'] as $ip) {
            $this->call('GET', '/chat/v1/me', 'unknown', null, null, [], $ip);
        }
        self::assertError($this->call('GET', '/chat/v1/me', 'unknown', null, null, [], '2001:db8::ffff'), 429, 'rate_limited');
        self::assertSame('2001:db8::/64', $this->denied[count($this->denied) - 1]->ipPrefix);
    }

    public function testThePerTokenAndThePersonsOwnBudgets(): void
    {
        $ana = $this->user('Ana');
        $this->tokens()->grant('one', $ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-one');
        $this->tokens()->grant('two', $ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-two');

        self::assertSame(200, $this->call('GET', '/chat/v1/me', 'one', null, null, [], '192.0.2.1')->getStatusCode());
        self::assertSame(200, $this->call('GET', '/chat/v1/me', 'one', null, null, [], '192.0.2.2')->getStatusCode());
        self::assertError($this->call('GET', '/chat/v1/me', 'one', null, null, [], '192.0.2.3'), 429, 'rate_limited');
        self::assertSame('tok-one', $this->denied[0]->tokenId);
        self::assertSame($ana->getChatPublicId(), $this->denied[0]->userPublicId);

        // The second token has its own budget, but Ana's reads (3 a minute, shared with the web UI) are spent after one more.
        self::assertSame(200, $this->call('GET', '/chat/v1/me', 'two', null, null, [], '192.0.2.4')->getStatusCode());
        self::assertError($this->call('GET', '/chat/v1/me', 'two', null, null, [], '192.0.2.5'), 429, 'rate_limited');
        self::assertSame('tok-two', $this->denied[1]->tokenId);
    }

    public function testWithoutAContextPolicyNoContextIsServed(): void
    {
        $ana = $this->user('Ana');
        $this->tokens()->grant('plain', $ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-plain');
        $this->tokens()->grant('bound', $this->user('Bo'), [ChatClientPrincipal::SCOPE_READ], 'org:acme', 'tok-bound');

        $me = $this->ok('GET', '/chat/v1/me', 'plain', null);
        self::assertNull($me['context']);
        self::assertSame([], $me['contexts']);
        self::assertSame('ids', $me['features']['realtimeEvents']);
        // A context the server cannot check is refused (fail closed).
        self::assertError($this->call('GET', '/chat/v1/me', 'plain', 'personal', null, ['X-Chat-Context' => 'personal']), 403, 'context_forbidden');
        // Audit ZBW-L1: a token bound to a context is refused when no policy can filter by it (fail closed), with or
        // without the header.
        self::assertError($this->call('GET', '/chat/v1/me', 'bound', null), 403, 'context_forbidden');
        self::assertError($this->call('GET', '/chat/v1/conversations', 'bound', null, null, ['X-Chat-Context' => 'org:acme'], '192.0.2.99'), 403, 'context_forbidden');
        self::assertSame(['context_forbidden', 'context_unverifiable', 'context_unverifiable'], array_map(static fn ($event): string => $event->reason, $this->denied));
        self::assertSame('tok-bound', $this->denied[1]->tokenId);
    }

    public function testIdsOnlyEventsCarryNoMessageBody(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->tokens()->grant('luis', $luis);
        $service      = $this->get(ChatService::class);
        $conversation = $service->openDirect($ana, $luis);
        $message      = $service->send($ana, $conversation, 'Secret body #[Deploy the API](task:1)');
        $service->edit($message, 'Edited body');
        $service->markRead($luis, $conversation, (int) $message->getId());
        $notifier = $this->get(ChatNotifier::class);
        $notifier->typing($conversation, $ana);
        $notifier->statusChanged($ana, ['status' => 'away', 'text' => 'At lunch', 'emoji' => '🍕'], ['status' => 'away', 'text' => 'At lunch', 'emoji' => '🍕', 'expiresAt' => null], [$luis]);
        // Audit ZBW-L4: in ids mode no name or status text reaches the hub either.
        $byType = [];
        foreach ($this->get(RecordingRealtime::class)->published as $event) {
            $byType[$event['payload']['type']] = $event['payload'];
        }
        self::assertSame(['type', 'conversationUuid', 'userUuid', 'messageId'], array_keys($byType['read']));
        self::assertSame(['type', 'conversationUuid', 'userUuid'], array_keys($byType['typing']));
        self::assertSame(['type' => 'status', 'userUuid' => $ana->getChatPublicId(), 'status' => 'away'], $byType['status']);

        $events = array_values(array_filter($this->get(RecordingRealtime::class)->published, static fn (array $event): bool => in_array($event['payload']['type'], ['message', 'message_updated'], true)));
        self::assertSame(['message', 'message_updated'], array_map(static fn (array $event): string => $event['payload']['type'], $events));
        foreach ($events as $event) {
            self::assertSame(['type', 'conversationUuid', 'messageUuid', 'messageId', 'deleted'], array_keys($event['payload']));
            self::assertSame($message->getUuid(), $event['payload']['messageUuid']);
            self::assertStringNotContainsString('body', (string) json_encode($event['payload']));
        }
        // (Another network than the other tests': the budgets live in the shared rate-limiter pool.)
        $realtime = $this->call('GET', '/chat/v1/realtime', 'luis', null, null, [], '192.0.2.77');
        self::assertSame('ids', self::decode($realtime)['events']);
        // The client re-fetches the message through the API.
        $page = $this->call('GET', '/chat/v1/conversations/' . $conversation->getUuid() . '/messages', 'luis', null, null, [], '192.0.2.77');
        self::assertSame([$message->getUuid()], array_column(self::decode($page)['messages'], 'uuid'));
    }

    public function testWithoutARevokerLogoutOnlyTellsTheClientToForgetItsToken(): void
    {
        $ana        = $this->user('Ana');
        $events     = [];
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(ChatClientLoggedOutEvent::class, static function (ChatClientLoggedOutEvent $event) use (&$events): void {
            $events[] = $event;
        });
        $this->tokens()->grant('kept', $ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-kept-logout');
        self::assertFalse(self::decode($this->call('GET', '/chat/v1/me', 'kept', null, null, [], '192.0.2.120'))['features']['serverLogout']);

        // 2.4.1: still 204 (the client forgets the token), but the token stays valid until it expires or is revoked in
        // the host's UI; the event says nothing was revoked.
        $logout = $this->call('POST', '/chat/v1/logout', 'kept', null, null, [], '192.0.2.121');
        self::assertSame(204, $logout->getStatusCode());
        self::assertSame('', (string) $logout->getContent());
        self::assertCount(1, $events);
        self::assertFalse($events[0]->revoked);
        self::assertSame('tok-kept-logout', $events[0]->tokenId);
        // The token stays valid without a revoker.
        self::assertSame(200, $this->call('GET', '/chat/v1/me', 'kept', null, null, [], '192.0.2.122')->getStatusCode());
        // A per-token logout budget (2 a minute, its own key: audit ZCB-L1) applies: the third logout is refused.
        self::assertSame(204, $this->call('POST', '/chat/v1/logout', 'kept', null, null, [], '192.0.2.123')->getStatusCode());
        self::assertError($this->call('POST', '/chat/v1/logout', 'kept', null, null, [], '192.0.2.124'), 429, 'rate_limited');
        self::assertSame('tok-kept-logout', $this->denied[0]->tokenId);
        self::assertCount(2, $events);
    }

    public function testThePerIpBudgetAlsoCoversLogout(): void
    {
        // Unknown tokens answer 204 but still spend the network's budget (4 a minute): guessing is throttled.
        foreach (['g1', 'g2', 'g3', 'g4'] as $guess) {
            self::assertSame(204, $this->call('POST', '/chat/v1/logout', $guess, null, null, [], '198.51.100.130')->getStatusCode());
        }
        $limited = $this->call('POST', '/chat/v1/logout', 'g5', null, null, [], '198.51.100.130');
        self::assertError($limited, 429, 'rate_limited');
        self::assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        self::assertSame(['rate_limited'], array_map(static fn ($event): string => $event->reason, $this->denied));
    }

    public function testReadTrafficNeverStarvesTheSignOut(): void
    {
        // Audit ZCB-L1: the token's read budget (2 a minute here) is spent, its own logout budget is not.
        $this->tokens()->grant('busy', $this->user('Ana'), [ChatClientPrincipal::SCOPE_READ], null, 'tok-busy-logout');
        self::assertSame(200, $this->call('GET', '/chat/v1/me', 'busy', null, null, [], '192.0.2.140')->getStatusCode());
        self::assertSame(200, $this->call('GET', '/chat/v1/me', 'busy', null, null, [], '192.0.2.140')->getStatusCode());
        self::assertError($this->call('GET', '/chat/v1/me', 'busy', null, null, [], '192.0.2.141'), 429, 'rate_limited');

        self::assertSame(204, $this->call('POST', '/chat/v1/logout', 'busy', null, null, [], '192.0.2.142')->getStatusCode());
        self::assertSame(204, $this->call('POST', '/chat/v1/logout', 'busy', null, null, [], '192.0.2.143')->getStatusCode());
        // The logout budget itself still applies.
        self::assertError($this->call('POST', '/chat/v1/logout', 'busy', null, null, [], '192.0.2.144'), 429, 'rate_limited');
    }
}
