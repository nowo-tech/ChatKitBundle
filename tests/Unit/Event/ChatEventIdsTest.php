<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Event;

use DateTimeImmutable;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatChannelOpeningEvent;
use Nowo\ChatKitBundle\Event\ChatConversationMarkedUnreadEvent;
use Nowo\ChatKitBundle\Event\ChatLinkAddedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkRemovedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkUpdatedEvent;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageEditedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageForwardedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageForwardingEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSavedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatMessageUnsavedEvent;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Event\ChatThreadReplyEvent;
use Nowo\ChatKitBundle\Event\EventRefs;
use Nowo\ChatKitBundle\Event\SupportTicketAssignedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketCreatedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketReopenedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketStatusChangedEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 2.0: every event answers with scalars only (uuids, primary keys, the key the chat stores for a user, public ids), so a
 * listener of an application with a private entity manager can `getReference()` by id and never keep the bundle's
 * instances (Beacon: "new entity found through a relationship").
 */
final class ChatEventIdsTest extends TestCase
{
    public function testMessageEvents(): void
    {
        [$ana, $luis, $conversation, $message] = $this->fixture();
        $ref                                   = (string) $ana->getId();

        $sent = new ChatMessageSentEvent($message);
        self::assertSame([11, $message->getUuid(), 7, $conversation->getUuid(), $ref, $ana->getChatPublicId()], [$sent->messageId(), $sent->messageUuid(), $sent->conversationId(), $sent->conversationUuid(), $sent->authorReference(), $sent->authorPublicId()]);
        $system = new ChatMessageSentEvent(ChatMessage::system($conversation, 'x'));
        self::assertSame([null, null], [$system->authorReference(), $system->authorPublicId()]);

        $edited = new ChatMessageEditedEvent($message, 3);
        self::assertSame([11, $message->getUuid(), 7, $conversation->getUuid(), $ref], [$edited->messageId(), $edited->messageUuid(), $edited->conversationId(), $edited->conversationUuid(), $edited->authorReference()]);

        $deleted = new ChatMessageDeletedEvent($message, $luis, false);
        self::assertSame([11, $message->getUuid(), 7, $conversation->getUuid(), (string) $luis->getId()], [$deleted->messageId(), $deleted->messageUuid(), $deleted->conversationId(), $deleted->conversationUuid(), $deleted->byReference()]);

        foreach ([new ChatMessageSavedEvent($message, $luis), new ChatMessageUnsavedEvent($message, $luis)] as $event) {
            self::assertSame([11, $message->getUuid(), 7, $conversation->getUuid(), (string) $luis->getId()], [$event->messageId(), $event->messageUuid(), $event->conversationId(), $event->conversationUuid(), $event->userReference()], $event::class);
        }

        $mention = new ChatMentionEvent($message, [$luis, $ana], $conversation);
        self::assertSame([11, $message->getUuid(), 7, $conversation->getUuid(), [(string) $luis->getId(), $ref]], [$mention->messageId(), $mention->messageUuid(), $mention->conversationId(), $mention->conversationUuid(), $mention->mentionedReferences()]);
        self::assertSame([], (new ChatMentionEvent($message, [], $conversation))->mentionedReferences());

        $unread = new ChatConversationMarkedUnreadEvent($conversation, $luis, $message->getUuid(), 5);
        self::assertSame([7, (string) $luis->getId()], [$unread->conversationId(), $unread->userReference()]);
    }

    public function testThreadAndForwardEvents(): void
    {
        [$ana, $luis, $conversation, $root] = $this->fixture();
        $other                              = ChatConversation::channel('project:b', new DateTimeImmutable('2026-11-01 10:00'));
        $this->setId($other, 8);
        $reply = new ChatMessage($conversation, $luis, 'r', '<p>r</p>', new DateTimeImmutable('2026-11-01 10:06'));
        $this->setId($reply, 12);
        $reply->replyIn($root);

        $thread = new ChatThreadReplyEvent($root, $reply);
        self::assertSame([11, $root->getUuid(), 12, $reply->getUuid(), 7, $conversation->getUuid(), (string) $luis->getId()], [$thread->rootId(), $thread->rootUuid(), $thread->replyId(), $thread->replyUuid(), $thread->conversationId(), $thread->conversationUuid(), $thread->replyAuthorReference()]);

        $copy = new ChatMessage($other, $luis, 'c', '<p>c</p>', new DateTimeImmutable('2026-11-01 10:07'));
        $this->setId($copy, 13);
        $copy->forwardOf($root);
        $forwarded = new ChatMessageForwardedEvent($root, $copy, $luis);
        self::assertSame([11, $root->getUuid(), 7, $conversation->getUuid(), 13, $copy->getUuid(), 8, $other->getUuid(), (string) $luis->getId()], [$forwarded->sourceId(), $forwarded->sourceUuid(), $forwarded->sourceConversationId(), $forwarded->sourceConversationUuid(), $forwarded->messageId(), $forwarded->messageUuid(), $forwarded->conversationId(), $forwarded->conversationUuid(), $forwarded->actorReference()]);

        $forwarding = new ChatMessageForwardingEvent($root, $luis, $other, $ana);
        self::assertSame([11, $root->getUuid(), 7, $conversation->getUuid(), 8, $other->getUuid(), (string) $luis->getId(), $luis->getChatPublicId(), (string) $ana->getId(), $ana->getChatPublicId()], [$forwarding->sourceId(), $forwarding->sourceUuid(), $forwarding->sourceConversationId(), $forwarding->sourceConversationUuid(), $forwarding->targetConversationId(), $forwarding->targetConversationUuid(), $forwarding->actorReference(), $forwarding->actorPublicId(), $forwarding->recipientReference(), $forwarding->recipientPublicId()]);
        $noTarget = new ChatMessageForwardingEvent($root, $luis, null);
        self::assertSame([null, null, null, null], [$noTarget->targetConversationId(), $noTarget->targetConversationUuid(), $noTarget->recipientReference(), $noTarget->recipientPublicId()]);
    }

