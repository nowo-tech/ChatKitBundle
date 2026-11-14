<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAuthorBadgeProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Ddd\DomainDirectory;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.29: author badges on a hexagonal host (`doctrine.user_reference: id`, plain participant objects behind the
 * directory, no Doctrine user): the provider gets the stored id and the public id, nothing of the framework.
 */
final class ChatAuthorBadgesDddHostTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    private DomainDirectory $directory;

    protected function authorBadges(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['user_class' => null, 'doctrine' => ['user_reference' => 'id']]]];
    }

    protected function setUp(): void
    {
        if (TestKernel::twinPorts()) {
            self::markTestSkipped('This host replaces the 1.26 resolver service; the twin replay wires its own.');
        }
        parent::setUp();
        $this->directory = new DomainDirectory();
        $container       = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $container->set(TestDirectory::class, $this->directory);
        $container->set(SecurityChatUserResolver::class, $this->directory);
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testTheProviderIsAskedByStoredIdAndPublicId(): void
    {
        $anaAccount = $this->user('Ana');
        $ana        = $this->directory->add($anaAccount);
        $bot        = $this->directory->add($this->user('Deploy bot'));
        $chat       = $this->get(ChatServiceInterface::class);
        $room       = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bot, $room, 'deployed');
        $provider         = $this->get(TestAuthorBadgeProvider::class);
        $provider->badges = [(string) $bot->getId() => [new ChatAuthorBadge('Agent', 'agent', 'MCP')]];

        $this->client->loginUser($anaAccount);
        $this->client->request(Request::METHOD_GET, '/page');
        $provider->clear();
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room->getUuid() . '/messages', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        /** @var array{messages: list<array<string, mixed>>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([[(string) $bot->getId()]], $provider->calls);
        self::assertSame([[$bot->getChatPublicId()]], $provider->publicIds);
        self::assertSame([['label' => 'Agent', 'variant' => 'agent', 'title' => 'MCP']], $data['messages'][0]['authorBadges']);

        $crawler = $this->client->request(Request::METHOD_GET, '/chat/' . $room->getUuid());
        self::assertSame('Agent', $crawler->filter('.nowo-chat-author-badge--agent')->text());
    }
}
