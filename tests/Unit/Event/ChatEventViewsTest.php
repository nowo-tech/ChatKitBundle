<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Event;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageMention;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatLinkAddedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkRemovedEvent;
use Nowo\ChatKitBundle\Event\ChatLinkUpdatedEvent;
use Nowo\ChatKitBundle\Event\ChatMentionEvent;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketAssignedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketCreatedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketReopenedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketStatusChangedEvent;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Security\SecurityChatUserResolver;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * 1.24: the events' read-only views (DTOs with scalars and public ids) for hosts that must not keep the bundle's
 * entities, the default current-user resolver and the reference helpers of the default `relation` mode.
 */
final class ChatEventViewsTest extends TestCase
{
    public function testMessageAndMentionViews(): void
    {
        $ana          = $this->user('Ana', 3);
        $luis         = $this->user('Luis', 4);
        $conversation = ChatConversation::channel('project:acme', new DateTimeImmutable('2026-11-01 10:00'));
        $this->setId($conversation, 7);
        $message = new ChatMessage($conversation, $ana, 'hi @[Luis](' . $luis->getChatPublicId() . ') @channel', '<p>hi</p>', new DateTimeImmutable('2026-11-01 10:05'));
        $this->setId($message, 11);
        $message->mentionAll(ChatMessage::MENTION_CHANNEL);

        $view = (new ChatMessageSentEvent($message))->messageView();
        self::assertSame([11, $message->getUuid(), $conversation->getUuid(), 'channel', 'project:acme', 'text', $ana->getChatPublicId()], [$view->id, $view->uuid, $view->conversationUuid, $view->conversationKind, $view->channelKey, $view->kind, $view->authorPublicId]);
        self::assertSame([[$luis->getChatPublicId()], 'channel', false, null], [$view->mentionedPublicIds, $view->mentionAll, $view->deleted, $view->editedAt]);
        self::assertSame('<p>hi</p>', $view->bodyHtml);

        $mention = new ChatMentionEvent($message, [$luis], $conversation, true);
        self::assertSame([$luis->getChatPublicId()], $mention->mentionedPublicIds());
        self::assertSame($message->getUuid(), $mention->messageView()->uuid);
        self::assertSame(['channel', 'project:acme', null, null], [$mention->conversationView()->kind, $mention->conversationView()->channelKey, $mention->conversationView()->supportOwnerPublicId, $mention->conversationView()->ticketNumber]);

        self::assertSame(['3', null, null], [$message->getAuthorReference(), $message->getPinnedByReference(), $message->getDeletedByReference()]);
        $line = ChatMessage::system($conversation, 'chat.channel', 'NowoChatKitBundle');
        $message->delete($luis);
        $deleted = new ChatMessageDeletedEvent($message, $luis, true);
        self::assertSame([$luis->getChatPublicId(), true, []], [$deleted->byPublicId(), $deleted->messageView()->deleted, $deleted->messageView()->mentionedPublicIds]);
        self::assertSame(['system', null, []], [ChatMessageView::of($line)->kind, ChatMessageView::of($line)->authorPublicId, ChatMessageView::of($line)->mentionedPublicIds]);
    }

