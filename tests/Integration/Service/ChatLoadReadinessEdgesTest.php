<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Link\ChatLinkUrls;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationLinkRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageLinkRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use PDOException;

/**
 * 1.30 (Beacon spec 239): the rare branches of the units of work on SQLite (the two-session races run in
 * tests/Database): a first opening whose insert race cannot be resolved surfaces the conflict (409 on the API), and a
 * pinned link that a concurrent add made one too many is refused under the row lock, before its insert.
 */
final class ChatLoadReadinessEdgesTest extends DatabaseTestCase
{
    public function testALostDirectOpeningWithoutTheWinnersRowSurfacesTheConflict(): void
    {
        $this->em->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof ChatConversation) {
                        throw new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('UNIQUE constraint failed')), null);
                    }
                }
            }
        });

        $this->expectException(UniqueConstraintViolationException::class);
        $this->get(ChatService::class)->openDirect($this->user('Ana'), $this->user('Luis'));
    }

    public function testALinkThatAConcurrentAddMadeOneTooManyIsRefusedUnderTheLock(): void
    {
        $ana = $this->user('Ana');
        $dm  = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));
        // The first count (before the unit of work) still sees room; the count under the row lock does not.
        $pinned = $this->createStub(ChatConversationLinkRepository::class);
        $pinned->method('countFor')->willReturnOnConsecutiveCalls(0, 1);
        $links = new ChatLinks($this->em, $this->get(ChatLinkUrls::class), $pinned, $this->get(ChatMessageLinkRepository::class), $this->get(ChatNotifier::class), maxPinned: 1);

        try {
            $links->add($ana, $dm, 'https://example.com/', null);
            self::fail('one too many');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('too_many_links', $exception->getMessage());
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation_link'));
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
    }
}
