<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\SearchKit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Command\ChatSearchIndexCommand;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Search\ChatSearchIndex;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestAccessPolicy;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_keys;
use function assert;
use function class_exists;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * 1.38: the kernel of the SearchKit contract tests (SearchKit's EntitySearchProviderTestCase cannot extend
 * DatabaseTestCase): in-memory SQLite, or with `$realDatabase` the server of `CHAT_KIT_RACE_DATABASE_URL` (schema only
 * updated, rows deleted first, the message full-text index built). One main request is on the stack, as in the palette
 * endpoint (ChatAccess keeps its answers on it). The two contract viewers are users whose identifiers are the kit's.
 */
trait SearchKitContractKernel
{
    protected TestKernel $kernel;

    protected EntityManagerInterface $em;

    /** @var array<string, TestUser> tenant (the viewer's participant key) => viewer */
    protected array $viewers = [];

    protected function bootContractKernel(bool $realDatabase): void
    {
        if (!class_exists(QueryCounter::class)) {
            self::markTestSkipped('nowo-tech/search-kit-bundle ^1.10 is not installed.');
        }
        $url = TestKernel::databaseUrl();
        if ($realDatabase !== ($url !== null)) {
            self::markTestSkipped($realDatabase ? 'Set CHAT_KIT_RACE_DATABASE_URL to a throwaway server database.' : 'The SQLite run (no CHAT_KIT_RACE_DATABASE_URL).');
        }
        if ($url !== null && preg_match('/(?:^|_)(?:test|race|throwaway)(?:_|$)/', ltrim((string) parse_url($url, PHP_URL_PATH), '/')) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": the test deletes its rows.', (string) parse_url($url, PHP_URL_PATH)));
        }
        $this->kernel = new TestKernel(['profiles' => ['default' => [
            'search'     => ['enabled' => true],
            'search_kit' => ['enabled' => true],
            'limits'     => ['searches_per_minute' => 1000],
        ]]]);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        if ($url === null) {
            $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        } else {
            foreach ($tool->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()) as $sql) {
                if (preg_match('/^DROP (TABLE|SEQUENCE)\b/i', $sql) !== 1) {
                    $em->getConnection()->executeStatement($sql);
                }
            }
            foreach (['nowo_chat_message_link', 'nowo_chat_message_mention', 'nowo_chat_reaction', 'nowo_chat_message_revision', 'nowo_chat_saved_message', 'nowo_chat_thread_read', 'nowo_chat_read_mark', 'nowo_chat_message', 'nowo_chat_conversation_link', 'nowo_chat_participant', 'nowo_chat_ticket_counter', 'nowo_chat_conversation', 'nowo_chat_quick_reply', 'nowo_chat_user_settings', 'nowo_chat_idempotency', 'test_user'] as $table) {
                if ($em->getConnection()->createSchemaManager()->tablesExist([$table])) {
                    $em->getConnection()->executeStatement('DELETE FROM ' . $table);
                }
            }
            $index = $this->service(ChatSearchIndex::class);
            if ($index->state() !== ChatSearchIndex::READY) {
                (new CommandTester(new ChatSearchIndexCommand($index)))->execute(['--force' => true]);
            }
        }
        $cache = $this->kernel->getContainer()->get('cache.app');
        assert($cache instanceof CacheItemPoolInterface);
        $cache->clear();
        $this->freshRequest();
        foreach ([self::VIEWER_A => 'Viewer Ana', self::VIEWER_B => 'Viewer Bea'] as $identifier => $name) {
            $viewer = $this->person($name);
            (new ReflectionProperty(TestUser::class, 'publicId'))->setValue($viewer, $identifier);
            $this->em->flush();
            $this->viewers[(string) ChatUserReferences::key($viewer)] = $viewer;
        }
    }

    /**
     * A new main request (the palette endpoint's): ChatAccess's answers of the previous one are gone.
     */
    protected function freshRequest(): void
    {
        $stack = $this->service(RequestStack::class);
        while ($stack->pop() instanceof Request) {
        }
        $stack->push(Request::create('/search'));
    }

    protected function shutdownContractKernel(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function tenants(): array
    {
        $keys = array_keys($this->viewers);

        return [$keys[0], $keys[1]];
    }

    protected function viewerOf(int|string $tenant): TestUser
    {
        return $this->viewers[(string) $tenant] ?? throw new RuntimeException('Unknown tenant.');
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $tokens                                              = $this->service(TokenStorageInterface::class);
        $this->service(TestAccessPolicy::class)->unreachable = [];
        if ($tenant === null) {
            $tokens->setToken(null);

            return;
        }
        $viewer = $this->viewerOf($tenant);
        self::assertSame($userIdentifier, $viewer->getUserIdentifier());
        $tokens->setToken(new UsernamePasswordToken($viewer, 'main', $viewer->getRoles()));
    }

    protected function disableFeature(): void
    {
        $user = $this->service(TokenStorageInterface::class)->getToken()?->getUser();
        assert($user instanceof TestUser);
        $this->service(TestAccessPolicy::class)->unreachable[$user->getChatPublicId()] = true;
    }

    protected function queryCounter(): QueryCounter
    {
        return $this->service(QueryCounter::class);
    }

    protected function person(string $name): TestUser
    {
        $user = new TestUser($name, null);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $container = $this->kernel->getContainer();
        if (!$container->has($id)) {
            $container = $container->get('test.service_container');
            assert($container instanceof ContainerInterface);
        }
        $service = $container->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
