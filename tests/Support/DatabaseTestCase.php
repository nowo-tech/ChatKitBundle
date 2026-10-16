<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment;

use function assert;

/**
 * Boots the test kernel on in-memory SQLite with the full schema (bundle entities + test user).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected TestKernel $kernel;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel($this->chatKitConfig());
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $cache = $this->kernel->getContainer()->get('cache.app');
        assert($cache instanceof CacheItemPoolInterface);
        $cache->clear();
    }

    /**
     * @return array<string, mixed>
     */
    protected function chatKitConfig(): array
    {
        return [];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function get(string $id): object
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

    protected function twig(): Environment
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $twig = $container->get('twig');
        assert($twig instanceof Environment);

        return $twig;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    protected function user(string $name): TestUser
    {
        $user = new TestUser($name, strtolower($name) . '@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
