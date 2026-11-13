<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function gc_collect_cycles;
use function json_decode;
use function json_encode;
use function memory_get_usage;

use const JSON_THROW_ON_ERROR;

/**
 * 1.27 (REQ-RUNTIME-002 with REQ-ARCH-001 rule 6): one kernel, the private entity manager on, every request driven through
 * the container's `http_kernel` (`handle()` + `terminate()`, no services resetter, as a FrankenPHP worker with
 * FRANKENPHP_RESET_KERNEL=false). Two people write and read in turn for ROUNDS rounds: each sees only their own
 * conversations, the private manager is dropped after every request, an application change left pending in the
 * application's manager is never flushed by a chat write, and memory stays within MEMORY_MARGIN after WARM_UP_ROUNDS.
 */
final class ChatPrivateEntityManagerWorkerTest extends DatabaseTestCase
{
    private const int ROUNDS = 300;

    private const int WARM_UP_ROUNDS = 30;

    /** Allowed memory growth after the warm-up (bytes). */
    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'limits'   => ['send_per_minute' => 10_000, 'actions_per_minute' => 10_000, 'reads_per_minute' => 10_000],
            'doctrine' => ['private_entity_manager' => true],
        ]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testTwoPeopleWriteInTurnOnOneKernel(): void
    {
        $ana      = $this->user('Ana');
        $luis     = $this->user('Luis');
        $eve      = $this->user('Eve');
        $anaS     = $this->signIn($ana);
        $luisS    = $this->signIn($luis);
        $eveS     = $this->signIn($eve);
        $withLuis = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $anaS, ['userUuid' => $luis->getChatPublicId()]))['conversation']['uuid'];
        $withEve  = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $eveS, ['userUuid' => $luis->getChatPublicId()]))['conversation']['uuid'];
        self::assertIsString($withLuis);
        self::assertIsString($withEve);
        $private = $this->get(ChatKitEntityManager::class);
        // The application keeps a change pending in its own manager for the whole run.
        $pending = new TestUser('Pending', 'pending@example.test');
        $this->em->persist($pending);

        $baseline = 0;
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $response = $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $withLuis . '/messages', $anaS, ['body' => 'ana ' . $round]);
            self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
            self::assertFalse($private->isCreated(), 'round ' . $round . ': dropped after the request');
            $response = $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $withEve . '/messages', $eveS, ['body' => 'eve ' . $round]);
            self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

            $ana = $this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations', $anaS))['conversations'];
            self::assertSame([$withLuis], array_column($ana, 'uuid'), 'round ' . $round . ': Ana sees only her conversation');
            $eve = $this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations', $eveS))['conversations'];
            self::assertSame([$withEve], array_column($eve, 'uuid'), 'round ' . $round . ': Eve sees only hers');
            $denied = $this->raw(Request::METHOD_GET, '/chat/api/conversations/' . $withLuis . '/messages', $eveS);
            self::assertSame(404, $denied->getStatusCode(), 'round ' . $round . ': Eve never reads Ana\'s conversation');

            // The test doubles' own records are not the kernel's state.
            $this->get(RecordingRealtime::class)->published = [];
            $this->get(QueryCounter::class)->clear();

            if ($round === self::WARM_UP_ROUNDS) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        $luisView = $this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations', $luisS))['conversations'];
        self::assertCount(2, $luisView);

        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_user'), 'the pending application insert was never flushed');
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending));
        self::assertSame(2 * self::ROUNDS, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'memory stays flat over the rounds on one kernel');
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
