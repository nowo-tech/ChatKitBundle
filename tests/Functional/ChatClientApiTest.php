<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\ClientApi\ChatClientApiGuard;
use Nowo\ChatKitBundle\ClientApi\ChatClientCall;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestLinkPreviewProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function array_column;
use function array_map;
use function count;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 2.4 (client API v1, Beacon Desktop BD-2): the client API end to end through one kernel: Bearer-only authentication
 * through the host's resolver, the context header checked on every request, the conversation list with its cursor and
 * unread counts, messages as tokens filtered per reader (R2), read marks, the realtime token, and every refusal with its
 * stable code (404 for anything the reader may not see).
 */
final class ChatClientApiTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string API = '/api/chat/v1';

    private TestUser $ana;

    private TestUser $luis;

    private TestUser $bea;

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->listenToDenials();
        $this->ana  = $this->user('Ana');
        $this->luis = $this->user('Luis');
        $this->bea  = $this->user('Bea');
        $this->tokens()->grant('ana-token', $this->ana);
        $this->tokens()->grant('luis-token', $this->luis);
        $this->tokens()->grant('bea-token', $this->bea);
        $this->contexts()->acmeMembers = [$this->bea->getChatPublicId() => true, $this->ana->getChatPublicId() => true];
    }

    public function testThePathPrefixIsAContainerParameter(): void
    {
        self::assertSame('/api/chat/v1', $this->kernel->getContainer()->getParameter('nowo_chat_kit.client_api.v1_path_prefix'));
        self::assertTrue($this->kernel->getContainer()->getParameter('nowo_chat_kit.client_api.enabled'));
    }

    public function testMeDescribesTheCallerTheContextsAndTheFeatures(): void
    {
        $me = $this->ok('GET', self::API . '/me', 'ana-token');

        self::assertSame(1, $me['apiVersion']);
        self::assertSame(['uuid' => $this->ana->getChatPublicId(), 'name' => 'Ana', 'initials' => $this->ana->getChatInitials(), 'avatarUrl' => null], $me['user']);
        self::assertSame([ChatClientPrincipal::SCOPE_READ], $me['scopes']);
        self::assertSame('personal', $me['context']);
        // The host's labels lose their control and bidirectional characters.
        self::assertSame([['key' => 'personal', 'label' => 'Personal'], ['key' => 'org:acme', 'label' => 'Acme']], $me['contexts']);
        self::assertSame(['realtime' => true, 'realtimeEvents' => 'ids', 'send' => false, 'search' => false, 'attachments' => false], $me['features']);
        self::assertSame(['conversationsPageMax' => 100, 'messagesPageMax' => 100], $me['limits']);
        // The resolver saw the request without its token, and the raw context header.
        $request = $this->tokens()->requests[0];
        self::assertSame('personal', $request->requestedContext);
        self::assertSame('203.0.113.9', $request->clientIp);
        self::assertSame('/api/chat/v1/me', $request->request->path);
    }

    public function testEveryRefusalHasAStableCodeAndAnEvent(): void
    {
        $missing = $this->call('GET', self::API . '/me');
        self::assertError($missing, 401, 'invalid_token');
        self::assertSame('Bearer', $missing->headers->get('WWW-Authenticate'));
        self::assertSame('no-store, private', $missing->headers->get('Cache-Control'));

        $unknown = $this->call('GET', self::API . '/me', 'nope');
        self::assertError($unknown, 401, 'invalid_token');
        self::assertSame('Bearer error="invalid_token"', $unknown->headers->get('WWW-Authenticate'));
        // A too long token never reaches the resolver.
        $asked = count($this->tokens()->requests);
        self::assertError($this->call('GET', self::API . '/me', str_repeat('a', 4097)), 401, 'invalid_token');
        self::assertCount($asked, $this->tokens()->requests);
        // Another scheme is no token.
        self::assertError($this->call('GET', self::API . '/me', null, 'personal', null, ['Authorization' => 'Basic YW5hOng=']), 401, 'invalid_token');

        // Scope.
        $this->tokens()->grant('write-only', $this->ana, [ChatClientPrincipal::SCOPE_WRITE, 'time:read'], null, 'tok-w');
        $scope = $this->call('GET', self::API . '/me', 'write-only');
        self::assertError($scope, 403, 'insufficient_scope');
        self::assertSame('Bearer error="insufficient_scope", scope="chat:read"', $scope->headers->get('WWW-Authenticate'));

        // An account that cannot use the chat any more.
        $this->get(TestAccessPolicy::class)->unreachable[$this->luis->getChatPublicId()] = true;
        self::assertError($this->call('GET', self::API . '/me', 'luis-token'), 401, 'invalid_token');

        // Contexts.
        self::assertError($this->call('GET', self::API . '/me', 'ana-token', null), 400, 'context_required');
        self::assertError($this->call('GET', self::API . '/me', 'ana-token', 'org acme'), 400, 'invalid_context');
        self::assertError($this->call('GET', self::API . '/me', 'ana-token', str_repeat('a', 129)), 400, 'invalid_context');
        self::assertError($this->call('GET', self::API . '/me', 'luis-token-x', 'org:acme'), 401, 'invalid_token');
        $this->get(TestAccessPolicy::class)->unreachable = [];
        self::assertError($this->call('GET', self::API . '/me', 'luis-token', 'org:acme'), 403, 'context_forbidden');
        self::assertError($this->call('GET', self::API . '/me', 'luis-token', 'org:unknown'), 403, 'context_forbidden');

        self::assertSame(
            ['invalid_token', 'invalid_token', 'invalid_token', 'invalid_token', 'insufficient_scope', 'account_inactive', 'context_required', 'invalid_context', 'invalid_context', 'invalid_token', 'context_forbidden', 'context_forbidden'],
            array_map(static fn ($event): string => $event->reason, $this->denied),
        );
        self::assertNull($this->denied[0]->tokenId);
        self::assertSame('203.0.113.0/24', $this->denied[0]->ipPrefix);
        self::assertSame('tok-w', $this->denied[4]->tokenId);
        self::assertSame($this->ana->getChatPublicId(), $this->denied[4]->userPublicId);
    }

    public function testATokenBoundToAContextNeverOpensAnotherOne(): void
    {
        $this->tokens()->grant('bound', $this->bea, [ChatClientPrincipal::SCOPE_READ], 'org:acme', 'tok-bound');

        // No header: the token's context; the same one in the header: fine; another one: refused, even one Bea may use.
        self::assertSame('org:acme', $this->ok('GET', self::API . '/me', 'bound', null)['context']);
        self::assertSame('org:acme', $this->ok('GET', self::API . '/me', 'bound', 'org:acme')['context']);
        self::assertError($this->call('GET', self::API . '/me', 'bound', 'personal'), 403, 'context_forbidden');

        // Audit ZBW-L3: membership is re-checked for a bound token too.
        $this->contexts()->acmeMembers = [];
        self::assertError($this->call('GET', self::API . '/me', 'bound', null), 403, 'context_forbidden');
    }

    public function testMembershipIsCheckedAgainOnEveryRequest(): void
    {
        self::assertSame('org:acme', $this->ok('GET', self::API . '/me', 'bea-token', 'org:acme')['context']);
        $this->contexts()->acmeMembers = [];
        self::assertError($this->call('GET', self::API . '/me', 'bea-token', 'org:acme'), 403, 'context_forbidden');
    }

    public function testSessionsAndCookiesAreIgnored(): void
    {
        // A signed-in browser session is no client API credential.
        $client = $this->kernel->getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->disableReboot();
        $client->loginUser($this->ana);
        $client->request('GET', self::API . '/me', [], [], ['HTTP_X_BEACON_CONTEXT' => 'personal']);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testTheListIsFilteredByContextAndCountsUnread(): void
    {
        $direct  = $this->direct($this->ana, $this->luis);
        $channel = $this->channel($this->ana);
        $this->send($this->luis, $direct, 'Hola Ana');
        $this->send($this->luis, $direct, 'Second');
        $this->send($this->bea, $channel, 'In Acme');

        // Personal: the direct conversation only (the Acme channel belongs to org:acme).
        $personal = $this->ok('GET', self::API . '/conversations', 'ana-token');
        self::assertSame([$direct->getUuid()], array_column($personal['conversations'], 'uuid'));
        self::assertNull($personal['nextCursor']);
        self::assertSame(2, $personal['unreadTotal']);
        self::assertFalse($personal['unreadTotalCapped']);
        $row = $personal['conversations'][0];
        self::assertSame('direct', $row['kind']);
        self::assertSame('Luis', $row['title']);
        self::assertSame(2, $row['unread']);
        self::assertFalse($row['archived']);
        self::assertNull($row['mutedUntil']);
        self::assertTrue($row['canSend']);
        self::assertSame('Second', $row['lastMessage']['preview']);
        self::assertSame($this->luis->getChatPublicId(), $row['peerUuid']);
        self::assertNull($row['ticket']);
        self::assertArrayNotHasKey('supportContext', $row);

        // org:acme: the channel too.
        $acme = $this->ok('GET', self::API . '/conversations', 'ana-token', 'org:acme');
        self::assertSame([$channel->getUuid(), $direct->getUuid()], array_column($acme['conversations'], 'uuid'));
        self::assertSame(3, $acme['unreadTotal']);
        self::assertSame('#Acme', $acme['conversations'][0]['title']);

        // A muted conversation keeps its count but leaves the total.
        $this->get(ChatService::class)->mute($this->ana, $direct, new DateTimeImmutable('+1 hour'));
        $muted = $this->ok('GET', self::API . '/conversations', 'ana-token', 'org:acme');
        self::assertSame(1, $muted['unreadTotal']);
        self::assertNotNull($muted['conversations'][1]['mutedUntil']);
        self::assertSame(2, $muted['conversations'][1]['unread']);

        // Closed conversations are listed with ?archived=1 only.
        $this->get(ChatService::class)->archive($this->ana, [$direct], true);
        self::assertSame([$channel->getUuid()], array_column($this->ok('GET', self::API . '/conversations', 'ana-token', 'org:acme')['conversations'], 'uuid'));
        $archived = $this->ok('GET', self::API . '/conversations?archived=1', 'ana-token', 'org:acme');
        self::assertSame([$direct->getUuid()], array_column($archived['conversations'], 'uuid'));
        self::assertTrue($archived['conversations'][0]['archived']);

        // The host may hide a conversation from every context.
        $this->contexts()->hidden = [$channel->getUuid()];
        self::assertSame([], $this->ok('GET', self::API . '/conversations', 'ana-token', 'org:acme')['conversations']);
        $refs = array_values(array_filter($this->contexts()->asked, static fn ($ref): bool => $ref->kind === 'direct'));
        self::assertSame($direct->getUuid(), $refs[0]->uuid);
        self::assertNull($refs[0]->channelKey);
        self::assertEqualsCanonicalizing([$this->ana->getChatPublicId(), $this->luis->getChatPublicId()], $refs[0]->memberPublicIds);
    }

    public function testTheCursorWalksEveryConversationOnce(): void
    {
        $people = [];
        foreach (['Carla', 'Dani', 'Eva', 'Fran', 'Gil'] as $name) {
            $person   = $this->user($name);
            $people[] = $this->direct($this->ana, $person)->getUuid();
            $this->send($person, $this->direct($this->ana, $person), 'hi');
        }
        // Same activity second: the id breaks the tie.
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_conversation SET last_message_at = ?', ['2026-11-25 10:00:00']);

        $seen   = [];
        $cursor = null;
        $pages  = 0;
        do {
            $page   = $this->ok('GET', self::API . '/conversations?limit=2' . ($cursor === null ? '' : '&cursor=' . $cursor), 'ana-token');
            $seen   = [...$seen, ...array_column($page['conversations'], 'uuid')];
            $cursor = $page['nextCursor'];
            self::assertSame($pages === 0 ? 5 : null, $page['unreadTotal']);
            self::assertSame($pages === 0 ? false : null, $page['unreadTotalCapped']);
            ++$pages;
        } while ($cursor !== null);

        self::assertSame(3, $pages);
        self::assertCount(5, $seen);
        self::assertEqualsCanonicalizing($people, $seen);

        self::assertError($this->call('GET', self::API . '/conversations?cursor=not-a-cursor', 'ana-token'), 400, 'invalid_cursor');
        foreach (['0', '101', 'x', '1.5'] as $limit) {
            self::assertError($this->call('GET', self::API . '/conversations?limit=' . $limit, 'ana-token'), 400, 'invalid_limit');
        }
    }

    public function testConversationDetailAndNotFound(): void
    {
        $direct  = $this->direct($this->ana, $this->luis);
        $channel = $this->channel($this->ana);
        $other   = $this->direct($this->bea, $this->luis);

        $detail = $this->ok('GET', self::API . '/conversations/' . $direct->getUuid(), 'ana-token')['conversation'];
        self::assertSame($direct->getUuid(), $detail['uuid']);
        self::assertSame(2, $detail['membersCount']);
        self::assertSame(['send' => true], $detail['permissions']);

        // Someone else's conversation, a conversation of another context, an unknown uuid: one answer.
        self::assertError($this->call('GET', self::API . '/conversations/' . $other->getUuid(), 'ana-token'), 404, 'not_found');
        self::assertError($this->call('GET', self::API . '/conversations/' . $channel->getUuid(), 'ana-token'), 404, 'not_found');
        self::assertError($this->call('GET', self::API . '/conversations/00000000-0000-4000-8000-000000000000', 'ana-token'), 404, 'not_found');
        self::assertError($this->call('GET', self::API . '/conversations/' . $other->getUuid() . '/messages', 'ana-token'), 404, 'not_found');
        self::assertSame(200, $this->call('GET', self::API . '/conversations/' . $channel->getUuid(), 'ana-token', 'org:acme')->getStatusCode());
        // Audit ZBW-I2: every 404 is audited (ids only).
        self::assertSame(['not_found', 'not_found', 'not_found', 'not_found'], array_map(static fn ($event): string => $event->reason, $this->denied));
        self::assertSame('tok-' . $this->ana->getChatPublicId(), $this->denied[0]->tokenId);
    }

    public function testMessagesAreTokensFilteredForTheReader(): void
    {
        $channel = $this->channel($this->ana);
        $this->channel($this->bea);
        $text = $this->send($this->ana, $channel, '**Hi** @[Bea](' . $this->bea->getChatPublicId() . ") see #[Deploy the API](task:1) and #[Secret plan](task:2)\n"
            . "[Acme](https://example.test/projects/acme/7) and https://example.test/projects/acme/8\n- one\n- two");
        $this->get(ChatService::class)->toggleReaction($this->bea, $text, '👍');

        $page = $this->ok('GET', self::API . '/conversations/' . $channel->getUuid() . '/messages', 'bea-token', 'org:acme');
        self::assertFalse($page['hasMore']);
        self::assertNull($page['readUpTo']);
        $message = $page['messages'][0];
        self::assertSame($text->getUuid(), $message['uuid']);
        self::assertSame(['uuid' => $this->ana->getChatPublicId(), 'name' => 'Ana', 'initials' => $this->ana->getChatInitials(), 'avatarUrl' => null, 'bot' => false, 'badges' => []], $message['author']);
        self::assertFalse($message['mine']);
        self::assertTrue($message['mentionsMe']);
        self::assertSame([['emoji' => '👍', 'count' => 1, 'mine' => true]], $message['reactions']);
        self::assertArrayNotHasKey('html', $message);
        self::assertSame('tokens/1', $message['body']['format']);

        $paragraph = $message['body']['blocks'][0]['tokens'];
        self::assertSame(['type' => 'text', 'text' => 'Hi', 'marks' => ['bold']], $paragraph[0]);
        self::assertSame(['type' => 'mention', 'userUuid' => $this->bea->getChatPublicId(), 'name' => 'Bea'], $paragraph[2]);
        self::assertSame('reference', $paragraph[4]['type']);
        self::assertTrue($paragraph[4]['visible']);
        self::assertSame('Deploy the API', $paragraph[4]['title']);
        self::assertSame('/tasks/1', $paragraph[4]['url']);
        // Ana may open her secret plan, Bea may not: the neutral placeholder, without type, id or title.
        self::assertSame(['type' => 'reference', 'visible' => false, 'text' => 'Content not visible'], $paragraph[6]);
        self::assertStringNotContainsString('Secret', json_encode($message, JSON_THROW_ON_ERROR));
        self::assertSame('list', $message['body']['blocks'][1]['type']);
        // Link previews of the reader (the provider's extra key is left out).
        self::assertSame([
            ['url' => 'https://example.test/projects/acme/7', 'title' => 'Acme · 7', 'subtitle' => 'Issue', 'badge' => 'open'],
            ['url' => 'https://example.test/projects/acme/8', 'title' => 'Acme · 8', 'subtitle' => 'Issue', 'badge' => 'open'],
        ], $message['previews']);

        $own = $this->ok('GET', self::API . '/conversations/' . $channel->getUuid() . '/messages/' . $text->getUuid(), 'ana-token', 'org:acme')['message'];
        self::assertTrue($own['mine']);
        self::assertSame('Secret plan', $own['body']['blocks'][0]['tokens'][6]['title']);

        self::assertError($this->call('GET', self::API . '/conversations/' . $channel->getUuid() . '/messages/00000000-0000-4000-8000-000000000000', 'ana-token', 'org:acme'), 404, 'not_found');
        // Threads are off in this profile: the thread route answers 404.
        self::assertError($this->call('GET', self::API . '/conversations/' . $channel->getUuid() . '/messages/' . $text->getUuid() . '/thread', 'ana-token', 'org:acme'), 404, 'not_found');

        // A group mention is named (`mentionAll`), as in the web UI.
        $lead = $this->user('Lead Lia');
        $this->channel($lead);
        $here = $this->send($lead, $channel, '@here standup');
        $seen = $this->ok('GET', self::API . '/conversations/' . $channel->getUuid() . '/messages/' . $here->getUuid(), 'bea-token', 'org:acme')['message'];
        self::assertSame('here', $seen['mentionAll']);
        self::assertTrue($seen['mentionsMe']);
    }

    public function testLinkPreviewsAreCappedPerMessageAndAskedTenAtATime(): void
    {
        $direct = $this->direct($this->ana, $this->luis);
        for ($m = 0; $m < 4; ++$m) {
            $this->send($this->ana, $direct, 'https://example.test/projects/acme/' . $m . 'a https://example.test/projects/acme/' . $m . 'b https://example.test/projects/acme/' . $m . 'c https://example.test/projects/acme/' . $m . 'd mailto:ana@example.test');
        }
        $previews = $this->get(TestLinkPreviewProvider::class);
        $messages = $this->ok('GET', self::API . '/conversations/' . $direct->getUuid() . '/messages', 'luis-token')['messages'];

        self::assertSame([10, 2], $previews->calls);
        foreach ($messages as $message) {
            self::assertCount(3, $message['previews']);
        }
    }

    public function testDeletedBotAndSystemMessages(): void
    {
        $direct = $this->direct($this->ana, $this->luis);
        $gone   = $this->send($this->ana, $direct, 'oops');
        $this->get(ChatService::class)->delete($this->ana, $gone);
        $this->get(ChatService::class)->postSystemLine($direct, 'chat.channel');

        $messages = $this->ok('GET', self::API . '/conversations/' . $direct->getUuid() . '/messages', 'luis-token')['messages'];
        self::assertTrue($messages[0]['deleted']);
        self::assertNull($messages[0]['body']);
        self::assertSame('system', $messages[1]['kind']);
        self::assertSame('paragraph', $messages[1]['body']['blocks'][0]['type']);
        self::assertSame('text', $messages[1]['body']['blocks'][0]['tokens'][0]['type']);
    }

    public function testMessagePagesAndCursors(): void
    {
        $direct = $this->direct($this->ana, $this->luis);
        $ids    = [];
        for ($i = 1; $i <= 5; ++$i) {
            $ids[] = (int) $this->send($this->ana, $direct, 'm' . $i)->getId();
        }
        $base = self::API . '/conversations/' . $direct->getUuid() . '/messages';

        $newest = $this->ok('GET', $base . '?limit=2', 'luis-token');
        self::assertTrue($newest['hasMore']);
        self::assertSame([$ids[3], $ids[4]], array_column($newest['messages'], 'id'));
        $older = $this->ok('GET', $base . '?limit=2&before=' . $ids[3], 'luis-token');
        self::assertSame([$ids[1], $ids[2]], array_column($older['messages'], 'id'));
        $after = $this->ok('GET', $base . '?limit=2&after=' . $ids[0], 'luis-token');
        self::assertTrue($after['hasMore']);
        self::assertSame([$ids[1], $ids[2]], array_column($after['messages'], 'id'));

        self::assertError($this->call('GET', $base . '?before=1&after=2', 'luis-token'), 400, 'invalid_cursor');
        self::assertError($this->call('GET', $base . '?before=-1', 'luis-token'), 400, 'invalid_cursor');
        self::assertError($this->call('GET', $base . '?limit=200', 'luis-token'), 400, 'invalid_limit');
    }

    public function testReadMarksNeedTheReadScopeOnlyAndNeverGoBack(): void
    {
        $direct = $this->direct($this->ana, $this->luis);
        $first  = (int) $this->send($this->ana, $direct, 'one')->getId();
        $last   = (int) $this->send($this->ana, $direct, 'two')->getId();
        $read   = self::API . '/conversations/' . $direct->getUuid() . '/read';

        self::assertSame(['ok' => true, 'readUpTo' => $last], $this->ok('POST', $read, 'luis-token', 'personal', '{"messageId": 999999999}'));
        // Never moved back.
        self::assertSame(['ok' => true, 'readUpTo' => $last], $this->ok('POST', $read, 'luis-token', 'personal', '{"messageId": ' . $first . '}'));
        self::assertSame(0, $this->ok('GET', self::API . '/conversations', 'luis-token')['unreadTotal']);

        self::assertError($this->call('POST', $read, 'luis-token', 'personal', '{"messageId": "1"}'), 422, 'invalid_message_id');
        self::assertError($this->call('POST', $read, 'luis-token', 'personal', '{}'), 422, 'invalid_message_id');
        self::assertError($this->call('POST', $read, 'luis-token', 'personal', '[1]'), 400, 'invalid_json');
        self::assertError($this->call('POST', $read, 'luis-token', 'personal', '{'), 400, 'invalid_json');
        self::assertError($this->call('POST', $read, 'luis-token', 'personal', '{"messageId": 1, "x": "' . str_repeat('a', 5000) . '"}'), 400, 'invalid_json');
        self::assertError($this->call('POST', self::API . '/conversations/' . $this->direct($this->bea, $this->ana)->getUuid() . '/read', 'luis-token', 'personal', '{"messageId": 1}'), 404, 'not_found');

        // An empty conversation has nothing to read.
        $empty = $this->direct($this->luis, $this->bea);
        self::assertSame(['ok' => true, 'readUpTo' => null], $this->ok('POST', self::API . '/conversations/' . $empty->getUuid() . '/read', 'luis-token', 'personal', '{"messageId": 5}'));
    }

    public function testTheRealtimeTokenIsForTheCallersOwnTopic(): void
    {
        $realtime = $this->ok('GET', self::API . '/realtime', 'ana-token');

        $topic = '/nowo-chat/users/' . $this->ana->getChatPublicId();
        self::assertTrue($realtime['enabled']);
        self::assertSame('https://hub.example.test/.well-known/mercure', $realtime['hubUrl']);
        self::assertSame($topic, $realtime['topic']);
        self::assertStringStartsWith('token:' . $topic . '@', $realtime['token']);
        self::assertSame('ids', $realtime['events']);
        self::assertGreaterThan(new DateTimeImmutable('+14 minutes'), new DateTimeImmutable($realtime['expiresAt']));

        // Hub off: poll.
        $this->get(RecordingRealtime::class)->disabled = true;
        $off                                           = $this->ok('GET', self::API . '/realtime', 'ana-token');
        self::assertFalse($off['enabled']);
        self::assertNull($off['hubUrl']);
        self::assertNull($off['token']);
        self::assertSame($topic, $off['topic']);
        self::assertFalse($this->ok('GET', self::API . '/me', 'ana-token')['features']['realtime']);
    }

    public function testTheCallLivesInTheRequestAttributesOnly(): void
    {
        $this->ok('GET', self::API . '/me', 'ana-token');
        // Nothing of the request is left in a service: the request stack is empty after the request.
        self::assertNull($this->get(RequestStack::class)->getCurrentRequest());
        // (No usable client IP: one shared "unknown" budget, no network in the events.)
        $request = Request::create(self::API . '/me', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ana-token', 'HTTP_X_BEACON_CONTEXT' => 'personal', 'REMOTE_ADDR' => 'not-an-ip']);
        $call    = $this->get(ChatClientApiGuard::class)->authenticate($request);
        self::assertSame($call, $request->attributes->get(ChatClientCall::ATTRIBUTE));
        self::assertSame('personal', $call->contextKey);
    }

    private function direct(TestUser $a, TestUser $b): ChatConversation
    {
        return $this->get(ChatService::class)->openDirect($a, $b);
    }

    private function channel(TestUser $user): ChatConversation
    {
        return $this->get(ChatService::class)->openChannel($user, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
    }

    private function send(TestUser $author, ChatConversation $conversation, string $body): ChatMessage
    {
        return $this->get(ChatService::class)->send($author, $conversation, $body);
    }
}
