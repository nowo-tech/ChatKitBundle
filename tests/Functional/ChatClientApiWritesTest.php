<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Event\ChatClientApiWriteEvent;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function assert;
use function count;
use function implode;
use function json_encode;
use function rawurlencode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 2.5 (client API v1 writes, Beacon Desktop BD-3): send, thread reply, reactions, edit and delete of one's own message
 * through the client API, with the web UI's rules (posting rights, edit window), the web UI's side effects (mentions,
 * unread counters, Web Push, ids-only realtime, events), the `Idempotency-Key` per token and conversation, the context
 * policy, the write budgets, and every refusal with its stable code.
 */
final class ChatClientApiWritesTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string API = '/api/chat/v1';

    private const array RW = [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE];

    private TestUser $ana;

    private TestUser $luis;

    private TestUser $bea;

    /** @var list<object> */
    private array $events = [];

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig([
            'client_api'   => ['writes' => true, 'limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000, 'writes_per_token_per_minute' => 100_000]],
            'push'         => true,
            'push_preview' => true,
            'message_menu' => ['threads' => ['enabled' => true]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->listenToDenials();
        $this->ana  = $this->user('Ana');
        $this->luis = $this->user('Luis');
        $this->bea  = $this->user('Bea');
        $this->tokens()->grant('ana', $this->ana, self::RW, null, 'tok-ana');
        $this->tokens()->grant('ana-2', $this->ana, self::RW, null, 'tok-ana-2');
        $this->tokens()->grant('ana-read', $this->ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-ana-read');
        $this->tokens()->grant('luis', $this->luis, self::RW, null, 'tok-luis');
        $this->tokens()->grant('bea', $this->bea, self::RW, null, 'tok-bea');
        $this->contexts()->acmeMembers = [$this->ana->getChatPublicId() => true, $this->luis->getChatPublicId() => true];
        $dispatcher                    = $this->kernel->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        foreach ([ChatClientApiWriteEvent::class, ChatMessageSentEvent::class, ChatMentionEvent::class] as $class) {
            $dispatcher->addListener($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testMeAdvertisesTheWrites(): void
    {
        $me = $this->ok('GET', self::API . '/me', 'ana');
        self::assertTrue($me['features']['send']);
        self::assertTrue($me['features']['threadReply']);
        self::assertTrue($me['features']['reactions']);
        self::assertTrue($me['features']['editOwn']);
        self::assertTrue($me['features']['deleteOwn']);
        self::assertFalse($me['features']['cards']);
        self::assertSame(self::RW, $me['scopes']);
        self::assertContains('👍', $me['reactions']['accepted']);
        self::assertTrue($this->kernel->getContainer()->getParameter('nowo_chat_kit.client_api.writes'));
    }

    public function testSendIsTheWebSendWithAnIdempotencyKey(): void
    {
        $dm   = $this->dm($this->ana, $this->luis);
        $text = 'Hi @[Luis](' . $this->luis->getChatPublicId() . ') **zebra**';
        $push = $this->em->getRepository(ChatUserSettings::class)->forUser($this->luis);
        $push->setPushEnabled(true);
        $this->em->persist($push);
        $this->em->flush();

        $first = $this->write('POST', $this->messages($dm), 'ana', ['text' => $text], 'k-1');
        self::assertSame(201, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame('no-store, private', $first->headers->get('Cache-Control'));
        $sent = self::decode($first);
        self::assertFalse($sent['replayed']);
        self::assertTrue($sent['message']['mine']);
        self::assertSame('tokens/1', $sent['message']['body']['format']);
        self::assertSame('mention', $sent['message']['body']['blocks'][0]['tokens'][1]['type']);
        self::assertSame($this->luis->getChatPublicId(), $sent['message']['body']['blocks'][0]['tokens'][1]['userUuid']);

        // The same key and text again (a retry after a lost answer): the first message, no second one.
        $again = $this->write('POST', $this->messages($dm), 'ana', ['text' => $text], 'k-1');
        self::assertSame(200, $again->getStatusCode());
        self::assertTrue(self::decode($again)['replayed']);
        self::assertSame($sent['message']['uuid'], self::decode($again)['message']['uuid']);
        self::assertSame(1, $this->rows(ChatMessage::class));
        // The same key with another text: refused, nothing written.
        self::assertError($this->write('POST', $this->messages($dm), 'ana', ['text' => 'other'], 'k-1'), 422, 'idempotency_mismatch');
        // The key is scoped to the token and the conversation: another device, or another conversation, is another key.
        self::assertSame(201, $this->write('POST', $this->messages($dm), 'ana-2', ['text' => $text], 'k-1')->getStatusCode());
        self::assertSame(201, $this->write('POST', $this->messages($this->dm($this->ana, $this->bea)), 'ana', ['text' => $text], 'k-1')->getStatusCode());
        self::assertSame(3, $this->rows(ChatMessage::class));

        // Side effects as the web send: the unread counter, the mention, Web Push, ids-only realtime, events.
        $list = $this->ok('GET', self::API . '/conversations', 'luis');
        self::assertSame(2, $list['unreadTotal']);
        $page = $this->ok('GET', $this->messages($dm), 'luis')['messages'];
        self::assertTrue($page[0]['mentionsMe']);
        self::assertArrayHasKey($this->luis->getChatPublicId(), $this->get(RecordingPushSender::class)->sent);
        $published = array_values(array_filter($this->get(RecordingRealtime::class)->published, static fn (array $e): bool => $e['payload']['type'] === 'message'));
        self::assertSame(['type', 'conversationUuid', 'messageUuid', 'messageId', 'deleted'], array_keys($published[0]['payload']));
        self::assertStringNotContainsString('zebra', (string) json_encode($published, JSON_THROW_ON_ERROR));
        $writes = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof ChatClientApiWriteEvent));
        self::assertSame([[ChatClientApiWriteEvent::SEND, 'tok-ana', false], [ChatClientApiWriteEvent::SEND, 'tok-ana', true], [ChatClientApiWriteEvent::SEND, 'tok-ana-2', false], [ChatClientApiWriteEvent::SEND, 'tok-ana', false]], array_map(static fn (ChatClientApiWriteEvent $e): array => [$e->action, $e->tokenId, $e->replayed], $writes));
        self::assertSame([$dm->getUuid(), $sent['message']['uuid'], 'personal', '203.0.113.0/24', $this->ana->getChatPublicId()], [$writes[0]->conversationUuid, $writes[0]->messageUuid, $writes[0]->contextKey, $writes[0]->ipPrefix, $writes[0]->userPublicId]);
        self::assertCount(3, array_filter($this->events, static fn (object $e): bool => $e instanceof ChatMessageSentEvent));
        self::assertNotEmpty(array_filter($this->events, static fn (object $e): bool => $e instanceof ChatMentionEvent));
    }

    public function testEveryWriteRefusalHasAStableCode(): void
    {
        $dm  = $this->dm($this->ana, $this->luis);
        $url = $this->messages($dm);

        // Key.
        self::assertError($this->call('POST', $url, 'ana', 'personal', '{"text":"x"}'), 400, 'idempotency_key_required');
        self::assertError($this->write('POST', $url, 'ana', ['text' => 'x'], 'bad key!'), 400, 'idempotency_key_invalid');
        self::assertError($this->write('POST', $url, 'ana', ['text' => 'x'], str_repeat('k', 81)), 400, 'idempotency_key_invalid');
        // Body: a JSON object with `text` only (2.6 card references are refused, never dropped).
        self::assertError($this->call('POST', $url, 'ana', 'personal', 'nope', ['Idempotency-Key' => 'b-1']), 400, 'invalid_json');
        self::assertError($this->call('POST', $url, 'ana', 'personal', str_repeat(' ', 32_769), ['Idempotency-Key' => 'b-1']), 400, 'invalid_json');
        self::assertError($this->write('POST', $url, 'ana', ['text' => 'x', 'cards' => [['type' => 'secret', 'id' => '1']]], 'b-2'), 422, 'unsupported_field');
        self::assertError($this->write('POST', $url, 'ana', ['body' => 'x'], 'b-3'), 422, 'unsupported_field');
        self::assertError($this->write('POST', $url, 'ana', ['text' => 42], 'b-4'), 422, 'invalid_text');
        self::assertError($this->write('POST', $url, 'ana', [], 'b-5'), 422, 'invalid_text');
        self::assertError($this->write('POST', $url, 'ana', ['text' => "  \n "], 'b-6'), 422, 'empty_text');
        self::assertError($this->write('POST', $url, 'ana', ['text' => str_repeat('a', 4001)], 'b-7'), 422, 'text_too_long');
        // Scopes: a read token stays read-only; a write token needs chat:read too.
        $scope = $this->write('POST', $url, 'ana-read', ['text' => 'x'], 's-1');
        self::assertError($scope, 403, 'insufficient_scope');
        self::assertSame('Bearer error="insufficient_scope", scope="chat:read chat:write"', $scope->headers->get('WWW-Authenticate'));
        $this->tokens()->grant('write-only', $this->ana, [ChatClientPrincipal::SCOPE_WRITE], null, 'tok-w');
        self::assertError($this->write('POST', $url, 'write-only', ['text' => 'x'], 's-2'), 403, 'insufficient_scope');
        // Someone else's conversation, an unknown one: 404 (audited).
        self::assertError($this->write('POST', $url, 'bea', ['text' => 'x'], 'n-1'), 404, 'not_found');
        self::assertError($this->write('POST', self::API . '/conversations/00000000-0000-4000-8000-000000000000/messages', 'ana', ['text' => 'x'], 'n-2'), 404, 'not_found');
        self::assertContains('not_found', array_map(static fn ($e): string => $e->reason, $this->denied));
        self::assertSame(0, $this->rows(ChatMessage::class));
    }

    public function testPostingRightsAreTheWebUis(): void
    {
        // A channel the person may read but not write (ChatChannelWritePolicyInterface, as in the dock).
        $reader                                                    = $this->user('Reader Rui');
        $this->contexts()->acmeMembers[$reader->getChatPublicId()] = true;
        $this->tokens()->grant('reader', $reader, self::RW, null, 'tok-reader');
        $acme = $this->get(ChatService::class)->openChannel($this->ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        self::assertSame(201, $this->write('POST', $this->messages($acme), 'ana', ['text' => 'in acme'], 'r-2', 'org:acme')->getStatusCode());
        self::assertError($this->write('POST', $this->messages($acme), 'reader', ['text' => 'x'], 'r-3', 'org:acme'), 403, 'read_only');
        // The context policy: a project channel is not in `personal` (404), and a context the person is not in is refused.
        self::assertError($this->write('POST', $this->messages($acme), 'ana', ['text' => 'x'], 'r-4'), 404, 'not_found');
        self::assertError($this->write('POST', $this->messages($acme), 'bea', ['text' => 'x'], 'r-5', 'org:acme'), 403, 'context_forbidden');
        // A blocked direct conversation (the access policy refuses it now).
        $stranger = $this->user('Stranger Sam');
        $blocked  = $this->dm($this->ana, $stranger);
        self::assertError($this->write('POST', $this->messages($blocked), 'ana', ['text' => 'x'], 'r-6'), 403, 'read_only');
        // An account that cannot use the chat any more.
        $this->tokens()->grant('disabled', $this->user('Disabled Dan'), self::RW, null, 'tok-dis');
        self::assertError($this->write('POST', $this->messages($blocked), 'disabled', ['text' => 'x'], 'r-7'), 401, 'invalid_token');
    }

    public function testThreadReplies(): void
    {
        $dm   = $this->dm($this->ana, $this->luis);
        $root = $this->get(ChatService::class)->send($this->luis, $dm, 'root');
        $url  = $this->messages($dm) . '/' . $root->getUuid() . '/thread';

        $reply = $this->write('POST', $url, 'ana', ['text' => 'a reply'], 't-1');
        self::assertSame(201, $reply->getStatusCode(), (string) $reply->getContent());
        $data = self::decode($reply);
        self::assertSame($root->getUuid(), $data['message']['threadRootUuid']);
        self::assertSame(1, $data['root']['thread']['count']);
        self::assertFalse($data['replayed']);
        $again = self::decode($this->write('POST', $url, 'ana', ['text' => 'a reply'], 't-1'));
        self::assertTrue($again['replayed']);
        self::assertSame($data['message']['uuid'], $again['message']['uuid']);
        self::assertError($this->write('POST', $url, 'ana', ['text' => 'another'], 't-1'), 422, 'idempotency_mismatch');
        // A reply's uuid replies in its root's thread.
        $nested = self::decode($this->write('POST', $this->messages($dm) . '/' . $data['message']['uuid'] . '/thread', 'luis', ['text' => 'second'], 't-2'));
        self::assertSame($root->getUuid(), $nested['message']['threadRootUuid']);
        self::assertSame(2, $nested['root']['thread']['count']);
        // The timeline keeps the root only.
        self::assertSame([$root->getUuid()], array_column($this->ok('GET', $this->messages($dm), 'luis')['messages'], 'uuid'));
        // A buzz takes no thread; someone else's conversation is 404.
        $buzz = $this->get(ChatService::class)->buzz($this->luis, $dm);
        self::assertError($this->write('POST', $this->messages($dm) . '/' . $buzz->getUuid() . '/thread', 'ana', ['text' => 'x'], 't-3'), 422, 'not_threadable');
        self::assertError($this->write('POST', $url, 'bea', ['text' => 'x'], 't-4'), 404, 'not_found');
        self::assertError($this->write('POST', $this->messages($dm) . '/00000000-0000-4000-8000-000000000000/thread', 'ana', ['text' => 'x'], 't-5'), 404, 'not_found');
        $writes = array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof ChatClientApiWriteEvent));
        self::assertSame(ChatClientApiWriteEvent::THREAD_REPLY, $writes[0]->action);
    }

    public function testReactionsAreSetAndRemovedIdempotently(): void
    {
        $dm      = $this->dm($this->ana, $this->luis);
        $message = $this->get(ChatService::class)->send($this->luis, $dm, 'react to me');
        $url     = $this->messages($dm) . '/' . $message->getUuid() . '/reactions/' . rawurlencode('👍');
        $before  = count($this->get(RecordingRealtime::class)->published);

        $set = $this->write('PUT', $url, 'ana', null, 'e-1');
        self::assertSame(200, $set->getStatusCode(), (string) $set->getContent());
        self::assertSame([['emoji' => '👍', 'count' => 1, 'mine' => true]], self::decode($set)['reactions']);
        $hints = count($this->get(RecordingRealtime::class)->published);
        self::assertGreaterThan($before, $hints);
        // Setting it again (another key: a second device) changes nothing and publishes nothing.
        self::assertSame([['emoji' => '👍', 'count' => 1, 'mine' => true]], self::decode($this->write('PUT', $url, 'ana', null, 'e-2'))['reactions']);
        self::assertCount($hints, $this->get(RecordingRealtime::class)->published);
        // The same key replays; the same key for the opposite change is refused.
        self::assertTrue(self::decode($this->write('PUT', $url, 'ana', null, 'e-1'))['replayed']);
        self::assertError($this->write('DELETE', $url, 'ana', null, 'e-1'), 422, 'idempotency_mismatch');
        // Luis sees the count, not who.
        self::assertSame([['emoji' => '👍', 'count' => 2, 'mine' => true]], self::decode($this->write('PUT', $url, 'luis', null, 'e-3'))['reactions']);
        // Removing it twice: one change.
        self::assertSame([['emoji' => '👍', 'count' => 1, 'mine' => false]], self::decode($this->write('DELETE', $url, 'ana', null, 'e-4'))['reactions']);
        self::assertSame([['emoji' => '👍', 'count' => 1, 'mine' => false]], self::decode($this->write('DELETE', $url, 'ana', null, 'e-5'))['reactions']);

        self::assertError($this->write('PUT', $this->messages($dm) . '/' . $message->getUuid() . '/reactions/x', 'ana', null, 'e-6'), 422, 'invalid_reaction');
        $buzz = $this->get(ChatService::class)->buzz($this->luis, $dm);
        self::assertError($this->write('PUT', $this->messages($dm) . '/' . $buzz->getUuid() . '/reactions/' . rawurlencode('👍'), 'ana', null, 'e-7'), 422, 'not_reactable');
        self::assertError($this->write('PUT', $url, 'bea', null, 'e-8'), 404, 'not_found');
        self::assertSame(405, $this->write('POST', $url, 'ana', null, 'e-9')->getStatusCode());
        $actions = array_map(static fn (ChatClientApiWriteEvent $e): string => $e->action, array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof ChatClientApiWriteEvent)));
        self::assertSame(['reaction_add', 'reaction_add', 'reaction_add', 'reaction_add', 'reaction_remove', 'reaction_remove'], $actions);
    }

    public function testEditAndDeleteOfOnesOwnMessage(): void
    {
        $dm     = $this->dm($this->ana, $this->luis);
        $mine   = $this->get(ChatService::class)->send($this->ana, $dm, 'typo');
        $theirs = $this->get(ChatService::class)->send($this->luis, $dm, 'theirs');
        $url    = $this->messages($dm) . '/' . $mine->getUuid();

        $edit = self::decode($this->write('PATCH', $url, 'ana', ['text' => 'fixed'], 'd-1'));
        self::assertTrue($edit['message']['edited']);
        self::assertSame('fixed', implode('', array_column($edit['message']['body']['blocks'][0]['tokens'], 'text')));
        self::assertTrue(self::decode($this->write('PATCH', $url, 'ana', ['text' => 'fixed'], 'd-1'))['replayed']);
        // The same text again under a new key: no change, no error.
        self::assertSame(200, $this->write('PATCH', $url, 'ana', ['text' => 'fixed'], 'd-2')->getStatusCode());
        self::assertError($this->write('PATCH', $this->messages($dm) . '/' . $theirs->getUuid(), 'ana', ['text' => 'x'], 'd-3'), 403, 'edit_forbidden');
        self::assertError($this->write('PATCH', $url, 'ana', ['text' => ''], 'd-4'), 422, 'empty_text');

        self::assertError($this->write('DELETE', $this->messages($dm) . '/' . $theirs->getUuid(), 'ana', null, 'd-5'), 403, 'delete_forbidden');
        $deleted = self::decode($this->write('DELETE', $url, 'ana', null, 'd-6'));
        self::assertTrue($deleted['message']['deleted']);
        self::assertNull($deleted['message']['body']);
        // Deleting it again (a retry under a new key): the tombstone again.
        self::assertTrue(self::decode($this->write('DELETE', $url, 'ana', null, 'd-7'))['message']['deleted']);
        self::assertError($this->write('PATCH', $url, 'ana', ['text' => 'back'], 'd-8'), 403, 'edit_forbidden');
        $actions = array_map(static fn (ChatClientApiWriteEvent $e): string => $e->action, array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof ChatClientApiWriteEvent)));
        self::assertSame(['edit', 'edit', 'edit', 'delete', 'delete'], $actions);
    }

    public function testOnOneKernelNothingOfAWriteStays(): void
    {
        // FrankenPHP worker mode (kernel reset false): two people, two tokens, many writes on one kernel.
        $dm = $this->dm($this->ana, $this->luis);
        for ($round = 0; $round < 60; ++$round) {
            $sender = $round % 2 === 0 ? 'ana' : 'luis';
            $sent   = self::decode($this->write('POST', $this->messages($dm), $sender, ['text' => 'round ' . $round], 'w-' . $round));
            self::assertTrue($sent['message']['mine']);
            $react = self::decode($this->write('PUT', $this->messages($dm) . '/' . $sent['message']['uuid'] . '/reactions/' . rawurlencode('🎉'), $sender === 'ana' ? 'luis' : 'ana', null, 'wr-' . $round));
            self::assertSame([['emoji' => '🎉', 'count' => 1, 'mine' => true]], $react['reactions']);
            $this->em->clear();
        }
        self::assertSame(60, $this->rows(ChatMessage::class));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function write(string $method, string $uri, string $token, ?array $body, string $key, string $context = 'personal'): Response
    {
        return $this->call($method, $uri, $token, $context, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), ['Idempotency-Key' => $key]);
    }

    private function messages(ChatConversation $conversation): string
    {
        return self::API . '/conversations/' . $conversation->getUuid() . '/messages';
    }

    private function dm(TestUser $a, TestUser $b): ChatConversation
    {
        return $this->get(ChatService::class)->openDirect($a, $b);
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e)')->from($class, 'e')->getQuery()->getSingleScalarResult();
    }
}
