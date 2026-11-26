<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentCleanup;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentDownloads;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentFileContext;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentObjects;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentOptions;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentSigner;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentsSweepCommand;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentTypes;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentViews;
use Nowo\ChatKitBundle\Attachment\Message\RemoveChatAttachmentObjects;
use Nowo\ChatKitBundle\Attachment\Message\ScanChatAttachment;
use Nowo\ChatKitBundle\Contract\ChatAttachmentScopeResolverInterface;
use Nowo\ChatKitBundle\Digest\ChatDigestDispatcher;
use Nowo\ChatKitBundle\Digest\SendChatDigestMessage;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatAttachment;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Event\ChatAttachmentDownloadedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentScanOverdueEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatComposerScope;
use Nowo\ChatKitBundle\Model\ChatStorageTarget;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Repository\ChatAttachmentRepository;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatForwarder;
use Nowo\ChatKitBundle\Service\ChatIdempotency;
use Nowo\ChatKitBundle\Service\ChatMarkdownRenderer;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatReferences;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\InMemoryStorage;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingDigestSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\StorageKitBundle\Download\FileDeliveryResponder;
use Nowo\StorageKitBundle\Reference\FileUris;
use Nowo\StorageKitBundle\Upload\UploadRefused;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage as LimiterStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function assert;
use function file_put_contents;
use function json_encode;
use function parse_str;
use function parse_url;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_QUERY;

/**
 * 2.7 (spec 259): the attachment services' edge cases — every refusal, the idempotency race, the StorageKit failures
 * (refused upload, refused delete, missing object), the scan's lost leases and retries, the sweep, the context answers,
 * and what a file-only message reads as in the digest. The rows are the truth: no step leaves a file served that the
 * rules refuse.
 */
final class ChatAttachmentsEdgeTest extends DatabaseTestCase
{
    private TestUser $ana;

    private TestUser $luis;

