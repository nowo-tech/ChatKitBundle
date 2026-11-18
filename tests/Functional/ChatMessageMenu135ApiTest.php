<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Contract\ChatMessageActionsInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
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

/**
 * 1.35: the richer message menu on the server — quick reactions from the picker, mark unread from here (receipts never
 * go back), save for later and the Saved list (hidden while access is lost), message info (own messages, moderators,
 * reciprocal read times, counts above the size), the share-read-times preference, the menu template and the dock config.
 */
final class ChatMessageMenu135ApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return match ($this->name()) {
            'testDefaultsOfferNoStoredDataItems' => [],
            'testStrictReactionsWithoutPicker'   => ['profiles' => ['default' => ['message_menu' => ['quick_reactions' => ['picker' => false, 'emojis' => ['👍']]]]]],
            default                              => ['profiles' => ['default' => ['message_menu' => [
                'save'         => ['enabled' => true, 'max_per_user' => 2],
                'message_info' => ['enabled' => true, 'per_person_max_members' => 3],
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

    public function testDefaultsOfferNoStoredDataItems(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $his  = $chat->send($luis, $dm, 'hello');

        $config = $this->login($ana);
        self::assertSame(['reply' => true, 'quote' => true, 'copy_text' => true, 'copy_link' => true, 'save' => false, 'mark_unread' => true, 'pin' => true, 'info' => false, 'edit' => true, 'delete' => true], $config['menu']['items']);
        self::assertSame(['👍', '❤️', '😂', '😮', '😢', '🙏'], $config['menu']['quick']);
        self::assertFalse($config['menu']['saveDockEntry']);
        $base = '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $his->getUuid();
        // Off: 404 for save, the Saved list and info; the messages page has no `saved` key.
        $this->send(Request::METHOD_PUT, $base . '/save', $config['csrf'], 404);
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, $base . '/info');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/saved');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages');
        self::assertArrayNotHasKey('saved', $this->decode());
        self::assertSame(['items' => [], 'next' => null], $this->get(ChatMessageActionsInterface::class)->savedMessages($this->fresh($ana)));
        // A picker emoji (not configured) is a valid reaction with the picker on.
        self::assertSame([$ana->getChatPublicId()], $this->send(Request::METHOD_POST, $base . '/reactions', $config['csrf'], 0, ['emoji' => '🚀'])['reactions']['🚀']);
        // Anything else stays refused.
        $this->send(Request::METHOD_POST, $base . '/reactions', $config['csrf'], 422, ['emoji' => "\u{200B}"]);
        // The template: the quick reactions row and the 1.35 items, the sheet, no Saved entry in the dock.
        $html = $this->renderDockTemplates();
        self::assertStringContainsString('data-nowo-ui-menu-row', $html);
        self::assertStringContainsString('data-part="menu-react-more"', $html);
        self::assertStringContainsString('data-nowo-ui-sheet', $html);
        foreach (['quote', 'copy-text', 'mark-unread'] as $part) {
            self::assertStringContainsString('data-part="menu-' . $part . '"', $html);
        }
        self::assertStringNotContainsString('data-part="menu-save"', $html);
        self::assertStringNotContainsString('data-part="menu-info"', $html);
        self::assertStringNotContainsString('data-part="saved"', $html);
    }

    public function testStrictReactionsWithoutPicker(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $his  = $chat->send($luis, $dm, 'hello');
        $csrf = $this->login($ana)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $his->getUuid() . '/reactions', $csrf, 422, ['emoji' => '🚀']);
        $html = $this->renderDockTemplates();
        self::assertStringNotContainsString('data-part="menu-react-more"', $html);
        self::assertSame(1, substr_count($html, 'data-part="menu-react"'));
    }

    public function testMarkUnreadKeepsTheReceipts(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $chat     = $this->get(ChatService::class);
        $dm       = $chat->openDirect($ana, $luis);
        $first    = $chat->send($luis, $dm, 'one');
        $second   = $chat->send($luis, $dm, 'two');
        $third    = $chat->send($luis, $dm, 'three');
        $mine     = $chat->send($ana, $dm, 'mine');
        $realtime = $this->get(RecordingRealtime::class);
        $csrf     = $this->login($ana)['csrf'];
        $base     = '/chat/api/conversations/' . $dm->getUuid();
        $this->send(Request::METHOD_POST, $base . '/read', $csrf, 0, ['messageId' => $mine->getId()]);
        $realtime->published = [];

        // Not on one's own message; CSRF required.
        $this->send(Request::METHOD_POST, $base . '/messages/' . $mine->getUuid() . '/unread', $csrf, 404);
        $this->send(Request::METHOD_POST, $base . '/messages/' . $second->getUuid() . '/unread', 'forged', 403);
        self::assertSame($first->getId(), $this->send(Request::METHOD_POST, $base . '/messages/' . $second->getUuid() . '/unread', $csrf)['readUpTo']);
        $types = array_column(array_column($realtime->published, 'payload'), 'type');
        self::assertContains('unread', $types);
        self::assertContains('list', $types);
        self::assertNotContains('read', $types, 'the peer hears nothing');
        // Again from the same message: nothing moves (no event).
        $realtime->published = [];
        self::assertSame($first->getId(), $this->send(Request::METHOD_POST, $base . '/messages/' . $second->getUuid() . '/unread', $csrf)['readUpTo']);
        self::assertSame([], $realtime->published);
        // From the first message: nothing read at all.
        self::assertNull($this->send(Request::METHOD_POST, $base . '/messages/' . $first->getUuid() . '/unread', $csrf)['readUpTo']);

        // The unread count is back for Ana; Luis still sees Ana's receipt at her newest read.
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations');
        $row = array_values(array_filter($this->decode()['conversations'], static fn (array $c): bool => $c['uuid'] === $dm->getUuid()))[0];
        self::assertSame(3, $row['unread']);
        $this->login($luis);
        $this->client->request(Request::METHOD_GET, $base . '/messages');
        self::assertSame($mine->getId(), $this->decode()['readers'][0]['messageId']);

        // Ana reads up to the second again: only her own tabs hear it (the receipt is further already).
        $csrf                = $this->login($ana)['csrf'];
        $realtime->published = [];
        $this->send(Request::METHOD_POST, $base . '/read', $csrf, 0, ['messageId' => $second->getId()]);
        self::assertCount(1, $realtime->published);
        self::assertSame(['messageId' => $second->getId()], ['messageId' => $realtime->published[0]['payload']['messageId']]);
        // Caught up past the receipt: everyone hears it again, and the two cursors are one.
        $realtime->published = [];
        $this->send(Request::METHOD_POST, $base . '/read', $csrf, 0, ['messageId' => $mine->getId()]);
        self::assertNotSame([], $realtime->published);
        $row = $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $dm, 'user' => $ana]);
        self::assertInstanceOf(ChatParticipant::class, $row);
        $this->em->refresh($row);
        self::assertSame($mine->getId(), $row->getReceiptMessageId());
        self::assertSame($third->getId() + 1, $mine->getId());
    }

    public function testSaveAndTheSavedList(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $chat     = $this->get(ChatService::class);
        $dm       = $chat->openDirect($ana, $luis);
        $one      = $chat->send($luis, $dm, '**one**');
        $two      = $chat->send($luis, $dm, 'two');
        $three    = $chat->send($luis, $dm, 'three');
        $config   = $this->login($ana);
        $csrf     = $config['csrf'];
        $save     = fn (ChatMessage $m, string $method = Request::METHOD_PUT, int $status = 0): array => $this->send($method, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $m->getUuid() . '/save', $csrf, $status);
        $realtime = $this->get(RecordingRealtime::class);

        self::assertTrue($config['menu']['saveDockEntry']);
        self::assertTrue($save($one)['saved']);
        self::assertTrue($save($one)['saved'], 'idempotent');
        self::assertTrue($save($two)['saved']);
        // max_per_user: 2.
        self::assertSame('too_many_saved', $save($three, status: 422)['error']);
        self::assertContains('saved', array_column(array_column($realtime->published, 'payload'), 'type'));
        // The messages page says which are saved.
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages');
        self::assertEqualsCanonicalizing([$one->getUuid(), $two->getUuid()], $this->decode()['saved']);

        // The list: newest save first, with the conversation title.
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        $list = $this->decode();
        self::assertSame([$two->getUuid(), $one->getUuid()], array_column(array_column($list['items'], 'message'), 'uuid'));
        self::assertSame('Luis', $list['items'][0]['conversation']['title']);
        self::assertNull($list['next']);
        // The page renders it (works without JavaScript).
        $this->client->request(Request::METHOD_GET, '/chat/saved');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $page = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<strong>one</strong>', $page);
        self::assertStringContainsString('?message=' . $one->getUuid(), $page);
        self::assertStringContainsString('data-nowo-chat-unsave="/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $one->getUuid() . '/save"', $page);

        // Unsave (idempotent), then a deleted message leaves the list.
        self::assertFalse($save($two, Request::METHOD_DELETE)['saved']);
        self::assertFalse($save($two, Request::METHOD_DELETE)['saved']);
        $chat->delete($this->fresh($luis), $this->fresh($one));
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        self::assertSame([], $this->decode()['items']);
        // A deleted message cannot be saved (one saved before stays saved: idempotent); an unknown one is 404.
        self::assertTrue($save($this->fresh($one))['saved']);
        $chat->delete($this->fresh($luis), $this->fresh($two));
        self::assertSame('not_savable', $save($this->fresh($two), status: 422)['error']);
        $this->send(Request::METHOD_PUT, '/chat/api/conversations/' . $dm->getUuid() . '/messages/00000000-0000-4000-8000-000000000000/save', $csrf, 404);
        // Someone outside the conversation: 404.
        $csrf = $this->login($this->user('Bea'))['csrf'];
        $this->send(Request::METHOD_PUT, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $three->getUuid() . '/save', $csrf, 404);
        $this->client->request(Request::METHOD_GET, '/chat/saved');
        self::assertStringContainsString('data-testid="chat-saved-empty"', (string) $this->client->getResponse()->getContent());
    }

    public function testSavedItemsHideWhileAccessIsLost(): void
    {
        $bea  = $this->user('Bea');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $csrf = $this->login($bea)['csrf'];
        $room = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation'];
        $said = $chat->send($this->fresh($luis), $this->conversation($room['uuid']), 'in the channel');
        $this->send(Request::METHOD_PUT, '/chat/api/conversations/' . $room['uuid'] . '/messages/' . $said->getUuid() . '/save', $csrf);
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        self::assertCount(1, $this->decode()['items']);

        // Bea loses the channel (a "Stranger" may not use it): the row is hidden, not removed.
        $this->rename($bea, 'Stranger Bea');
        $this->client->loginUser($this->fresh($bea));
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        self::assertSame([], $this->decode()['items']);
        self::assertCount(1, $this->em->getRepository(ChatSavedMessage::class)->findAll());
        $this->rename($bea, 'Bea');
        $this->client->loginUser($this->fresh($bea));
        $this->client->request(Request::METHOD_GET, '/chat/api/saved');
        self::assertCount(1, $this->decode()['items']);
    }

    public function testMessageInfoAndReciprocalReadTimes(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $mine = $chat->send($ana, $dm, 'did you read it?');
        $info = static fn (ChatConversation $c, ChatMessage $m): string => '/chat/api/conversations/' . $c->getUuid() . '/messages/' . $m->getUuid() . '/info';

        // Not read yet.
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, $info($dm, $mine));
        $answer = $this->decode();
        self::assertSame('people', $answer['mode']);
        self::assertSame(1, $answer['memberCount']);
        self::assertSame(0, $answer['readCount']);
        self::assertFalse($answer['readers'][0]['read']);
        $his = $chat->send($this->fresh($luis), $this->fresh($dm), 'his');
        // Someone else's message: 404 (own messages only).
        $this->client->request(Request::METHOD_GET, $info($dm, $his));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // Luis reads it: Ana sees when.
        $csrf = $this->login($luis)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/read', $csrf, 0, ['messageId' => $his->getId()]);
        // His own message moved his receipt already (a read time, compacted with this read).
        self::assertCount(1, $this->marksOf($luis));
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, $info($dm, $mine));
        $answer = $this->decode();
        self::assertSame(1, $answer['readCount']);
        self::assertTrue($answer['readTimes']);
        self::assertNotNull($answer['readers'][0]['readAt']);
        self::assertSame('Luis', $answer['readers'][0]['name']);

        // Ana stops sharing read times: she sees nobody's (reciprocal); the read state stays.
        $csrf = $this->login($ana)['csrf'];
        self::assertFalse($this->send(Request::METHOD_POST, '/chat/api/settings', $csrf, 0, ['key' => 'shareReadTimes', 'value' => false])['prefs']['shareReadTimes']);
        $this->client->request(Request::METHOD_GET, $info($dm, $mine));
        $answer = $this->decode();
        self::assertTrue($answer['readers'][0]['read']);
        self::assertNull($answer['readers'][0]['readAt']);
        self::assertFalse($answer['readTimes']);
        $this->send(Request::METHOD_POST, '/chat/api/settings', $csrf, 0, ['key' => 'shareReadTimes', 'value' => true]);

        // Luis stops sharing: his stored read times go, and none are written again.
        $csrf = $this->login($luis)['csrf'];
        $this->send(Request::METHOD_POST, '/chat/api/settings', $csrf, 0, ['key' => 'shareReadTimes', 'value' => false]);
        self::assertSame([], $this->marksOf($luis));
        $later = $chat->send($this->fresh($ana), $this->fresh($dm), 'and this one?');
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/read', $csrf, 0, ['messageId' => $later->getId()]);
        self::assertSame([], $this->marksOf($luis));
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, $info($dm, $later));
        $answer = $this->decode();
        self::assertTrue($answer['readers'][0]['read']);
        self::assertNull($answer['readers'][0]['readAt']);
    }

    public function testMessageInfoCountsAboveTheSizeAndModerators(): void
    {
        $ana   = $this->user('Ana');
        $users = [$this->user('Luis'), $this->user('Bea'), $this->user('Carl')];
        $agent = $this->user('Agent Smith');
        $chat  = $this->get(ChatService::class);
        $csrf  = $this->login($ana)['csrf'];
        $room  = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation'];
        $said  = $chat->send($this->fresh($ana), $this->conversation($room['uuid']), 'hello all');
        $chat->edit($this->fresh($said), 'hello everyone');
        foreach ($users as $user) {
            $this->send(Request::METHOD_POST, '/chat/api/channel', $this->login($user)['csrf'], 0, ['channelKey' => TestChannelProvider::KEY]);
            $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $room['uuid'] . '/read', $this->login($user)['csrf'], 0, ['messageId' => $said->getId()]);
        }
        // Four people besides Ana (the agent included) > per_person_max_members 3: counts only, no read times stored.
        self::assertSame([], $this->em->getRepository(ChatReadMark::class)->findAll());
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room['uuid'] . '/messages/' . $said->getUuid() . '/info');
        $answer = $this->decode();
        self::assertSame('counts', $answer['mode']);
        self::assertSame(4, $answer['memberCount']);
        self::assertSame(3, $answer['readCount']);
        self::assertSame([], $answer['readers']);
        self::assertNotNull($answer['editedAt']);
        // A support agent (moderator) may open the info of anyone's message in a channel.
        $this->login($agent);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room['uuid'] . '/messages/' . $said->getUuid() . '/info');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // Another member may not.
        $this->login($users[0]);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room['uuid'] . '/messages/' . $said->getUuid() . '/info');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTemplateAndDockWithEveryItem(): void
    {
        $this->login($this->user('Ana'));
        $html = $this->renderDockTemplates();
        foreach (['menu-save', 'menu-info', 'saved'] as $part) {
            self::assertStringContainsString('data-part="' . $part . '"', $html);
        }
        // Groups are separated: reactions | reply quote | copy … pin | info edit | delete.
        self::assertSame(4, substr_count($html, 'data-part="menu-separator"'));
        self::assertStringContainsString('href="/chat/saved"', $html);
    }

    /**
     * @return list<ChatReadMark>
     */
    private function marksOf(TestUser $user): array
    {
        /** @var list<ChatReadMark> $marks */
        $marks = $this->em->createQueryBuilder()->select('r')->from(ChatReadMark::class, 'r')->innerJoin('r.participant', 'p')
            ->where('IDENTITY(p.user) = :u')->setParameter('u', $user->getId())->getQuery()->getResult();

        return $marks;
    }

    private function rename(TestUser $user, string $name): void
    {
        $meta = $this->em->getClassMetadata(TestUser::class);
        $this->em->getConnection()->update($meta->getTableName(), [$meta->getColumnName('name') => $name], [$meta->getColumnName('id') => $user->getId()]);
        $this->em->clear();
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
        // A fully loaded user (not a lazy reference another row loaded): the session keeps it.
        $this->em->clear();
        $this->client->loginUser($this->fresh($user));
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr((string) $this->client->getResponse()->getContent(), 0, 2000));
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
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
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
