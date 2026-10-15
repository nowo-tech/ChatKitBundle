<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * Boots the test kernel on in-memory SQLite with the full schema (bundle entities + test user).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected TestKernel $kernel;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel();
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
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
