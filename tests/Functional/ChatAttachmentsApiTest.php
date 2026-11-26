<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentObjects;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Contract\ChatAttachmentsInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Event\ChatAttachmentBlockedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentRemovedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentScannedEvent;
use Nowo\ChatKitBundle\Event\ChatAttachmentUploadedEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\InMemoryStorage;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function assert;
use function file_put_contents;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * 2.7 (spec 259 block B): attachments through the dock's JSON API, end to end on the test kernel (StorageKit's ports in
 * memory, the test scanner). SC-002: nobody but the uploader ever sees a file before its scan is clean; downloads are
 * signed per reader, bound to the session and re-checked; files go with their message.
 */
final class ChatAttachmentsApiTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    /** @var list<object> */
    private array $events = [];

    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'push'         => true,
            'push_preview' => true,
            'attachments'  => ['enabled' => true, 'scanner' => TestScanner::class, 'max_pending_per_user' => 4, 'scan' => ['max_attempts' => 2]],
        ]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
        $dispatcher   = $this->kernel->getContainer()->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        foreach ([ChatAttachmentUploadedEvent::class, ChatAttachmentScannedEvent::class, ChatAttachmentBlockedEvent::class, ChatAttachmentRemovedEvent::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testAFileIsTheUploadersAloneUntilCleanThenEveryReadersThroughSignedUrls(): void
    {
        [$ana, $luis, $channel] = $this->channel();
        $config                 = $this->login($ana);
        self::assertSame(10, $config['attachments']['maxPerMessage']);
        self::assertContains('pdf', $config['attachments']['extensions']);
        self::assertNotContains('svg', $config['attachments']['extensions']);
        $base = '/chat/api/conversations/' . $channel->getUuid();

        $draft = $this->upload($base, 'notes.md', "# Notes\nhello", $config['csrf'])['attachment'];
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame(['name' => 'notes.md', 'extension' => 'md', 'mediaType' => 'text/markdown', 'kind' => 'markdown', 'state' => 'uploaded'], ['name' => $draft['name'], 'extension' => $draft['extension'], 'mediaType' => $draft['mediaType'], 'kind' => $draft['kind'], 'state' => $draft['state']]);
        self::assertSame([ChatAttachmentUploadedEvent::class], array_map(static fn (object $event): string => $event::class, $this->events));
        $storage = $this->get(InMemoryStorage::class);
        self::assertCount(1, $storage->references, 'the file is referenced (never an orphan for StorageKit\'s purge)');
        // The uploader sees the draft; nobody can download it before the scan (even its uploader).
        self::assertSame([$draft['uuid']], array_column($this->getJson($base . '/attachments/drafts')['attachments'], 'uuid'));
        self::assertNull($this->getJson('/chat/api/attachments/' . $draft['uuid'])['url']);

        // A send with a file still scanning is refused whole: no message.
        $refused = $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'see', 'attachments' => [$draft['uuid']]], $config['csrf']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('attachment_not_ready', $refused['error']);
        self::assertSame(0, $this->messages());

        // SC-002: another member sees nothing of the draft.
        $this->login($luis);
        $this->client->request(Request::METHOD_GET, '/chat/api/attachments/' . $draft['uuid']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->getJson($base . '/attachments/drafts')['attachments']);

        // The scan: clean, the uploader alone is told (ids and the state only).
        $realtime            = $this->get(RecordingRealtime::class);
        $realtime->published = [];
        self::assertSame(ChatAttachmentState::Clean, $this->scan($draft['uuid']));
        self::assertSame(['attachment'], array_column(array_column($realtime->published, 'payload'), 'type'));
        /** @var list<array{topics: list<string>, payload: array<string, mixed>}> $published */
        $published = $realtime->published;
        $event     = $published[0]['payload'] ?? [];
        unset($event['type']);
        self::assertSame(['conversationUuid' => $channel->getUuid(), 'attachmentUuid' => $draft['uuid'], 'state' => 'clean'], $event);
        self::assertNull($this->scan($draft['uuid']), 'a replayed scan message changes nothing');

        // A message of files only (no text).
        $config = $this->login($ana);
        $sent   = $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => '', 'attachments' => [$draft['uuid']]], $config['csrf'])['message'];
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), json_encode($sent, JSON_THROW_ON_ERROR));
        self::assertSame([$draft['uuid']], array_column($sent['attachments'], 'uuid'));
        self::assertSame([], $this->getJson($base . '/attachments/drafts')['attachments'], 'no longer a draft');
        // Sent twice: refused (already attached).
        $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'again', 'attachments' => [$draft['uuid']]], $config['csrf']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        // The reader: the page lists it; a URL signed for them; the download works in their session only.
        $this->login($luis);
        $page = $this->getJson($base . '/messages')['messages'];
        self::assertSame([$draft['uuid']], array_column($page[0]['attachments'], 'uuid'));
        $shown = $this->getJson('/chat/api/attachments/' . $draft['uuid']);
        $url   = $shown['url'];
        assert(is_string($url));
        $this->client->request(Request::METHOD_GET, $url);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('deliver:attachment:60', implode(',', $storage->calls), 'StorageKit 60-second URL, attachment disposition');
        // Tampered, another variant, Ana's session: the same empty 404.
        foreach ([$url . 'x', str_replace('/original?', '/thumb?', $url)] as $bad) {
            $this->client->request(Request::METHOD_GET, $bad);
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
            self::assertSame('', (string) $this->client->getResponse()->getContent());
        }
        $this->login($ana);
        $this->client->request(Request::METHOD_GET, $url);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'bound to the reader\'s session');
        // Expired.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->login($luis);
        $this->client->request(Request::METHOD_GET, str_replace('e=' . (is_string($query['e'] ?? null) ? $query['e'] : ''), 'e=1000', $url));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        // Luis may not remove Ana's file; Ana removes it: gone for everyone, objects removed after the commit.
        $this->client->request(Request::METHOD_DELETE, '/chat/api/attachments/' . $draft['uuid'], server: ['HTTP_X_CSRF_TOKEN' => $this->login($luis)['csrf']]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $config = $this->login($ana);
        $this->client->request(Request::METHOD_DELETE, '/chat/api/attachments/' . $draft['uuid'], server: ['HTTP_X_CSRF_TOKEN' => $config['csrf']]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->getJson($base . '/messages')['messages'][0]['attachments']);
        $this->get(ChatAttachmentObjects::class)->remove(null);
        self::assertSame([], $storage->objects);
        self::assertSame(0, $this->rows(), 'a deleted row goes once its objects are removed');
    }

    public function testInfectedUnscannableAndFailedFilesAreNeverServedAndTheirObjectsGo(): void
    {
        [$ana, , $channel] = $this->channel();
        $config            = $this->login($ana);
        $base              = '/chat/api/conversations/' . $channel->getUuid();
        $infected          = $this->upload($base, 'virus.txt', 'X5O EICAR test', $config['csrf'])['attachment'];
        $locked            = $this->upload($base, 'locked.txt', 'ENCRYPTED', $config['csrf'])['attachment'];
        $crash             = $this->upload($base, 'crash.txt', 'CRASH', $config['csrf'])['attachment'];
        self::assertSame(ChatAttachmentState::Blocked, $this->scan($infected['uuid']));
        self::assertSame(ChatAttachmentState::Blocked, $this->scan($locked['uuid']));
        // A scanner failure is a retry (never fail-open), then failed after max_attempts (2).
        self::assertSame(ChatAttachmentState::Uploaded, $this->scan($crash['uuid']));
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_attachment SET lease_until = NULL');
        self::assertSame(ChatAttachmentState::Failed, $this->scan($crash['uuid']));
        $scanned = array_values(array_filter($this->events, static fn (object $event): bool => $event instanceof ChatAttachmentScannedEvent));
        self::assertSame(['blocked', 'blocked', 'failed'], array_column($scanned, 'state'));
        self::assertSame('Eicar-Test-Signature', $scanned[0]->signature);

        foreach ([$infected, $locked, $crash] as $file) {
            $this->requestJson(Request::METHOD_POST, $base . '/messages', ['body' => 'x', 'attachments' => [$file['uuid']]], $config['csrf']);
            self::assertSame(422, $this->client->getResponse()->getStatusCode());
            self::assertNull($this->getJson('/chat/api/attachments/' . $file['uuid'])['url']);
        }
        self::assertSame(['blocked', 'blocked', 'failed'], array_column($this->getJson($base . '/attachments/drafts')['attachments'], 'state'), 'the uploader sees why');
        $storage = $this->get(InMemoryStorage::class);
        $this->get(ChatAttachmentObjects::class)->remove(null);
        self::assertCount(1, $storage->objects, 'blocked objects removed; a failed one stays until removed');
        self::assertSame(3, $this->rows(), 'blocked rows stay for their uploader');
    }

    public function testUploadRefusals(): void
    {
        [$ana, , $channel] = $this->channel();
        $config            = $this->login($ana);
        $base              = '/chat/api/conversations/' . $channel->getUuid();
        $cases             = [
            ['run.exe', 'MZ binary', 415, 'file_type_not_allowed'],
            ['picture.png', 'not a png at all', 415, 'type_mismatch'],
            ['drawing.svg', '<svg/>', 415, 'file_type_not_allowed'],
            ["\u{202E}..", 'x', 400, 'invalid_file_name'],
        ];
        foreach ($cases as [$name, $bytes, $status, $error]) {
            $answer = $this->upload($base, $name, $bytes, $config['csrf']);
            self::assertSame($status, $this->client->getResponse()->getStatusCode(), $name);
            self::assertSame($error, $answer['error'], $name);
        }
        // No file, a compressed body, a bad CSRF token, a foreign conversation.
        $this->client->request(Request::METHOD_POST, $base . '/attachments', server: ['HTTP_X_CSRF_TOKEN' => $config['csrf']]);
        self::assertSame('invalid_file', $this->decode()['error']);
        $this->upload($base, 'a.txt', 'x', $config['csrf'], ['HTTP_CONTENT_ENCODING' => 'gzip']);
        self::assertSame('content_encoding_refused', $this->decode()['error']);
        $this->upload($base, 'a.txt', 'x', 'nope');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $stranger = $this->user('Stranger Sam');
        $this->login($stranger);
        $this->client->request(Request::METHOD_GET, $base . '/attachments/drafts');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        // The draft quota (4 here).
        $config = $this->login($ana);
        for ($i = 0; $i < 4; ++$i) {
            $this->upload($base, 'f' . $i . '.txt', 'ok ' . $i, $config['csrf']);
            self::assertSame(201, $this->client->getResponse()->getStatusCode());
        }
        self::assertSame('too_many_pending', $this->upload($base, 'f5.txt', 'ok', $config['csrf'])['error']);
        self::assertSame(4, $this->rows());
        self::assertSame(0, $this->messages());
    }

    public function testAnIdempotencyKeyReplaysTheFirstUploadAndAMismatchIsRefused(): void
    {
        [$ana, , $channel] = $this->channel();
        $config            = $this->login($ana);
        $base              = '/chat/api/conversations/' . $channel->getUuid();
        $first             = $this->upload($base, 'a.txt', 'same', $config['csrf'], ['HTTP_IDEMPOTENCY_KEY' => 'k-1'])['attachment'];
        $again             = $this->upload($base, 'a.txt', 'same', $config['csrf'], ['HTTP_IDEMPOTENCY_KEY' => 'k-1'])['attachment'];
        self::assertSame($first['uuid'], $again['uuid']);
        self::assertSame(1, $this->rows());
        self::assertCount(1, $this->get(InMemoryStorage::class)->objects, 'the replay stored nothing');
        self::assertSame('idempotency_mismatch', $this->upload($base, 'b.txt', 'other', $config['csrf'], ['HTTP_IDEMPOTENCY_KEY' => 'k-1'])['error']);
        self::assertSame('idempotency_key_invalid', $this->upload($base, 'b.txt', 'other', $config['csrf'], ['HTTP_IDEMPOTENCY_KEY' => 'client:x'])['error']);
    }

    public function testFilesGoWithTheirMessageAndThePersonsErasure(): void
    {
        [$ana, $luis, $channel] = $this->channel();
        $attachments            = $this->get(ChatAttachmentsInterface::class);
        $file                   = $this->stored($attachments->upload($ana, $channel->getUuid(), $this->file('one'), 'one.txt')->uuid);
        $this->scan($file);
        $sent = $attachments->send($ana, $channel->getUuid(), 'with a file', [$file]);
        self::assertSame(1, $this->countState('clean'));
        // The message deleted: its file is deleted in the same unit, the objects go after the commit.
        $this->get(ChatService::class)->delete($ana, $this->message($sent->uuid));
        self::assertSame(1, $this->countState('deleted'));
        $this->get(ChatAttachmentObjects::class)->remove(null);
        self::assertSame([], $this->get(InMemoryStorage::class)->objects);

        // Erasure: every upload of the person goes (drafts and sent), the rows forget them.
        $draft  = $attachments->upload($ana, $channel->getUuid(), $this->file('two'), 'two.txt');
        $other  = $attachments->upload($luis, $channel->getUuid(), $this->file('three'), 'three.txt');
        $export = $this->get(ChatPersonalData::class)->export($ana);
        self::assertSame(['two.txt'], array_column($export['attachments'] ?? [], 'name'));
        $this->get(ChatPersonalData::class)->erase($ana);
        self::assertSame(ChatAttachmentState::Deleted->value, $this->stateOf($draft->uuid));
        self::assertSame(ChatAttachmentState::Uploaded->value, $this->stateOf($other->uuid), 'another person\'s file stays');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_attachment WHERE uploader_id IS NOT NULL AND state = ?', ['deleted']));
    }

    public function testPushAndListPreviewOfAMessageOfFilesOnlyNeverNameTheFile(): void
    {
        [$ana, $luis, $channel] = $this->channel();
        $settings               = $this->em->getRepository(ChatUserSettings::class)->forUser($luis);
        $settings->setPushEnabled(true);
        $this->em->persist($settings);
        $this->em->flush();
        $attachments = $this->get(ChatAttachmentsInterface::class);
        $file        = $attachments->upload($ana, $channel->getUuid(), $this->file('secret plans'), 'Q4 layoffs.pdf.txt');
        $this->scan($file->uuid);
        $attachments->send($ana, $channel->getUuid(), '', [$file->uuid]);
        $push = json_encode($this->get(RecordingPushSender::class)->sent, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('layoffs', $push);
        self::assertStringContainsString('Attachment', $push);
        $this->login($luis);
        $list = json_encode($this->getJson('/chat/api/conversations'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('layoffs', $list);
    }

    /**
     * @return array{TestUser, TestUser, ChatConversation}
     */
    private function channel(): array
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $this->get(ChatService::class)->openChannel($luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        return [$ana, $luis, $channel];
    }

    private function scan(string $uuid): ?ChatAttachmentState
    {
        return $this->get(ChatAttachmentScan::class)->scan((int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]));
    }

    private function stored(string $uuid): string
    {
        return $uuid;
    }

    private function stateOf(string $uuid): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT state FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]);
    }

    private function countState(string $state): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_attachment WHERE state = ?', [$state]);
    }

    private function message(string $uuid): ChatMessage
    {
        $message = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $uuid]);
        assert($message instanceof ChatMessage);

        return $message;
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-att-');
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function upload(string $base, string $name, string $bytes, string $csrf, array $server = []): array
    {
        $file = new UploadedFile($this->file($bytes), $name, null, null, true);
        $this->client->request(Request::METHOD_POST, $base . '/attachments', [], ['file' => $file], ['HTTP_X_CSRF_TOKEN' => $csrf] + $server);

        return $this->decode();
    }

    private function rows(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_attachment');
    }

    private function messages(): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(m)')->from(ChatMessage::class, 'm')->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, mixed>
     */
    private function login(TestUser $user): array
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/page');
        /** @var array<string, mixed> $config */
        $config = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url): array
    {
        $this->client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        return $this->decode();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $body, string $csrf): array
    {
        $this->client->request($method, $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->decode();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
