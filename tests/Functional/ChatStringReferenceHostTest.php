<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Functional;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatSecurityUser;
use Nowo\ChatKitBundle\Port\Ports;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function array_column;
use function assert;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.27 (REQ-ARCH-001 rule 2 + REQ-SEC-006): a strict DDD host — chat users that are plain PHP objects known only by a
 * string reference (ChatUserReferenceInterface, no integer id, not Doctrine entities), `doctrine.user_reference: id`,
 * the private entity manager, and the host ports through their framework-free twins only (the current user through
 * ChatParticipantResolverInterface). Look-alike references (`bob`, `Bob`, `bób`) are different people everywhere: direct
 * pairs, participant rows, settings, the directory's answers and the erasure; unusable references are refused before
 * anything is written.
 */
final class ChatStringReferenceHostTest extends DatabaseTestCase
{
    private RefDirectory $directory;

    private KernelBrowser $client;

    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => [
            'user_class' => null,
            'doctrine'   => ['user_reference' => 'string', 'private_entity_manager' => true],
            'limits'     => ['send_per_minute' => 10_000, 'actions_per_minute' => 10_000, 'reads_per_minute' => 10_000],
        ]]];
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
        $this->client = $client;
    }

    public function testLookAlikeReferencesAreDifferentPeopleThroughTheApi(): void
    {
        $bob  = $this->person('bob', 'Bob lower');
        $bigB = $this->person('Bob', 'Bob upper');
        $bob2 = $this->person('bób', 'Bob accent');
        $ana  = $this->person('7c1e-ana', 'Ana');

        $anaSession = $this->signIn('7c1e-ana');
        $withBob    = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $anaSession, ['userUuid' => $bob->getChatPublicId()]))['conversation']['uuid'];
        $withBigB   = $this->json($this->raw(Request::METHOD_POST, '/chat/api/direct', $anaSession, ['userUuid' => $bigB->getChatPublicId()]))['conversation']['uuid'];
        self::assertNotSame($withBob, $withBigB, 'bob and Bob are two people');
        $this->raw(Request::METHOD_POST, '/chat/api/conversations/' . $withBob . '/messages', $anaSession, ['body' => 'for bob only']);

        $conversation = $this->get(ChatKitEntityManager::class)->getRepository(ChatConversation::class)->findOneBy(['uuid' => $withBob]);
        self::assertInstanceOf(ChatConversation::class, $conversation);
        self::assertStringStartsWith(ChatConversation::HASHED_DIRECT_KEY, (string) $conversation->getDirectKey());
        self::assertSame(['7c1e-ana', 'bob'], $conversation->directUserKeys());
        self::assertSame([], $conversation->directUserIds());
        self::assertTrue($conversation->involvesDirectly($bob));
        self::assertFalse($conversation->involvesDirectly($bigB));
        self::assertFalse($conversation->involvesDirectly($bob2));
        self::assertSame('bob', $conversation->peerKeyOf($ana));
        self::assertNull($conversation->peerIdOf($ana));

        $bobSession  = $this->signIn('bob');
        $bigBSession = $this->signIn('Bob');
        $bob2Session = $this->signIn('bób');
        $list        = $this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations', $bobSession))['conversations'];
        self::assertSame([$withBob], array_column($list, 'uuid'));
        self::assertSame('Ana', $list[0]['title']);
        self::assertSame(1, $list[0]['unread']);
        self::assertSame([$withBigB], array_column($this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations', $bigBSession))['conversations'], 'uuid'));
        self::assertSame([], $this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations', $bob2Session))['conversations']);
        self::assertSame(404, $this->raw(Request::METHOD_GET, '/chat/api/conversations/' . $withBob . '/messages', $bigBSession)->getStatusCode(), 'Bob never reads bob\'s thread');
        self::assertSame(404, $this->raw(Request::METHOD_GET, '/chat/api/conversations/' . $withBob . '/messages', $bob2Session)->getStatusCode());
        $messages = $this->json($this->raw(Request::METHOD_GET, '/chat/api/conversations/' . $withBob . '/messages', $bobSession))['messages'];
        self::assertSame(['for bob only'], array_map(static fn (array $m): string => strip_tags((string) $m['html']), $messages));

        // The rows hold the exact references.
        $refs = $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_participant ORDER BY user_id');
        self::assertSame(['7c1e-ana', '7c1e-ana', 'Bob', 'bob'], $refs);
    }

    public function testTheDirectoryMustAnswerTheExactReference(): void
    {
        $this->person('bob', 'Bob lower');
        $bigB                     = $this->person('Bob', 'Bob upper');
        $this->directory->folding = true;
        $access                   = $this->get(ChatAccess::class);

        // A folding directory returns both for "bob": only the exact one is kept.
        $users = $access->usersByIds(['bob']);
        self::assertSame(['bob'], array_map(strval(...), array_keys($users)));
        self::assertSame('Bob lower', $users['bob']->getChatDisplayName());
        $this->directory->forget($bigB);
        self::assertSame([], $access->usersByIds(['Bob']), 'a look-alike never stands in for a gone user');
    }

    public function testSettingsAndParticipantRowsAreExact(): void
    {
        $bob      = $this->person('bob', 'Bob lower');
        $bigB     = $this->person('Bob', 'Bob upper');
        $ana      = $this->person('ana', 'Ana');
        $settings = $this->get(ChatUserSettingsRepository::class);
        $mine     = $settings->forUser($bob);
        $mine->setPushEnabled(true);
        $settings->save($mine);
        $settings->reset();
        self::assertNotSame($mine, $settings->forUser($bigB));
        self::assertNull($settings->forUser($bigB)->getId(), 'Bob has no stored row');
        self::assertCount(2, $settings->forUsers([$bob, $bigB]));

        $chat         = $this->get(ChatServiceInterface::class);
        $conversation = $chat->openDirect($ana, $bob);
        $participants = $this->get(ChatParticipantRepository::class);
        self::assertNotNull($participants->findOneFor($conversation, $bob));
        self::assertNull($participants->findOneFor($conversation, $bigB));
        self::assertNotSame($conversation->getUuid(), $chat->openDirect($ana, $bigB)->getUuid());
        self::assertSame($conversation->getUuid(), $this->get(ChatMessagingInterface::class)->openDirect($bob, $ana)->uuid);
    }

    public function testEraseTouchesOnlyTheExactReference(): void
    {
        $bob  = $this->person('bob', 'Bob lower');
        $bigB = $this->person('Bob', 'Bob upper');
        $ana  = $this->person('ana', 'Ana');
        $chat = $this->get(ChatServiceInterface::class);
        $chat->send($bob, $chat->openDirect($ana, $bob), 'from bob');
        $chat->send($bigB, $chat->openDirect($ana, $bigB), 'from Bob');

        $this->get(ChatPersonalData::class)->erase($bob);

        self::assertSame(['Bob'], $this->em->getConnection()->fetchFirstColumn('SELECT author_id FROM nowo_chat_message WHERE author_id IS NOT NULL'));
        self::assertSame(['Bob', 'ana', 'ana'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_participant ORDER BY user_id'));

        // An account the directory no longer knows, by its string reference.
        $this->directory->forget($bigB);
        $this->get(ChatPersonalData::class)->eraseFormerAccount('Bob', $bigB->getChatPublicId(), 'Bob upper');
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT author_id FROM nowo_chat_message WHERE author_id IS NOT NULL'));
    }

    public function testUnusableReferencesAreRefusedBeforeAnyWrite(): void
    {
        $ana  = $this->person('ana', 'Ana');
        $chat = $this->get(ChatServiceInterface::class);
        foreach (['bob ', ' bob', "bob\n", "bo\u{200B}b", "\u{FEFF}bob", "bob\u{00A0}", "bob\u{202E}", '', str_repeat('x', 65), "\xC3\x28"] as $reference) {
            try {
                $chat->openDirect($ana, $this->directory->add(new RefChatUser($reference, 'pub-' . bin2hex($reference), 'X')));
                self::fail('accepted ' . json_encode($reference));
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'));
        self::assertSame('ok', ChatUserReferences::key(new RefChatUser('ok', 'p', 'X')));
        self::assertSame(str_repeat('é', 64), ChatUserReferences::key(new RefChatUser(str_repeat('é', 64), 'p', 'X')), '64 characters, not bytes');
    }

    private function person(string $reference, string $name): RefChatUser
    {
        $account = new TestUser($name, null);
        $this->em->persist($account);
        $this->em->flush();

        return $this->directory->add(new RefChatUser($reference, 'pub-' . bin2hex($reference), $name), $account->getChatPublicId());
    }

    /**
     * @return array{cookies: array<string, string>, csrf: string}
     */
    private function signIn(string $reference): array
    {
        $publicId = null;
        foreach ($this->em->getRepository(TestUser::class)->findAll() as $account) {
            $user = $this->directory->resolve(new ChatSecurityUser(TestUser::class, null, $account->getChatPublicId()));
            if ($user instanceof RefChatUser && $user->getChatUserReference() === $reference) {
                $publicId = $account;
            }
        }
        assert($publicId instanceof TestUser);
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($publicId);
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
