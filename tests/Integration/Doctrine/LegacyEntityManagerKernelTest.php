<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 1.27: without `doctrine.private_entity_manager` (the default) the chat writes through the application's manager, as
 * 1.26 did: a chat write flushes the application's pending changes too.
 */
final class LegacyEntityManagerKernelTest extends DatabaseTestCase
{
    public function testTheDefaultFlushesTheApplicationManagerAs126(): void
    {
        if (TestKernel::privateEntityManager()) {
            self::markTestSkipped('The private entity manager replay changes this on purpose.');
        }
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $this->em->persist(new TestUser('Pending', 'pending@example.test'));

        $chat = $this->get(ChatServiceInterface::class);
        $chat->send($ana, $chat->openDirect($ana, $luis), 'hello');

        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_user'));
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertFalse($container->has(ChatKitEntityManager::class));
    }
}
