<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Service\ChatConversationPurger;
use Nowo\TransactionContract\TransactionRunnerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function strtoupper;

/**
 * 2.0: the purge's "nothing matched" answers: an unknown uuid, a look-alike uuid, a row without id, and a conversation
 * another call deleted between the lookup and the row lock (the concurrent second purge: false, no write, no event).
 */
final class ChatConversationPurgerTest extends TestCase
{
    public function testAConversationGoneOnceLockedIsFalseWithoutEvent(): void
    {
        $conversation = ChatConversation::channel('project:acme');
        (new ReflectionProperty(ChatConversation::class, 'id'))->setValue($conversation, 7);
        $repository = $this->createMock(ChatConversationRepository::class);
        $repository->method('findOneByUuid')->willReturn($conversation);
        $repository->method('findChannel')->willReturn($conversation);
        // The lock finds no row: another purge committed first.
        $repository->expects(self::exactly(2))->method('lockActivity')->with($conversation)->willReturn(null);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('createQueryBuilder');
        $events = $this->createMock(EventDispatcherInterface::class);
        $events->expects(self::never())->method('dispatch');

        $purger = new ChatConversationPurger($manager, $repository, $this->runner(), $events);

        self::assertFalse($purger->purgeConversation($conversation->getUuid()));
        self::assertFalse($purger->purgeChannel('project:acme'));
    }

    public function testUnknownLookAlikeAndUnsavedRowsAreFalse(): void
    {
        $unsaved    = ChatConversation::channel('project:acme');
        $repository = $this->createMock(ChatConversationRepository::class);
        $repository->method('findOneByUuid')->willReturnOnConsecutiveCalls(null, $unsaved, $unsaved);
        $repository->method('findChannel')->willReturn(null);
        $repository->expects(self::never())->method('lockActivity');
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('flush');

        $purger = new ChatConversationPurger($manager, $repository);

        self::assertFalse($purger->purgeConversation('unknown'));
        // The SQL lookup answered a row whose uuid is not exactly the one asked for (a folding collation).
        self::assertFalse($purger->purgeConversation(strtoupper($unsaved->getUuid())));
        // Exactly that uuid, but never saved: nothing to delete.
        self::assertFalse($purger->purgeConversation($unsaved->getUuid()));
        self::assertFalse($purger->purgeChannel('project:acme'));
    }

    private function runner(): TransactionRunnerInterface
    {
        return new class implements TransactionRunnerInterface {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
    }
}
