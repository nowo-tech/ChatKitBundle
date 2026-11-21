<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatMentionInboxInterface;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagedRows;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Repository\ChatMessageMentionRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;

/**
 * 2.0: ChatKit's DQL bulk writes leave no stale state in its private entity manager: a purged conversation and every
 * managed row of it are detached (find() answers null in the same manager, and in the next request of a worker without
 * a kernel reset), a forward elsewhere drops the pointer the database dropped, unrelated managed rows and their pending
 * changes stay, and mentions marked read in bulk show their read time on the managed instance.
 */
final class ChatKitManagedRowsTest extends DatabaseTestCase
{
    public function testAPurgedConversationIsGoneFromTheManager(): void
    {
        [$ana, $luis, $direct, $root, $other, $forward] = $this->seed();
        $id                                             = (int) $direct->getId();
        $rootId                                         = (int) $root->getId();
        $mention                                        = $this->em->getRepository(ChatMessageMention::class)->findOneBy(['message' => $root]) ?? self::fail('mention');
        $reaction                                       = $this->em->getRepository(ChatReaction::class)->findOneBy(['message' => $root]) ?? self::fail('reaction');
        $kept                                           = $this->get(ChatService::class)->participant($other, $ana);
        $mutedTill                                      = new DateTimeImmutable('2030-01-01 00:00:00');
        $kept->muteUntil($mutedTill);
        foreach ([$direct, $root, $mention, $reaction] as $row) {
            self::assertTrue($this->em->contains($row));
        }

        self::assertTrue($this->get(ChatMessagingInterface::class)->deleteConversation($direct->getUuid()));

        foreach ([$direct, $root, $mention, $reaction] as $row) {
            self::assertFalse($this->em->contains($row), $row::class . ' is detached');
        }
        self::assertNull($this->em->find(ChatConversation::class, $id));
        self::assertNull($this->em->find(ChatMessage::class, $rootId));
        self::assertNull($this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $id]));
        // The forward elsewhere stays managed and drops the pointer the database dropped; the next flush works.
        self::assertTrue($this->em->contains($forward));
        self::assertNull($forward->getForwardedFrom());
        self::assertTrue($forward->isForwarded());
        // Unrelated work of the manager is untouched, pending change included.
        self::assertTrue($this->em->contains($kept) && $this->em->contains($other));
        $this->em->flush();
        self::assertSame('2030-01-01 00:00:00', (string) $this->em->getConnection()->fetchOne('SELECT muted_until FROM nowo_chat_participant WHERE id = ?', [$kept->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation'));
        unset($luis);
    }

    public function testTwoWorkerRequestsWithoutAKernelReset(): void
    {
        [, , $direct, $root] = $this->seed();
        $id                  = (int) $direct->getId();
        $rootId              = (int) $root->getId();
        $boundary            = $this->get(ChatRequestBoundaryListener::class);

        // Request 1: the conversation is loaded (held by the manager), then purged.
        self::assertSame($direct, $this->em->find(ChatConversation::class, $id));
        self::assertTrue($this->get(ChatMessagingInterface::class)->deleteConversation($direct->getUuid()));
        self::assertNull($this->em->find(ChatConversation::class, $id));
        $boundary->clear();

        // Request 2, same kernel and manager (FrankenPHP worker, kernel reset off): still gone, not the old instance.
        self::assertNull($this->em->find(ChatConversation::class, $id));
        self::assertNull($this->em->find(ChatMessage::class, $rootId));
        self::assertFalse($this->get(ChatMessagingInterface::class)->deleteConversation($direct->getUuid()));
    }

    public function testMentionsMarkedReadInBulkShowTheirReadTimeWhenManaged(): void
    {
        $chat = $this->get(ChatService::class);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = $chat->openDirect($ana, $luis);
        $one  = $chat->send($ana, $dm, 'one @[Luis](' . $luis->getChatPublicId() . ')');
        $two  = $chat->send($ana, $dm, 'two @[Luis](' . $luis->getChatPublicId() . ')');
        $repo = $this->get(ChatMessageMentionRepository::class);
        $held = $repo->findOneBy(['message' => $one]) ?? self::fail('mention');
        $next = $repo->findOneBy(['message' => $two]) ?? self::fail('mention');
        self::assertTrue($held->isUnread() && $next->isUnread());

        // ChatMentionInboxInterface::markMentionsRead(): the managed instance reads as read.
        self::assertSame(1, $this->get(ChatMentionInboxInterface::class)->markMentionsRead($luis, [(int) $held->getId()]));
        self::assertTrue($this->em->contains($held));
        self::assertFalse($held->isUnread());
        self::assertFalse($repo->findOneForUser($luis, (int) $held->getId())?->isUnread() ?? true);

        // Reading the conversation (the bulk UPDATE of markReadUpTo) does the same for the other one.
        self::assertTrue($next->isUnread());
        $chat->markRead($luis, $dm, (int) $two->getId());
        self::assertFalse($next->isUnread());
    }

    public function testARowWhoseRequiredPointerLeadsToAGoneRowGoesWithIt(): void
    {
        [, , , $root] = $this->seed();
        $mention      = $this->em->getRepository(ChatMessageMention::class)->findOneBy(['message' => $root]) ?? self::fail('mention');
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_message_mention WHERE message_id = ?', [$root->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_reaction WHERE message_id = ?', [$root->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_message SET forwarded_from_message_id = NULL WHERE forwarded_from_message_id = ?', [$root->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_message WHERE id = ?', [$root->getId()]);

        // Only the message's class is named: the mention (required pointer to it) is found through the pointer.
        self::assertGreaterThanOrEqual(2, ChatKitManagedRows::forgetGone($this->em, [ChatMessage::class]));
        self::assertFalse($this->em->contains($root));
        self::assertFalse($this->em->contains($mention));
        self::assertSame(0, ChatKitManagedRows::forgetGone($this->em, [ChatMessage::class, ChatMessageMention::class]), 'nothing more to forget');
        // A refresh of rows the manager does not hold loads nothing.
        ChatKitManagedRows::refresh($this->em, ChatMessage::class, [(int) $root->getId(), 999_999]);
        $this->em->flush();
    }

    /**
     * A direct conversation with a mention and a reaction on its first message, and a forward of it in another
     * conversation, all held by ChatKit's manager.
     *
     * @return array{TestUser, TestUser, ChatConversation, ChatMessage, ChatConversation, ChatMessage}
     */
    private function seed(): array
    {
        $chat   = $this->get(ChatService::class);
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $direct = $chat->openDirect($ana, $luis);
        $root   = $chat->send($ana, $direct, 'hi @[Luis](' . $luis->getChatPublicId() . ')');
        $chat->toggleReaction($luis, $root, '👍');
        $other   = $chat->openDirect($ana, $this->user('Eve'));
        $forward = new ChatMessage($other, $ana, 'fwd', '<p>fwd</p>');
        $forward->forwardOf($root);
        $this->em->persist($forward);
        $this->em->flush();

        return [$ana, $luis, $direct, $root, $other, $forward];
    }
}
