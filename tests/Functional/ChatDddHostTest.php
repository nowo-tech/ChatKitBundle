<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Contract\CurrentChatUserResolverInterface;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Ddd\DomainDirectory;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * 1.24: a hexagonal host — `doctrine.user_reference: id`, chat participants that are plain objects (not Doctrine
 * entities, not the security user) behind ChatDirectoryInterface, and CurrentChatUserResolverInterface mapping the
 * signed-in security user to its participant. The dock, the voter and the API all go through the resolver; listeners
 * read the events' DTOs, never the entities.
 */
final class ChatDddHostTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    private DomainDirectory $directory;

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

    public function testAHostWithoutADoctrineChatUser(): void
    {
        $ana  = $this->directory->add($this->user('Ana'));
        $luis = $this->directory->add($this->user('Luis'));
        self::assertInstanceOf(ChatService::class, $this->get(ChatServiceInterface::class), 'the application service behind its port');
        self::assertSame($this->directory, $this->get(CurrentChatUserResolverInterface::class));

        /** @var list<object> $events */
        $events = [];
        $this->get(EventDispatcherInterface::class)->addListener(ChatMessageSentEvent::class, static function (ChatMessageSentEvent $event) use (&$events): void {
            $events[] = $event->messageView();
        });
        $this->get(EventDispatcherInterface::class)->addListener(ChatMentionEvent::class, static function (ChatMentionEvent $event) use (&$events): void {
            $events[] = $event->mentionedPublicIds();
        });

        $csrf = $this->login('Ana');
        self::assertSame($ana->getChatPublicId(), $this->config['userUuid'], 'the dock knows the participant, not the account');
        $conversation = $this->send(Request::METHOD_POST, '/chat/api/direct', $csrf, ['userUuid' => $luis->getChatPublicId()])['conversation'];
        self::assertSame($luis->getChatPublicId(), $conversation['peerUuid']);
        $sent = $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $conversation['uuid'] . '/messages', $csrf, ['body' => 'hi @[Luis](' . $luis->getChatPublicId() . ')'])['message'];
        self::assertTrue($sent['mine']);
        self::assertSame($ana->getChatPublicId(), $sent['authorUuid']);

        $view = $events[0];
        assert($view instanceof ChatMessageView);
        self::assertSame([$conversation['uuid'], 'direct', $ana->getChatPublicId(), [$luis->getChatPublicId()]], [$view->conversationUuid, $view->conversationKind, $view->authorPublicId, $view->mentionedPublicIds]);
        self::assertSame([$luis->getChatPublicId()], $events[1]);

        // Luis, after the rows are read back from the database.
        $this->em->clear();
        $csrf = $this->login('Luis');
        $list = $this->getJson('/chat/api/conversations');
        self::assertSame([1, 'Ana'], [$list['unreadTotal'], $list['conversations'][0]['title']]);
        $messages = $this->getJson('/chat/api/conversations/' . $conversation['uuid'] . '/messages')['messages'];
        self::assertSame([$ana->getChatPublicId(), false, true], [$messages[0]['authorUuid'], $messages[0]['mine'], $messages[0]['mentionsMe']]);
        $this->send(Request::METHOD_POST, '/chat/api/conversations/' . $conversation['uuid'] . '/read', $csrf, ['messageId' => $messages[0]['id']]);
        self::assertSame(0, $this->getJson('/chat/api/conversations')['unreadTotal']);
        self::assertSame(1, $this->countRows((string) $luis->getId()), 'Luis has his participant row, by reference');

        // An account the host maps to no participant has no chat.
        $this->directory->forget($luis);
        $this->login('Luis', false);
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** @var array<string, mixed> */
    private array $config = [];

    private function login(string $name, bool $expectDock = true): string
    {
        $account = $this->em->getRepository(TestUser::class)->findOneBy(['name' => $name]);
        assert($account !== null);
        $this->client->loginUser($account);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        if (!$expectDock) {
            self::assertCount(0, $crawler->filter('[data-nowo-chat-config]'));

            return '';
        }
        /** @var array<string, mixed> $config */
        $config       = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $this->config = $config;

        return (string) $config['csrf'];
    }

    private function countRows(string $reference): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE user_id = ?', [$reference]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $url, string $csrf, array $body = []): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertLessThan(300, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /* @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
