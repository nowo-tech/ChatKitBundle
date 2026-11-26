<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\InMemoryStorage;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_column;
use function assert;
use function file_put_contents;
use function is_string;
use function json_encode;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * 2.7 (spec 259 block B): the client API (Beacon Desktop) parity: `me` advertises attachments and their limits, uploads
 * need chat:write and an Idempotency-Key, files in messages carry URLs signed for the token and context, downloads need
 * the token too and are re-checked, and a send takes `attachments`.
 */
final class ChatClientApiAttachmentsTest extends DatabaseTestCase
{
    use ClientApiCalls;

    private const string API = '/api/chat/v1';

    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig([
            'client_api'  => ['writes' => true, 'limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000, 'writes_per_token_per_minute' => 100_000]],
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class],
        ]);
    }

    public function testUploadScanSendAndDownloadThroughTheApi(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');
        $this->tokens()->grant('ana', $ana, [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE], null, 'tok-ana');
        $this->tokens()->grant('ana-ro', $ana, [ChatClientPrincipal::SCOPE_READ], null, 'tok-ana-ro');
        $this->tokens()->grant('luis', $luis, [ChatClientPrincipal::SCOPE_READ], null, 'tok-luis');
        $this->tokens()->grant('eve', $eve, [ChatClientPrincipal::SCOPE_READ], null, 'tok-eve');
        $dm   = $this->get(ChatService::class)->openDirect($ana, $luis);
        $conv = self::API . '/conversations/' . $dm->getUuid();

        $me = $this->ok('GET', self::API . '/me', 'ana');
        self::assertTrue($me['features']['attachments']);
        self::assertSame(10, $me['limits']['attachments']['maxPerMessage']);
        self::assertContains('pdf', $me['limits']['attachments']['kinds']);

        // Write scope and an Idempotency-Key are required.
        self::assertSame(403, $this->upload($conv, 'ana-ro', 'k1', 'a.txt', 'x')->getStatusCode());
        self::assertError($this->upload($conv, 'ana', null, 'a.txt', 'x'), 400, 'idempotency_key_required');
        self::assertError($this->upload($conv, 'ana', 'k1', 'a.exe', 'x'), 415, 'file_type_not_allowed');
        $created = $this->upload($conv, 'ana', 'k1', 'notes.txt', 'hello');
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $uuid = self::decode($created)['attachment']['uuid'];
        self::assertSame($uuid, self::decode($this->upload($conv, 'ana', 'k1', 'notes.txt', 'hello'))['attachment']['uuid'], 'the same key replays');
        self::assertSame([$uuid], array_column($this->ok('GET', $conv . '/attachments/drafts', 'ana')['attachments'], 'uuid'));
        self::assertSame(404, $this->call('GET', self::API . '/attachments/' . $uuid, 'luis')->getStatusCode(), 'a draft is the uploader\'s alone');
        self::assertArrayNotHasKey('urls', $this->ok('GET', self::API . '/attachments/' . $uuid, 'ana')['attachment']);

        $this->get(ChatAttachmentScan::class)->scan((int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]));
        // `attachments` on a send; an unknown uuid is attachment_invalid.
        self::assertError($this->call('POST', $conv . '/messages', 'ana', 'personal', json_encode(['text' => 'x', 'attachments' => ['not-a-uuid']], JSON_THROW_ON_ERROR), ['Idempotency-Key' => 's0']), 422, 'attachment_invalid');
        $sent = $this->call('POST', $conv . '/messages', 'ana', 'personal', json_encode(['text' => '', 'attachments' => [$uuid]], JSON_THROW_ON_ERROR), ['Idempotency-Key' => 's1']);
        self::assertSame(201, $sent->getStatusCode(), (string) $sent->getContent());
        $file = self::decode($sent)['message']['attachments'][0];
        self::assertSame($uuid, $file['uuid']);
        self::assertStringStartsWith('/api/chat/v1/files/' . $uuid . '/original?e=', $file['urls']['original']);

        // Luis reads it with his own URL (bound to his token and context).
        $page = $this->ok('GET', $conv . '/messages', 'luis');
        $url  = $page['messages'][0]['attachments'][0]['urls']['original'];
        assert(is_string($url));
        $download = $this->call('GET', $url, 'luis');
        self::assertSame(200, $download->getStatusCode());
        self::assertStringContainsString('deliver:attachment:60', implode(',', $this->get(InMemoryStorage::class)->calls));
        // Another token, another context, no token, a tampered signature, a stranger: the same 404.
        self::assertSame(404, $this->call('GET', $url, 'ana')->getStatusCode());
        $this->tokens()->grant('luis2', $luis, [ChatClientPrincipal::SCOPE_READ], null, 'tok-luis-2');
        self::assertSame(404, $this->call('GET', $url, 'luis2')->getStatusCode());
        self::assertSame(401, $this->call('GET', $url)->getStatusCode());
        self::assertSame(404, $this->call('GET', str_replace('&s=', '&s=x', $url), 'luis')->getStatusCode());
        self::assertSame(404, $this->call('GET', $url, 'eve')->getStatusCode());
        self::assertSame(404, $this->call('GET', self::API . '/attachments/' . $uuid, 'eve')->getStatusCode());

        // Remove (write scope); afterwards the file is gone from the message for everyone.
        self::assertSame(403, $this->call('DELETE', self::API . '/attachments/' . $uuid, 'luis')->getStatusCode());
        self::assertSame(204, $this->call('DELETE', self::API . '/attachments/' . $uuid, 'ana')->getStatusCode());
        self::assertSame([], $this->ok('GET', $conv . '/messages', 'luis')['messages'][0]['attachments']);
        self::assertSame(404, $this->call('GET', $url, 'luis')->getStatusCode());
    }

    private function upload(string $conv, string $token, ?string $key, string $name, string $bytes): Response
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-api-');
        file_put_contents($path, $bytes);
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_X_BEACON_CONTEXT' => 'personal'] + ($key === null ? [] : ['HTTP_IDEMPOTENCY_KEY' => $key]);
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($conv . '/attachments', 'POST', [], [], ['file' => new UploadedFile($path, $name, null, null, true)], $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
