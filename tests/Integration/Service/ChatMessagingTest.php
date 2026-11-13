<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatParticipantView;
use Nowo\ChatKitBundle\Service\ChatMessaging;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function strtoupper;

/**
 * 1.27 (REQ-ARCH-001 rule 1): ChatMessagingInterface, the application service by public uuid: same rows, events and
 * validation as ChatServiceInterface, answers as views, unknown and look-alike uuids refused before anything is written.
 */
final class ChatMessagingTest extends DatabaseTestCase
{
    public function testTheServiceByUuidAnswersWithViews(): void
    {
        $messaging = $this->get(ChatMessagingInterface::class);
        self::assertInstanceOf(ChatMessaging::class, $messaging);
        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $sent = [];
        $this->get(EventDispatcherInterface::class)->addListener(ChatMessageSentEvent::class, static function (ChatMessageSentEvent $event) use (&$sent): void {
            $sent[] = $event->messageView()->uuid;
        });

        $direct = $messaging->openDirect($ana, $luis);
        self::assertSame('direct', $direct->kind);
        self::assertSame($direct->uuid, $messaging->openDirect($luis, $ana)->uuid, 'one conversation per pair');

        $message = $messaging->send($ana, $direct->uuid, 'hello **Luis**');
        self::assertSame([$direct->uuid, $ana->getChatPublicId(), 'hello **Luis**'], [$message->conversationUuid, $message->authorPublicId, $message->body]);
        self::assertSame([$message->uuid], $sent);

        $edited = $messaging->edit($ana, $direct->uuid, $message->uuid, 'hello again');
        self::assertSame(['hello again', true], [$edited->body, $edited->editedAt instanceof DateTimeImmutable]);
        self::assertSame(['👍' => [$luis->getChatPublicId()]], $messaging->toggleReaction($luis, $direct->uuid, $message->uuid, '👍'));
        self::assertTrue($messaging->markRead($luis, $direct->uuid, $message->id));
        $messaging->pin($ana, $direct->uuid, $message->uuid, true);
        self::assertSame('buzz', $messaging->buzz($ana, $direct->uuid)->kind);
        self::assertSame('system', $messaging->postSystemLine($direct->uuid, 'Renamed')->kind);

        $muted = $messaging->mute($luis, $direct->uuid, new DateTimeImmutable('+1 hour'));
        self::assertInstanceOf(ChatParticipantView::class, $muted);
        self::assertSame([$direct->uuid, $luis->getChatPublicId(), $message->id], [$muted->conversationUuid, $muted->userPublicId, $muted->lastReadMessageId]);
        self::assertNotNull($muted->mutedUntil);

        $messaging->archive($luis, [$direct->uuid, '00000000-0000-7000-8000-000000000000'], true);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE archived_at IS NOT NULL'));

        $messaging->delete($ana, $direct->uuid, $message->uuid);
        $stored = $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $message->uuid]);
        self::assertInstanceOf(ChatMessage::class, $stored);
        self::assertTrue($stored->isDeleted());
    }

    public function testChannelsSupportAndJoin(): void
    {
        $messaging = $this->get(ChatMessagingInterface::class);
        $ana       = $this->user('Ana');
        $agent     = $this->user('Agent Zoe');

        $channel = $messaging->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        self::assertSame(['channel', TestChannelProvider::KEY], [$channel->kind, $channel->channelKey]);
        $joined = $messaging->join($channel->uuid, $agent);
        self::assertSame($agent->getChatPublicId(), $joined->userPublicId);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant'), 'join() saved the row');

        $support = $messaging->openSupport($ana, ['url' => '/billing']);
        self::assertSame('support', $support->kind);
        $messaging->send($ana, $support->uuid, 'help');
        $messaging->resolveSupport($agent, $support->uuid);
        $messaging->reopenSupport($agent, $support->uuid);
        self::assertNull($messaging->mute($agent, $support->uuid, null), 'unmuting without a row creates none');
    }

    public function testUnknownAndLookAlikeUuidsAreRefusedBeforeAnyWrite(): void
    {
        $messaging = $this->get(ChatMessagingInterface::class);
        $ana       = $this->user('Ana');
        $direct    = $messaging->openDirect($ana, $this->user('Luis'));
        $message   = $messaging->send($ana, $direct->uuid, 'hi');
        $before    = $this->messageCount();

        foreach ([
            static fn () => $messaging->send($ana, '00000000-0000-7000-8000-000000000000', 'x'),
            static fn () => $messaging->send($ana, strtoupper($direct->uuid), 'x'),
            static fn () => $messaging->send($ana, $direct->uuid . ' ', 'x'),
            static fn () => $messaging->edit($ana, $direct->uuid, strtoupper($message->uuid), 'x'),
            static fn () => $messaging->delete($ana, $direct->uuid, '00000000-0000-7000-8000-000000000000'),
        ] as $i => $call) {
            try {
                $call();
                self::fail('call ' . $i . ' was accepted');
            } catch (ChatNotFoundException $exception) {
                self::assertSame('not_found', $exception->getMessage());
            }
        }
        self::assertSame($before, $this->messageCount());
        $messaging->archive($ana, [strtoupper($direct->uuid)], true);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE archived_at IS NOT NULL'));
    }

    public function testStrangersAndNonMembersGetNotFound(): void
    {
        $messaging = $this->get(ChatMessagingInterface::class);
        $ana       = $this->user('Ana');
        $luis      = $this->user('Luis');
        $eve       = $this->user('Eve');
        $stranger  = $this->user('Stranger Sam');
        $direct    = $messaging->openDirect($ana, $luis);
        $message   = $messaging->send($ana, $direct->uuid, 'only for Luis');
        $before    = $this->messageCount();

        foreach ([$eve, $stranger] as $outsider) {
            foreach ([
                'send'   => static fn () => $messaging->send($outsider, $direct->uuid, 'x'),
                'buzz'   => static fn () => $messaging->buzz($outsider, $direct->uuid),
                'edit'   => static fn () => $messaging->edit($outsider, $direct->uuid, $message->uuid, 'x'),
                'delete' => static fn () => $messaging->delete($outsider, $direct->uuid, $message->uuid),
                'pin'    => static fn () => $messaging->pin($outsider, $direct->uuid, $message->uuid, true),
                'react'  => static fn () => $messaging->toggleReaction($outsider, $direct->uuid, $message->uuid, '👍'),
                'read'   => static fn () => $messaging->markRead($outsider, $direct->uuid, $message->id),
                'join'   => static fn () => $messaging->join($direct->uuid, $outsider),
                'mute'   => static fn () => $messaging->mute($outsider, $direct->uuid, null),
                'solve'  => static fn () => $messaging->resolveSupport($outsider, $direct->uuid),
            ] as $what => $call) {
                try {
                    $call();
                    self::fail($outsider->getChatDisplayName() . ' could ' . $what);
                } catch (ChatNotFoundException $exception) {
                    self::assertSame('not_found', $exception->getMessage(), 'forbidden reads exactly like unknown');
                }
            }
            self::assertFalse($messaging->can($outsider, $direct->uuid, ChatMessagingInterface::VIEW));
        }
        // Luis is a member, but the message is Ana's: he may react, not edit, delete or (direct) is free to pin.
        foreach (['edit' => static fn () => $messaging->edit($luis, $direct->uuid, $message->uuid, 'x'), 'delete' => static fn () => $messaging->delete($luis, $direct->uuid, $message->uuid)] as $what => $call) {
            try {
                $call();
                self::fail('Luis could ' . $what . ' Ana\'s message');
            } catch (ChatNotFoundException) {
            }
        }
        self::assertSame($before, $this->messageCount());
        $messaging->archive($eve, [$direct->uuid], true);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE archived_at IS NOT NULL'), 'archive skips what the user cannot read');

        // The stranger may not open a direct thread with Luis, nor use the channel.
        foreach ([static fn () => $messaging->openDirect($stranger, $luis), static fn () => $messaging->openChannel($stranger, new ChatChannel(TestChannelProvider::KEY, 'Acme')), static fn () => $messaging->openChannel($ana, new ChatChannel('project:ACME', 'Acme'))] as $call) {
            try {
                $call();
                self::fail('opened');
            } catch (ChatNotFoundException) {
            }
        }
    }

    public function testCanAnswersByUuid(): void
    {
        $messaging = $this->get(ChatMessagingInterface::class);
        $ana       = $this->user('Ana');
        $luis      = $this->user('Luis');
        $agent     = $this->user('Agent Zoe');
        $direct    = $messaging->openDirect($ana, $luis);
        $message   = $messaging->send($ana, $direct->uuid, 'hi');

        self::assertTrue($messaging->can($luis, $direct->uuid, ChatMessagingInterface::VIEW));
        self::assertTrue($messaging->can($luis, $direct->uuid, ChatMessagingInterface::SEND));
        self::assertTrue($messaging->can($luis, $direct->uuid, ChatMessagingInterface::PIN));
        self::assertTrue($messaging->can($luis, $direct->uuid, ChatMessagingInterface::REACT, $message->uuid));
        self::assertFalse($messaging->can($luis, $direct->uuid, ChatMessagingInterface::EDIT, $message->uuid));
        self::assertTrue($messaging->can($ana, $direct->uuid, ChatMessagingInterface::EDIT, $message->uuid));
        self::assertTrue($messaging->can($ana, $direct->uuid, ChatMessagingInterface::DELETE, $message->uuid));
        self::assertFalse($messaging->can($ana, $direct->uuid, ChatMessagingInterface::EDIT), 'EDIT needs the message');
        self::assertFalse($messaging->can($ana, $direct->uuid, ChatMessagingInterface::RESOLVE));
        self::assertFalse($messaging->can($ana, $direct->uuid, 'unknown'));
        self::assertFalse($messaging->can($ana, '00000000-0000-7000-8000-000000000000', ChatMessagingInterface::VIEW));
        $support = $messaging->openSupport($ana);
        self::assertTrue($messaging->can($agent, $support->uuid, ChatMessagingInterface::RESOLVE));
        self::assertFalse($messaging->can($agent, $direct->uuid, ChatMessagingInterface::VIEW));
    }

    public function testTheViewsAreThoseOfTheEntityService(): void
    {
        $ana    = $this->user('Ana');
        $luis   = $this->user('Luis');
        $direct = $this->get(ChatServiceInterface::class)->openDirect($ana, $luis);

        self::assertEquals(ChatConversationView::of($direct), $this->get(ChatMessagingInterface::class)->openDirect($ana, $luis));
    }

    private function messageCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message');
    }
}
