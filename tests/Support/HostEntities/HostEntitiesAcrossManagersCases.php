<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support\HostEntities;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Model\ErasedChatUser;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestHostTicketLink;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function assert;

/**
 * 2.0 (REQ-ARCH-001 rule 6, audit lesson W1-2: XS, MailKit c011507, Yopass 2.0.0): ChatKit writes only through its private
 * entity manager, and both directions across the managers hold, on the relation-mapped test host (TestUser, and
 * TestHostTicketLink pointing at a chat conversation):
 *
 * - a host user the application's manager holds (initialised, or an uninitialised proxy) is written as the private
 *   manager's reference by id, never as a new entity, and the application's instance stays the host's (events hand it
 *   back as is);
 * - a host user persisted but not flushed, or never persisted, fails clearly before anything is written, and the host's
 *   scheduled insert is kept;
 * - a chat row loaded through the application's manager and handed to the chat services is re-loaded by id
 *   (ChatKitWrites::attach()): same result as with one of ChatKit's, no duplicate row; a row gone meanwhile is "not
 *   found";
 * - the application flushes its own entity pointing at a chat row through `getReference()`;
 * - ChatKit never flushes, clears or detaches the application's manager: a pending change stays pending, intact.
 *
 * Used on SQLite (tests/Integration) and on the servers of CHAT_KIT_RACE_DATABASE_URL (tests/Database).
 */
