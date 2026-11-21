<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Entity\ChatThreadRead;
use Nowo\ChatKitBundle\Event\ChatConversationDeletedEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Service\ChatConversationPurger;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function count;
use function implode;
use function sort;
use function sprintf;
use function strtoupper;

/**
 * 2.0: ChatMessagingInterface::deleteConversation() / purgeChannel(), the host's "delete a conversation" under the private
 * entity manager: every row that hangs on the conversation goes (nothing orphaned), forwards elsewhere keep a neutral
 * marker, exact uuids and channel keys only (house rule LQ), false and no event when nothing matched or on a repeat.
 */
final class ChatConversationPurgeTest extends DatabaseTestCase
{
    /** Tables that hang on a conversation, with the column read to scope them. */
    private const array TABLES = [
        'nowo_chat_message',
        'nowo_chat_message_mention',
        'nowo_chat_reaction',
        'nowo_chat_saved_message',
        'nowo_chat_message_link',
        'nowo_chat_message_revision',
        'nowo_chat_thread_read',
        'nowo_chat_read_mark',
        'nowo_chat_participant',
        'nowo_chat_conversation_link',
        'nowo_chat_conversation',
    ];

    /** @var list<ChatConversationDeletedEvent> */
    private array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(EventDispatcherInterface::class)->addListener(ChatConversationDeletedEvent::class, function (ChatConversationDeletedEvent $event): void {
            $this->deleted[] = $event;
        });
    }

    public function testDeleteConversationRemovesEveryRowThatHangsOnIt(): void
    {
        $ana                   = $this->user('Ana');
        $luis                  = $this->user('Luis');
        $eve                   = $this->user('Eve');
        [$direct, $messageIds] = $this->fullConversation($ana, $luis);
        // Another conversation that must survive untouched, with a forward of the deleted conversation's first message.
        $chat  = $this->get(ChatServiceInterface::class);
        $other = $chat->openDirect($ana, $eve);
        $kept  = $chat->send($ana, $other, sprintf('hi @[Eve](%s)', $eve->getChatPublicId()));
        $copy  = new ChatMessage($other, $ana, 'forwarded', '<p>forwarded</p>');
        $copy->forwardOf($this->message($messageIds[0]));
        $this->em->persist($copy);
        $this->em->flush();
        $before = $this->counts();

        self::assertTrue($this->get(ChatMessagingInterface::class)->deleteConversation($direct->getUuid()));

        $after = $this->counts();
        foreach (self::TABLES as $table) {
            self::assertSame($this->rowsOf($table, (int) $direct->getId(), $messageIds), 0, $table . ': nothing of the conversation is left');
        }
        self::assertSame(1, $after['nowo_chat_conversation']);
        self::assertSame(2, $after['nowo_chat_participant'], 'the other conversation keeps its people');
        self::assertSame(1, $after['nowo_chat_message_mention'], 'the other conversation keeps its mention');
        self::assertSame($before['nowo_chat_message'] - count($messageIds), $after['nowo_chat_message']);
        $forward = $this->em->getConnection()->fetchAssociative('SELECT forwarded, forwarded_from_message_id, forwarded_from_conversation_id FROM nowo_chat_message WHERE id = ?', [$copy->getId()]);
        self::assertIsArray($forward);
        self::assertSame([1, null, null], [(int) $forward['forwarded'], $forward['forwarded_from_message_id'], $forward['forwarded_from_conversation_id']], 'the forward keeps its neutral marker');
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM nowo_chat_message WHERE id = ?', [$kept->getId()]));

        self::assertCount(1, $this->deleted);
        $event = $this->deleted[0];
        $ids   = $event->messageIds;
        sort($ids);
        self::assertSame([(int) $direct->getId(), $direct->getUuid(), 'direct', null, $messageIds], [$event->conversationId(), $event->conversationUuid(), $event->kind, $event->channelKey, $ids]);

        // Idempotent: the second call finds nothing, throws nothing and dispatches nothing.
        self::assertFalse($this->get(ChatMessagingInterface::class)->deleteConversation($direct->getUuid()));
        self::assertCount(1, $this->deleted);
        self::assertSame($after, $this->counts());
    }

    public function testOnlyTheExactUuidOrChannelKeyMatches(): void
    {
        $ana       = $this->user('Ana');
        $messaging = $this->get(ChatMessagingInterface::class);
        $direct    = $messaging->openDirect($ana, $this->user('Luis'));
        $channel   = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $messaging->send($ana, $channel->uuid, 'hello channel');
        $before = $this->counts();

        foreach ([strtoupper($direct->uuid), $direct->uuid . ' ', '', '00000000-0000-7000-8000-000000000000'] as $uuid) {
            self::assertFalse($messaging->deleteConversation($uuid), sprintf('uuid "%s"', $uuid));
        }
        foreach ([strtoupper(TestChannelProvider::KEY), TestChannelProvider::KEY . ' ', "project:acme\n", '', 'project:unknown', $direct->uuid] as $key) {
            self::assertFalse($messaging->purgeChannel($key), sprintf('key "%s"', $key));
        }
        self::assertSame($before, $this->counts());
        self::assertSame([], $this->deleted);

        self::assertTrue($messaging->purgeChannel(TestChannelProvider::KEY));
        self::assertFalse($messaging->purgeChannel(TestChannelProvider::KEY));
        self::assertCount(1, $this->deleted);
        self::assertSame(['channel', TestChannelProvider::KEY, $channel->uuid], [$this->deleted[0]->kind, $this->deleted[0]->channelKey, $this->deleted[0]->conversationUuid]);
        self::assertSame(1, $this->counts()['nowo_chat_conversation'], 'the direct conversation is left');

        // Opening the channel again starts a new, empty conversation.
        $again = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        self::assertNotSame($channel->uuid, $again->uuid);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE conversation_id = ?', [$again->id]));
    }

    public function testAConversationAlreadyHeldByTheManagerAndAnEmptyOne(): void
    {
        $ana     = $this->user('Ana');
        $chat    = $this->get(ChatServiceInterface::class);
        $support = $chat->openSupport($ana);
        self::assertTrue($this->em->contains($support));

        self::assertTrue($this->get(ChatConversationPurger::class)->purgeConversation($support->getUuid()));
        self::assertSame(0, $this->counts()['nowo_chat_conversation']);
        self::assertSame(['support', $support->getUuid()], [$this->deleted[0]->kind, $this->deleted[0]->conversationUuid]);
        self::assertSame(0, $this->counts()['nowo_chat_message']);
        // A new support conversation for the same person afterwards.
        self::assertNotSame($support->getUuid(), $chat->openSupport($ana)->getUuid());
    }

    /**
     * A direct conversation with a row in every table that hangs on it.
     *
     * @return array{ChatConversation, list<int>} The conversation and its message ids, ascending
     */
    private function fullConversation(TestUser $ana, TestUser $luis): array
    {
        $chat   = $this->get(ChatServiceInterface::class);
        $direct = $chat->openDirect($ana, $luis);
        $root   = $chat->send($ana, $direct, sprintf('see https://example.test @[Luis](%s)', $luis->getChatPublicId()));
        $chat->toggleReaction($luis, $root, '👍');
        $reply = new ChatMessage($direct, $luis, 'in the thread', '<p>in the thread</p>');
        $reply->replyIn($root);
        $this->em->persist($reply);
        $this->em->persist(new ChatSavedMessage($root, $luis));
        $this->em->persist(new ChatMessageLink($root, 'https://example.test', 'example.test', null, 'web', null, new DateTimeImmutable()));
        $this->em->persist(new ChatMessageRevision($root, 'first text', new DateTimeImmutable()));
        $this->em->persist(new ChatThreadRead($root, $luis, new DateTimeImmutable()));
        $this->em->persist(new ChatConversationLink($direct, $ana, 'https://example.test/doc', 'example.test', 'Doc'));
        $participant = $this->get(ChatParticipantRepository::class)->findOneFor($direct, $luis) ?? self::fail('participant');
        $this->em->persist(new ChatReadMark($participant, (int) $root->getId(), new DateTimeImmutable()));
        $this->em->flush();
        $ids = [(int) $root->getId(), (int) $reply->getId()];
        sort($ids);
        foreach (['nowo_chat_message_mention', 'nowo_chat_reaction', 'nowo_chat_saved_message', 'nowo_chat_message_link', 'nowo_chat_message_revision', 'nowo_chat_thread_read', 'nowo_chat_read_mark', 'nowo_chat_conversation_link'] as $table) {
            self::assertGreaterThan(0, $this->rowsOf($table, (int) $direct->getId(), $ids), $table . ' is seeded');
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_reaction'));

        return [$direct, $ids];
    }

    private function message(int $id): ChatMessage
    {
        return $this->em->find(ChatMessage::class, $id) ?? self::fail('message ' . $id);
    }

    /**
     * Rows of the table that belong to the conversation (directly, through its messages or through its participants).
     *
     * @param list<int> $messageIds
     */
    private function rowsOf(string $table, int $conversationId, array $messageIds): int
    {
        $in  = $messageIds === [] ? '0' : implode(',', $messageIds);
        $sql = match ($table) {
            'nowo_chat_conversation'                                                    => 'SELECT COUNT(*) FROM nowo_chat_conversation WHERE id = ' . $conversationId,
            'nowo_chat_message', 'nowo_chat_participant', 'nowo_chat_conversation_link' => 'SELECT COUNT(*) FROM ' . $table . ' WHERE conversation_id = ' . $conversationId,
            'nowo_chat_thread_read'                                                     => 'SELECT COUNT(*) FROM nowo_chat_thread_read WHERE root_message_id IN (' . $in . ')',
            // Its read times, and any read time left without a participant row.
            'nowo_chat_read_mark' => 'SELECT COUNT(*) FROM nowo_chat_read_mark WHERE participant_id NOT IN (SELECT id FROM nowo_chat_participant WHERE conversation_id <> ' . $conversationId . ')',
            default               => 'SELECT COUNT(*) FROM ' . $table . ' WHERE message_id IN (' . $in . ')',
        };

        return (int) $this->em->getConnection()->fetchOne($sql);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
        }

        return $counts;
    }
}
