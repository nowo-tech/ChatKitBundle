<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;
use DomainException;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Service\ChatChannelDeniedException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
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

    public function testAChannelKeyThatIsNotAStringAnswersNotFoundWithoutAWarning(): void
    {
        $heard = $this->listen(static function (): void {
        });
        $this->client->loginUser($this->user('Ana'));
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array{csrf: string} $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $this->client->request(Request::METHOD_POST, '/chat/api/channel', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf'], 'CONTENT_TYPE' => 'application/json'], content: '{"channelKey":["project:acme"]}');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $heard->events);
    }

    public function testNoListenerCreatesTheChannelWithItsWholeRow(): void
    {
        $ana          = $this->user('Ana');
        $conversation = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertNotNull($conversation->getId());
        self::assertTrue($this->em->contains($conversation), 'managed, so the participant row can point at it');
        $uuid = $conversation->getUuid();
        $this->em->clear();
        $row = $this->em->getRepository(ChatConversation::class)->findOneBy(['channelKey' => TestChannelProvider::KEY]);
        self::assertInstanceOf(ChatConversation::class, $row);
        self::assertSame($uuid, $row->getUuid());
        self::assertSame(ConversationKind::Channel, $row->getKind());
        self::assertSame($row->getCreatedAt()->getTimestamp(), $row->getLastMessageAt()->getTimestamp());
        self::assertSame(1, $this->em->getRepository(ChatParticipant::class)->count([]));
    }

    public function testTheLoserOfTwoFirstOpeningsGetsTheChannel(): void
    {
        $em = $this->em;
        // The other first opening commits between this attempt's check and its insert.
        $this->listen(static function () use ($em): void {
            $em->persist(ChatConversation::channel(TestChannelProvider::KEY));
            $em->flush();
        });
        $ana          = $this->user('Ana');
        $conversation = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertTrue($em->isOpen());
        self::assertSame(1, $em->getRepository(ChatConversation::class)->count(['channelKey' => TestChannelProvider::KEY]));
        self::assertSame(TestChannelProvider::KEY, $conversation->getChannelKey());
        self::assertSame(1, $em->getRepository(ChatParticipant::class)->count(['conversation' => $conversation]));
    }

    public function testADenialOpensAChannelSomeoneElseJustCreated(): void
    {
        $em = $this->em;
        $this->listen(static function (ChatChannelOpeningEvent $event) use ($em): void {
            $em->persist(ChatConversation::channel(TestChannelProvider::KEY));
            $em->flush();
            $event->deny('The limit was reached by creating this very channel.');
        });

        $conversation = $this->get(ChatService::class)->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertSame(TestChannelProvider::KEY, $conversation->getChannelKey());
    }

    public function testADenialLeavesTheEntityManagerOpen(): void
    {
        $this->listen(static fn (ChatChannelOpeningEvent $event) => $event->deny('Limit'));
        $ana = $this->user('Ana');

        try {
            $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
            self::fail('Expected a refusal.');
        } catch (ChatChannelDeniedException) {
            self::assertTrue($this->em->isOpen());
            self::assertFalse($this->em->getConnection()->isTransactionActive());
        }
    }

    public function testAListenerThatThrowsLeavesNothingAndTheEntityManagerOpen(): void
    {
        $this->listen(static function (): never {
            throw new DomainException('Plan refused');
        });
        $ana = $this->user('Ana');

        try {
            $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
            self::fail('Expected the listener\'s exception.');
        } catch (DomainException $thrown) {
            self::assertSame('Plan refused', $thrown->getMessage());
        }
        self::assertTrue($this->em->isOpen(), 'the host still answers through it');
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame(0, $this->em->getRepository(ChatConversation::class)->count(['channelKey' => TestChannelProvider::KEY]));
    }

    public function testAnIdGeneratorThatRunsBeforeTheInsertIsAsked(): void
    {
        // GZ-M1: what a sequence does on Postgres, whose id column has no default.
        $this->em->getClassMetadata(ChatConversation::class)->setIdGenerator(new class extends AbstractIdGenerator {
            public function generateId(EntityManagerInterface $em, ?object $entity): int
            {
                return 4242;
            }
        });

        $conversation = $this->get(ChatService::class)->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertSame(4242, $conversation->getId());
    }

    /**
     * @return iterable<string, array{bool, bool, bool, int}>
     */
    public static function pendingChanges(): iterable
    {
        // GZ-L2: flushed by the listener, by whom, how it ends, rows expected.
        yield 'listener flushes, then denies' => [true, true, false, 1];
        yield 'listener flushes, then throws' => [true, true, true, 1];
        yield 'listener only persists, denies' => [false, true, false, 0];
        yield 'listener only persists, throws' => [false, true, true, 0];
        yield 'the caller left it pending, denies' => [false, false, false, 0];
    }

    #[DataProvider('pendingChanges')]
    public function testARefusalDoesNotFlushWhatIsPending(bool $flushes, bool $inListener, bool $throws, int $rows): void
    {
        $em      = $this->em;
        $ana     = $this->user('Ana');
        $pending = new TestUser('Pending', 'pending@example.test');
        if (!$inListener) {
            $em->persist($pending);
        }
        $this->listen(static function (ChatChannelOpeningEvent $event) use ($em, $pending, $flushes, $inListener, $throws): void {
            if ($inListener) {
                $em->persist($pending);
            }
            if ($flushes) {
                $em->flush();
            }
            if ($throws) {
                throw new DomainException('Plan refused');
            }
            $event->deny('Limit');
        });

        try {
            $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
            self::fail('Expected a refusal.');
        } catch (ChatChannelDeniedException|DomainException) {
        }

        self::assertTrue($em->isOpen());
        $table = $em->getClassMetadata(TestUser::class)->getTableName();
        self::assertSame($rows, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table . " WHERE email = 'pending@example.test'"));
        self::assertSame($rows === 0, $em->getUnitOfWork()->isScheduledForInsert($pending), 'what was not flushed stays pending');
    }

    public function testACallersTransactionSurvivesLosingTheRace(): void
    {
        $em = $this->em;
        $this->listen(static function () use ($em): void {
            $em->persist(ChatConversation::channel(TestChannelProvider::KEY));
            $em->flush();
        });
        $ana        = $this->user('Ana');
        $connection = $em->getConnection();

        $connection->beginTransaction();
        $conversation = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $connection->commit();

        self::assertTrue($em->isOpen());
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(TestChannelProvider::KEY, $conversation->getChannelKey());
    }

    public function testLosingToARowThatCannotBeReadRethrowsTheUniqueError(): void
    {
        // GZ-I10: a caller's transaction on MySQL's repeatable read. Nobody refused, so this is no refusal.
        $this->rivalListener();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->serviceThatCannotSeeTheRow()->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
    }

    public function testADenialThatCannotSeeTheRowIsStillADenial(): void
    {
        $this->rivalListener(deny: true);

        $this->expectException(ChatChannelDeniedException::class);
        $this->serviceThatCannotSeeTheRow()->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
    }

    public function testARowThatCannotBeReadAfterItsOwnInsertIsALogicError(): void
    {
        $this->expectException(LogicException::class);
        $this->serviceThatCannotSeeTheRow()->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
    }

    public function testAnErrorInTheInsertLeavesTheEntityManagerOpen(): void
    {
        // GZ-I12: any failure inside the transaction; a key the entity refuses is the cheapest one.
        $ana = $this->user('Ana');

        try {
            $this->get(ChatService::class)->openChannel($ana, new ChatChannel(str_repeat('x', ChatConversation::CHANNEL_KEY_MAX_LENGTH + 1), 'Long'));
            self::fail('Expected the error.');
        } catch (LogicException) {
            self::assertTrue($this->em->isOpen());
            self::assertFalse($this->em->getConnection()->isTransactionActive());
        }
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

    private function rivalListener(bool $deny = false): void
    {
        $em = $this->em;
        $this->listen(static function (ChatChannelOpeningEvent $event) use ($em, $deny): void {
            $em->getConnection()->insert($em->getClassMetadata(ChatConversation::class)->getTableName(), [
                'uuid'            => '0190aaaa-0000-7000-8000-000000000000',
                'kind'            => 'channel',
                'channel_key'     => TestChannelProvider::KEY,
                'last_message_at' => '2026-11-06 10:00:00',
                'created_at'      => '2026-11-06 10:00:00',
            ]);
            if ($deny) {
                $event->deny('Limit');
            }
        });
    }

    /** The real service, with a repository that never finds the channel. */
    private function serviceThatCannotSeeTheRow(): ChatService
    {
        $real       = $this->get(ChatService::class);
        $reflection = new ReflectionClass($real);
        $arguments  = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $arguments[$parameter->getName()] = $reflection->getProperty($parameter->getName())->getValue($real);
        }
        $arguments['conversations'] = $this->createStub(ChatConversationRepository::class);

        return new ChatService(...$arguments);
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
