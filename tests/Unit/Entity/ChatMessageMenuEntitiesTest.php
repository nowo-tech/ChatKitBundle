<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReadMark;
use Nowo\ChatKitBundle\Entity\ChatSavedMessage;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Event\ChatConversationMarkedUnreadEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSavedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageUnsavedEvent;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.35: the rows and events of the message menu — the receipt cursor that never goes back, read marks, saved messages,
 * the share-read-times preference.
 */
final class ChatMessageMenuEntitiesTest extends TestCase
{
    public function testReceiptCursorAndMarkUnread(): void
    {
        $row = new ChatParticipant(ChatConversation::channel('project:acme'), $this->user('Ana', 1));
        self::assertNull($row->getReceiptMessageId());
        self::assertFalse($row->markUnreadFrom(null), 'nothing read: nothing to move back');
        self::assertTrue($row->markReadUpTo(10));
        self::assertSame(10, $row->getReceiptMessageId());
        // Mark unread from 7 (6 is before it): the read cursor goes back, the receipt stays.
        self::assertTrue($row->markUnreadFrom(6));
        self::assertSame([6, 10], $this->cursors($row));
        self::assertFalse($row->markUnreadFrom(8), 'already before it');
        self::assertTrue($row->markUnreadFrom(null));
        self::assertSame([null, 10], $this->cursors($row));
        // Reading again below the receipt keeps it; past it, the two cursors are one.
        self::assertTrue($row->markReadUpTo(8));
        self::assertSame([8, 10], $this->cursors($row));
        self::assertTrue($row->markReadUpTo(12));
        self::assertSame([12, 12], $this->cursors($row));
        self::assertNull((new ReflectionProperty(ChatParticipant::class, 'receiptMessageId'))->getValue($row));
    }

    public function testReadMarkExtendsForward(): void
    {
        $row  = new ChatParticipant(ChatConversation::channel('project:acme'), $this->user('Ana', 1));
        $mark = new ChatReadMark($row, 5, new DateTimeImmutable('2026-11-18 10:00:00'));
        self::assertSame([null, $row, 5], [$mark->getId(), $mark->getParticipant(), $mark->getUpToMessageId()]);
        $mark->extend(4, new DateTimeImmutable('2026-11-18 10:00:30'));
        self::assertSame('10:00:00', $mark->getReadAt()->format('H:i:s'));
        $mark->extend(9, new DateTimeImmutable('2026-11-18 10:00:40'));
        self::assertSame([9, '10:00:40'], [$mark->getUpToMessageId(), $mark->getReadAt()->format('H:i:s')]);
    }

    public function testSavedMessageAndEvents(): void
    {
        $ana          = $this->user('Ana', 3);
        $conversation = ChatConversation::channel('project:acme');
        $message      = new ChatMessage($conversation, $ana, 'hi', '<p>hi</p>');
        $saved        = new ChatSavedMessage($message, $ana, new DateTimeImmutable('2026-11-18 10:00'));
        self::assertSame([null, $message, $ana, '3', '2026-11-18 10:00'], [$saved->getId(), $saved->getMessage(), $saved->getUser(), $saved->getUserReference(), $saved->getSavedAt()->format('Y-m-d H:i')]);
        $before = new DateTimeImmutable();
        self::assertGreaterThanOrEqual($before, (new ChatSavedMessage($message, $ana))->getSavedAt());
        (new ReflectionProperty(ChatSavedMessage::class, 'userRef'))->setValue($saved, null);
        self::assertSame('3', $saved->getUserReference());

        foreach ([new ChatMessageSavedEvent($message, $ana), new ChatMessageUnsavedEvent($message, $ana)] as $event) {
            self::assertSame([$message->getUuid(), $ana->getChatPublicId()], [$event->messageView()->uuid, $event->userPublicId()]);
        }
        $unread = new ChatConversationMarkedUnreadEvent($conversation, $ana, $message->getUuid(), 4);
        self::assertSame([$conversation->getUuid(), $ana->getChatPublicId(), $message->getUuid(), 4], [$unread->conversationUuid(), $unread->userPublicId(), $unread->fromMessageUuid, $unread->readUpTo]);
    }

    public function testShareReadTimesPreference(): void
    {
        $settings = new ChatUserSettings($this->user('Ana', 1));
        self::assertTrue($settings->isShareReadTimes());
        self::assertTrue($settings->preferences()['shareReadTimes']);
        $settings->applyPreference('shareReadTimes', false);
        self::assertFalse($settings->isShareReadTimes());
        self::assertFalse($settings->preferences()['shareReadTimes']);
    }

    /**
     * @return array{int|null, int|null} The read cursor and the receipt cursor
     */
    private function cursors(ChatParticipant $row): array
    {
        return [$row->getLastReadMessageId(), $row->getReceiptMessageId()];
    }

    private function user(string $name, int $id): TestUser
    {
        $user = new TestUser($name);
        (new ReflectionProperty(TestUser::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
