<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * 1.27 (REQ-ARCH-001 rule 6): with `doctrine.private_entity_manager: true` a chat write never flushes, clears or closes
 * the application's entity manager; the security user (managed by the application's manager) is adopted, never
 * written; a failing chat write closes only the private manager, which is created again on the next call.
 */
final class PrivateEntityManagerKernelTest extends DatabaseTestCase
{
    protected function chatKitConfig(): array
    {
        return ['profiles' => ['default' => ['doctrine' => ['private_entity_manager' => true]]]];
    }

    public function testChatWritesLeaveThePendingApplicationChangesAlone(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        // The application has a change pending in its own unit of work.
        $pending = new TestUser('Pending', 'pending@example.test');
        $this->em->persist($pending);
        $ana->setChatLocale('fr');

        $chat   = $this->get(ChatServiceInterface::class);
        $direct = $chat->openDirect($ana, $luis);
        $chat->send($ana, $direct, 'hello');
        $channel = $chat->openChannel($luis, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($luis, $channel, 'hi all');
        $chat->toggleReaction($ana, $chat->send($ana, $direct, 'react'), '👍');
        $chat->markRead($luis, $direct, 1);

        self::assertSame(2, $this->users(), 'the pending application insert was not flushed by the chat');
        self::assertNull($this->em->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$ana->getId()]), 'nor the pending update');
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($pending), 'the application change is still pending');
        self::assertTrue($this->em->isOpen());
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
        self::assertFalse($this->em->contains($direct), 'the chat rows live in the private manager');

        $this->em->flush();
        self::assertSame(3, $this->users(), 'the application flushes its own change');
    }

    public function testTheBundleServicesUseThePrivateManager(): void
    {
        $private = $this->get(ChatKitEntityManager::class);
        self::assertSame($private, (new ReflectionProperty(ChatService::class, 'entityManager'))->getValue($this->get(ChatService::class)));
        self::assertSame($private, (new ReflectionMethod($this->get(ChatMessageRepository::class), 'getEntityManager'))->invoke($this->get(ChatMessageRepository::class)));
        self::assertInstanceOf(ChatKitManagerRegistry::class, $this->get(ChatKitManagerRegistry::class));
        self::assertNotSame($private, $this->em);
        self::assertSame($this->em, (new ReflectionProperty(TestChannelProvider::class, 'entityManager'))->getValue($this->get(TestChannelProvider::class)), 'host services keep the application manager');
    }

    public function testAFailingChatWriteClosesOnlyThePrivateManager(): void
    {
        $ana     = $this->user('Ana');
        $luis    = $this->user('Luis');
        $chat    = $this->get(ChatServiceInterface::class);
        $direct  = $chat->openDirect($ana, $luis);
        $private = $this->get(ChatKitEntityManager::class);
        $first   = $private->live();

        // A unique index the second message breaks fails the private flush.
        $this->em->getConnection()->executeStatement('CREATE UNIQUE INDEX test_one_message ON nowo_chat_message (conversation_id)');
        $chat->send($ana, $direct, 'one');
        try {
            $chat->send($ana, $direct, 'two');
            self::fail('the duplicate was written');
        } catch (Throwable) {
        }

        self::assertFalse($first->isOpen(), 'the private manager closed');
        self::assertTrue($this->em->isOpen(), 'the application manager did not');
        self::assertNotSame($first, $private->live(), 'a new private manager on the next call');
        self::assertTrue($private->live()->isOpen());
    }

    public function testTheBoundaryDropsThePrivateManager(): void
    {
        $ana  = $this->user('Ana');
        $chat = $this->get(ChatServiceInterface::class);
        $chat->openDirect($ana, $this->user('Luis'));
        $private = $this->get(ChatKitEntityManager::class);
        self::assertTrue($private->isCreated());

        $this->get(ChatRequestBoundaryListener::class)->clear();

        self::assertFalse($private->isCreated(), 'dropped at the boundary');
    }

    private function users(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM test_user');
    }
}
