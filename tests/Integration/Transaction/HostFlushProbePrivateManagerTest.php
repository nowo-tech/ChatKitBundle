<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Deprecation\DeprecationRecorder;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;

/**
 * 1.39: with `doctrine.private_entity_manager: true` a chat write never flushes the application's manager, so the
 * host-flush probe reports nothing (and the application's pending change stays pending).
 */
final class HostFlushProbePrivateManagerTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['private_entity_manager' => true]]]];
    }

    public function testThePrivateManagerReportsNothing(): void
    {
        $app = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $app);
        $ana  = new TestUser('Ana', 'ana@example.test');
        $luis = new TestUser('Luis', 'luis@example.test');
        $app->persist($ana);
        $app->persist($luis);
        $app->flush();
        $chat = $this->get(ChatServiceInterface::class);
        $dm   = $chat->openDirect($ana, $luis);
        $ana->setChatLocale('es');
        $pending = new TestUser('Pending', 'pending@example.test');
        $app->persist($pending);

        [, $messages] = DeprecationRecorder::record('flushed by NowoChatKitBundle', static fn (): mixed => $chat->send($ana, $dm, 'hello'));

        self::assertSame([], $messages);
        self::assertTrue($app->getUnitOfWork()->isScheduledForInsert($pending), 'the application\'s change stays pending');
        self::assertSame(2, (int) $app->getConnection()->fetchOne('SELECT COUNT(*) FROM test_user'));
    }
}
