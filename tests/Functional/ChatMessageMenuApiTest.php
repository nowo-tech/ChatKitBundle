<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.33: the message menu (Reply, Edit, Copy link, Pin / Unpin, Delete) is only a view of what the server allows. Every
 * item's endpoint checks again: edit and delete for the author within the 15-minute window, pin for who may pin, the new
 * message link for whoever sees the message (POST, CSRF header, same origin). Reply sends through the send endpoint.
 */
final class ChatMessageMenuApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testEditAndDeleteMatrix(): void
    {
        $ana   = $this->user('Ana');
        $luis  = $this->user('Luis');
        $chat  = $this->get(ChatService::class);
        $dm    = $chat->openDirect($ana, $luis);
        $mine  = $chat->send($ana, $dm, 'mine, fresh');
        $old   = $chat->send($ana, $dm, 'mine, old');
        $other = $chat->send($luis, $dm, 'his');
        $this->age($old, '-16 minutes');
        $base = '/chat/api/conversations/' . $dm->getUuid() . '/messages/';

        $csrf = $this->login($ana)['csrf'];
        // Author, inside the window: edit and delete.
        self::assertSame('<p>edited</p>', $this->send(Request::METHOD_PATCH, $base . $mine->getUuid(), $csrf, 0, ['body' => 'edited'])['message']['html']);
        // Author, outside the window: 404 for both (the item is not shown either).
        $this->send(Request::METHOD_PATCH, $base . $old->getUuid(), $csrf, 404, ['body' => 'late']);
        $this->send(Request::METHOD_DELETE, $base . $old->getUuid(), $csrf, 404);
        // Not the author: 404 for both, inside the window.
        $this->send(Request::METHOD_PATCH, $base . $other->getUuid(), $csrf, 404, ['body' => 'not mine']);
        $this->send(Request::METHOD_DELETE, $base . $other->getUuid(), $csrf, 404);
        // No or a forged CSRF header: 403.
        $this->send(Request::METHOD_PATCH, $base . $mine->getUuid(), 'forged', 403, ['body' => 'x']);
        $this->send(Request::METHOD_DELETE, $base . $mine->getUuid(), '', 403);
        self::assertTrue($this->send(Request::METHOD_DELETE, $base . $mine->getUuid(), $csrf)['message']['deleted']);
        // A deleted message is neither edited nor deleted again.
        $this->send(Request::METHOD_PATCH, $base . $mine->getUuid(), $csrf, 404, ['body' => 'again']);
        $this->send(Request::METHOD_DELETE, $base . $mine->getUuid(), $csrf, 404);

        // Luis may edit his own message; Ana's old one stays out of reach for him too.
        $csrf = $this->login($luis)['csrf'];
        $this->send(Request::METHOD_PATCH, $base . $other->getUuid(), $csrf, 0, ['body' => 'his, edited']);
        $this->send(Request::METHOD_PATCH, $base . $old->getUuid(), $csrf, 404, ['body' => 'x']);
    }

    public function testPinMatrix(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $agent   = $this->user('Agent Smith');
        $bea     = $this->user('Bea');
        $chat    = $this->get(ChatService::class);
        $dm      = $chat->openDirect($ana, $luis);
        $his     = $chat->send($luis, $dm, 'pin me');
        $support = $chat->openSupport($ana);
        $asked   = $chat->send($ana, $support, 'help');
        $pin     = static fn (ChatConversation $c, ChatMessage $m): string => '/chat/api/conversations/' . $c->getUuid() . '/messages/' . $m->getUuid() . '/pin';

        // Direct: both people pin, someone else's message included; CSRF required.
        $csrf = $this->login($ana)['csrf'];
        $this->send(Request::METHOD_POST, $pin($dm, $his), 'forged', 403);
        self::assertTrue($this->send(Request::METHOD_POST, $pin($dm, $his), $csrf)['canPin']);
        $this->send(Request::METHOD_DELETE, $pin($dm, $his), $csrf);
        // Support: the requester may not pin (403), an agent may.
        $this->send(Request::METHOD_POST, $pin($support, $asked), $csrf, 403);
        // Channels: nobody without a ChatPinPolicyInterface.
        $channel = $this->send(Request::METHOD_POST, '/chat/api/channel', $csrf, 0, ['channelKey' => TestChannelProvider::KEY])['conversation'];
        self::assertFalse($channel['canPin']);
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $channel['uuid'] . '/messages/' . $chat->send($this->fresh($ana), $this->conversation($channel['uuid']), 'in the channel')->getUuid() . '/pin', $csrf, 403);

        $csrf = $this->login($agent)['csrf'];
        $this->send(Request::METHOD_POST, $pin($support, $asked), $csrf);
        // Someone outside the conversation: 404.
        $csrf = $this->login($bea)['csrf'];
        $this->send(Request::METHOD_POST, $pin($dm, $his), $csrf, 404);
    }

    public function testMessageLink(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $bea    = $this->user('Bea');
        $chat   = $this->get(ChatService::class);
        $dm     = $chat->openDirect($ana, $luis);
        $his    = $chat->send($luis, $dm, 'link me');
        $gone   = $chat->send($ana, $dm, 'soon deleted');
        $old    = $chat->send($luis, $dm, 'old but linkable');
        $link   = static fn (ChatMessage $m): string => '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $m->getUuid() . '/link';
        $config = $this->login($ana);
        $csrf   = $config['csrf'];
        $chat->delete($this->fresh($ana), $this->fresh($gone));
        $this->age($old, '-2 days');

        self::assertSame('/chat/api/conversations/__uuid__/messages/__message__/link', $config['endpoints']['messageLink']);
        // Anyone who sees the message, any age: the address of the full-page chat scrolled to it.
        $path = $this->send(Request::METHOD_POST, $link($his), $csrf, 0, [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin'])['path'];
        self::assertSame('/chat/' . $dm->getUuid() . '?message=' . $his->getUuid(), $path);
        // Audit SQ-I1: a forged Host never reaches the answer (a path, no host).
        self::assertSame($path, $this->send(Request::METHOD_POST, $link($his), $csrf, 0, [], ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example'])['path']);
        // The browser's history now points at that host: back to localhost for the relative URLs below.
        $this->client->request(Request::METHOD_GET, 'http://localhost/page');
        // Audit SQ-L2: Sec-Fetch-Site decides when sent (a TLS-terminating proxy without trusted_proxies changes the
        // scheme seen here, not the browser's same-origin verdict); Origin is compared only without it.
        $this->send(Request::METHOD_POST, $link($his), $csrf, 0, [], ['HTTP_ORIGIN' => 'https://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->send(Request::METHOD_POST, $link($his), $csrf, 0, [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->send(Request::METHOD_POST, $link($old), $csrf);
        // A deleted message: 404.
        $this->send(Request::METHOD_POST, $link($gone), $csrf, 404);
        // CSRF and same origin.
        $this->send(Request::METHOD_POST, $link($his), 'forged', 403);
        self::assertSame('cross_origin', $this->send(Request::METHOD_POST, $link($his), $csrf, 403, [], ['HTTP_ORIGIN' => 'https://evil.example'])['error']);
        self::assertSame('cross_origin', $this->send(Request::METHOD_POST, $link($his), $csrf, 403, [], ['HTTP_SEC_FETCH_SITE' => 'cross-site'])['error']);
        self::assertSame('cross_origin', $this->send(Request::METHOD_POST, $link($his), $csrf, 403, [], ['HTTP_SEC_FETCH_SITE' => 'same-site'])['error']);
        // GET is not a route (a link never changes or reveals anything on a GET).
        $this->client->request(Request::METHOD_GET, $link($his));
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
        // Someone outside the conversation, or a guest.
        $csrf = $this->login($bea)['csrf'];
        $this->send(Request::METHOD_POST, $link($his), $csrf, 404);
        $this->client->getCookieJar()->clear();
        $this->send(Request::METHOD_POST, $link($his), $csrf, 401);
    }

    public function testReplySendsThroughTheSendEndpoint(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $chat->send($luis, $dm, 'question?');

        $csrf = $this->login($ana)['csrf'];
        $sent = $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages', $csrf, 201, ['body' => "> Luis: question?\n\nanswer"])['message'];
        self::assertSame('<blockquote><p>Luis: question?</p></blockquote><p>answer</p>', $sent['html']);
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $dm->getUuid() . '/messages', 'forged', 403, ['body' => 'x']);
    }

    public function testStoredHtmlLosesUiKitAttributes(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $luis);
        $sent = $chat->send($luis, $dm, 'stored');
        // HTML stored by another writer (an older version, the application): the presenter strips UiKit's attributes.
        $meta = $this->em->getClassMetadata(ChatMessage::class);
        $this->em->getConnection()->update($meta->getTableName(), [$meta->getColumnName('bodyHtml') => '<p data-nowo-ui-context-menu="admin-menu" data-nowo-ui-card>stored</p>'], [$meta->getColumnName('id') => $sent->getId()]);

        $this->login($ana);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $dm->getUuid() . '/messages/' . $sent->getUuid());
        self::assertSame('<p>stored</p>', $this->decode()['message']['html']);
        $this->client->request(Request::METHOD_GET, '/chat/' . $dm->getUuid());
        self::assertStringNotContainsString('admin-menu', (string) $this->client->getResponse()->getContent());
    }

    public function testTheMessageTemplateHasTheMenu(): void
    {
        $html = $this->renderDockTemplates();
        self::assertMatchesRegularExpression('~<article class="nowo-chat-msg" tabindex="0" data-nowo-ui-context-menu-scope>~', $html);
        self::assertStringContainsString('data-part="menu" hidden', $html);
        self::assertStringContainsString('aria-label="More actions"', $html);
        foreach (['reply' => 'Reply', 'edit' => 'Edit', 'copy-link' => 'Copy link', 'pin' => 'Pin', 'delete' => 'Delete'] as $part => $label) {
            self::assertMatchesRegularExpression('~<a\s[^>]*data-part="menu-' . $part . '" hidden="hidden"[^>]*>' . $label . '</a>~', $html);
        }
        // Default: UiKit's theme flag (off here) decides, so the kebab is not a context-menu source.
        self::assertStringNotContainsString('data-nowo-ui-context-menu-source', $html);
        self::assertStringNotContainsString('data-nowo-ui-context-menu="off"', $html);
        self::assertStringNotContainsString('popovertarget', $html, 'a cloned template never carries a popover id');
    }

    private function age(ChatMessage $message, string $modifier): void
    {
        $meta = $this->em->getClassMetadata(ChatMessage::class);
        $this->em->getConnection()->update(
            $meta->getTableName(),
            [$meta->getColumnName('createdAt') => (new DateTimeImmutable($modifier))->format('Y-m-d H:i:s')],
            [$meta->getColumnName('id') => $message->getId()],
        );
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
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, string $csrf, int $status = 0, array $body = [], array $headers = []): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'] + $headers, content: json_encode($body, JSON_THROW_ON_ERROR));
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
