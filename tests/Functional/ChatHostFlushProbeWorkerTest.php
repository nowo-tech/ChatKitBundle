<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Deprecation\DeprecationRecorder;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.39 (REQ-RUNTIME-002, worker mode with kernel reset off): one kernel, every request driven through the container's
 * `http_kernel` (`handle()` + `terminate()`, no services resetter). The application leaves a change pending before each
 * request; the host-flush probe reports it in every request (what it reported is forgotten at the boundary, never kept
 * for the next request of the worker), and once per request however many chat flushes it makes.
 */
final class ChatHostFlushProbeWorkerTest extends DatabaseTestCase
{
    private const int ROUNDS = 5;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->em instanceof ChatKitEntityManager) {
            self::markTestSkipped('Host mode only: the private manager replay never flushes the application\'s manager.');
        }
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testEveryRequestOfTheWorkerReportsAgain(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $session = $this->signIn($ana);
        $dm      = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $session, ['userUuid' => $luis->getChatPublicId()]))['conversation']['uuid'];
        self::assertIsString($dm);
        $pending = $this->em->find(TestUser::class, $luis->getId());
        self::assertInstanceOf(TestUser::class, $pending);

        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $pending->setChatLocale('l' . $round);
            [$response, $messages] = DeprecationRecorder::record('flushed by NowoChatKitBundle', fn (): Response => $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $dm . '/messages', $session, ['body' => 'round ' . $round]));
            self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
            self::assertCount(1, $messages, 'round ' . $round . ': reported once in this request');
            self::assertStringContainsString(TestUser::class, $messages[0]);
            self::assertSame('l' . $round, $this->em->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$luis->getId()]), 'still flushed in 1.x');
        }
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
