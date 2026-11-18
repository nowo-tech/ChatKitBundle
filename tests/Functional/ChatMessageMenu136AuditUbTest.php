<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatThreads;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestGrantReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function assert;
use function json_decode;
use function json_encode;
use function sort;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * 1.36, audit UB: previous texts and forwards never carry a #reference title the reader (or forwarder) may not open, an
 * erasure scrubs previous texts too, a reply deleted twice leaves its root's count once, a deleted root takes no reply,
 * group mentions do not make anyone follow a thread, and a direct conversation a failed forward opened goes again.
 */
final class ChatMessageMenu136AuditUbTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['message_menu' => [
            'forward'      => ['enabled' => true, 'max_targets' => 3],
            'threads'      => ['enabled' => true],
            'edit_history' => ['enabled' => true],
            'message_info' => ['enabled' => true],
        ]]]];
    }

    protected function grantReferences(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
        $this->get(TestGrantReferenceProvider::class)->createTable();
    }

    /** UB-1: the previous texts never give a moderator the title of an item they may not open. */
    public function testRevisionsHideReferenceTitles(): void
    {
        $ana   = $this->user('Ana');
        $agent = $this->user('Agent Smith');
        $this->grant($ana, '7');
        $csrf = $this->login($ana)['csrf'];
        $room = $this->conversation($this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation']['uuid']);
        $said = $this->get(ChatService::class)->send($this->fresh($ana), $this->fresh($room), 'see #[x](doc:7)');
        self::assertStringContainsString('Doc 7 (granted)', $this->fresh($said)->getBody());
        $base = '/chat/api/conversations/' . $room->getUuid() . '/messages/' . $said->getUuid();
        $this->send(Request::METHOD_PATCH, $base, $csrf, 0, ['body' => 'nothing to see']);
        // The author may open it: the title.
        $this->client->request(Request::METHOD_GET, $base . '/revisions');
        self::assertSame('see #Doc 7 (granted)', $this->decode()['revisions'][0]['text']);

        $this->login($agent);
        $this->client->request(Request::METHOD_GET, $base . '/revisions');
        $raw = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Doc 7', $raw);
        self::assertStringNotContainsString('"body"', $raw);
        self::assertStringContainsString('nowo-chat-ref--restricted', $this->decode()['revisions'][0]['html']);
    }

    /** UB-2: a forward copies a #reference the forwarder may not open without its title. */
    public function testForwardDropsTitlesTheForwarderCannotOpen(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->grant($ana, '7');
        $csrf = $this->login($ana)['csrf'];
        $room = $this->conversation($this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation']['uuid']);
        $chat = $this->get(ChatService::class);
        $said = $chat->send($this->fresh($ana), $this->fresh($room), 'see #[x](doc:7) and `#[y](doc:7)`');
        $dm   = $chat->openDirect($this->fresh($luis), $this->fresh($ana));

        $csrf = $this->login($luis)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY]);
        $out = $this->keyed(Request::METHOD_POST, '/chat/api/conversations/' . $room->getUuid() . '/messages/' . $said->getUuid() . '/forward', $csrf, 'ub-2', 201, ['targets' => [['conversationUuid' => $dm->getUuid()]]]);
        self::assertStringNotContainsString('Doc 7 (granted)', json_encode($out, JSON_THROW_ON_ERROR));
        $copy = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $out['messages'][0]['message']['uuid']]);
        self::assertInstanceOf(ChatMessage::class, $copy);
        // The token stays (for readers who may open the item); code spans stay as written.
        self::assertSame('see #[…](doc:7) and `#[y](doc:7)`', $copy->getBody());
        self::assertStringNotContainsString('Doc 7 (granted)', json_encode($this->get(ChatPersonalData::class)->export($this->fresh($luis)), JSON_THROW_ON_ERROR));
        // Ana may open the item: she sees its card in the copy.
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $copy->getUuid());
        self::assertStringContainsString('Doc 7 (granted)', $this->decode()['message']['html']);
    }

    /** UB-3: erasing a person scrubs their mention tokens from other people's previous texts too. */
    public function testErasureScrubsPreviousTexts(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $said = $chat->send($this->fresh($ana), $this->fresh($dm), 'ping @[Luis](' . $luis->getChatPublicId() . ') now');
        $chat->edit($this->fresh($said), 'ping @[Luis](' . $luis->getChatPublicId() . ') later');
        $chat->edit($this->fresh($said), 'no mention any more');
        // A previous text that cannot be scanned (invalid UTF-8) is deleted rather than kept with the id.
        $meta = $this->em->getClassMetadata(ChatMessageRevision::class);
        $this->em->getConnection()->insert($meta->getTableName(), ['message_id' => $said->getId(), 'body' => "bad \xC3\x28 @[Luis](" . $luis->getChatPublicId() . ')', 'edited_at' => '2026-11-18 10:00:00']);
        $this->get(ChatPersonalData::class)->erase($this->fresh($luis));
        $this->em->clear();
        $texts = array_map(static fn (ChatMessageRevision $row): string => $row->getBody(), $this->em->getRepository(ChatMessageRevision::class)->findAll());
        sort($texts);
        self::assertSame(['ping @… later', 'ping @… now'], $texts);
    }

    /** UB-4: a reply deleted twice (two tabs past the voter) leaves its root's count once; a deleted root takes no reply. */
    public function testDoubleDeleteAndDeletedRoot(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatService::class);
        $threads = $this->get(ChatThreads::class);
        $dm      = $chat->openDirect($ana, $luis);
        $root    = $chat->send($this->fresh($ana), $this->fresh($dm), 'root');
        [$one]   = $threads->reply($this->fresh($ana), $this->fresh($root), 'one', 'ub4-1');
        $threads->reply($this->fresh($ana), $this->fresh($root), 'two', 'ub4-2');
        $this->em->clear();
        $stale = $this->fresh($one);
        $chat->delete($this->fresh($ana), $this->fresh($one));
        $this->em->clear();
        $chat->delete($this->fresh($ana), $stale);
        $this->em->clear();
        self::assertSame(1, $this->fresh($root)->getThreadReplyCount());

        $staleRoot = $this->fresh($root);
        $this->em->clear();
        $chat->delete($this->fresh($ana), $this->fresh($root));
        $this->em->clear();
        try {
            $threads->reply($this->fresh($ana), $staleRoot, 'too late', 'ub4-3');
            self::fail('a deleted root took a reply');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('not_threadable', $exception->getMessage());
        }
        self::assertSame(1, $this->fresh($root)->getThreadReplyCount());
    }

    /** UB-5: @channel / @here in a reply notify but make nobody follow; an explicit @mention does. */
    public function testGroupMentionsDoNotFollow(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $csrf = $this->login($ana)['csrf'];
        $room = $this->conversation($this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation']['uuid']);
        foreach ([$luis, $bea] as $member) {
            $this->send(Request::METHOD_POST, '/chat/api/channel', $this->login($member)['csrf'], 0, ['channelKey' => TestChannelProvider::KEY]);
        }
        $root    = $this->get(ChatService::class)->send($this->fresh($ana), $this->fresh($room), 'root');
        $threads = $this->get(ChatThreads::class);
        $threads->reply($this->fresh($ana), $this->fresh($root), 'hey @channel and @here', 'ub5-1');
        self::assertSame(['Ana'], $this->followers());
        $threads->reply($this->fresh($ana), $this->fresh($root), 'you @[Bea](' . $bea->getChatPublicId() . ')', 'ub5-2');
        self::assertSame(['Ana', 'Bea'], $this->followers());
    }

    /** UB-7: a direct conversation a forward opened goes again when the forward fails; one with messages stays. */
    public function testFailedForwardDropsTheDirectConversationItOpened(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bea  = $this->user('Bea');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $said = $chat->send($this->fresh($ana), $this->fresh($dm), 'forward me');
        $csrf = $this->login($ana)['csrf'];
        $this->keyed(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $said->getUuid() . '/forward', $csrf, 'ub7', 422, ['targets' => [['contactUuid' => $bea->getChatPublicId()]], 'comment' => str_repeat('x', ChatMessage::MAX_LENGTH + 1)]);
        $this->em->clear();
        self::assertNull($this->get(ChatConversationRepository::class)->findDirect($this->fresh($ana), $this->fresh($bea)));
        self::assertFalse($chat->dropEmptyDirect($this->fresh($dm)), 'a conversation with messages stays');
    }

    /**
     * @return list<string>
     */
    private function followers(): array
    {
        $this->em->clear();
        $names = array_map(static fn (ChatThreadRead $row): string => $row->getUser()->getChatDisplayName(), $this->em->getRepository(ChatThreadRead::class)->findAll());
        sort($names);

        return $names;
    }

    private function grant(TestUser $user, string $item): void
    {
        $this->em->getConnection()->insert(TestGrantReferenceProvider::TABLE, ['user_id' => $user->getId(), 'item_id' => $item]);
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