    public function testSupportAndLinkViews(): void
    {
        $ana    = $this->user('Ana', 3);
        $agent  = $this->user('Agent', 5);
        $ticket = ChatConversation::support($ana);
        $ticket->assign($agent);
        $this->setId($ticket, 9);

        self::assertSame(['3', '5', null], [$ticket->getSupportOwnerReference(), $ticket->getAssigneeReference(), $ticket->getResolvedByReference()]);
        $view = ChatConversationView::of($ticket);
        self::assertSame([9, 'support', $ana->getChatPublicId(), $agent->getChatPublicId(), 'open'], [$view->id, $view->kind, $view->supportOwnerPublicId, $view->assigneePublicId, $view->supportStatus]);

        $status = new ChatSupportStatusChangedEvent($ticket, null);
        self::assertSame([9, null], [$status->conversationView()->id, $status->byPublicId()]);
        $assigned = new SupportTicketAssignedEvent($ticket, null, $agent);
        self::assertSame([null, $agent->getChatPublicId(), 9], [$assigned->assigneePublicId(), $assigned->byPublicId(), $assigned->ticketView()->id]);
        $created = new SupportTicketCreatedEvent($ticket, $ana);
        self::assertSame([$ana->getChatPublicId(), 9], [$created->requesterPublicId(), $created->ticketView()->id]);
        $reopened = new SupportTicketReopenedEvent($ticket, $agent);
        self::assertSame([$agent->getChatPublicId(), 9], [$reopened->byPublicId(), $reopened->ticketView()->id]);
        $changed = new SupportTicketStatusChangedEvent($ticket, SupportStatus::Resolved, $agent);
        self::assertSame([$agent->getChatPublicId(), 9], [$changed->byPublicId(), $changed->ticketView()->id]);

        $link = new ChatConversationLink($ticket, $ana, 'https://meet.example/x', 'meet.example', 'Standup');
        self::assertSame('3', $link->getCreatedByReference());
        foreach ([new ChatParticipant($ticket, $ana), new ChatReaction(ChatMessage::system($ticket, 'x'), $ana, '👍'), new ChatMessageMention(ChatMessage::system($ticket, 'x'), $ana), new ChatQuickReply($ana, 'T', 'B'), new ChatUserSettings($ana)] as $row) {
            self::assertSame('3', $row->getUserReference(), $row::class);
        }
        $added = new ChatLinkAddedEvent($link, $ana);
        self::assertSame([$link->getUuid(), $ticket->getUuid(), 'https://meet.example/x', 'Standup', $ana->getChatPublicId()], [$added->linkView()->uuid, $added->linkView()->conversationUuid, $added->linkView()->url, $added->linkView()->title, $added->linkView()->createdByPublicId]);
        self::assertSame($ana->getChatPublicId(), $added->byPublicId());
        $updated = new ChatLinkUpdatedEvent($link, $agent, 'https://old.example', null);
        self::assertSame([$agent->getChatPublicId(), $link->getCreatedAt()], [$updated->byPublicId(), $updated->linkView()->createdAt]);
        $removed = new ChatLinkRemovedEvent($ticket, $link->getUuid(), $link->getUrl(), null, $agent);
        self::assertSame([$agent->getChatPublicId(), 9], [$removed->byPublicId(), $removed->conversationView()->id]);
    }

    public function testTheDefaultResolverAndTheRelationModeReferences(): void
    {
        $ana      = $this->user('Ana', 3);
        $resolver = new SecurityChatUserResolver();
        self::assertSame($ana, $resolver->resolve($ana));
        self::assertNull($resolver->resolve(new InMemoryUser('ops', null)));
        self::assertNull($resolver->resolve(null));

        $relation = new ChatUserReferences();
        self::assertFalse($relation->isIdMode());
        self::assertSame(['p.user', 'IDENTITY(p.user)', $ana, 7, [1, 2], ['user' => $ana]], [$relation->field('p', 'user'), $relation->identity('p', 'user'), $relation->value($ana), $relation->sqlValue(7), $relation->ids([1, 2]), $relation->criteria('user', $ana)]);
        $id = new ChatUserReferences(ChatUserReferences::ID);
        self::assertSame(['p.userRef', 'p.userRef', '3', '7', ['1', '2'], ['userRef' => '3']], [$id->field('p', 'user'), $id->identity('p', 'user'), $id->value($ana), $id->sqlValue(7), $id->ids([1, 2]), $id->criteria('user', $ana)]);
        self::assertNull(ChatUserReferences::reference(null));
        self::assertNull(ChatUserReferences::reference(new TestUser('New')));
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
