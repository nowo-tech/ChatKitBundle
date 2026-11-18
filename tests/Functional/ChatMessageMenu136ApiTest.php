<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatIdempotencyKey;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatForwardRefused;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_filter;
use function array_values;
use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

/**
 * 1.36: forward (all or nothing, under an Idempotency-Key, the marker per reader), one-level threads (out of the main
 * timeline, counted on the root, followers) and edit history (author and moderators read the previous texts).
 */
final class ChatMessageMenu136ApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testDefaultsOfferNone' => [],
            default                 => ['profiles' => ['default' => ['message_menu' => [
                'forward'      => ['enabled' => true, 'max_targets' => 2],
                'threads'      => ['enabled' => true],
                'edit_history' => ['enabled' => true, 'max_revisions' => 2],
                'message_info' => ['enabled' => true],
                'save'         => ['enabled' => true],
            ]]]],
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testDefaultsOfferNone(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $his  = $chat->send($luis, $dm, 'hello');

        $config = $this->login($ana);
        self::assertFalse($config['menu']['items']['thread']);
        self::assertFalse($config['menu']['items']['forward']);
        self::assertFalse($config['menu']['editHistory']);
        $base = $this->base($dm, $his);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $config['csrf'], 'k1', 404, ['targets' => [['conversationUuid' => $dm->getUuid()]]]);
        $this->keyed(Request::METHOD_POST, $base . '/thread', $config['csrf'], 'k2', 404, ['body' => 'x']);
        foreach (['/thread', '/revisions'] as $path) {
            $this->client->request(Request::METHOD_GET, $base . $path);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $path);
        }
        $this->client->request(Request::METHOD_GET, '/chat/api/forward-targets');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->send(Request::METHOD_POST, $base . '/thread/read', $config['csrf'], 404, ['messageId' => 1]);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages');
        self::assertArrayNotHasKey('threads', $this->decode());
        $html = $this->renderDockTemplates();
        self::assertStringNotContainsString('data-part="menu-thread"', $html);
        self::assertStringNotContainsString('data-part="menu-forward"', $html);
        // An edit keeps no previous text with the history off.
        $chat->edit($this->fresh($his), 'hello there');
        self::assertSame([], $this->em->getRepository(ChatMessageRevision::class)->findAll());
        // The DDD façade answers "not found" with the items off.
        $actions = $this->get(ChatMessageActionsInterface::class);
        $this->expectException(ChatNotFoundException::class);
        $actions->thread($this->fresh($ana), $dm->getUuid(), $his->getUuid());
    }

    public function testForwardIsAllOrNothingAndIdempotent(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $his  = $chat->send($luis, $dm, 'see @[Ana](' . $ana->getChatPublicId() . ') and @channel');
        $csrf = $this->login($ana)['csrf'];
        $room = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation'];
        $old  = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::ARCHIVE_KEY])['conversation'];
        $base = $this->base($dm, $his);

        // The picker: Ana's conversations where she may write (not the read-only archive), people with a query.
        $this->client->request(Request::METHOD_GET, '/chat/api/forward-targets');
        $targets = $this->decode()['targets'];
        self::assertContains($room['uuid'], array_column($targets, 'conversationUuid'));
        self::assertNotContains($old['uuid'], array_column($targets, 'conversationUuid'));
        $this->client->request(Request::METHOD_GET, '/chat/api/forward-targets?q=bea');
        $people = array_values(array_filter($this->decode()['targets'], static fn (array $row): bool => $row['contactUuid'] !== null));
        self::assertSame([$bea->getChatPublicId()], array_column($people, 'contactUuid'));
        $this->client->request(Request::METHOD_GET, '/chat/api/forward-targets?q=acm');
        self::assertSame(['#Acme'], array_column($this->decode()['targets'], 'title'));
        // Luis has a direct conversation with Ana already: he is listed once, as that conversation.
        $this->client->request(Request::METHOD_GET, '/chat/api/forward-targets?q=lui');
        self::assertSame([[$dm->getUuid(), null]], array_map(static fn (array $row): array => [$row['conversationUuid'], $row['contactUuid']], $this->decode()['targets']));
        // The messages page of a conversation without threads.
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages');
        self::assertSame([], $this->decode()['threads']);

        // No key, a bad key: 400; no targets, too many, malformed: 422; an unknown target: 404.
        $this->send(Request::METHOD_POST, $base . '/forward', $csrf, 400, ['targets' => [['conversationUuid' => $room['uuid']]]]);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'bad key!', 400, ['targets' => [['conversationUuid' => $room['uuid']]]]);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-0', 422, ['targets' => []]);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-0', 422, ['targets' => 'x']);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-0', 422, ['targets' => [['conversationUuid' => $room['uuid']], ['contactUuid' => $bea->getChatPublicId()], ['conversationUuid' => $dm->getUuid()]]]);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-0', 422, ['targets' => [['conversationUuid' => $room['uuid'], 'contactUuid' => 'x']]]);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-0', 404, ['targets' => [['conversationUuid' => '00000000-0000-4000-8000-000000000000']]]);
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-0', 404, ['targets' => [['contactUuid' => 'nobody']]]);
        // A read-only target refuses the whole forward: nothing is written.
        $count   = $this->messageCount();
        $refused = $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-1', 403, ['targets' => [['conversationUuid' => $room['uuid']], ['conversationUuid' => $old['uuid']]]]);
        self::assertSame(['ok' => false, 'error' => 'forward_forbidden', 'target' => 1], $refused);
        self::assertSame($count, $this->messageCount());

        // To the channel and to Bea (a new direct conversation), with a comment.
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        $done                = $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-2', 201, ['targets' => [['conversationUuid' => $room['uuid']], ['contactUuid' => $bea->getChatPublicId()]], 'comment' => 'look']);
        self::assertCount(2, $done['messages']);
        self::assertSame($room['uuid'], $done['messages'][0]['conversationUuid']);
        $copy = $done['messages'][0]['message'];
        // Nobody is mentioned again; the marker names the source for Ana (she reads it).
        self::assertSame([], $copy['mentions']);
        self::assertArrayNotHasKey('mentionAll', $copy);
        self::assertStringContainsString('see @Ana and @channel', $copy['html']);
        self::assertSame(['from' => 'direct', 'label' => null], $copy['forwarded']);
        self::assertSame($count + 4, $this->messageCount(), 'a comment and a copy per target');
        self::assertContains('message', $realtime->types());

        // The same key and request: the same answer, nothing new. The same key for another request: 422.
        $again = $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-2', 200, ['targets' => [['conversationUuid' => $room['uuid']], ['contactUuid' => $bea->getChatPublicId()]], 'comment' => 'look']);
        self::assertTrue($again['replayed']);
        self::assertSame(array_column(array_column($done['messages'], 'message'), 'uuid'), array_column(array_column($again['messages'], 'message'), 'uuid'));
        self::assertSame($count + 4, $this->messageCount());
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-2', 422, ['targets' => [['conversationUuid' => $room['uuid']]]]);

        // Bea reads the channel but not Ana's DM: the neutral "Forwarded".
        $this->login($bea);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room['uuid'] . '/messages/' . $copy['uuid']);
        self::assertSame(['from' => null, 'label' => null], $this->decode()['message']['forwarded']);

        // A forward of a channel message names the channel to its readers.
        $said = $chat->send($this->fresh($luis), $this->conversation($room['uuid']), 'channel news');
        $csrf = $this->login($ana)['csrf'];
        $fwd  = $this->keyed(Request::METHOD_POST, $this->base($this->conversation($room['uuid']), $said) . '/forward', $csrf, 'k-3', 201, ['targets' => [['conversationUuid' => $dm->getUuid()]]]);
        self::assertSame(['from' => 'channel', 'label' => 'Acme'], $fwd['messages'][0]['message']['forwarded']);
        // The realtime payload is neutral: the client fetches it again.
        $last   = array_values(array_filter($this->get(RecordingRealtime::class)->published, static fn (array $event): bool => $event['payload']['type'] === 'message'));
        $newest = end($last);
        self::assertIsArray($newest);
        self::assertTrue($newest['payload']['message']['refsPending']);

        // Not forwardable: a buzz line (404), a support ticket without `from_support` (404).
        $buzz = $chat->buzz($this->fresh($ana), $this->fresh($dm));
        $this->keyed(Request::METHOD_POST, $this->base($dm, $buzz) . '/forward', $csrf, 'k-4', 404, ['targets' => [['conversationUuid' => $room['uuid']]]]);
        $ticket = $chat->openSupport($this->fresh($ana));
        $asked  = $chat->send($this->fresh($ana), $ticket, 'help');
        $this->keyed(Request::METHOD_POST, $this->base($ticket, $asked) . '/forward', $csrf, 'k-5', 404, ['targets' => [['conversationUuid' => $room['uuid']]]]);
        // The picker leaves the ticket out (Ana is no agent).
        $this->client->request(Request::METHOD_GET, '/chat/api/forward-targets');
        self::assertNotContains($ticket->getUuid(), array_column($this->decode()['targets'], 'conversationUuid'));
        // Into a ticket: agents only.
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-6', 403, ['targets' => [['conversationUuid' => $ticket->getUuid()]]]);
        // To someone Ana may not write to.
        $stranger = $this->user('Stranger Sam');
        $this->keyed(Request::METHOD_POST, $base . '/forward', $csrf, 'k-7', 403, ['targets' => [['contactUuid' => $stranger->getChatPublicId()]]]);

        // The DDD façade: the same rules by uuid (and the key format).
        $actions = $this->get(ChatMessageActionsInterface::class);
        $views   = $actions->forward($this->fresh($ana), $dm->getUuid(), $his->getUuid(), [['conversationUuid' => $room['uuid']]], null, 'ddd-1');
        self::assertCount(1, $views);
        self::assertTrue($views[0]->forwarded);
        try {
            $actions->forward($this->fresh($ana), $dm->getUuid(), $his->getUuid(), [['conversationUuid' => $room['uuid']]], null, 'bad key');
            self::fail('a bad key');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('idempotency_key_invalid', $exception->getMessage());
        }
        try {
            $actions->forward($this->fresh($ana), $dm->getUuid(), $his->getUuid(), [['conversationUuid' => $old['uuid']]], null, 'ddd-2');
            self::fail('a read-only target');
        } catch (ChatForwardRefused $refusedTarget) {
            self::assertSame(0, $refusedTarget->target);
        }
        self::assertTrue($this->get(ChatMessagingInterface::class)->can($this->fresh($ana), $dm->getUuid(), ChatMessagingInterface::FORWARD, $his->getUuid()));

        // GDPR: the forwards are exported; the keys go with the erasure, and forwards of the erased user's messages
        // show the neutral marker.
        $export = $this->get(ChatPersonalData::class)->export($this->fresh($ana));
        self::assertNotSame([], $export['forwards']);
        self::assertSame($his->getUuid(), $export['forwards'][0]['source_message_uuid']);
        self::assertNotSame([], $this->em->getRepository(ChatIdempotencyKey::class)->findAll());
        $this->get(ChatPersonalData::class)->erase($this->fresh($luis));
        $this->em->clear();
        foreach ($this->em->getRepository(ChatMessage::class)->findBy(['forwarded' => true]) as $forward) {
            self::assertNull($forward->getForwardedFrom());
        }
        $this->get(ChatPersonalData::class)->erase($this->fresh($ana));
        self::assertSame([], $this->em->getRepository(ChatIdempotencyKey::class)->findAll());
    }

    public function testThreadsStayOutOfTheTimeline(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $chat = $this->get(ChatService::class);
        $csrf = $this->login($ana)['csrf'];
        $room = $this->conversation($this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation']['uuid']);
        $this->send(Request::METHOD_POST, '/chat/api/channel', $this->login($luis)['csrf'], 0, ['channelKey' => TestChannelProvider::KEY]);
        $this->send(Request::METHOD_POST, '/chat/api/channel', $this->login($bea)['csrf'], 0, ['channelKey' => TestChannelProvider::KEY]);
        $root = $chat->send($this->fresh($ana), $this->fresh($room), 'shall we?');
        $base = $this->base($room, $root);

        // Luis replies (a key is required), mentioning Bea: she follows the thread.
        $csrf = $this->login($luis)['csrf'];
        $this->send(Request::METHOD_POST, $base . '/thread', $csrf, 400, ['body' => 'yes']);
        $reply = $this->keyed(Request::METHOD_POST, $base . '/thread', $csrf, 't-1', 201, ['body' => 'yes @[Bea](' . $bea->getChatPublicId() . ')']);
        self::assertSame($root->getUuid(), $reply['message']['threadRootUuid']);
        self::assertSame(['count' => 1, 'lastReplyAt' => $reply['message']['createdAt']], $reply['root']['thread']);
        // A replay is the same reply; another body under the key is refused.
        $again = $this->keyed(Request::METHOD_POST, $base . '/thread', $csrf, 't-1', 200, ['body' => 'yes @[Bea](' . $bea->getChatPublicId() . ')']);
        self::assertSame($reply['message']['uuid'], $again['message']['uuid']);
        $this->keyed(Request::METHOD_POST, $base . '/thread', $csrf, 't-1', 422, ['body' => 'no']);
        $this->keyed(Request::METHOD_POST, $base . '/thread', $csrf, 't-0', 422, ['body' => '   ']);
        // A reply to a reply goes to the root.
        $second = $this->keyed(Request::METHOD_POST, $this->base($room, $this->message($reply['message']['uuid'])) . '/thread', $csrf, 't-2', 201, ['body' => 'and more']);
        self::assertSame($root->getUuid(), $second['message']['threadRootUuid']);
        self::assertSame(2, $second['root']['thread']['count']);
        $followers = array_map(static fn (ChatThreadRead $row): string => $row->getUser()->getChatDisplayName(), $this->em->getRepository(ChatThreadRead::class)->findAll());
        sort($followers);
        self::assertSame(['Ana', 'Bea', 'Luis'], $followers);

        // The main timeline and its unread count leave the replies out; the page tells each viewer about the thread.
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room->getUuid() . '/messages');
        $page = $this->decode();
        self::assertSame([$root->getUuid()], array_column($page['messages'], 'uuid'));
        self::assertSame(2, $page['threads'][$root->getUuid()]['count']);
        self::assertTrue($page['threads'][$root->getUuid()]['unread']);
        self::assertSame(['Luis'], array_column($page['threads'][$root->getUuid()]['repliers'], 'name'));
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations');
        self::assertSame(0, $this->decode()['unreadTotal']);

        // Ana opens the thread and reads it.
        $this->client->request(Request::METHOD_GET, $base . '/thread');
        $thread = $this->decode();
        self::assertSame(['yes @Bea', 'and more'], array_map(static fn (array $row): string => strip_tags($row['html']), $thread['replies']));
        self::assertSame(2, $thread['replyCount']);
        self::assertTrue($thread['following']);
        $csrf = $this->login($ana)['csrf'];
        $this->send(Request::METHOD_POST, $base . '/thread/read', $csrf, 0, ['messageId' => PHP_INT_MAX]);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room->getUuid() . '/messages');
        self::assertFalse($this->decode()['threads'][$root->getUuid()]['unread']);
        $this->client->request(Request::METHOD_GET, $this->base($room, $this->message($second['message']['uuid'])) . '/thread?before=' . $second['message']['id']);
        self::assertCount(1, $this->decode()['replies']);

        // Replies are not pinned; deleting one leaves the count.
        try {
            $chat->pin($this->fresh($ana), $this->message($reply['message']['uuid']), true);
            self::fail('a reply is pinned');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('not_pinnable', $exception->getMessage());
        }
        $chat->delete($this->fresh($luis), $this->message($second['message']['uuid']));
        $this->em->clear();
        self::assertSame(1, $this->message($root->getUuid())->getThreadReplyCount());
        self::assertContains('thread', $this->get(RecordingRealtime::class)->types());

        // The DDD façade.
        $actions = $this->get(ChatMessageActionsInterface::class);
        $view    = $actions->replyInThread($this->fresh($bea), $room->getUuid(), $root->getUuid(), 'me too', 'ddd-t');
        self::assertSame($root->getUuid(), $view->threadRootUuid);
        $page = $actions->thread($this->fresh($bea), $room->getUuid(), $view->uuid);
        self::assertSame($root->getUuid(), $page['root']->uuid);
        self::assertCount(3, $page['replies']);

        // GDPR: the thread rows are exported and erased.
        self::assertNotSame([], $this->get(ChatPersonalData::class)->export($this->fresh($bea))['thread_reads']);
        $this->get(ChatPersonalData::class)->erase($this->fresh($bea));
        self::assertCount(2, $this->em->getRepository(ChatThreadRead::class)->findAll());

        // The purge takes the thread with its root.
        $this->get(ChatMessageRepository::class)->purgeOlderThan(new DateTimeImmutable('+1 day'), 100);
        self::assertSame([], $this->em->getRepository(ChatMessage::class)->findAll());
        self::assertSame([], $this->em->getRepository(ChatThreadRead::class)->findAll());
    }

    public function testEditHistoryForTheAuthorAndModerators(): void
    {
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $agent = $this->user('Agent Smith');
        $chat  = $this->get(ChatService::class);
        $csrf  = $this->login($ana)['csrf'];
        $room  = $this->conversation($this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation']['uuid']);
        $said  = $chat->send($this->fresh($ana), $this->fresh($room), 'one');
        $base  = $this->base($room, $said);
        foreach (['two', 'two', 'three', 'four'] as $text) {
            $this->send(Request::METHOD_PATCH, $base, $csrf, 0, ['body' => $text]);
        }
        // The same text again is no edit; two previous texts are kept (max_revisions), newest first.
        $this->client->request(Request::METHOD_GET, $base . '/revisions');
        self::assertSame(['three', 'two'], array_column($this->decode()['revisions'], 'text'));
        $this->client->request(Request::METHOD_GET, $base . '/info');
        self::assertCount(2, $this->decode()['edits']);
        // Another member: 403; a moderator (an agent in a channel): the texts.
        $this->login($luis);
        $this->client->request(Request::METHOD_GET, $base . '/revisions');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('history_forbidden', $this->decode()['error']);
        $this->login($agent);
        $this->client->request(Request::METHOD_GET, $base . '/revisions');
        self::assertSame('<p>three</p>', $this->decode()['revisions'][0]['html']);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room->getUuid() . '/messages/00000000-0000-4000-8000-000000000000/revisions');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        // The DDD façade and the export.
        self::assertCount(2, $this->get(ChatMessageActionsInterface::class)->revisions($this->fresh($ana), $room->getUuid(), $said->getUuid()));
        self::assertCount(2, $this->get(ChatPersonalData::class)->export($this->fresh($ana))['revisions']);
        // A deleted message keeps no text.
        $chat->delete($this->fresh($ana), $this->fresh($said));
        self::assertSame([], $this->em->getRepository(ChatMessageRevision::class)->findAll());
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, $base . '/revisions');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testOptionalKeyOnSend(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        $csrf = $this->login($ana)['csrf'];
        $url  = '/chat/api/conversations/' . $dm->getUuid() . '/messages';
        $one  = $this->keyed(Request::METHOD_POST, $url, $csrf, 's-1', 201, ['body' => 'once']);
        $two  = $this->keyed(Request::METHOD_POST, $url, $csrf, 's-1', 200, ['body' => 'once']);
        self::assertSame($one['message']['uuid'], $two['message']['uuid']);
        self::assertTrue($two['replayed']);
        $this->keyed(Request::METHOD_POST, $url, $csrf, 's-1', 422, ['body' => 'twice']);
        $this->keyed(Request::METHOD_POST, $url, $csrf, 'bad key', 400, ['body' => 'x']);
        self::assertSame(1, $this->messageCount());
    }

    public function testTemplateWithEveryItem(): void
    {
        $config = $this->login($this->user('Ana'));
        self::assertTrue($config['menu']['items']['thread']);
        self::assertTrue($config['menu']['items']['forward']);
        self::assertSame(2, $config['menu']['forwardMaxTargets']);
        self::assertTrue($config['menu']['editHistory']);
        $html = $this->renderDockTemplates();
        self::assertStringContainsString('data-part="menu-thread"', $html);
        self::assertStringContainsString('data-part="menu-forward"', $html);
    }

    private function base(ChatConversation $conversation, ChatMessage $message): string
    {
        return '/chat/api/conversations/' . $conversation->getUuid() . '/messages/' . $message->getUuid();
    }

    private function messageCount(): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(m.id)')->from(ChatMessage::class, 'm')->getQuery()->getSingleScalarResult();
    }

    private function message(string $uuid): ChatMessage
    {
        $message = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(ChatMessage::class, $message);

        return $message;
    }

    private function conversation(string $uuid): ChatConversation
    {
        $conversation = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $uuid]);
        self::assertInstanceOf(ChatConversation::class, $conversation);

        return $conversation;
    }

    /**
     * @template T of ChatConversation|ChatMessage|TestUser
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(ChatConversation|ChatMessage|TestUser $entity): ChatConversation|ChatMessage|TestUser
    {
        $managed = $this->em->find($entity::class, $entity->getId());
        self::assertNotNull($managed);

        return $managed;
    }

    /**
     * @return array<string, mixed>
     */
    private function login(TestUser $user): array
    {
        $this->em->clear();
        $this->client->loginUser($this->fresh($user));
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, string $csrf, int $status = 0, array $body = []): array
    {
        return $this->keyed($method, $url, $csrf, null, $status, $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function keyed(string $method, string $url, string $csrf, ?string $key, int $status = 0, array $body = []): array
    {
        $server = ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'] + ($key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]);
        $this->client->request($method, $url, server: $server, content: json_encode($body, JSON_THROW_ON_ERROR));
        $code = $this->client->getResponse()->getStatusCode();
        if ($status !== 0) {
            self::assertSame($status, $code, $method . ' ' . $url . ': ' . $this->client->getResponse()->getContent());
        } else {
            self::assertLessThan(300, $code, (string) $this->client->getResponse()->getContent());
        }

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
