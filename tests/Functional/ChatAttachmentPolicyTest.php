<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\InMemoryStorage;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestAttachmentPolicy;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_column;
use function assert;
use function count;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * 2.7 (spec 259 FR-010, owner decision 2026-11-26): with a host ChatAttachmentPolicyInterface, attaching is denied
 * unless the policy allows it — asked on the upload and again on the send, on the dock and the client API, with the
 * same `403 attachments_not_allowed`. A policy that throws denies. Nothing is stored or sent on a refusal.
 */
final class ChatAttachmentPolicyTest extends DatabaseTestCase
{
    use ClientApiCalls;

    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return self::clientApiConfig([
            'client_api'  => ['writes' => true, 'limits' => ['per_ip_per_minute' => 100_000, 'per_token_per_minute' => 100_000, 'writes_per_token_per_minute' => 100_000]],
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class, 'policy' => TestAttachmentPolicy::class],
        ]);
    }

    public function testDeniedUnlessThePolicyAllowsOnUploadAndAgainOnSend(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $ana     = $this->user('Ana');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $policy  = $this->get(TestAttachmentPolicy::class);
        $storage = $this->get(InMemoryStorage::class);
        $client->loginUser($ana);
        $crawler = $client->request('GET', '/page');
        /** @var array{csrf: string} $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $base   = '/chat/api/conversations/' . $channel->getUuid();

        // Not allowed: 403 before any byte travels.
        $client->request('POST', $base . '/attachments', [], ['file' => new UploadedFile($this->file('hello world'), 'a.txt', null, null, true)], ['HTTP_X_CSRF_TOKEN' => $config['csrf']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'attachments_not_allowed'], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([], $storage->calls);
        self::assertSame([$ana->getChatPublicId()], array_column($policy->asked, 'viewerPublicId'));
        self::assertSame('channel', $policy->asked[0]->conversationKind);
        self::assertSame(TestChannelProvider::KEY, $policy->asked[0]->channelKey);
        self::assertNull($policy->asked[0]->contextKey);

        // Allowed: stored; withdrawn before the send: the send is refused the same way, nothing written.
        $policy->allowed[$ana->getChatPublicId()] = true;
        $uuid                                     = $this->get(ChatAttachments::class)->upload($ana, $channel->getUuid(), $this->file('hello world'), 'a.txt')->uuid;
        $this->get(ChatAttachmentScan::class)->scan((int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]));
        $policy->allowed = [];
        $client->request('POST', $base . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => 'x', 'attachments' => [$uuid]], JSON_THROW_ON_ERROR));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('"error":"attachments_not_allowed"', (string) $client->getResponse()->getContent());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
        // A text-only send never asks the policy.
        $asked = count($policy->asked);
        $this->get(ChatAttachments::class)->send($ana, $channel->getUuid(), 'text only', []);
        self::assertCount($asked, $policy->asked);
        // A failing policy denies.
        $policy->allowed[$ana->getChatPublicId()] = true;
        $policy->failing                          = true;
        try {
            $this->get(ChatAttachments::class)->send($ana, $channel->getUuid(), 'x', [$uuid]);
            self::fail('a failing policy must deny');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::NOT_ALLOWED, $refused->error);
            self::assertSame(403, $refused->status);
        }
        $policy->failing = false;
        self::assertSame('x', $this->get(ChatAttachments::class)->send($ana, $channel->getUuid(), 'x', [$uuid])->body);
    }

    public function testTheClientApiAsksWithItsContext(): void
    {
        $ana = $this->user('Ana');
        $bea = $this->user('Bea');
        $this->tokens()->grant('ana', $ana, [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE], null, 'tok-ana');
        $dm     = $this->get(ChatService::class)->openDirect($ana, $bea);
        $policy = $this->get(TestAttachmentPolicy::class);
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create('/api/chat/v1/conversations/' . $dm->getUuid() . '/attachments', 'POST', [], [], ['file' => new UploadedFile($this->file('hello world'), 'a.txt', null, null, true)], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer ana', 'HTTP_X_BEACON_CONTEXT' => 'personal', 'HTTP_IDEMPOTENCY_KEY' => 'p1']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertError($response, 403, 'attachments_not_allowed');
        self::assertSame('personal', $policy->asked[0]->contextKey ?? null);
        self::assertSame('direct', $policy->asked[0]->conversationKind);
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-policy-');
        file_put_contents($path, $bytes);

        return $path;
    }
}
