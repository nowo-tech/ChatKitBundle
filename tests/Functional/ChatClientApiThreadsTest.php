<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;

/**
 * 2.4 (owner 2026-11-25: threads and reactions in the first desktop version): thread reads (root + a page of replies),
 * per-message reply count, reaction summaries for the reader, and ids-only events for replies and reaction changes.
 */
final class ChatClientApiThreadsTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string API = '/api/chat/v1';

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig(['realtime_events' => 'ids', 'message_menu' => ['threads' => ['enabled' => true]]]);
    }

    public function testThreadsAndReactionsForTheReader(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $this->tokens()->grant('luis', $luis);
        $this->tokens()->grant('bea', $bea);
        $service      = $this->get(ChatService::class);
        $conversation = $service->openDirect($ana, $luis);
        $root         = $service->send($ana, $conversation, 'Root #[Deploy the API](task:1)');
        [$reply]      = $this->get(ChatThreads::class)->reply($luis, $root, '**First** reply', 'key-1');
        $this->get(ChatThreads::class)->reply($ana, $root, 'Second reply', 'key-2');
        $service->toggleReaction($luis, $reply, '👍');
        $service->toggleReaction($ana, $reply, '👍');
        $service->toggleReaction($ana, $reply, '🎉');

        // The timeline: the root only, with its reply count and last reply time.
        $timeline = $this->ok('GET', self::API . '/conversations/' . $conversation->getUuid() . '/messages', 'luis')['messages'];
        self::assertSame([$root->getUuid()], array_column($timeline, 'uuid'));
        self::assertSame(2, $timeline[0]['thread']['count']);
        self::assertNotNull($timeline[0]['thread']['lastReplyAt']);

        // The thread, opened by the root or by a reply.
        $thread = $this->ok('GET', self::API . '/conversations/' . $conversation->getUuid() . '/messages/' . $reply->getUuid() . '/thread', 'luis');
        self::assertSame($root->getUuid(), $thread['root']['uuid']);
        self::assertSame(['First reply', 'Second reply'], array_map(static fn (array $m): string => implode('', array_column($m['body']['blocks'][0]['tokens'], 'text')), $thread['replies']));
        self::assertSame(['bold'], $thread['replies'][0]['body']['blocks'][0]['tokens'][0]['marks']);
        self::assertSame($root->getUuid(), $thread['replies'][0]['threadRootUuid']);
        self::assertSame(2, $thread['replyCount']);
        self::assertFalse($thread['hasMore']);
        // The root's #reference is resolved for the reader, as in the timeline.
        self::assertTrue($thread['root']['body']['blocks'][0]['tokens'][1]['visible']);
        // Reactions: emoji, count, whether the reader reacted.
        self::assertEqualsCanonicalizing([['emoji' => '👍', 'count' => 2, 'mine' => true], ['emoji' => '🎉', 'count' => 1, 'mine' => false]], $thread['replies'][0]['reactions']);

        // Someone outside the conversation, a cursor pair, an unknown message: refused.
        self::assertError($this->call('GET', self::API . '/conversations/' . $conversation->getUuid() . '/messages/' . $root->getUuid() . '/thread', 'bea'), 404, 'not_found');
        self::assertError($this->call('GET', self::API . '/conversations/' . $conversation->getUuid() . '/messages/' . $root->getUuid() . '/thread?before=1&after=2', 'luis'), 400, 'invalid_cursor');
        self::assertError($this->call('GET', self::API . '/conversations/' . $conversation->getUuid() . '/messages/00000000-0000-4000-8000-000000000000/thread', 'luis'), 404, 'not_found');

        // Ids-only events: a reply names its root; a reaction change is a `message_updated` hint.
        $events = array_values(array_filter($this->get(RecordingRealtime::class)->published, static fn (array $e): bool => in_array($e['payload']['type'], ['message', 'message_updated'], true) && $e['payload']['messageUuid'] === $reply->getUuid()));
        self::assertSame('message', $events[0]['payload']['type']);
        self::assertSame($root->getUuid(), $events[0]['payload']['threadRootUuid']);
        self::assertSame('message_updated', $events[1]['payload']['type']);
        self::assertArrayNotHasKey('message', $events[1]['payload']);
    }
}
