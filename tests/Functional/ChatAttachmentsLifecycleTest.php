<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentCleanup;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentObjects;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentOptions;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentSigner;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentsSweepCommand;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentTypes;
use Nowo\ChatKitBundle\Attachment\Message\RemoveChatAttachmentObjects;
use Nowo\ChatKitBundle\Command\ChatPurgeCommand;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatAttachment;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatClientPrincipal;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\InMemoryStorage;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\ClientApiCalls;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Nowo\TransactionContract\TransientStorageFailure;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function assert;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * 2.7 (spec 259): files leave with what holds them — a purged conversation, the retention purge — and every failure
 * on the way (a lost queue, a bucket that refuses, a lease taken over, a lock conflict in the sweep) leaves the rows
 * consistent and nothing served that the rules refuse.
 */
final class ChatAttachmentsLifecycleTest extends DatabaseTestCase
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
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class],
        ]);
    }

    public function testAPurgedConversationAndTheRetentionPurgeTakeTheirFiles(): void
    {
        $ana     = $this->user('Ana');
        $channel = $this->channel($ana);
        $draft   = $this->upload($ana, $channel, 'draft');
        self::assertTrue($this->get(ChatMessagingInterface::class)->deleteConversation($channel->getUuid()));
        self::assertSame('deleted', $this->state($draft));
        $this->get(ChatAttachmentObjects::class)->remove(null);
        self::assertSame([], $this->get(InMemoryStorage::class)->objects);

        // The retention purge: an old message with a file.
        $channel = $this->channel($ana);
        $file    = $this->clean($ana, $channel, 'old');
        $sent    = $this->get(ChatAttachments::class)->send($ana, $channel->getUuid(), 'old', [$file]);
        $this->em->getConnection()->executeStatement("UPDATE nowo_chat_message SET created_at = '2000-01-01 00:00:00' WHERE uuid = ?", [$sent->uuid]);
        $bus     = $this->recordingBus();
        $command = new ChatPurgeCommand($this->get(ChatMessageRepository::class), 365, attachments: new ChatAttachmentCleanup($this->get(ChatAttachmentRepository::class), $bus));
        self::assertSame(0, (new CommandTester($command))->execute([]));
        self::assertSame('deleted', $this->state($file));
        self::assertInstanceOf(RemoveChatAttachmentObjects::class, $bus->sent[0] ?? null);
    }

    public function testQueueAndBucketFailuresAreLoggedNotFatal(): void
    {
        $rows = $this->get(ChatAttachmentRepository::class);
        (new ChatAttachmentCleanup($rows))->afterCommit([1]);
        (new ChatAttachmentCleanup($rows, $this->recordingBus()))->afterCommit([]);
        $failing = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new RuntimeException('transport down');
            }
        };
        (new ChatAttachmentCleanup($rows, $failing, new NullLogger()))->afterCommit([1]);
        self::assertFalse((new ChatAttachmentCleanup($rows))->forUploader(new TestUser('Unsaved', 'u@example.test'), new DateTimeImmutable()));
        self::assertSame([], $rows->forMessages([]));
        self::assertSame(0, $rows->markDeletedForMessages([], new DateTimeImmutable()));
        self::assertSame([], $rows->objectsToRemove(10, []));
        self::assertSame(0, $rows->deleteStale([], new DateTimeImmutable(), new DateTimeImmutable()));
        self::assertSame([], $rows->lockForSend([]));

        // An upload whose queue fails is still stored (the sweep queues its scan); a unit that fails expires the copy
        // even when the bucket refuses that too.
        $ana         = $this->user('Ana');
        $channel     = $this->channel($ana);
        $attachments = new ChatAttachments(...$this->arguments(['bus' => $failing, 'logger' => new NullLogger()]));
        $draft       = $attachments->upload($ana, $channel->getUuid(), $this->file('queued later'), 'q.txt');
        self::assertSame('uploaded', $this->state($draft->uuid));
        $storage                 = $this->get(InMemoryStorage::class);
        $storage->failNextExpire = true;
        $storage->failNextUpload = null;
        // Write access is lost while the bytes travel (the person became a reader): the unit refuses.
        $storage->afterUpload = static function () use ($ana): void {
            (new ReflectionProperty(TestUser::class, 'name'))->setValue($ana, 'Reader Ana');
        };
        $racing = new ChatAttachments(...$this->arguments(['logger' => new NullLogger()]));
        try {
            $racing->upload($ana, $channel->getUuid(), $this->file('lost access'), 'l.txt');
            self::fail('read only');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::READ_ONLY, $refused->error);
        }
        self::assertContains('expire', $storage->calls);
    }

    public function testAConversationDeletedWhileTheBytesTravelIsNotFound(): void
    {
        $ana                  = $this->user('Ana');
        $channel              = $this->channel($ana);
        $storage              = $this->get(InMemoryStorage::class);
        $connection           = $this->em->getConnection();
        $storage->afterUpload = static function () use ($connection, $channel): void {
            $connection->executeStatement('DELETE FROM nowo_chat_conversation WHERE id = ?', [$channel->getId()]);
        };
        try {
            $this->get(ChatAttachments::class)->upload($ana, $channel->getUuid(), $this->file('orphan bytes'), 'o.txt');
            self::fail('not found');
        } catch (ChatNotFoundException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_attachment'));
        self::assertContains('expire', $storage->calls);
    }

    public function testLostLeasesAndRacingRemovesChangeNothing(): void
    {
        $ana     = $this->user('Ana');
        $channel = $this->channel($ana);
        $busy    = $this->upload($ana, $channel, 'BUSY');
        $plain   = $this->upload($ana, $channel, 'plain');
        $lost    = new class($this->get(ChatAttachmentRepository::class)) extends ChatAttachmentRepository {
            public function __construct(private readonly ChatAttachmentRepository $inner)
            {
            }

            public function findRowById(int $id): ?array
            {
                return $this->inner->findRowById($id);
            }

            public function claim(int $id, string $token, DateTimeImmutable $now, DateTimeImmutable $leaseUntil, int $maxAttempts): bool
            {
                return $this->inner->claim($id, $token, $now, $leaseUntil, $maxAttempts);
            }

            public function release(int $id, string $token, DateTimeImmutable $retryAt): bool
            {
                return false;
            }

            public function verdict(int $id, string $token, ChatAttachmentState $state, string $engine, ?string $signature, DateTimeImmutable $now): bool
            {
                return false;
            }
        };
        $scan = new ChatAttachmentScan($lost, $this->get(TestScanner::class), $this->get(InMemoryStorage::class), $this->get(ChatAttachmentOptions::class), $this->get(ChatAttachmentCleanup::class));
        self::assertNull($scan->scan($this->id($busy)), 'a retry whose lease was lost');
        self::assertNull($scan->scan($this->id($plain)), 'a verdict whose lease was lost');

        // A remove that finds the draft attached meanwhile leaves it to its message.
        $clean = $this->clean($ana, $channel, 'racing');
        $moved = new class($this->get(ChatAttachmentRepository::class)) extends ChatAttachmentRepository {
            public function __construct(private readonly ChatAttachmentRepository $inner)
            {
            }

            public function findRow(string $uuid, bool $lock = false): ?array
            {
                $row = $this->inner->findRow($uuid, $lock);

                return $row === null || !$lock ? $row : ['message_id' => 42] + $row;
            }
        };
        (new ChatAttachments(...$this->arguments(['rows' => $moved])))->remove($ana, $clean);
        self::assertSame('clean', $this->state($clean));
    }

    public function testTheSweepStopsWithAConflictCode(): void
    {
        $failing = new class extends ChatAttachmentRepository {
            public function __construct()
            {
            }

            public function failExhausted(DateTimeImmutable $now, int $maxAttempts): array
            {
                throw new TransientStorageFailure();
            }
        };
        $tester = new CommandTester(new ChatAttachmentsSweepCommand($failing, new ChatAttachmentOptions(), $this->get(ChatAttachmentObjects::class), null, null, logger: new NullLogger()));
        self::assertSame(ChatAttachmentsSweepCommand::CONFLICT, $tester->execute([]));
        self::assertStringContainsString('CONFLICT_001', $tester->getDisplay());
        // Anything else is not a conflict: it propagates.
        $broken = new class extends ChatAttachmentRepository {
            public function __construct()
            {
            }

            public function failExhausted(DateTimeImmutable $now, int $maxAttempts): array
            {
                throw new RuntimeException('disk full');
            }
        };
        $this->expectExceptionObject(new RuntimeException('disk full'));
        (new CommandTester(new ChatAttachmentsSweepCommand($broken, new ChatAttachmentOptions(), $this->get(ChatAttachmentObjects::class))))->execute([]);
    }

    public function testClientApiRefusalsOnUnreadableConversationsAndStorage(): void
    {
        $ana  = $this->user('Ana');
        $bea  = $this->user('Bea');
        $luis = $this->user('Luis');
        $this->tokens()->grant('ana', $ana, [ChatClientPrincipal::SCOPE_READ, ChatClientPrincipal::SCOPE_WRITE], null, 'tok-ana');
        $this->tokens()->grant('luis', $luis, [ChatClientPrincipal::SCOPE_READ], null, 'tok-luis');
        $dm  = $this->get(ChatService::class)->openDirect($ana, $bea);
        $api = '/api/chat/v1';
        // Visible in the context (the Acme channel in org:acme), not readable by a stranger: one 404.
        $stranger = $this->user('Stranger Sid');
        $this->tokens()->grant('sid', $stranger, [ChatClientPrincipal::SCOPE_READ], null, 'tok-sid');
        $this->contexts()->acmeMembers[$stranger->getChatPublicId()] = true;
        $channel                                                     = $this->channel($ana);
        self::assertSame(404, $this->call('GET', $api . '/conversations/' . $channel->getUuid() . '/attachments/drafts', 'sid', 'org:acme')->getStatusCode());
        self::assertSame(404, $this->call('GET', $api . '/conversations/' . $dm->getUuid() . '/attachments/drafts', 'luis')->getStatusCode());
        // StorageKit refuses the context: 404.
        $this->get(InMemoryStorage::class)->failNextUpload = UploadRefused::CONTEXT;
        $kernel                                            = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($api . '/conversations/' . $dm->getUuid() . '/attachments', 'POST', [], [], ['file' => new UploadedFile($this->file('hello world'), 'a.txt', null, null, true)], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_AUTHORIZATION' => 'Bearer ana', 'HTTP_X_BEACON_CONTEXT' => 'personal', 'HTTP_IDEMPOTENCY_KEY' => 'x1']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheEntityExposesWhatTheRowsNeed(): void
    {
        $ana          = $this->user('Ana');
        $conversation = $this->channel($ana);
        $row          = new ChatAttachment($conversation, $ana, 'storage-1', 'a.txt', 'txt', 'text/plain', 'text', 3, 'sha', null, null, new DateTimeImmutable());
        self::assertNull($row->getId());
        self::assertSame('storage-1', $row->getStorageUuid());
        self::assertSame($conversation->getUuid(), $row->getConversationUuid());
        self::assertSame((string) $ana->getId(), $row->getUploaderReference());
        self::assertSame('uploaded', $row->getState());
        $this->expectException(ChatNotFoundException::class);
        $this->get(ChatAttachments::class)->find($ana, '01a1f09f-0000-7000-8000-00000000beef');
    }

    /**
     * @param array<string, object> $replace
     *
     * @return array<string, mixed>
     */
    private function arguments(array $replace): array
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $replace + [
            'entityManager' => $container->get(ChatKitEntityManager::class),
            'transactions'  => $container->get(ChatKitTransactionRunner::SERVICE_ID),
            'rows'          => $this->get(ChatAttachmentRepository::class),
            'conversations' => $this->get(ChatConversationRepository::class),
            'messages'      => $this->get(ChatMessageRepository::class),
            'voter'         => $this->get(ChatVoter::class),
            'access'        => $this->get(ChatAccess::class),
            'chat'          => $this->get(ChatService::class),
            'idempotency'   => $this->get(ChatIdempotency::class),
            'types'         => $this->get(ChatAttachmentTypes::class),
            'options'       => $this->get(ChatAttachmentOptions::class),
            'signer'        => $this->get(ChatAttachmentSigner::class),
            'cleanup'       => $this->get(ChatAttachmentCleanup::class),
            'uploader'      => $this->get(InMemoryStorage::class),
            'remover'       => $this->get(InMemoryStorage::class),
            'references'    => $this->get(InMemoryStorage::class),
            'uris'          => $this->get(FileUris::class),
            'urls'          => $this->kernel->getContainer()->get('router'),
        ];
    }

    private function recordingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };
    }

    private function channel(TestUser $user): ChatConversation
    {
        return $this->get(ChatService::class)->openChannel($user, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
    }

    private function upload(TestUser $user, ChatConversation $conversation, string $bytes): string
    {
        return $this->get(ChatAttachments::class)->upload($user, $conversation->getUuid(), $this->file($bytes), 'f.txt')->uuid;
    }

    private function clean(TestUser $user, ChatConversation $conversation, string $bytes): string
    {
        $uuid = $this->upload($user, $conversation, $bytes);
        $this->get(ChatAttachmentScan::class)->scan($this->id($uuid));

        return $uuid;
    }

    private function id(string $uuid): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]);
    }

    private function state(string $uuid): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT state FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]);
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-life-');
        file_put_contents($path, $bytes);

        return $path;
    }
}
