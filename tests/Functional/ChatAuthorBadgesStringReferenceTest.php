<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Model\ChatAuthorBadge;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Port\Ports;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAuthorBadgeProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_combine;
use function array_map;
use function assert;
use function bin2hex;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.29: author badges with `doctrine.user_reference: string` (a DDD host whose users are known by a string). The
 * provider is asked by the exact stored reference, and a badge never attaches to a look-alike's messages (`Bob`,
 * `bob`, `bób`), in the API and on the page.
 */
final class ChatAuthorBadgesStringReferenceTest extends DatabaseTestCase
{
    private RefDirectory $directory;

    private KernelBrowser $client;

    private TestAuthorBadgeProvider $provider;

    protected function authorBadges(): bool
    {
        return true;
    }

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'user_class' => null,
            'doctrine'   => ['user_reference' => 'string'],
        ]]];
    }

    protected function hostFixtures(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        if (TestKernel::twinPorts()) {
            self::markTestSkipped('This host wires its own twin resolver.');
        }
        parent::setUp();
        $this->directory = new RefDirectory();
        $container       = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $container->set(TestDirectory::class, $this->directory);
        $container->set(SecurityChatUserResolver::class, Ports::currentUser($this->directory));
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client   = $client;
        $this->provider = $this->get(TestAuthorBadgeProvider::class);
    }

    public function testABadgeKeyedByTheExactReferenceNeverReachesALookAlike(): void
    {
        $ana  = $this->person('7c1e-ana', 'Ana');
        $bigB = $this->person('Bob', 'Bob agent');
        $bob  = $this->person('bob', 'Bob human');
        $bob2 = $this->person('bób', 'Bob accent');
        $chat = $this->get(ChatServiceInterface::class);
        $room = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($bigB, $room, 'deployed');
        $chat->send($bob, $room, 'thanks');
        $chat->send($bob2, $room, 'me too');
        $this->provider->badges = ['Bob' => [new ChatAuthorBadge('Agent', 'agent')]];

        $this->signIn('7c1e-ana');
        $this->provider->clear();
        $this->client->request(Request::METHOD_GET, '/chat/api/conversations/' . $room->getUuid() . '/messages', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        /** @var array{messages: list<array<string, mixed>>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([['Bob', 'bob', 'bób']], $this->provider->calls, 'three people, asked by their exact references');
        self::assertSame(
            ['Bob agent' => 'Agent', 'Bob human' => null, 'Bob accent' => null],
            array_combine(
                array_map(static fn (array $message): string => (string) $message['authorName'], $data['messages']),
                array_map(static fn (array $message): ?string => $message['authorBadges'][0]['label'] ?? null, $data['messages']),
            ),
        );

        $crawler = $this->client->request(Request::METHOD_GET, '/chat/' . $room->getUuid());
        self::assertSame(['Bob agent Agent', 'Bob human', 'Bob accent'], $crawler->filter('.nowo-chat-page__messages .nowo-chat-msg__author')->each(static fn ($node): string => $node->text()));
    }

    private function person(string $reference, string $name): RefChatUser
    {
        $account = new TestUser($name);
        $this->em->persist($account);
        $this->em->flush();

        return $this->directory->add(new RefChatUser($reference, 'pub-' . bin2hex($reference), $name), $account->getChatPublicId());
    }

    private function signIn(string $reference): void
    {
        foreach ($this->em->getRepository(TestUser::class)->findAll() as $account) {
            $user = $this->directory->resolve(new ChatSecurityUser(TestUser::class, null, $account->getChatPublicId()));
            if ($user instanceof RefChatUser && $user->getChatUserReference() === $reference) {
                $this->client->loginUser($account);
                $this->client->request(Request::METHOD_GET, '/page');

                return;
            }
        }
        self::fail('unknown ' . $reference);
    }
}
