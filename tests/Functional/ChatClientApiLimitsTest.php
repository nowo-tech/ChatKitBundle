<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestClientPrincipalResolver;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
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

    public function testWithoutAContextPolicyTheTokensOwnContextIsTheOnlyOne(): void
    {
        $ana = $this->user('Ana');
        $this->tokens()->grant('plain', $ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-plain');
        $this->tokens()->grant('bound', $ana, [ChatClientPrincipal::SCOPE_READ], 'org:acme', 'tok-bound');

        $me = $this->ok('GET', '/chat/v1/me', 'plain', null);
        self::assertNull($me['context']);
        self::assertSame([], $me['contexts']);
        self::assertSame('ids', $me['features']['realtimeEvents']);
        // A context the server cannot check is refused (fail closed).
        self::assertError($this->call('GET', '/chat/v1/me', 'plain', 'personal', null, ['X-Chat-Context' => 'personal']), 403, 'context_forbidden');
        self::assertSame('org:acme', $this->ok('GET', '/chat/v1/me', 'bound', null)['context']);
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
}
