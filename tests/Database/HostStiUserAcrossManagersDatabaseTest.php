<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\HostEntities\HostStiUserCases;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use RuntimeException;

use function assert;
use function ltrim;
use function parse_url;
use function preg_match;
use function sprintf;

use const PHP_URL_PATH;

/**
 * 2.0 (audit lesson W1-2): a single-table-inheritance `user_class` across the managers, on the server of
 * CHAT_KIT_RACE_DATABASE_URL ({@see HostStiUserCases}). The chat's user foreign keys point at the STI table here, so
 * every test creates that schema and leaves the default one behind (as SchemaForeignKeysDatabaseTest does).
 */
final class HostStiUserAcrossManagersDatabaseTest extends LoadDatabaseTestCase
{
    use HostStiUserCases;

    // Not the parent's setUp(): the test creates the schema of its own user class.
    // @phpstan-ignore phpunit.callParent
    protected function setUp(): void
    {
        $url = TestKernel::databaseUrl();
        if ($url === null) {
            self::markTestSkipped('Set CHAT_KIT_RACE_DATABASE_URL to a throwaway server database (MySQL or PostgreSQL).');
        }
        $database = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        if (preg_match('/(?:^|_)(?:test|race|throwaway)(?:_|$)/', $database) !== 1) {
            throw new RuntimeException(sprintf('Refusing to use database "%s": the setup drops everything in it.', $database));
        }
        $this->kernel = new TestKernel(self::stiConfig());
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (TestKernel::databaseUrl() === null) {
            return;
        }
        $kernel = new TestKernel();
        $kernel->boot();
        try {
            $em = $kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
            assert($em instanceof EntityManagerInterface);
            $tool = new SchemaTool($em);
            $tool->dropDatabase();
            $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        } finally {
            $kernel->shutdown();
        }
        // The next load test creates its own schema (and its ballast table) again.
        self::$schema = false; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }
}
