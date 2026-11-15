<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use LogicException;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use Throwable;

use function str_contains;

/**
 * 1.30, audits QD-M1 / QD-M2, ported from the audit's PoCs (QdHostManagerTest) with the assertions inverted. ChatKit
 * shares the application's manager (`private_entity_manager` unset, Beacon's setup): a chat failure never resets it or
 * detaches the application's entities, a refusal never touches it, and a lost insert race is resolved in a savepoint,
 * so the caller's next write of the same request works.
 */
final class HostManagerSafetyTest extends DatabaseTestCase
{
    /**
     * QD-M1: a transient failure inside the chat flush closes the manager (the ORM does). It is not reset: the
     * application's next flush fails loudly, as in 1.29 — never silently. (The boundary replaces it afterwards.).
     */
    public function testATransientChatFailureNeverSilentlyDropsTheHostsLaterChange(): void
    {
        $this->sharedManagerOnly();
        $ana  = $this->user('Ana');
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($ana, $this->user('Luis'));
        $this->em->getEventManager()->addEventListener([Events::postPersist], new class {
            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($args->getObject() instanceof ChatMessage) {
                    throw new DeadlockException(PdoDriverException::new(new PDOException('Deadlock found when trying to get lock', 40001)), null);
                }
            }
        });

        $failure = null;
        try {
            $chat->send($ana, $dm, 'hello');
        } catch (Throwable $failure) {
        }
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());

        // The ORM closed (and so cleared) the manager in the failed flush, as in 1.29; it is not reset behind the
        // application's back.
        self::assertFalse($this->em->isOpen());
        $ana->setChatLocale('es');
        $hostError = null;
        try {
            $this->em->flush();
        } catch (Throwable $hostError) {
        }
        self::assertNotNull($hostError, 'loud, as in 1.29');
        self::assertStringContainsString('closed', $hostError->getMessage());
    }

    /** QD-M1: a refusal of a ChatKit write comes before the unit of work and never touches the manager. */
    public function testARefusalLeavesTheHostsChangeToBeWritten(): void
    {
        $this->sharedManagerOnly();
        $ana = $this->user('Ana');
        $dm  = $this->get(ChatService::class)->openDirect($ana, $this->user('Luis'));

        try {
            $this->get(ChatService::class)->resolveSupport($ana, $dm);
            self::fail('LogicException expected');
        } catch (LogicException) {
        }

        $ana->setChatLocale('fr');
        $this->appFlush();
        self::assertSame('fr', $this->em->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$ana->getId()]));
    }

    /**
     * QD-M2 (Beacon's ShareToProjectChat / MCP flow): openChannel() then send() with the user the request holds. The
     * membership insert meets the row another request of the same person wrote first: only its savepoint is rolled
     * back, that row is read, and the next write of the same request works with the same user.
     */
    public function testAWriteAfterALostMembershipRaceWorksWithTheCallersUser(): void
    {
        $ana     = $this->user('Ana');
        $channel = $this->get(ChatService::class)->openChannel($this->user('Luis'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $counter = $this->get(QueryCounter::class);
        $em      = $this->em;
        // "Another request" writes Ana's row just before this one's insert (SQLite: on the same connection, before the
        // savepoint, so the insert meets it).
        $counter->before = static function (string $sql) use ($em, $channel, $ana): bool {
            if (!str_contains($sql, 'SAVEPOINT nowo_chat_kit_row_') || str_contains($sql, 'RELEASE') || str_contains($sql, 'ROLLBACK')) {
                return false;
            }
            $em->getConnection()->insert('nowo_chat_participant', ['conversation_id' => $channel->getId(), 'user_id' => $ana->getId(), 'joined_at' => '2026-11-15 10:00:00']);

            return true;
        };

        $conversation = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertNull($counter->before, 'the race was played');
        self::assertTrue($this->em->contains($ana) || $this->em instanceof ChatKitEntityManager, 'the caller\'s user stays managed');
        $message = $this->get(ChatService::class)->send($ana, $conversation, 'shared a link');
        self::assertNotNull($message->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE conversation_id = ? AND user_id = ?', [$channel->getId(), $ana->getId()]));
    }

    private function sharedManagerOnly(): void
    {
        if ($this->em instanceof ChatKitEntityManager) {
            self::markTestSkipped('The application\'s manager is ChatKit\'s here only without the private manager.');
        }
    }

    private function appFlush(): void
    {
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->flush();
    }
}
