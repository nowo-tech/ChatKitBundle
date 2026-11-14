<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAuthorBadgeProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_map;
use function assert;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.29, FrankenPHP worker mode with the kernel reset off: two people take turns on ONE kernel, straight through
 * `http_kernel` (no services resetter, no reboot) while the host turns a badge on and off between requests. Every
 * request shows the badges of that request — the memo never carries one over — and asks the provider again.
 */
final class ChatAuthorBadgesWorkerTest extends DatabaseTestCase
{
    private KernelBrowser $client;

    protected function authorBadges(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['limits' => ['reads_per_minute' => 10_000]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;
    }

    public function testTwoRequestsTwoUsersOneKernel(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $bot  = $this->user('Deploy bot');
        $room = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bot, $room, 'deployed');
        $anaCookies  = $this->signIn($ana);
        $luisCookies = $this->signIn($luis);
        $provider    = $this->get(TestAuthorBadgeProvider::class);
        $url         = '/chat/api/conversations/' . $room->getUuid() . '/messages';

        for ($round = 0; $round < 6; ++$round) {
            // Ana's request: the bot is an agent in even rounds.
            $provider->badges = $round % 2 === 0 ? [(string) $bot->getId() => [new ChatAuthorBadge('Agent ' . $round, 'agent')]] : [];
            $provider->clear();
            self::assertSame($round % 2 === 0 ? ['Agent ' . $round] : [], $this->labels($this->raw($url, $anaCookies)), 'round ' . $round . ': Ana');
            self::assertCount(1, $provider->calls, 'round ' . $round . ': Ana\'s request asks the provider');

            // Luis's request on the same kernel: the host changed its mind in between.
            $provider->badges = $round % 2 === 0 ? [] : [(string) $bot->getId() => [new ChatAuthorBadge('Bot ' . $round, 'info')]];
            $provider->clear();
            self::assertSame($round % 2 === 0 ? [] : ['Bot ' . $round], $this->labels($this->raw($url, $luisCookies)), 'round ' . $round . ': Luis never sees Ana\'s request');
            self::assertCount(1, $provider->calls, 'round ' . $round . ': Luis\'s request asks the provider');
        }
    }

    /**
     * @return list<string> the badge labels of the first message
     */
    private function labels(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{messages: list<array{authorBadges?: list<array{label: string}>}>} $data */
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return array_map(static fn (array $badge): string => $badge['label'], $data['messages'][0]['authorBadges'] ?? []);
    }

    /**
     * @return array<string, string>
     */
    private function signIn(TestUser $user): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($user);
        $this->client->request(Request::METHOD_GET, '/page');
        $cookies = [];
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        self::assertNotSame([], $cookies);

        return $cookies;
    }

    /**
     * One request straight through the container's `http_kernel`: no kernel boot check, no services resetter.
     *
     * @param array<string, string> $cookies
     */
    private function raw(string $uri, array $cookies): Response
    {
        $kernel = $this->kernel->getContainer()->get('http_kernel');
        assert($kernel instanceof HttpKernelInterface && $kernel instanceof TerminableInterface);
        $request  = Request::create($uri, Request::METHOD_GET, [], $cookies, [], ['HTTP_ACCEPT' => 'application/json']);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
