<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentObjects;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Contract\ChatAttachmentsInterface;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Attachment\InMemoryStorage;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingPushSender;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Translation\DataCollectorTranslator;

use function assert;
use function file_put_contents;
use function gc_collect_cycles;
use function is_string;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function ob_end_clean;
use function ob_start;
use function sys_get_temp_dir;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * 2.7 (spec 259 §20, REQ-RUNTIME-002): attachments on one kernel with no services resetter (FrankenPHP worker mode,
 * kernel reset false), every request straight through `http_kernel`. Two people in two sessions upload, scan, send and
 * download for hundreds of rounds; every other round the host blocks the file half-way: the next download is refused
 * at once (no memoised access, URL or row), and memory stays flat.
 */
final class ChatAttachmentsWorkerTest extends DatabaseTestCase
{
    private const int ROUNDS = 300;

    private const int WARM_UP_ROUNDS = 50;

    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    protected function attachments(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'limits'      => ['send_per_minute' => 100_000, 'actions_per_minute' => 100_000, 'reads_per_minute' => 100_000],
            'attachments' => ['enabled' => true, 'scanner' => TestScanner::class, 'limits' => ['uploads_per_minute' => 100_000, 'downloads_per_minute' => 100_000, 'upload_bytes_per_hour' => 1_073_741_824]],
        ]]];
    }

    public function testAccessIsCheckedEveryRequestAndMemoryStaysFlat(): void
    {
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $channel = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $this->get(ChatService::class)->openChannel($luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $base        = '/chat/api/conversations/' . $channel->getUuid();
        $storage     = $this->get(InMemoryStorage::class);
        $scanner     = $this->get(TestScanner::class);
        $realtime    = $this->get(RecordingRealtime::class);
        $push        = $this->get(RecordingPushSender::class);
        $path        = (string) tempnam(sys_get_temp_dir(), 'chat-worker-');
        $anaSession  = $this->signIn($client, $ana);
        $luisSession = $this->signIn($client, $luis);

        $baseline = 0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            file_put_contents($path, 'round ' . $round);
            $upload = $this->raw('POST', $base . '/attachments', $anaSession, null, ['file' => new UploadedFile($path, 'r.txt', null, null, true)]);
            self::assertSame(201, $upload->getStatusCode(), 'round ' . $round . ': ' . $upload->getContent());
            $uuid = self::json($upload)['attachment']['uuid'];
            $this->get(ChatAttachmentScan::class)->scan((int) $this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$uuid]));
            self::assertSame(201, $this->raw('POST', $base . '/messages', $anaSession, ['body' => 'r', 'attachments' => [$uuid]])->getStatusCode());

            $url = self::json($this->raw('GET', '/chat/api/attachments/' . $uuid, $luisSession))['url'];
            assert(is_string($url));
            $blocked = $round % 2 === 1;
            if ($blocked) {
                $this->get(ChatAttachmentsInterface::class)->block($uuid);
            }
            $download = $this->raw('GET', $url, $luisSession);
            ob_start();
            $download->sendContent();
            ob_end_clean();
            self::assertSame($blocked ? 404 : 200, $download->getStatusCode(), 'round ' . $round . ': access as the rows say now');

            $this->get(ChatAttachmentObjects::class)->remove(null);
            $storage->calls      = [];
            $scanner->scanned    = [];
            $realtime->published = [];
            $push->sent          = [];
            $storage->objects    = [];
            // Test infrastructure only (not the kernel's state): the in-memory transport, the translation collector and
            // the query counter record every envelope, lookup and statement.
            $transport = $this->testContainer()->get('messenger.transport.chat_attachments');
            assert($transport instanceof InMemoryTransport);
            $transport->reset();
            $collector = $this->testContainer()->get(TestKernel::TRANSLATOR_COLLECTOR);
            assert($collector instanceof DataCollectorTranslator);
            $collector->reset();
            $this->get(QueryCounter::class)->clear();
            if ($round === self::WARM_UP_ROUNDS) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'memory stays flat over the rounds on one kernel');
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function signIn(KernelBrowser $client, TestUser $user): array
    {
        $client->getCookieJar()->clear();
        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/page');
        /** @var array{csrf: string} $config */
        $config  = json_decode((string) $crawler->filter('[data-nowo-chat-config]')->attr('data-nowo-chat-config'), true, 512, JSON_THROW_ON_ERROR);
        $cookies = [];
        foreach ($client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return ['cookies' => $cookies, 'csrf' => $config['csrf']];
    }

    /**
     * One request straight through the container's `http_kernel`: no services resetter (worker mode).
     *
     * @param array{cookies: array<string, string>, csrf: string} $session
     * @param array<string, mixed>|null $body
     * @param array<string, UploadedFile> $files
     */
    private function raw(string $method, string $uri, array $session, ?array $body = null, array $files = []): Response
    {
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $server   = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $session['csrf']] + ($body === null ? [] : ['CONTENT_TYPE' => 'application/json']);
        $request  = Request::create($uri, $method, [], $session['cookies'], $files, $server, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function testContainer(): ContainerInterface
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }
}
