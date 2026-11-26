<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function range;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;
use const UPLOAD_ERR_INI_SIZE;

/**
 * 2.7 (spec 259): the attachment routes' answers at their edges: malformed `attachments` fields, PHP's own size
 * refusal, unknown files on every route, `bind_session: false` URLs, the no-JS page listing a message's files with
 * signed links, and the client API's context check on downloads.
 */
final class ChatAttachmentRoutesTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string UNKNOWN = '01a1f09f-0000-7000-8000-00000000beef';

    private KernelBrowser $client;

    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig([
            'client_api'  => ['writes' => true, 'limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000, 'writes_per_token_per_minute' => 100_000]],
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class, 'download' => ['bind_session' => false]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testDockRouteEdges(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $this->get(ChatService::class)->openChannel($luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $base = '/chat/api/conversations/' . $channel->getUuid();
        $csrf = $this->login($ana);

        foreach (['x', ['a' => self::UNKNOWN], range(1, 21), ['not-a-uuid']] as $field) {
            $this->client->request('POST', $base . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => 'x', 'attachments' => $field], JSON_THROW_ON_ERROR));
            self::assertSame(422, $this->client->getResponse()->getStatusCode());
            self::assertSame('attachment_invalid', $this->decodeClient()['error']);
        }
        // PHP refused the body size itself.
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-route-');
        file_put_contents($path, 'x');
        $this->client->request('POST', $base . '/attachments', [], ['file' => new UploadedFile($path, 'a.txt', null, UPLOAD_ERR_INI_SIZE, true)], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertSame(413, $this->client->getResponse()->getStatusCode());
        // Unknown files and conversations: 404 everywhere.
        foreach ([['GET', '/chat/api/attachments/' . self::UNKNOWN], ['GET', '/chat/api/conversations/' . self::UNKNOWN . '/attachments/drafts'], ['DELETE', '/chat/api/attachments/' . self::UNKNOWN]] as [$method, $url]) {
            $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), $url);
        }
        $this->client->request('POST', '/chat/api/conversations/' . self::UNKNOWN . '/attachments', [], ['file' => new UploadedFile($this->file('hello world'), 'a.txt', null, null, true)], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // bind_session: false — the URL names its reader and works without the session (5 minutes, re-checked).
        $file = $this->sent($ana, $channel->getUuid(), 'shared');
        $this->login($luis);
        $url = $this->getJson('/chat/api/attachments/' . $file)['url'];
        self::assertStringContainsString('v=' . $luis->getChatPublicId(), (string) $url);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', (string) $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', str_replace('v=' . $luis->getChatPublicId(), 'v=' . $ana->getChatPublicId(), (string) $url));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', str_replace('v=' . $luis->getChatPublicId(), 'v=', (string) $url));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // The no-JS page lists the message's files with links signed for the reader.
        $this->login($luis);
        $page = $this->client->request('GET', '/chat/' . $channel->getUuid());
        self::assertCount(1, $page->filter('.nowo-chat-files .nowo-chat-file__link'));
        self::assertStringContainsString('/chat/files/' . $file . '/original?', (string) $page->filter('.nowo-chat-file__link')->attr('href'));
    }

    public function testClientApiEdges(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->tokens()->grant('ana', $ana, [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE], null, 'tok-ana');
        $this->tokens()->grant('luis', $luis, [ChatClientPrincipal::SCOPE_READ], null, 'tok-luis');
        $dm      = $this->get(ChatService::class)->openDirect($ana, $luis);
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $api     = '/api/chat/v1';

        self::assertSame([], $this->ok('GET', $api . '/conversations/' . $dm->getUuid() . '/attachments/drafts', 'luis')['attachments']);
        self::assertSame(404, $this->call('GET', $api . '/conversations/' . $channel->getUuid() . '/attachments/drafts', 'luis')->getStatusCode());
        self::assertSame(404, $this->call('GET', $api . '/attachments/' . self::UNKNOWN, 'ana')->getStatusCode());
        self::assertSame(404, $this->call('DELETE', $api . '/attachments/' . self::UNKNOWN, 'ana')->getStatusCode());
        self::assertSame(404, $this->call('GET', $api . '/files/' . self::UNKNOWN . '/original?e=1&s=x', 'ana')->getStatusCode());
        // PHP refused the body size itself.
        self::assertSame(413, $this->upload($api . '/conversations/' . $dm->getUuid() . '/attachments', 'ana', UPLOAD_ERR_INI_SIZE)->getStatusCode());
        // A file of a channel conversation is out of the personal context: its download is refused there.
        $file = $this->sent($ana, $channel->getUuid(), 'channel file');
        self::assertSame(404, $this->call('GET', $api . '/attachments/' . $file, 'ana')->getStatusCode());
        self::assertSame(404, $this->call('GET', $api . '/files/' . $file . '/original?e=1&s=x', 'ana')->getStatusCode());
        // A send with a file still scanning: 409 attachment_not_ready, nothing written.
        $draft = $this->get(ChatAttachments::class)->upload($ana, $dm->getUuid(), $this->file('later'), 'later.txt')->uuid;
        $sent  = $this->call('POST', $api . '/conversations/' . $dm->getUuid() . '/messages', 'ana', 'personal', json_encode(['text' => 'x', 'attachments' => [$draft]], JSON_THROW_ON_ERROR), ['Idempotency-Key' => 'n1']);
        self::assertError($sent, 409, 'attachment_not_ready');
        // A file blocked after its URL was signed is dropped from the message's list.
        $clean = $this->sent($ana, $dm->getUuid(), 'to block');
        $this->get(ChatAttachments::class)->block($clean);
        $page = $this->ok('GET', $api . '/conversations/' . $dm->getUuid() . '/messages', 'ana');
        self::assertSame([], $page['messages'][0]['attachments']);
    }

    private function sent(TestUser $author, string $conversation, string $bytes): string
    {
        $attachments = $this->get(ChatAttachments::class);
        $uuid        = $attachments->upload($author, $conversation, $this->file($bytes), 'f.txt')->uuid;
        $this->get(ChatAttachmentScan::class)->scan((int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]));
        $attachments->send($author, $conversation, '', [$uuid]);

        return $uuid;
    }

    private function upload(string $url, string $token, int $error): Response
    {
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($url, 'POST', [], [], ['file' => new UploadedFile($this->file('x'), 'a.txt', null, $error, true)], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_X_BEACON_CONTEXT' => 'personal', 'HTTP_IDEMPOTENCY_KEY' => 'u1']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-route-');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function login(TestUser $user): string
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/page');
        /** @var array{csrf: string} $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config['csrf'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->decodeClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeClient(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
