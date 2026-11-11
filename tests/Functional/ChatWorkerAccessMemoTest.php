<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Nowo\ChatKitBundle\Tests\Support\Chat\CountingAvatarResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestGrantReferenceProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_column;
use function assert;
use function json_decode;
use function json_encode;
use function substr_count;

use const JSON_THROW_ON_ERROR;

/**
 * 1.26.3 (REQ-RUNTIME-002): one kernel kept alive with NO services resetter between requests (the container's
 * `http_kernel` called directly, as a FrankenPHP worker with FRANKENPHP_RESET_KERNEL=false does). A reference card
 * is access-filtered per viewer and memoised for the request: when the application revokes the viewer's access in the
 * database between two requests, the next request must show the neutral "Content not visible" chip, never the cached
 * card. The same holds for two people served in turn while one of them is granted and revoked on every round, and for
 * a profile picture changed by the host between two requests.
 */
final class ChatWorkerAccessMemoTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['limits' => ['actions_per_minute' => 10_000, 'reads_per_minute' => 10_000]]]];
    }

    protected function grantReferences(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
        $this->get(TestGrantReferenceProvider::class)->createTable();
    }

    public function testAccessRevokedInTheDatabaseIsRefusedOnTheNextRequest(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->grant($ana, '7');
        $this->grant($luis, '7');
        [$anaSession, $luisSession, $uuid] = $this->directConversationWithDoc($ana, $luis);

        // Luis is granted: the card is served (and memoised for him in ChatReferences).
        $message = $this->messages($uuid, $luisSession)[0];
        self::assertStringContainsString('Doc 7 (granted)', $message['html']);
        self::assertSame(['7'], array_column($message['references'], 'id'));

        // The application revokes Luis's access with plain SQL; the same worker serves his next request.
        $this->revoke($luis, '7');
        $message = $this->messages($uuid, $luisSession)[0];
        self::assertStringNotContainsString('Doc 7', $message['html'], 'a revoked item must not keep its card in the worker');
        self::assertStringNotContainsString('/docs/7', $message['html']);
        self::assertSame(1, substr_count($message['html'], 'nowo-chat-ref--restricted'));
        self::assertSame([], $message['references']);

        // Ana keeps her access.
        self::assertStringContainsString('Doc 7 (granted)', $this->messages($uuid, $anaSession)[0]['html']);

        // Granted again: the card comes back on the next request.
        $this->grant($luis, '7');
        self::assertSame(['7'], array_column($this->messages($uuid, $luisSession)[0]['references'], 'id'));
    }

    public function testTwoPeopleInTurnWhileOneIsGrantedAndRevokedEveryRound(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->grant($ana, '7');
        $this->grant($luis, '7');
        [$anaSession, $luisSession, $uuid] = $this->directConversationWithDoc($ana, $luis);
        $resolver                          = $this->get(CountingAvatarResolver::class);
        $realtime                          = $this->get(RecordingRealtime::class);
        $queries                           = $this->get(QueryCounter::class);

        $granted = true;
        for ($round = 0; $round < 200; ++$round) {
            // The host changes Ana's picture between two requests.
            $resolver->urls = [$ana->getChatPublicId() => '/files/ana/' . $round . '.webp'];

            $mine = $this->messages($uuid, $anaSession)[0];
            self::assertSame(['7'], array_column($mine['references'], 'id'), 'round ' . $round . ': Ana is always granted');

            $seen = $this->messages($uuid, $luisSession)[0];
            self::assertSame($granted ? ['7'] : [], array_column($seen['references'], 'id'), 'round ' . $round . ': Luis sees the card only while granted');
            self::assertSame($granted ? 0 : 1, substr_count($seen['html'], 'nowo-chat-ref--restricted'));
            self::assertSame('/files/ana/' . $round . '.webp', $seen['authorAvatarUrl'], 'round ' . $round . ': the picture of this request, not a memoised one');

            $granted ? $this->revoke($luis, '7') : $this->grant($luis, '7');
            $granted = !$granted;
            // The test doubles' own records are not the kernel's state.
            $resolver->calls     = [];
            $realtime->published = [];
            $queries->clear();
        }
    }

    /**
     * Signs both people in, opens their direct conversation and has Ana post a `/doc` reference to document 7.
     *
     * @return array{array{cookies: array<string, string>, csrf: string}, array{cookies: array<string, string>, csrf: string}, string}
     */
    private function directConversationWithDoc(TestUser $ana, TestUser $luis): array
    {
        $anaSession  = $this->signIn($ana);
        $luisSession = $this->signIn($luis);
        // From here on only the raw worker path: no kernel reboot, no services resetter.
        $uuid = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $anaSession, ['userUuid' => $luis->getChatPublicId()]))['conversation']['uuid'];
        self::assertIsString($uuid);
        $sent = $this->json($this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $uuid . '/messages', $anaSession, ['body' => 'see #[x](doc:7)']))['message'];
        self::assertSame(['7'], array_column($sent['references'], 'id'), 'both people may open document 7, so the token is kept');

        return [$anaSession, $luisSession, $uuid];
    }

    /**
     * @param array{cookies: array<string, string>, csrf: string} $session
     *
     * @return list<array<string, mixed>>
     */
    private function messages(string $uuid, array $session): array
    {
        $response = $this->raw(Request::METHOD_GET, '/chat/api/conversations/' . $uuid . '/messages', $session);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var list<array<string, mixed>> $messages */
        $messages = $this->json($response)['messages'];

        return $messages;
    }

    private function grant(TestUser $user, string $item): void
    {
        $this->connection()->insert(TestGrantReferenceProvider::TABLE, ['user_id' => $user->getId(), 'item_id' => $item]);
    }

    private function revoke(TestUser $user, string $item): void
    {
        $this->connection()->delete(TestGrantReferenceProvider::TABLE, ['user_id' => $user->getId(), 'item_id' => $item]);
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }

    /**
     * Signs a person in with the test browser (a new session) and loads a page with the dock.
     *
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
        self::assertNotSame([], $cookies);

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
