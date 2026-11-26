<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Contract\ChatAttachmentsInterface;
use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 2.7 (spec 259): attachments are off by default: no service, every attachment route answers 404, a send naming files
 * is refused, message payloads keep 2.6's shape (no `attachments` key), the dock offers no attach button, and turning
 * them on without a scanner fails the build (the scan is mandatory).
 */
final class ChatAttachmentsOffTest extends DatabaseTestCase
{
    public function testEverythingIsOffByDefault(): void
    {
        self::assertFalse($this->kernel->getContainer()->has(ChatAttachmentsInterface::class));
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $ana     = $this->user('Ana');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $client->loginUser($ana);
        $crawler = $client->request('GET', '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('attachments', $config);
        $base = '/chat/api/conversations/' . $channel->getUuid();
        foreach ([['POST', $base . '/attachments'], ['GET', $base . '/attachments/drafts'], ['GET', '/chat/api/attachments/01a1f09f-0000-7000-8000-00000000beef'], ['DELETE', '/chat/api/attachments/01a1f09f-0000-7000-8000-00000000beef'], ['GET', '/chat/files/01a1f09f-0000-7000-8000-00000000beef/original']] as [$method, $url]) {
            $client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $config['csrf']]);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $url);
        }
        $client->request('POST', $base . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => 'x', 'attachments' => ['01a1f09f-0000-7000-8000-00000000beef']], JSON_THROW_ON_ERROR));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('POST', $base . '/messages', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json'], content: json_encode(['body' => 'plain'], JSON_THROW_ON_ERROR));
        /** @var array{message: array<string, mixed>} $sent */
        $sent = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('attachments', $sent['message'], '2.6 shape');
    }

    public function testAttachmentsWithoutAScannerFailTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('attachments.scanner');
        (new NowoChatKitExtension())->load([['profiles' => ['default' => ['attachments' => ['enabled' => true]]]]], new ContainerBuilder());
    }
}