    private ChatConversation $channel;

    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class, 'max_per_message' => 2, 'scan' => ['max_attempts' => 2, 'unscannable' => 'allow']],
        ]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana     = $this->user('Ana');
        $this->luis    = $this->user('Luis');
        $chat          = $this->get(ChatService::class);
        $this->channel = $chat->openChannel($this->ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->openChannel($this->luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
    }

    public function testUploadRefusalsBeforeAnyByteTravels(): void
    {
        $attachments = $this->get(ChatAttachments::class);
        $storage     = $this->get(InMemoryStorage::class);
        $uuid        = $this->channel->getUuid();
        $cases       = [
            [ChatAttachmentRefused::INVALID_FILE, fn () => $attachments->upload($this->ana, $uuid, sys_get_temp_dir() . '/missing-file', 'a.txt')],
            [ChatAttachmentRefused::INVALID_FILE, fn () => $attachments->upload($this->ana, $uuid, 'php://memory', 'a.txt')],
            [ChatAttachmentRefused::EMPTY_FILE, fn () => $attachments->upload($this->ana, $uuid, $this->file(''), 'a.txt')],
            [ChatAttachmentRefused::IDEMPOTENCY_MISMATCH, fn () => $attachments->upload($this->ana, $uuid, $this->file('x'), 'a.txt', 'bad key!')],
            [ChatAttachmentRefused::INVALID_FILE_NAME, fn () => $attachments->upload($this->ana, $uuid, $this->file('x'), "\u{202E}")],
        ];
        foreach ($cases as [$code, $call]) {
            try {
                $call();
                self::fail($code);
            } catch (ChatAttachmentRefused $refused) {
                self::assertSame($code, $refused->error);
            }
        }
        self::assertSame([], $storage->calls, 'nothing reached StorageKit');
        // Unknown and unreadable conversations are "not found"; a read-only member is refused.
        $this->expectNotFound(fn () => $attachments->upload($this->ana, '01a1f09f-0000-7000-8000-00000000beef', $this->file('x'), 'a.txt'));
        $stranger = $this->user('Stranger Sam');
        $this->expectNotFound(fn () => $attachments->upload($stranger, $uuid, $this->file('x'), 'a.txt'));
        $reader = $this->user('Reader Rita');
        $this->get(ChatService::class)->openChannel($reader, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        try {
            $attachments->upload($reader, $uuid, $this->file('x'), 'a.txt');
            self::fail('read_only');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::READ_ONLY, $refused->error);
        }
        // Too large for the effective limit.
        $small = new ChatAttachments(...$this->rebuild(['options' => new ChatAttachmentOptions(maxBytes: 3)]));
        try {
            $small->upload($this->ana, $uuid, $this->file('four'), 'a.txt');
            self::fail('too large');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::FILE_TOO_LARGE, $refused->error);
        }
    }

    public function testStorageKitRefusalsAreMappedAndAFailedUnitExpiresTheStoredCopy(): void
    {
        $attachments = $this->get(ChatAttachments::class);
        $storage     = $this->get(InMemoryStorage::class);
        $uuid        = $this->channel->getUuid();
        foreach ([UploadRefused::TYPE => ChatAttachmentRefused::TYPE_MISMATCH, UploadRefused::LIMIT => ChatAttachmentRefused::FILE_TOO_LARGE, UploadRefused::EMPTY => ChatAttachmentRefused::EMPTY_FILE, UploadRefused::SOURCE => ChatAttachmentRefused::INVALID_FILE, UploadRefused::STORAGE => ChatAttachmentRefused::STORAGE_UNAVAILABLE] as $reason => $code) {
            $storage->failNextUpload = $reason;
            try {
                $attachments->upload($this->ana, $uuid, $this->file('hello world'), 'a.txt');
                self::fail($reason);
            } catch (ChatAttachmentRefused $refused) {
                self::assertSame($code, $refused->error, $reason);
            }
        }
        $storage->failNextUpload = UploadRefused::CONTEXT;
        $this->expectNotFound(fn () => $attachments->upload($this->ana, $uuid, $this->file('hello world'), 'a.txt'));

        // The quota re-checked under the lock (a draft another request added meanwhile): the copy is expired.
        $quota = new ChatAttachments(...$this->rebuild(['options' => new ChatAttachmentOptions(maxPendingPerUser: 1)]));
        $quota->upload($this->ana, $uuid, $this->file('first'), 'a.txt');
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_attachment SET uploader_id = NULL');
        $storage->calls = [];
        $sneaky         = new ChatAttachments(...$this->rebuild(['options' => new ChatAttachmentOptions(maxPendingPerUser: 1), 'rows' => new class($this->get(ChatAttachmentRepository::class)) extends ChatAttachmentRepository {
            private int $calls = 0;

            public function __construct(private readonly ChatAttachmentRepository $inner)
            {
            }

            public function countDrafts(int|string $uploaderKey): int
            {
                return ++$this->calls === 1 ? 0 : 1;
            }

            public function findRow(string $uuid, bool $lock = false): ?array
            {
                return $this->inner->findRow($uuid, $lock);
            }

            public function insert(ChatAttachment $attachment): ?UniqueConstraintViolationException
            {
                return $this->inner->insert($attachment);
            }
        }]));
        try {
            $sneaky->upload($this->ana, $uuid, $this->file('second'), 'b.txt');
            self::fail('quota');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::TOO_MANY_PENDING, $refused->error);
        }
        self::assertSame(['upload', 'sync', 'expire'], $storage->calls, 'stored, referenced, then expired after the rollback');
    }

    public function testAKeyRaceReturnsTheWinnersAnswer(): void
    {
        $attachments = $this->get(ChatAttachments::class);
        $uuid        = $this->channel->getUuid();
        $first       = $attachments->upload($this->ana, $uuid, $this->file('same'), 'a.txt', 'k');
        // A second request that checked the key before the first one committed: its insert loses the unique key.
        $blind = new ChatAttachments(...$this->rebuild(['rows' => new class($this->get(ChatAttachmentRepository::class)) extends ChatAttachmentRepository {
            private int $lookups = 0;

            public function __construct(private readonly ChatAttachmentRepository $inner)
            {
            }

            public function findByKey(int|string $uploaderKey, string $key): ?array
            {
                return ++$this->lookups === 1 ? null : $this->inner->findByKey($uploaderKey, $key);
            }

            public function countDrafts(int|string $uploaderKey): int
            {
                return 0;
            }

            public function insert(ChatAttachment $attachment): ?UniqueConstraintViolationException
            {
                return $this->inner->insert($attachment);
            }

            public function findRow(string $uuid, bool $lock = false): ?array
            {
                return $this->inner->findRow($uuid, $lock);
            }
        }]));
        self::assertSame($first->uuid, $blind->upload($this->ana, $uuid, $this->file('same'), 'a.txt', 'k')->uuid);
        self::assertSame(1, $this->rows());
        self::assertContains('expire', $this->get(InMemoryStorage::class)->calls, 'the loser\'s copy is expired');
    }

    public function testSendsRemovesBlocksAndViews(): void
    {
        $attachments = $this->get(ChatAttachments::class);
        $uuid        = $this->channel->getUuid();
        $one         = $this->clean('one');
        $two         = $this->clean('two');
        $three       = $this->clean('three');
        try {
            $attachments->send($this->ana, $uuid, 'x', [$one, $two, $three]);
            self::fail('too many');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::TOO_MANY_ATTACHMENTS, $refused->error);
        }
        try {
            $attachments->send($this->ana, $uuid, 'x', [$one], 'client:nope');
            self::fail('key');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::IDEMPOTENCY_MISMATCH, $refused->error);
        }
        // Luis may not send Ana's draft.
        try {
            $attachments->send($this->luis, $uuid, 'x', [$one]);
            self::fail('foreign');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::INVALID, $refused->error);
        }
        $plain = $attachments->send($this->ana, $uuid, 'text only', []);
        self::assertSame('text only', $plain->body);
        $sent  = $attachments->send($this->ana, $uuid, 'two files', [$one, $two], 'key-1');
        $again = $attachments->send($this->ana, $uuid, 'two files', [$one, $two], 'key-1');
        self::assertSame($sent->uuid, $again->uuid, 'the key replays');
        self::assertSame([$one, $two], array_column(array_map(static fn ($view): array => $view->toArray(), $attachments->attachmentsOf($this->luis, $uuid, $sent->uuid)), 'uuid'));
        $this->expectNotFound(fn () => $attachments->attachmentsOf($this->luis, $uuid, '01a1f09f-0000-7000-8000-00000000beef'));
        self::assertSame($sent->uuid, $attachments->find($this->luis, $one)->messageUuid);

        // Links: the reader's own URL; with bind_session off it names the reader.
        $url = $attachments->downloadUrl($this->luis, $one);
        self::assertStringStartsWith('/chat/files/' . $one . '/original?e=', $url->url);
        self::assertStringNotContainsString('v=', $url->url);
        $unbound = new ChatAttachments(...$this->rebuild(['options' => new ChatAttachmentOptions(bindSession: false)]));
        self::assertStringContainsString('v=' . $this->luis->getChatPublicId(), $unbound->downloadUrl($this->luis, $one)->url);
        $this->expectNotFound(fn () => $attachments->downloadUrl($this->ana, $three === '' ? '' : $this->draft('pending')));

        // A forward of a message with files is refused (Q-259-8 A).
        $forward = $this->get(ChatForwarder::class);
        try {
            $forward->forward($this->ana, $this->message($sent->uuid), [['type' => 'conversation', 'uuid' => $uuid]], null, 'fw');
            self::fail('forward');
        } catch (InvalidArgumentException $refused) {
            self::assertSame(ChatForwarder::HAS_ATTACHMENTS, $refused->getMessage());
        }

        // Luis may not remove it; Ana may (her message, in the edit window); twice is idempotent.
        $this->expectNotFound(fn () => $attachments->remove($this->luis, $one));
        $attachments->remove($this->ana, $one);
        $attachments->remove($this->ana, $three);
        $this->expectNotFound(fn () => $attachments->remove($this->ana, $one));
        // Block: unknown, a clean file blocked once, then idempotent.
        self::assertFalse($attachments->block('01a1f09f-0000-7000-8000-00000000beef'));
        self::assertTrue($attachments->block($two));
        self::assertFalse($attachments->block($two));
        $this->expectNotFound(fn () => $attachments->find($this->luis, $two));
        self::assertSame([], $attachments->attachmentsOf($this->luis, $uuid, $sent->uuid));
        self::assertCount(1, $attachments->drafts($this->ana, $uuid), 'the draft still scanning');
    }

    public function testScanLeasesRetriesAndTheBus(): void
    {
        $scan    = $this->get(ChatAttachmentScan::class);
        $storage = $this->get(InMemoryStorage::class);
        $unknown = $scan->scan(999_999);
        self::assertNull($unknown);
        // Unscannable is clean with `unscannable: allow`.
        self::assertSame(ChatAttachmentState::Clean, $scan->scan($this->id($this->draft('ENCRYPTED'))));
        // A missing object is a retry, then failed (2 attempts); the retry goes to the bus with a delay.
        $busy = $this->id($this->draft('BUSY'));
        $bus  = new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $sent = [];

            public bool $fail = false;

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($this->fail) {
                    throw new RuntimeException('transport down');
                }
                $this->sent[] = $envelope = new Envelope($message, $stamps);

                return $envelope;
            }
        };
        $withBus = new ChatAttachmentScan($this->get(ChatAttachmentRepository::class), $this->get(TestScanner::class), $storage, $this->get(ChatAttachmentOptions::class), $this->get(ChatAttachmentCleanup::class), $bus, null, null, null, new MockClock(), new NullLogger());
        self::assertSame(ChatAttachmentState::Uploaded, $withBus->scan($busy));
        self::assertInstanceOf(ScanChatAttachment::class, $bus->sent[0]->getMessage());
        self::assertNull($withBus->scan($busy), 'not due before its backoff');
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_attachment SET lease_until = NULL');
        $bus->fail = true;
        self::assertSame(ChatAttachmentState::Failed, $withBus->scan($busy));
        // A read failure (the object is gone) is a retry too; a failed dispatch is only logged.
        $gone                  = $this->id($this->draft('plain'));
        $storage->failNextRead = true;
        self::assertSame(ChatAttachmentState::Uploaded, $withBus->scan($gone));
        // The handler entry points.
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_attachment SET lease_until = NULL');
        $scan(new ScanChatAttachment($gone));
        self::assertSame('clean', $this->state($gone));
        $this->get(ChatAttachmentObjects::class)(new RemoveChatAttachmentObjects([$gone]));
        // A verdict whose user is gone from the directory tells nobody (no error).
        $orphan = $this->id($this->draft('orphan'));
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_attachment SET uploader_id = NULL WHERE id = ?', [$orphan]);
        self::assertSame(ChatAttachmentState::Clean, $scan->scan($orphan));
    }

    public function testObjectRemovalFallsBackToExpiry(): void
    {
        $storage = $this->get(InMemoryStorage::class);
        $draft   = $this->draft('to remove');
        $this->get(ChatAttachments::class)->remove($this->ana, $draft);
        $storage->failNextRemove = true;
        self::assertSame(1, $this->get(ChatAttachmentObjects::class)->remove(null));
        self::assertTrue($storage->objects[array_key_first($storage->objects)]['expired']);
        self::assertSame(0, $this->rows());
    }

    public function testTheSweepQueuesPurgesRemovesAndAlerts(): void
    {
        $events = new EventDispatcher();
        $alerts = [];
        $events->addListener(ChatAttachmentScanOverdueEvent::class, static function (ChatAttachmentScanOverdueEvent $event) use (&$alerts): void {
            $alerts[] = $event;
        });
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };
        $waiting    = $this->id($this->draft('waiting'));
        $exhausted  = $this->id($this->draft('exhausted'));
        $stale      = $this->id($this->draft('stale'));
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE nowo_chat_attachment SET created_at = '2000-01-01 00:00:00' WHERE id = ?", [$stale]);
        $connection->executeStatement('UPDATE nowo_chat_attachment SET created_at = ? WHERE id = ?', [(new DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'), $waiting]);
        $connection->executeStatement('UPDATE nowo_chat_attachment SET scan_attempts = 5 WHERE id = ?', [$exhausted]);
        $connection->executeStatement("UPDATE nowo_chat_attachment SET state = 'clean' WHERE id = ?", [$stale]);
        $command = new ChatAttachmentsSweepCommand($this->get(ChatAttachmentRepository::class), new ChatAttachmentOptions(maxAttempts: 2), $this->get(ChatAttachmentObjects::class), $bus, $events, new MockClock());
        $tester  = new CommandTester($command);
        self::assertSame(0, $tester->execute(['--max-batches' => '1']));
        self::assertStringContainsString('scan(s) queued', $tester->getDisplay());
        self::assertSame('failed', $this->state($exhausted), 'exhausted: fail-closed');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_attachment WHERE id = ?', [$stale]), 'a stale draft is deleted and its objects removed');
        self::assertContains($waiting, array_map(static fn (object $message): int => $message instanceof ScanChatAttachment ? $message->attachmentId : 0, $bus->sent));
        self::assertCount(1, $alerts);
        self::assertGreaterThan(0, $alerts[0]->waiting);
        self::assertGreaterThan(0, $alerts[0]->oldestSeconds);
        self::assertSame(1, $alerts[0]->failed);
        // Nothing left: no alert.
        $connection->executeStatement('DELETE FROM nowo_chat_attachment');
        self::assertSame(0, (new CommandTester($command))->execute([]));
        self::assertCount(1, $alerts);
    }

    public function testTheStorageContextAnswers(): void
    {
        $scopes = new class implements ChatAttachmentScopeResolverInterface {
            public ?ChatComposerScope $asked = null;

            public function targetFor(ChatComposerScope $scope): ChatStorageTarget
            {
                $this->asked = $scope;

                return new ChatStorageTarget('tenant-a', 'org-1');
            }
        };
        $context = new ChatAttachmentFileContext($this->get(ChatConversationRepository::class), $this->get(ChatVoter::class), $this->get(ChatAccess::class), new ChatAttachmentTypes(), new ChatAttachmentOptions(maxBytes: 99), $scopes);
        $uuid    = $this->channel->getUuid();
        self::assertSame('chat', $context->type());
        self::assertSame([], $context->readable($this->ana, [$uuid]), 'never through StorageKit\'s route');
        self::assertSame([$uuid], $context->existing([$uuid, strtoupper($uuid), 'gone']));
        self::assertSame([], $context->existing([]));
        self::assertSame(99, $context->maxBytes());
        self::assertContains('application/pdf', $context->acceptedTypes());
        $target = $context->writableTarget($this->ana, $uuid);
        self::assertSame(['tenant-a', 'org-1'], [$target?->scope, $target?->owner]);
        self::assertSame('channel', $scopes->asked?->conversationKind);
        self::assertNull($context->writableTarget(new stdClass(), $uuid));
        self::assertNull($context->writableTarget($this->ana, strtoupper($uuid)));
        self::assertNull($context->writableTarget($this->user('Stranger Sue'), $uuid));
        $default = $this->get(ChatAttachmentFileContext::class)->writableTarget($this->ana, $uuid);
        self::assertSame([null, $uuid], [$default?->scope, $default?->owner], 'the instance bucket, owned by the conversation');
    }

    public function testDownloadsAreRefusedLimitedAuditedAndRedirected(): void
    {
        $file    = $this->clean('payload');
        $sent    = $this->get(ChatAttachments::class)->send($this->ana, $this->channel->getUuid(), '', [$file]);
        $storage = $this->get(InMemoryStorage::class);
        $events  = new EventDispatcher();
        $seen    = [];
        $events->addListener(ChatAttachmentDownloadedEvent::class, static function (ChatAttachmentDownloadedEvent $event) use (&$seen): void {
            $seen[] = $event;
        });
        $limiter           = new RateLimiterFactory(['id' => 'd', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new LimiterStorage());
        $downloads         = new ChatAttachmentDownloads($this->get(ChatAttachments::class), $this->get(ChatAttachmentSigner::class), new ChatAttachmentOptions(auditDownloads: true), $storage, new FileDeliveryResponder(), $limiter, $events);
        $request           = $this->signed($this->get(ChatAttachments::class)->downloadUrl($this->luis, $file)->url);
        $storage->redirect = true;
        $first             = $downloads->respond($request, $file, 'original', $this->luis);
        self::assertSame(302, $first->getStatusCode());
        self::assertCount(1, $seen);
        self::assertFalse($seen[0]->api);
        self::assertSame(429, $downloads->respond($request, $file, 'original', $this->luis)->getStatusCode());
        // No viewer, a non-clean draft of its uploader (validly signed), a missing object: the same 404.
        self::assertSame(404, $downloads->respond($request, $file, 'original', null)->getStatusCode());
        $draft        = $this->draft('scanning');
        $signer       = $this->get(ChatAttachmentSigner::class);
        $draftRequest = new Request(['e' => '4102444800', 's' => $signer->sign($draft, 'original', $this->ana->getChatPublicId(), null, null, 4_102_444_800)]);
        self::assertSame(404, $downloads->respond($draftRequest, $draft, 'original', $this->ana)->getStatusCode());
        $plain            = new ChatAttachmentDownloads($this->get(ChatAttachments::class), $signer, new ChatAttachmentOptions(), $storage, new FileDeliveryResponder());
        $storage->objects = [];
        self::assertSame(404, $plain->respond($this->signed($this->get(ChatAttachments::class)->downloadUrl($this->luis, $file)->url), $file, 'original', $this->luis)->getStatusCode());
        self::assertSame('', $sent->body);
    }

    public function testAMessageOfFilesOnlyReadsAsTheGenericLineInTheDigest(): void
    {
        $file = $this->clean('quarterly numbers');
        $this->get(ChatAttachments::class)->send($this->ana, $this->channel->getUuid(), '', [$file]);
        $sender     = new RecordingDigestSender();
        $dispatcher = new ChatDigestDispatcher(
            $this->get(TestDirectory::class),
            $this->get(ChatConversationRepository::class),
            $this->get(ChatMessageRepository::class),
            $this->get(ChatUserSettingsRepository::class),
            $this->get(ChatPresence::class),
            $this->get(ChatAccess::class),
            $this->get(ChatPresenter::class),
            new ChatMarkdownRenderer(),
            $sender,
            new ArrayAdapter(),
            '/inbox?chat={uuid}',
            60,
            $this->get(ChatReferences::class),
            null,
            new Clock(),
            $this->kernel->getContainer()->get('translator') instanceof TranslatorInterface ? $this->kernel->getContainer()->get('translator') : null,
        );
        $dispatcher(new SendChatDigestMessage((int) $this->luis->getId()));
        $digest = json_encode($sender->sent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('📎 Attachment', $digest);
        self::assertStringNotContainsString('quarterly', $digest);
        $views = $this->get(ChatAttachmentViews::class)->forMessages([$this->em->getRepository(ChatMessage::class)->findOneBy(['body' => '']) ?? self::fail('message')]);
        self::assertCount(1, $views);
    }

    /**
     * The constructor arguments of ChatAttachments from the container, with some replaced.
     *
     * @param array<string, object> $replace
     *
     * @return array<string, mixed>
     */
    private function rebuild(array $replace): array
    {
        return $replace + [
            'entityManager' => $this->testContainer()->get(ChatKitEntityManager::class),
            'transactions'  => $this->testContainer()->get(ChatKitTransactionRunner::SERVICE_ID),
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

    private function signed(string $url): Request
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return new Request($query);
    }

    private function expectNotFound(callable $call): void
    {
        try {
            $call();
            self::fail('not found expected');
        } catch (ChatNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function draft(string $bytes): string
    {
        return $this->get(ChatAttachments::class)->upload($this->ana, $this->channel->getUuid(), $this->file($bytes), 'f.txt')->uuid;
    }

    private function clean(string $bytes): string
    {
        $uuid = $this->draft($bytes);
        self::assertSame(ChatAttachmentState::Clean, $this->get(ChatAttachmentScan::class)->scan($this->id($uuid)));

        return $uuid;
    }

    private function id(string $uuid): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]);
    }

    private function state(int $id): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT state FROM nowo_chat_attachment WHERE id = ?', [$id]);
    }

    private function rows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_attachment');
    }

    private function message(string $uuid): ChatMessage
    {
        return $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $uuid]) ?? self::fail('message');
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-edge-');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function testContainer(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }
}
