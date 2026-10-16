<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Repository\ChatReactionRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;

/**
 * Repositories on SQLite: unique cardinalities, list scope, cursor pages, unread counts in one query, open
 * support counter, batched retention purge and per-user settings.
 */
final class ChatRepositoriesTest extends DatabaseTestCase
{
    public function testListPagesUnreadSupportAndPurge(): void
    {
        $em   = $this->em;
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve  = $this->user('Eve');

        $dm         = ChatConversation::direct($ana, $luis);
        $foreignDm  = ChatConversation::direct($luis, $eve);
        $channel    = ChatConversation::channel('project:acme');
        $anaSupport = ChatConversation::support($ana);
        $eveSupport = ChatConversation::support($eve);
        foreach ([$dm, $foreignDm, $channel, $anaSupport, $eveSupport] as $conversation) {
            $em->persist($conversation);
        }
        $dm->touch(new DateTimeImmutable('-1 minute'));
        $channel->touch(new DateTimeImmutable('-2 minutes'));
        $anaSupport->touch(new DateTimeImmutable('-3 minutes'));
        $eveSupport->touch(new DateTimeImmutable('-4 minutes'));
        $eveSupport->resolve($ana);

        $old     = new ChatMessage($dm, $luis, 'old', '<p>old</p>', new DateTimeImmutable('-400 days'));
        $m1      = new ChatMessage($dm, $luis, 'one', '<p>one</p>');
        $m2      = new ChatMessage($dm, $ana, 'two', '<p>two</p>');
        $m3      = new ChatMessage($dm, $luis, 'three', '<p>three</p>');
        $deleted = new ChatMessage($dm, $luis, 'gone', '<p>gone</p>');
        $deleted->delete($luis);
        $channelMsg = new ChatMessage($channel, $luis, 'hello all', '<p>hello all</p>');
        $question   = new ChatMessage($anaSupport, $ana, 'help', '<p>help</p>');
        foreach ([$old, $m1, $m2, $m3, $deleted, $channelMsg, $question] as $message) {
            $em->persist($message);
        }
        $anaInDm = new ChatParticipant($dm, $ana);
        $em->persist($anaInDm);
        $em->persist(new ChatParticipant($dm, $luis));
        $em->persist(new ChatParticipant($foreignDm, $luis));
        $em->persist(new ChatParticipant($foreignDm, $eve));
        $em->persist(new ChatParticipant($anaSupport, $ana));
        $em->persist(new ChatParticipant($eveSupport, $eve));
        $em->persist(new ChatReaction($m1, $ana, '👍'));
        $em->flush();
        $anaInDm->markReadUpTo((int) $m1->getId());
        $em->flush();

        $conversations = $em->getRepository(ChatConversation::class);
        $messages      = $em->getRepository(ChatMessage::class);
        self::assertInstanceOf(ChatConversationRepository::class, $conversations);
        self::assertInstanceOf(ChatMessageRepository::class, $messages);

        self::assertSame($dm, $conversations->findOneByUuid($dm->getUuid()));
        self::assertSame($dm, $conversations->findDirect($luis, $ana));
        self::assertNull($conversations->findDirect($ana, $eve));
        self::assertSame($channel, $conversations->findChannel('project:acme'));
        self::assertSame($anaSupport, $conversations->findSupportFor($ana));

        // Member: own DM, an allowed channel, own support thread — newest first.
        self::assertSame([$dm, $channel, $anaSupport], $conversations->findListForUser($ana, ['project:acme'], false, 10));
        self::assertSame([$dm], $conversations->findListForUser($ana, [], false, 1));
        self::assertNotContains($channel, $conversations->findListForUser($ana, [], false, 10), 'a channel not offered is not listed');
        self::assertSame([$channel, $anaSupport], $conversations->findListForUser($ana, ['project:acme'], false, 10, $dm->getLastMessageAt()));

        // Support agent: every support thread, never someone else's DM.
        $agentList = $conversations->findListForUser($ana, [], true, 10);
        self::assertContains($anaSupport, $agentList);
        self::assertNotContains($eveSupport, $conversations->findListForUser($luis, [], true, 10), 'an empty support thread stays out of other agents\' lists');
        self::assertNotContains($foreignDm, $agentList);
        self::assertSame(1, $conversations->countOpenSupport());
        self::assertSame([$anaSupport], $conversations->findSupportInbox('open', $ana, 10));
        self::assertSame([$anaSupport], $conversations->findSupportInbox(null, $ana, 10), 'never-written threads are left out');

        // Cursor pages, oldest first; catch-up after an id.
        self::assertSame([$m3, $deleted], $messages->findPage($dm, 2));
        self::assertSame([$m1, $m2], $messages->findPage($dm, 2, (int) $m3->getId()));
        self::assertSame([$m2, $m3], $messages->findPage($dm, 2, null, (int) $m1->getId()));
        self::assertSame($deleted, $messages->findLatest($dm));
        self::assertSame($m2, $messages->findOneInConversation($dm, $m2->getUuid()));
        self::assertNull($messages->findOneInConversation($channel, $m2->getUuid()), 'uuid of another conversation');
        self::assertSame([(int) $dm->getId() => $deleted, (int) $channel->getId() => $channelMsg], $messages->findLatestByConversationIds([(int) $dm->getId(), (int) $channel->getId()]));
        self::assertSame([], $messages->findLatestByConversationIds([]));
        self::assertSame([], $messages->findLatestByConversationIds([(int) $foreignDm->getId()]));

        // Unread: after the cursor, not own, not deleted; no participant row → every foreign message.
        $unread = $messages->countUnreadByConversation((int) $ana->getId(), [(int) $dm->getId(), (int) $channel->getId()]);
        self::assertSame([(int) $dm->getId() => 1, (int) $channel->getId() => 1], $unread);
        self::assertSame([], $messages->countUnreadByConversation((int) $ana->getId(), []));

        $participants = $em->getRepository(ChatParticipant::class);
        self::assertInstanceOf(ChatParticipantRepository::class, $participants);
        self::assertCount(6, $participants->findAll());
        self::assertSame([], $participants->findReaders($dm, $ana));
        self::assertSame([$anaInDm], $participants->findReaders($dm, $luis));
        self::assertSame([(int) $luis->getId()], $conversations->findDirectPeerIds($ana));
        self::assertInstanceOf(ChatReactionRepository::class, $em->getRepository(ChatReaction::class));

        // Retention purge in batches (reactions first).
        self::assertSame(1, $messages->purgeOlderThan(new DateTimeImmutable('-365 days'), 100));
        self::assertSame(0, $messages->purgeOlderThan(new DateTimeImmutable('-365 days'), 100));
        self::assertSame(5, $messages->purgeOlderThan(new DateTimeImmutable('+1 day'), 5));
        self::assertCount(0, $em->getRepository(ChatReaction::class)->findAll());
    }

