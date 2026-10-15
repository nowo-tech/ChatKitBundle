<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

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
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $cache = $this->kernel->getContainer()->get('cache.app');
        \assert($cache instanceof CacheItemPoolInterface);
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
        $service = $this->kernel->getContainer()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    protected function user(string $name): TestUser
    {
        $user = new TestUser($name, strtolower($name).'@example.test');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
