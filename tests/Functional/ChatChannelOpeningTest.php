<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatChannelDeniedException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * CK-2: ChatChannelOpeningEvent before a channel's conversation is created: a refusal leaves no row and reaches the
 * caller with its reason; a channel already in use opens without asking.
 */
final class ChatChannelOpeningTest extends DatabaseTestCase
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

    public function testADeniedOpeningWritesNothingAndAnswersTheReason(): void
    {
        $ana   = $this->user('Ana');
        $heard = $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('Your plan allows 3 channels.'));

        $this->client->loginUser($ana);
        self::assertSame(['ok' => false, 'error' => 'channel_denied', 'message' => 'Your plan allows 3 channels.'], $this->open());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        self::assertCount(1, $heard->events);
        self::assertSame(TestChannelProvider::KEY, $heard->events[0]->channelKey);
        self::assertSame('project', $heard->events[0]->scope);
        self::assertSame($ana->getChatPublicId(), $heard->events[0]->user->getChatPublicId());
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(ChatConversation::class)->count(['channelKey' => TestChannelProvider::KEY]));
        self::assertSame(0, $this->em->getRepository(ChatParticipant::class)->count([]));
    }

    public function testADenialWithoutAReasonAnswersTheCodeAlone(): void
    {
        $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny());

        $this->client->loginUser($this->user('Ana'));
        self::assertSame(['ok' => false, 'error' => 'channel_denied'], $this->open());
    }

    public function testASecondListenerDoesNotRunAfterADenial(): void
    {
        $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('First'), 10);
        $late = $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('Second'));

        $this->client->loginUser($this->user('Ana'));
        self::assertSame('First', $this->open()['message'] ?? null);
        self::assertSame([], $late->events);
    }

    public function testAChannelAlreadyInUseOpensWithoutAsking(): void
    {
        $ana = $this->user('Ana');
        $this->client->loginUser($ana);
        self::assertSame('channel', $this->open()['conversation']['kind'] ?? null, 'nobody denies: the channel is created');

        $heard = $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('Limit'));
        self::assertSame('channel', $this->open()['conversation']['kind'] ?? null);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $heard->events);
    }

    public function testAnAllowedOpeningAsksOnceAndCreatesTheChannel(): void
    {
        $heard = $this->listen(static function (): void {
        });

        $this->client->loginUser($this->user('Ana'));
        self::assertSame('channel', $this->open()['conversation']['kind'] ?? null);
        self::assertCount(1, $heard->events);
    }

    public function testNobodyAsksForAChannelTheUserMayNotUse(): void
    {
        $heard = $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('Limit'));

        $this->client->loginUser($this->user('Stranger Eve'));
        self::assertSame('not_found', $this->open()['error'] ?? null);
        self::assertSame([], $heard->events);
    }

    public function testTheServiceRefusesForEveryCaller(): void
    {
        $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('Limit'));

        try {
            $this->get(ChatService::class)->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
            self::fail('Expected a refusal.');
        } catch (ChatChannelDeniedException $denied) {
            self::assertSame(TestChannelProvider::KEY, $denied->channelKey);
            self::assertSame('Limit', $denied->reason);
        }
        self::assertSame(0, $this->em->getRepository(ChatConversation::class)->count(['channelKey' => TestChannelProvider::KEY]));
    }

    /**
     * @param callable(ChatChannelOpeningEvent): void $listener
     *
     * @return object{events: list<ChatChannelOpeningEvent>}
     */
    private function listen(callable $listener, int $priority = 0): object
    {
        $heard = new class {
            /** @var list<ChatChannelOpeningEvent> */
            public array $events = [];
        };
        $dispatcher = $this->client->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(ChatChannelOpeningEvent::class, static function (ChatChannelOpeningEvent $event) use ($heard, $listener): void {
            $heard->events[] = $event;
            $listener($event);
        }, $priority);

        return $heard;
    }

    /**
     * @return array<string, mixed>
     */
    private function open(): array
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array{csrf: string} $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/chat/api/channel', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json'], content: json_encode(['channelKey' => TestChannelProvider::KEY], JSON_THROW_ON_ERROR));
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }
}