    public function testOneDirectConversationPerPair(): void
    {
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');

        $this->em->persist(ChatConversation::direct($ana, $luis));
        $this->em->persist(ChatConversation::direct($luis, $ana));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testOneConversationPerChannelKey(): void
    {
        $this->em->persist(ChatConversation::channel('project:acme'));
        $this->em->persist(ChatConversation::channel('project:acme'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testUserSettingsDefaultUntilSaved(): void
    {
        $ana        = $this->user('Ana');
        $luis       = $this->user('Luis');
        $repository = $this->em->getRepository(ChatUserSettings::class);
        self::assertInstanceOf(ChatUserSettingsRepository::class, $repository);

        $defaults = $repository->forUser($ana);
        self::assertNull($defaults->getId());
        self::assertSame('pop', $defaults->getSound());

        $defaults->setSound('chime');
        $defaults->setPushEnabled(true);
        $this->em->persist($defaults);
        $this->em->flush();
        $this->em->clear();

        $ana  = $this->em->find($ana::class, $ana->getId());
        $luis = $this->em->find($luis::class, $luis->getId());
        self::assertNotNull($ana);
        self::assertNotNull($luis);
        self::assertSame('chime', $repository->forUser($ana)->getSound());
        $many = $repository->forUsers([$ana, $luis]);
        self::assertTrue($many[(int) $ana->getId()]->isPushEnabled());
        self::assertFalse($many[(int) $luis->getId()]->isPushEnabled());
        self::assertSame([], $repository->forUsers([]));
    }
}
