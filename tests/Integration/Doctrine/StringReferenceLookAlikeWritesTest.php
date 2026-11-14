<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\AutoReply\ChatAutoReplier;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatMentions;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestDirectory;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefDirectory;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_column;
use function assert;
use function preg_quote;
use function preg_replace;
use function sprintf;

/**
 * 1.28, audit PV (`doctrine.user_reference: string`, a user column that folds behind a passed schema check): the
 * look-alike refusal is scoped to the column it was seen in and to the user who was asked for (PV-L1); the writes
 * without a preceding read — a direct conversation between look-alikes and the mention rows of one message — never
 * reach a clashing flush (PV-L2); the GDPR export returns exactly the user's own rows (PV-L4); and the automatic reply
 * skips a look-alike instead of raising (PV-L5). SQLite `COLLATE NOCASE` stands for a host's `*_ci` collation.
 */
final class StringReferenceLookAlikeWritesTest extends TestCase
{
    private TestKernel $kernel;

    private EntityManagerInterface $em;

    private RefDirectory $directory;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel(['profiles' => ['default' => ['user_class' => null, 'doctrine' => ['user_reference' => 'string']]]]);
        $this->kernel->boot();
        $em = $this->kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $this->directory = new RefDirectory();
        $this->services()->set(TestDirectory::class, $this->directory);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    /** PV-L1: the list read flags the asked user, in its own column only; the row owner keeps writing everywhere. */
    public function testTheListReadFlagsTheAskedUserInItsColumnOnly(): void
    {
        $chat     = $this->get(ChatServiceInterface::class);
        $settings = $this->get(ChatUserSettingsRepository::class);
        $ana      = $this->make('ana');
        $bob      = $this->make('bob');
        $bigB     = $this->make('Bob');
        $withBob  = $chat->openDirect($ana, $bob);
        $message  = $chat->send($ana, $withBob, 'hi');
        $settings->save($settings->forUser($bob));
        $this->fold('nowo_chat_user_settings', 'user_id');
        $this->em->clear();
        $settings->reset();
        $message = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $message->getUuid()]) ?? self::fail('message');

        // What ChatPresence / ChatPushDispatcher do: ask about Bob; the folding column answers bob's row.
        self::assertNull($settings->forUsers([$bigB])['Bob']->getId());

        // bob (the row's owner) is not flagged, and the reaction table compares exactly: his first reaction is written.
        self::assertNotSame([], $chat->toggleReaction($bob, $message, '👍'));
        // Bob (the asked user) may react too: the look-alike was seen in the settings column, not in the reactions.
        self::assertNotSame([], $chat->toggleReaction($bigB, $message, '👍'));
        // Bob's first settings row is refused: it would clash with bob's row in the folding column.
        $this->expectException(ChatUserLookAlikeException::class);
        try {
            $settings->save($settings->forUser($bigB));
        } finally {
            self::assertTrue($this->em->isOpen());
        }
    }

    /** PV-L1: asking for both look-alikes at once still flags the one whose row the column folded onto. */
    public function testTheListReadFlagsTheAskedLookAlikeNextToTheOwner(): void
    {
        $settings = $this->get(ChatUserSettingsRepository::class);
        $bob      = $this->make('bob');
        $bigB     = $this->make('Bob');
        $settings->save($settings->forUser($bob));
        $this->fold('nowo_chat_user_settings', 'user_id');
        $this->em->clear();
        $settings->reset();

        $both = $settings->forUsers([$bob, $bigB]);
        self::assertNotNull($both['bob']->getId());
        self::assertNull($both['Bob']->getId());
        $this->expectException(ChatUserLookAlikeException::class);
        $settings->save($both['Bob']);
    }

    /** PV-L2: a direct conversation between two look-alikes is refused before the clash; the manager stays open. */
    public function testADirectConversationBetweenLookAlikesIsRefusedOnAFoldingColumn(): void
    {
        $chat = $this->get(ChatServiceInterface::class);
        $bob  = $this->make('bob');
        $bigB = $this->make('Bob');
        $this->fold('nowo_chat_participant', 'user_id');

        try {
            $chat->openDirect($bob, $bigB);
            self::fail('a direct conversation onto a look-alike\'s participant row must be refused');
        } catch (ChatUserLookAlikeException $exception) {
            self::assertStringNotContainsString('Bob', $exception->getMessage());
        }
        self::assertTrue($this->em->isOpen(), 'no clash closed the entity manager');
        self::assertSame([], $this->em->getUnitOfWork()->getIdentityMap()[ChatConversation::class] ?? [], 'the rolled-back conversation is detached');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'), 'nothing half-written is left');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant'));

        // Everybody else keeps working on the same manager.
        $ana = $this->make('ana');
        self::assertNotNull($chat->send($ana, $chat->openDirect($ana, $bob), 'hi')->getId());
    }

    /** PV-D1: inside the host's transaction the refusal rolls back to a savepoint; the host's own work commits. */
    public function testARefusedDirectConversationInsideAHostTransactionLeavesTheTransactionUsable(): void
    {
        $chat       = $this->get(ChatServiceInterface::class);
        $ana        = $this->make('ana');
        $bob        = $this->make('bob');
        $bigB       = $this->make('Bob');
        $connection = $this->em->getConnection();
        $this->fold('nowo_chat_participant', 'user_id');

        $connection->beginTransaction();
        $chat->openDirect($ana, $bob);
        try {
            $chat->openDirect($bob, $bigB);
            self::fail('refused');
        } catch (ChatUserLookAlikeException) {
        }
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is still open');
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();

        self::assertTrue($this->em->isOpen());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'), 'ana and bob\'s conversation committed, nothing else');
        self::assertSame(['ana', 'bob'], $connection->fetchFirstColumn('SELECT user_id FROM nowo_chat_participant ORDER BY user_id'));
    }

    /** PV-L6: the host's pending work survives a refused conversation between look-alikes (no host transaction). */
    public function testTheHostsPendingWorkSurvivesARefusal(): void
    {
        $bob  = $this->make('bob');
        $bigB = $this->make('Bob');
        $this->fold('nowo_chat_participant', 'user_id');
        $this->em->persist(new ChatQuickReply($bob, 'host title', 'host body'));
        try {
            $this->get(ChatServiceInterface::class)->openDirect($bob, $bigB);
            self::fail('refused');
        } catch (ChatUserLookAlikeException) {
        }
        $this->em->flush();

        self::assertSame(['host body'], $this->em->getConnection()->fetchFirstColumn('SELECT body FROM nowo_chat_quick_reply'));
    }

    /** PV-L6: the same inside the host's transaction (savepoint path). */
    public function testTheHostsPendingWorkSurvivesARefusalInsideAHostTransaction(): void
    {
        $bob        = $this->make('bob');
        $bigB       = $this->make('Bob');
        $connection = $this->em->getConnection();
        $this->fold('nowo_chat_participant', 'user_id');
        $connection->beginTransaction();
        $this->em->persist(new ChatQuickReply($bob, 'host title', 'host body'));
        try {
            $this->get(ChatServiceInterface::class)->openDirect($bob, $bigB);
            self::fail('refused');
        } catch (ChatUserLookAlikeException) {
        }
        $this->em->flush();
        $connection->commit();

        self::assertSame(['host body'], $connection->fetchFirstColumn('SELECT body FROM nowo_chat_quick_reply'));
    }

    /** PV-D7: a message whose author is refused as a look-alike is never stored, not even by a later flush. */
    public function testARefusedAuthorsMessageIsNeverStored(): void
    {
        $chat    = $this->get(ChatServiceInterface::class);
        $ana     = $this->make('ana');
        $bob     = $this->make('bob');
        $bigB    = $this->make('Bob');
        $withBob = $chat->openDirect($ana, $bob);
        $this->fold('nowo_chat_participant', 'user_id');
        $this->em->clear();
        $withBob = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $withBob->getUuid()]) ?? self::fail('conversation');

        try {
            $chat->send($bigB, $withBob, 'unannounced?');
            self::fail('the look-alike author must be refused');
        } catch (ChatUserLookAlikeException) {
        }
        $this->em->flush();

        self::assertTrue($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
    }

    /** PV-D1: on an exact column inside the host's transaction both rows are written through the savepoint. */
    public function testCaseVariantsInsideAHostTransactionOnAnExactColumn(): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $conversation = $this->get(ChatServiceInterface::class)->openDirect($this->make('bob'), $this->make('Bob'));
        self::assertSame(1, $connection->getTransactionNestingLevel());
        $connection->commit();

        self::assertSame(['Bob', 'bob'], $connection->fetchFirstColumn('SELECT user_id FROM nowo_chat_participant WHERE conversation_id = ? ORDER BY user_id', [$conversation->getId()]));
    }

    /** PV-D1: any other failure of the second step rolls the conversation back and surfaces unchanged. */
    public function testAnotherFailureOfTheSecondStepRollsBackAndSurfaces(): void
    {
        $this->em->getEventManager()->addEventListener(Events::onFlush, new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof ChatParticipant && ChatUserReferences::key($entity->getUser()) === 'Bob') {
                        throw new RuntimeException('second step failed');
                    }
                }
            }
        });
        try {
            $this->get(ChatServiceInterface::class)->openDirect($this->make('bob'), $this->make('Bob'));
            self::fail('the failure must surface');
        } catch (RuntimeException $failure) {
            self::assertSame('second step failed', $failure->getMessage());
        }
        $connection = $this->em->getConnection();
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'), 'no orphan conversation');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant'));
    }

    /** PV-L2: on exact columns two references that differ only by case are two people, as before. */
    public function testADirectConversationBetweenCaseVariantsWorksOnAnExactColumn(): void
    {
        $chat         = $this->get(ChatServiceInterface::class);
        $conversation = $chat->openDirect($this->make('bob'), $this->make('Bob'));

        self::assertSame(['Bob', 'bob'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_participant WHERE conversation_id = ? ORDER BY user_id', [$conversation->getId()]));
    }

    /** PV-L2: one message mentioning two look-alikes keeps one mention row and never clashes; both are notified. */
    public function testMentionsOfLookAlikesInOneMessageNeverClash(): void
    {
        $chat    = $this->get(ChatServiceInterface::class);
        $ana     = $this->make('ana');
        $bob     = $this->make('bob');
        $bigB    = $this->make('Bob');
        $message = $chat->send($ana, $chat->openDirect($ana, $bob), 'hi');
        $this->fold('nowo_chat_message_mention', 'user_id');
        $this->em->clear();
        $message = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $message->getUuid()]) ?? self::fail('message');

        $added = $this->get(ChatMentions::class)->sync($message, [$bob, $bigB]);
        $this->em->flush();

        self::assertCount(2, $added, 'both people are still notified');
        self::assertTrue($this->em->isOpen());
        self::assertSame(['bob'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_message_mention'));

        // PV-D4: the next edit with the same mentions notifies nobody again.
        self::assertSame([], $this->get(ChatMentions::class)->sync($message, [$bob, $bigB]));
        $this->em->flush();
        self::assertSame(['bob'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_message_mention'));
    }

    /** PV-D4: on an exact column both case variants keep their own row and are notified once, however often it is edited. */
    public function testOnAnExactColumnCaseVariantsKeepTheirMentionRowsAndAreNotifiedOnce(): void
    {
        $chat     = $this->get(ChatServiceInterface::class);
        $mentions = $this->get(ChatMentions::class);
        $ana      = $this->make('ana');
        $bob      = $this->make('bob');
        $bigB     = $this->make('Bob');
        $message  = $chat->send($ana, $chat->openDirect($ana, $bob), 'hi');

        self::assertSame([$bob, $bigB], $mentions->sync($message, [$bob, $bigB]));
        $this->em->flush();
        foreach ([1, 2] as $edit) {
            self::assertSame([], $mentions->sync($message, [$bob, $bigB]), 'edit ' . $edit . ' notifies nobody again');
            $this->em->flush();
        }
        self::assertSame(['Bob', 'bob'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_message_mention ORDER BY user_id'), 'each has an unread badge and a mentions list entry');
    }

    /** PV-L2: an edit that swaps a mention for its look-alike never clashes with the row the folding delete kept. */
    public function testAnEditSwappingAMentionForItsLookAlikeNeverClashes(): void
    {
        $chat     = $this->get(ChatServiceInterface::class);
        $mentions = $this->get(ChatMentions::class);
        $ana      = $this->make('ana');
        $bob      = $this->make('bob');
        $bigB     = $this->make('Bob');
        $message  = $chat->send($ana, $chat->openDirect($ana, $bob), 'hi');
        $mentions->sync($message, [$bigB]);
        $this->em->flush();
        $this->fold('nowo_chat_message_mention', 'user_id');
        $this->em->clear();
        $message = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $message->getUuid()]) ?? self::fail('message');

        $mentions->sync($message, [$bob]);
        $this->em->flush();

        self::assertTrue($this->em->isOpen());
        self::assertSame(['Bob'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_message_mention'), 'the folding delete kept Bob\'s row; no second row was inserted');
    }

    /** PV-L2: on exact columns both case variants keep their own mention row after an edit, as before. */
    public function testAnEditSwappingAMentionOnAnExactColumnReplacesTheRow(): void
    {
        $chat     = $this->get(ChatServiceInterface::class);
        $mentions = $this->get(ChatMentions::class);
        $ana      = $this->make('ana');
        $bob      = $this->make('bob');
        $bigB     = $this->make('Bob');
        $message  = $chat->send($ana, $chat->openDirect($ana, $bob), 'hi');
        $mentions->sync($message, [$bigB]);
        $this->em->flush();

        self::assertSame([$bob], $mentions->sync($message, [$bob]));
        $this->em->flush();
        self::assertSame(['bob'], $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM nowo_chat_message_mention'));
    }

    /** PV-L4: the GDPR export of a look-alike returns none of the other person's messages, pins, quick replies or links. */
    public function testTheExportNeverReturnsALookAlikesRows(): void
    {
        $chat    = $this->get(ChatServiceInterface::class);
        $ana     = $this->make('ana');
        $bob     = $this->make('bob');
        $bigB    = $this->make('Bob');
        $withBob = $chat->openDirect($ana, $bob);
        $secret  = $chat->send($bob, $withBob, 'secret of lower bob');
        $chat->pin($bob, $secret, true);
        $this->em->persist(new ChatQuickReply($bob, 'Mine', 'quick reply of lower bob'));
        $this->em->persist(new ChatConversationLink($withBob, $bob, 'https://example.com/bob', 'example.com', 'link of lower bob'));
        $this->em->flush();
        $this->fold('nowo_chat_message', 'author_id');
        $this->fold('nowo_chat_message', 'pinned_by_id');
        $this->fold('nowo_chat_quick_reply', 'user_id');
        $this->fold('nowo_chat_conversation_link', 'created_by_id');
        $this->em->clear();
        // The columns really fold now.
        self::assertGreaterThan(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE author_id = ?', ['Bob']));

        $export = $this->get(ChatPersonalData::class)->export($bigB);
        self::assertSame([], $export['messages']);
        self::assertSame([], $export['pins']);
        self::assertSame([], $export['quick_replies']);
        self::assertSame([], $export['links']);

        // bob's own export is whole.
        $own = $this->get(ChatPersonalData::class)->export($bob);
        self::assertContains('secret of lower bob', array_column($own['messages'], 'text'));
        self::assertCount(1, $own['pins']);
        self::assertSame(['quick reply of lower bob'], array_column($own['quick_replies'], 'text'));
        self::assertSame(['link of lower bob'], array_column($own['links'], 'title'));
    }

    /** PV-L5: the automatic reply of a user whose look-alike holds the participant row is skipped, not raised. */
    public function testTheAutomaticReplyOfALookAlikeIsSkipped(): void
    {
        $chat     = $this->get(ChatServiceInterface::class);
        $settings = $this->get(ChatUserSettingsRepository::class);
        $ana      = $this->make('ana');
        $bigB     = $this->make('Bob');
        $this->make('bob');
        $withBigB = $chat->openDirect($ana, $bigB);
        $away     = $settings->forUser($bigB);
        $away->setAutoReply(true, 'Away until Monday', 'range', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), false);
        $settings->save($away);
        // Bob's participant row now holds bob's reference, and the column folds: the look-alike case.
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_participant SET user_id = ? WHERE user_id = ?', ['bob', 'Bob']);
        $this->fold('nowo_chat_participant', 'user_id');
        $this->em->clear();
        $settings->reset();
        $withBigB = $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $withBigB->getUuid()]) ?? self::fail('conversation');
        $message  = $chat->send($ana, $withBigB, 'are you there?');

        self::assertSame([], $this->get(ChatAutoReplier::class)->afterSend($message));
        self::assertTrue($this->em->isOpen());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE kind = ?', ['auto']));
        // The skip came from the refusal: the guard holds the look-alike of Bob in the participant column.
        $this->expectException(ChatUserLookAlikeException::class);
        $this->get(StringReferenceSchemaGuard::class)->assertNoLookAlike('nowo_chat_participant.user_id', 'Bob');
    }

    private function make(string $reference): RefChatUser
    {
        return $this->directory->add(new RefChatUser($reference, 'pub-' . $reference, 'Name ' . $reference));
    }

    /**
     * Gives one column of one SQLite table a case-folding collation, keeping its rows and its indexes: what a host's
     * default collation does on MySQL / MariaDB. SQLite cannot alter a collation, so the table is rebuilt.
     */
    private function fold(string $table, string $column): void
    {
        $connection = $this->em->getConnection();
        $ddl        = (string) $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
        $indexes    = $connection->fetchFirstColumn('SELECT sql FROM sqlite_master WHERE type = ? AND tbl_name = ? AND sql IS NOT NULL', ['index', $table]);
        $folding    = (string) preg_replace('/\b' . preg_quote($column, '/') . '\b(\s+[A-Za-z]+(?:\(\d+\))?)/', $column . '$1 COLLATE NOCASE', $ddl, 1);
        $folding    = (string) preg_replace('/\b' . preg_quote($table, '/') . '\b/', $table . '_folding', $folding, 1);
        $connection->executeStatement($folding);
        $connection->executeStatement(sprintf('INSERT INTO %s_folding SELECT * FROM %s', $table, $table));
        $connection->executeStatement(sprintf('DROP TABLE %s', $table));
        $connection->executeStatement(sprintf('ALTER TABLE %s_folding RENAME TO %s', $table, $table));
        foreach ($indexes as $index) {
            $connection->executeStatement((string) $index);
        }
    }

    private function services(): ContainerInterface
    {
        $services = $this->kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);

        return $services;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->services()->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