    public function testSupportLinkAndChannelEvents(): void
    {
        $ana    = $this->user('Ana', 3);
        $agent  = $this->user('Agent', 5);
        $ticket = ChatConversation::support($ana);
        $this->setId($ticket, 9);
        $ref   = (string) $ana->getId();
        $agRef = (string) $agent->getId();

        $created = new SupportTicketCreatedEvent($ticket, $ana);
        self::assertSame([9, $ticket->getUuid(), $ref], [$created->ticketId(), $created->ticketUuid(), $created->requesterReference()]);
        $assigned = new SupportTicketAssignedEvent($ticket, $agent, $ana);
        self::assertSame([9, $ticket->getUuid(), $agRef, $ref], [$assigned->ticketId(), $assigned->ticketUuid(), $assigned->assigneeReference(), $assigned->byReference()]);
        self::assertNull((new SupportTicketAssignedEvent($ticket, null, $ana))->assigneeReference());
        $reopened = new SupportTicketReopenedEvent($ticket, $agent);
        self::assertSame([9, $ticket->getUuid(), $agRef], [$reopened->ticketId(), $reopened->ticketUuid(), $reopened->byReference()]);
        $changed = new SupportTicketStatusChangedEvent($ticket, SupportStatus::Resolved, $agent);
        self::assertSame([9, $ticket->getUuid(), $agRef], [$changed->ticketId(), $changed->ticketUuid(), $changed->byReference()]);
        $status = new ChatSupportStatusChangedEvent($ticket, $agent);
        self::assertSame([9, $ticket->getUuid(), $agRef, null], [$status->conversationId(), $status->conversationUuid(), $status->byReference(), (new ChatSupportStatusChangedEvent($ticket, null))->byReference()]);

        $link = new ChatConversationLink($ticket, $ana, 'https://meet.example/x', 'meet.example', 'Standup');
        $this->setId($link, 21);
        foreach ([new ChatLinkAddedEvent($link, $agent), new ChatLinkUpdatedEvent($link, $agent, 'https://old.example', null)] as $event) {
            self::assertSame([21, $link->getUuid(), 9, $ticket->getUuid(), $agRef], [$event->linkId(), $event->linkUuid(), $event->conversationId(), $event->conversationUuid(), $event->byReference()], $event::class);
        }
        $removed = new ChatLinkRemovedEvent($ticket, $link->getUuid(), $link->getUrl(), null, $agent);
        self::assertSame([9, $ticket->getUuid(), $agRef], [$removed->conversationId(), $removed->conversationUuid(), $removed->byReference()]);

        $opening = new ChatChannelOpeningEvent(new ChatChannel('project:x', 'X'), $ana);
        self::assertSame([$ref, $ana->getChatPublicId()], [$opening->userReference(), $opening->userPublicId()]);
    }

    public function testAnEventNeverCarriesARowOrUserWithoutAnId(): void
    {
        self::assertNull(EventRefs::optional(null));
        self::assertSame('3', EventRefs::optional($this->user('Ana', 3)));
        self::assertSame(4, EventRefs::id(4));
        foreach ([static fn () => EventRefs::user(new TestUser('New')), static fn () => EventRefs::id(null)] as $call) {
            try {
                $call();
                self::fail('A missing id must be refused.');
            } catch (LogicException $failure) {
                self::assertStringContainsString('needs an id', $failure->getMessage());
            }
        }
    }

    /**
     * @return array{TestUser, TestUser, ChatConversation, ChatMessage}
     */
    private function fixture(): array
    {
        $ana          = $this->user('Ana', 3);
        $luis         = $this->user('Luis', 4);
        $conversation = ChatConversation::channel('project:acme', new DateTimeImmutable('2026-11-01 10:00'));
        $this->setId($conversation, 7);
        $message = new ChatMessage($conversation, $ana, 'hi', '<p>hi</p>', new DateTimeImmutable('2026-11-01 10:05'));
        $this->setId($message, 11);

        return [$ana, $luis, $conversation, $message];
    }

    private function user(string $name, int $id): TestUser
    {
        $user = new TestUser($name);
        $this->setId($user, $id);

        return $user;
    }

    private function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
