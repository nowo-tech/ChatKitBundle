<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\Transaction\ScriptedTransactionRunner;
use PDOException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Throwable;

use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.30 (Beacon spec 239): one kernel, every request through the container's `http_kernel` with no services resetter
 * (FrankenPHP worker mode, kernel reset off). A storage failure in one request answers 503 (transient, Retry-After) or 409
 * (conflict), never 500, and leaves the next request of the same worker healthy: no transaction left open (nesting
 * level 0), an open entity manager, nothing of the failed unit of work pending.
 */
final class ChatLoadReadinessWorkerTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function scriptedTransactionRunner(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['limits' => ['send_per_minute' => 10_000, 'actions_per_minute' => 10_000, 'reads_per_minute' => 10_000]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testATransientFailureAnswers503WithRetryAfterAndTheNextRequestWorks(): void
    {
        [$session, $uuid]                                    = $this->directConversation();
        $this->get(ScriptedTransactionRunner::class)->script = ['unavailable'];

        $failed = $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', $session, ['body' => 'one']);
        self::assertSame(503, $failed->getStatusCode());
        self::assertSame('7', $failed->headers->get('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'unavailable'], $this->json($failed));

        $sent = $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', $session, ['body' => 'two']);
        self::assertSame(201, $sent->getStatusCode());
        $this->assertHealthy();
    }

    public function testADeadlockInOneRequestLeavesTheNextRequestHealthy(): void
    {
        [$session, $uuid] = $this->directConversation();
        $this->failOnce(ChatMessage::class, new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock')), null));

        $failed = $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', $session, ['body' => 'lost']);
        self::assertSame(503, $failed->getStatusCode(), (string) $failed->getContent());
        self::assertSame('1', $failed->headers->get('Retry-After'));
        $this->assertHealthy();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'), 'nothing half-written');

        $sent = $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', $session, ['body' => 'kept']);
        self::assertSame(201, $sent->getStatusCode(), (string) $sent->getContent());
        self::assertSame(['kept'], $this->em->getConnection()->fetchFirstColumn('SELECT body FROM nowo_chat_message'));
        $this->assertHealthy();
    }

    public function testAUniqueViolationInOneRequestAnswers409AndLeavesTheNextRequestHealthy(): void
    {
        [$session] = $this->directConversation();
        $this->failOnce(ChatQuickReply::class, new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null));

        $failed = $this->raw(Request::METHOD_POST, '/chat/api/quick-replies', $session, ['title' => 'Hi', 'body' => 'Hello']);
        self::assertSame(409, $failed->getStatusCode(), (string) $failed->getContent());
        self::assertSame(['ok' => false, 'error' => 'conflict'], $this->json($failed));
        $this->assertHealthy();

        $saved = $this->raw(Request::METHOD_POST, '/chat/api/quick-replies', $session, ['title' => 'Hi', 'body' => 'Hello']);
        self::assertSame(201, $saved->getStatusCode(), (string) $saved->getContent());
        $this->assertHealthy();
    }

    public function testAMembershipAnotherRequestWroteFirstOpensTheChannel(): void
    {
        [$session] = $this->directConversation();
        // The participant insert loses the race once: the unit of work runs again and reads the row (idempotent).
        $this->failOnce(ChatParticipant::class, new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null));

        $opened = $this->raw(Request::METHOD_POST, '/chat/api/channel', $session, ['channelKey' => TestChannelProvider::KEY]);
        self::assertSame(200, $opened->getStatusCode(), (string) $opened->getContent());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM nowo_chat_participant p JOIN nowo_chat_conversation c ON c.id = p.conversation_id WHERE c.kind = 'channel'"));
        $this->assertHealthy();
    }

    private function assertHealthy(): void
    {
        $connection = $this->em->getConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel(), 'no transaction left open for the next request');
        $registry = $this->kernel->getContainer()->get('doctrine');
        assert($registry instanceof ManagerRegistry);
        $manager = $registry->getManager();
        assert($manager instanceof EntityManagerInterface);
        self::assertTrue($manager->isOpen(), 'the entity manager is open for the next request');
        self::assertTrue($this->em->isOpen());
        self::assertSame([], $this->em->getUnitOfWork()->getScheduledEntityInsertions(), 'nothing of the failed unit of work pending');
    }

    /**
     * The next flush that inserts a `$class` row fails with `$failure` (once).
     *
     * @param class-string $class
     */
    private function failOnce(string $class, Throwable $failure): void
    {
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class($class, $failure) {
            private bool $done = false;

            public function __construct(private readonly string $class, private readonly Throwable $failure)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if (!$this->done && $entity instanceof $this->class) {
                        $this->done = true;

                        throw $this->failure;
                    }
                }
            }
        });
    }

    /**
     * @return array{0: array{cookies: array<string, string>, csrf: string}, 1: string}
     */
    private function directConversation(): array
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $session = $this->signIn($ana);
        $opened  = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $session, ['userUuid' => $luis->getChatPublicId()]));
        $uuid    = $opened['conversation']['uuid'] ?? null;
        self::assertIsString($uuid);

        return [$session, $uuid];
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function signIn(TestUser $user): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array{csrf: string} $config */
        $config  = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return ['cookies' => $cookies, 'csrf' => $config['csrf']];
    }

    /**
     * One request straight through the container's `http_kernel`: no kernel boot check, no services resetter.
     *
     * @param array{cookies: array<string, string>, csrf: string} $session
     * @param array<string, mixed>|null $body
     */
    private function raw(string $method, string $uri, array $session, ?array $body = null): Response
    {
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $server   = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $session['csrf']];
        $request  = Request::create($uri, $method, [], $session['cookies'], [], $server, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