trait HostEntitiesAcrossManagersCases
{
    public function testChatWritesWithUsersTheHostHoldsLeaveTheHostManagerAlone(): void
    {
        $host  = $this->chatHostManager();
        $ana   = $this->hostUser('Ana');
        $luis  = $this->hostUser('Luis');
        $agent = $this->hostUser('Agent Smith');
        // Pending work of the application: a new user and a change of a user the chat writes with.
        $pending = new TestUser('Pending', 'pending@example.test');
        $host->persist($pending);
        $luis->setChatLocale('fr');
        /** @var list<ChatMessageDeletedEvent> $deleted */
        $deleted = [];
        $this->hostService(EventDispatcherInterface::class)->addListener(ChatMessageDeletedEvent::class, static function (ChatMessageDeletedEvent $event) use (&$deleted): void {
            $deleted[] = $event;
        });

        $chat    = $this->hostService(ChatServiceInterface::class);
        $direct  = $chat->openDirect($ana, $luis);
        $message = $chat->send($ana, $direct, 'hello');
        $chat->edit($message, 'hello again');
        $chat->toggleReaction($luis, $message, '👍');
        $chat->pin($ana, $message, true);
        $support = $chat->openSupport($ana);
        $chat->send($agent, $support, 'on it');
        $chat->resolveSupport($agent, $support);
        $chat->delete($luis, $chat->send($luis, $direct, 'oops'));

        $sql = $host->getConnection();
        self::assertSame(2, $this->rows('nowo_chat_conversation'), 'no duplicate conversation');
        // The people's text messages (the pin line and the support assistant's greeting are other kinds).
        self::assertSame([(string) $ana->getId(), (string) $agent->getId(), (string) $luis->getId()], array_map('strval', $sql->fetchFirstColumn("SELECT author_id FROM nowo_chat_message WHERE kind = 'text' ORDER BY id")));
        self::assertSame((string) $ana->getId(), (string) $sql->fetchOne("SELECT author_id FROM nowo_chat_message WHERE kind = 'pin'"));
        self::assertSame((string) $ana->getId(), (string) $sql->fetchOne('SELECT pinned_by_id FROM nowo_chat_message WHERE id = ?', [$message->getId()]));
        self::assertSame((string) $luis->getId(), (string) $sql->fetchOne('SELECT user_id FROM nowo_chat_reaction'));
        self::assertSame((string) $ana->getId(), (string) $sql->fetchOne('SELECT support_owner_id FROM nowo_chat_conversation WHERE id = ?', [$support->getId()]));
        self::assertSame((string) $agent->getId(), (string) $sql->fetchOne('SELECT resolved_by_id FROM nowo_chat_conversation WHERE id = ?', [$support->getId()]));
        self::assertSame(3, $this->rows('test_user'), 'no host user written twice, the pending one not written');

        // The application's manager: open, its pending work intact and still pending, its entities still its own.
        self::assertTrue($host->isOpen());
        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($pending));
        self::assertSame('fr', $luis->getChatLocale());
        self::assertNull($sql->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$luis->getId()]), 'the pending change was not flushed by the chat');
        self::assertTrue($host->contains($ana) && $host->contains($luis) && $host->contains($agent));
        self::assertFalse($host->contains($direct), 'the chat rows live in the private manager');
        self::assertCount(1, $deleted);
        self::assertSame($luis, $deleted[0]->by, 'the event hands the host its own instance');

        $host->flush();
        self::assertSame(4, $this->rows('test_user'), 'the application flushes its own work');
        self::assertSame('fr', $sql->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$luis->getId()]));
    }

    public function testAnUninitialisedHostProxyIsReferencedByIdWithoutLoadingIt(): void
    {
        $host = $this->chatHostManager();
        $ana  = (int) $this->hostUser('Ana')->getId();
        $luis = (int) $this->hostUser('Luis')->getId();
        $host->clear();
        $proxy = $host->getReference(TestUser::class, $ana);
        assert($proxy instanceof TestUser);
        self::assertTrue($host->getUnitOfWork()->isUninitializedObject($proxy));

        $private = $this->hostService(ChatKitEntityManager::class);
        $private->persist(new ChatQuickReply($proxy, 'title', 'body'));
        $private->flush();
        self::assertTrue($host->getUnitOfWork()->isUninitializedObject($proxy), 'its id is taken from the host\'s unit of work, never by loading it');
        self::assertSame((string) $ana, (string) $host->getConnection()->fetchOne('SELECT user_id FROM nowo_chat_quick_reply'));

        $other = $host->getReference(TestUser::class, $luis);
        assert($other instanceof TestUser);
        $chat    = $this->hostService(ChatServiceInterface::class);
        $direct  = $chat->openDirect($proxy, $other);
        $message = $chat->send($proxy, $direct, 'through proxies');
        self::assertSame((string) $ana, (string) $host->getConnection()->fetchOne('SELECT author_id FROM nowo_chat_message WHERE id = ?', [$message->getId()]));
        self::assertSame(2, $this->rows('nowo_chat_participant'));
        self::assertSame(2, $this->rows('test_user'));
    }

    public function testAnUnsavedHostUserFailsClearlyAndWritesNothing(): void
    {
        $host = $this->chatHostManager();
        $ana  = $this->hostUser('Ana');
        $nina = new TestUser('Nina', 'nina@example.test');
        $host->persist($nina);
        $chat = $this->hostService(ChatServiceInterface::class);

        foreach (['support' => static fn () => $chat->openSupport($nina), 'direct' => static fn () => $chat->openDirect($ana, $nina), 'transient' => static fn () => $chat->openSupport(new TestUser('Tom', 'tom@example.test'))] as $case => $write) {
            try {
                $write();
                self::fail($case . ': an unsaved user must be refused');
            } catch (LogicException $failure) {
                // A direct pair is refused by its own check first (it needs both ids), the rest by the private manager.
                $case === 'direct'
                    ? self::assertSame('A direct conversation needs two different persisted users.', $failure->getMessage())
                    : self::assertStringContainsString('ChatKit cannot write Nowo\\ChatKitBundle\\Entity\\ChatConversation::$supportOwner: the ' . TestUser::class . ' it points at is not saved yet', $failure->getMessage(), $case);
            }
            self::assertSame(0, $this->rows('nowo_chat_conversation'), $case . ': nothing written');
            self::assertSame(0, $this->rows('nowo_chat_participant'), $case);
            self::assertSame(0, $this->rows('nowo_chat_message'), $case);
            self::assertTrue($host->isOpen(), $case);
            self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($nina), $case . ': the host\'s scheduled insert is kept');
        }

        // Once the application saved its user, the same call works.
        $host->flush();
        $support = $chat->openSupport($nina);
        self::assertSame((string) $nina->getId(), (string) $host->getConnection()->fetchOne('SELECT support_owner_id FROM nowo_chat_conversation WHERE id = ?', [$support->getId()]));
    }

    public function testARefusedChatWriteLeavesTheHostsPendingChangeToTheHost(): void
    {
        $host   = $this->chatHostManager();
        $ana    = $this->hostUser('Ana');
        $direct = $this->hostService(ChatServiceInterface::class)->openDirect($ana, $this->hostUser('Luis'));
        $ana->setChatLocale('fr');

        try {
            $this->hostService(ChatServiceInterface::class)->resolveSupport($ana, $direct);
            self::fail('only support conversations are resolved');
        } catch (LogicException $refusal) {
            self::assertSame('Only support conversations can be resolved or reopened.', $refusal->getMessage());
        }

        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($ana));
        self::assertNull($host->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$ana->getId()]), 'not flushed by the chat');
        $host->flush();
        self::assertSame('fr', $host->getConnection()->fetchOne('SELECT locale FROM test_user WHERE id = ?', [$ana->getId()]));
    }

    public function testAUserOfAnotherClassOrFromAClosedHostManager(): void
    {
        $host    = $this->chatHostManager();
        $ana     = $this->hostUser('Ana');
        $private = $this->hostService(ChatKitEntityManager::class);

        // Not the association's target class (resolve_target_entities maps ChatUserInterface to TestUser): refused.
        $private->persist(new ChatQuickReply(new ErasedChatUser((int) $ana->getId(), 'erased', 'Erased'), 'title', 'body'));
        try {
            $private->flush();
            self::fail('another class is refused');
        } catch (LogicException $failure) {
            self::assertSame('ChatKit cannot write ' . ChatQuickReply::class . '::$user: it holds a ' . ErasedChatUser::class . ', which is not a ' . TestUser::class . '.', $failure->getMessage());
        }
        $private->reset();
        self::assertSame(0, $this->rows('nowo_chat_quick_reply'));

        // A user of a host manager that is closed now (no unit of work to ask): its mapped id is used.
        $host->close();
        $private->persist(new ChatQuickReply($ana, 'title', 'body'));
        $private->flush();
        self::assertSame((string) $ana->getId(), (string) $private->getConnection()->fetchOne('SELECT user_id FROM nowo_chat_quick_reply'));
    }

    public function testAChatRowLoadedByTheHostManagerIsReattachedNeverInsertedAgain(): void
    {
        $host    = $this->chatHostManager();
        $ana     = $this->hostUser('Ana');
        $luis    = $this->hostUser('Luis');
        $agent   = $this->hostUser('Agent Smith');
        $chat    = $this->hostService(ChatServiceInterface::class);
        $direct  = $chat->openDirect($ana, $luis);
        $first   = $chat->send($ana, $direct, 'first');
        $support = $chat->openSupport($ana);

        // The application loads the chat rows through its own manager (a route value resolver, a host query, ...).
        $hostDirect  = $host->find(ChatConversation::class, $direct->getId());
        $hostFirst   = $host->find(ChatMessage::class, $first->getId());
        $hostSupport = $host->find(ChatConversation::class, $support->getId());
        assert($hostDirect instanceof ChatConversation && $hostFirst instanceof ChatMessage && $hostSupport instanceof ChatConversation);
        self::assertNotSame($direct, $hostDirect);

        $second = $chat->send($luis, $hostDirect, 'second');
        $chat->edit($hostFirst, 'first, edited');
        $chat->toggleReaction($luis, $hostFirst, '👍');
        $chat->pin($ana, $hostFirst, true);
        $chat->resolveSupport($agent, $hostSupport);

        $sql = $host->getConnection();
        self::assertSame(2, $this->rows('nowo_chat_conversation'), 'no duplicate conversation');
        self::assertSame(2, (int) $sql->fetchOne("SELECT COUNT(*) FROM nowo_chat_message WHERE kind = 'text' AND conversation_id = ?", [$direct->getId()]), 'no duplicate message');
        self::assertSame((string) $direct->getId(), (string) $sql->fetchOne('SELECT conversation_id FROM nowo_chat_message WHERE id = ?', [$second->getId()]));
        self::assertSame('first, edited', $sql->fetchOne('SELECT body FROM nowo_chat_message WHERE id = ?', [$first->getId()]));
        self::assertSame((string) $ana->getId(), (string) $sql->fetchOne('SELECT pinned_by_id FROM nowo_chat_message WHERE id = ?', [$first->getId()]));
        self::assertSame(1, $this->rows('nowo_chat_reaction'));
        self::assertSame((string) $agent->getId(), (string) $sql->fetchOne('SELECT resolved_by_id FROM nowo_chat_conversation WHERE id = ?', [$support->getId()]));
        self::assertSame(2, (int) $sql->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE conversation_id = ?', [$direct->getId()]), 'the direct pair only');

        // The application's copies were not written by ChatKit; its own flush writes nothing back over the chat's changes.
        self::assertTrue($host->contains($hostFirst));
        self::assertSame('first', $hostFirst->getBody());
        $host->flush();
        self::assertSame('first, edited', $sql->fetchOne('SELECT body FROM nowo_chat_message WHERE id = ?', [$first->getId()]));

        // A row deleted meanwhile (the application still holds it) is "not found", never inserted again.
        $hostSecond = $host->find(ChatMessage::class, $second->getId());
        self::assertInstanceOf(ChatMessage::class, $hostSecond);
        $sql->executeStatement('DELETE FROM nowo_chat_message WHERE id = ?', [$second->getId()]);
        // The next request: the private manager starts empty (it is dropped at every boundary).
        $private = $this->hostService(ChatKitEntityManager::class);
        $private->reset();
        try {
            ChatKitWrites::attach($private, $hostSecond);
            self::fail('a deleted row is not found');
        } catch (ChatNotFoundException) {
        }
        self::assertSame(1, (int) $sql->fetchOne("SELECT COUNT(*) FROM nowo_chat_message WHERE kind = 'text' AND conversation_id = ?", [$direct->getId()]));
    }

    public function testTheHostFlushesItsOwnEntityPointingAtAChatRow(): void
    {
        $host    = $this->chatHostManager();
        $ana     = $this->hostUser('Ana');
        $support = $this->hostService(ChatServiceInterface::class)->openSupport($ana);

        $reference = $host->getReference(ChatConversation::class, $support->getId());
        assert($reference instanceof ChatConversation);
        $link = new TestHostTicketLink($ana, $reference);
        $host->persist($link);
        $host->flush();

        $sql = $host->getConnection();
        self::assertSame((string) $support->getId(), (string) $sql->fetchOne('SELECT conversation_id FROM test_host_ticket_link WHERE id = ?', [$link->getId()]));
        self::assertSame((string) $ana->getId(), (string) $sql->fetchOne('SELECT owner_id FROM test_host_ticket_link WHERE id = ?', [$link->getId()]));

        // A chat write afterwards never touches the application's row or detaches it.
        $this->hostService(ChatServiceInterface::class)->send($ana, $support, 'still there');
        self::assertTrue($host->contains($link));
        self::assertSame(1, $this->rows('test_host_ticket_link'));
        self::assertSame(1, $this->rows('nowo_chat_conversation'));
    }

    /**
     * 2.0: a listener that keeps only ids from the event (`authorReference()`, `conversationId()`, `messageUuid()`) and
     * points its own entity at them with `getReference()` on the application's manager: the flush writes the foreign
     * keys, and no "new entity found through a relationship" comes from the private manager's instances.
     */
    public function testAListenerUsingOnlyEventIdsFlushesItsOwnEntityWithForeignKeys(): void
    {
        $host = $this->chatHostManager();
        $ana  = $this->hostUser('Ana');
        $luis = $this->hostUser('Luis');
        $chat = $this->hostService(ChatServiceInterface::class);
        /** @var list<array{string, int}> $seen */
        $seen = [];
        $this->hostService(EventDispatcherInterface::class)->addListener(ChatMessageSentEvent::class, static function (ChatMessageSentEvent $event) use ($host, &$seen): void {
            $owner        = $host->getReference(TestUser::class, (int) $event->authorReference());
            $conversation = $host->getReference(ChatConversation::class, $event->conversationId());
            assert($owner instanceof TestUser && $conversation instanceof ChatConversation);
            $host->persist(new TestHostTicketLink($owner, $conversation));
            $host->flush();
            $seen[] = [$event->messageUuid(), $event->messageId()];
        });

        $direct  = $chat->openDirect($ana, $luis);
        $message = $chat->send($ana, $direct, 'hello');
        $chat->send($luis, $direct, 'hi');

        $sql = $host->getConnection();
        self::assertSame([[$message->getUuid(), (int) $message->getId()]], array_slice($seen, 0, 1));
        self::assertCount(2, $seen);
        self::assertSame([(string) $ana->getId(), (string) $luis->getId()], array_map('strval', $sql->fetchFirstColumn('SELECT owner_id FROM test_host_ticket_link ORDER BY id')));
        self::assertSame([(string) $direct->getId(), (string) $direct->getId()], array_map('strval', $sql->fetchFirstColumn('SELECT conversation_id FROM test_host_ticket_link ORDER BY id')));
        self::assertSame(1, $this->rows('nowo_chat_conversation'), 'no duplicate conversation');
        self::assertSame(2, $this->rows('test_user'), 'no duplicate user');
        self::assertTrue($host->isOpen());
    }

    private function chatHostManager(): EntityManagerInterface
    {
        $manager = $this->kernel->getContainer()->get('doctrine.orm.entity_manager');
        assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    private function hostUser(string $name): TestUser
    {
        $user = new TestUser($name, strtolower($name) . '@example.test');
        $this->chatHostManager()->persist($user);
        $this->chatHostManager()->flush();

        return $user;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function hostService(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        $service = $container->get($id);
        assert($service instanceof $id);

        return $service;
    }

    private function rows(string $table): int
    {
        return (int) $this->chatHostManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
