<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Service;

use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Event\ChatMessageDeletedEvent;
use Nowo\ChatKitBundle\Event\ChatMessageSentEvent;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Realtime\ChatPresence;
use Nowo\ChatKitBundle\Realtime\ChatTopics;
use Nowo\ChatKitBundle\Repository\ChatParticipantRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatSupportInbox;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Conversation lifecycle end to end on SQLite: open (idempotent), send / buzz / edit / delete / react, read
 * cursors, support reopen, and the realtime events each step publishes to whom.
 */
final class ChatServiceTest extends DatabaseTestCase
{
    public function testDirectConversationLifecycle(): void
    {
        $chat = $this->get(ChatService::class);
        $realtime = $this->get(RecordingRealtime::class);
        $ana = $this->user('Ana');
        $luis = $this->user('Luis');

        $dm = $chat->openDirect($ana, $luis);
        self::assertSame($dm, $chat->openDirect($luis, $ana), 'one conversation per pair');

        $message = $chat->send($ana, $dm, "  **hola**\r\nLuis  ");
        self::assertSame("**hola**\nLuis", $message->getBody());
        self::assertSame('<p><strong>hola</strong><br>Luis</p>', $message->getBodyHtml());
        self::assertSame($message->getCreatedAt(), $dm->getLastMessageAt());
        self::assertSame((int) $message->getId(), $chat->participant($dm, $ana)->getLastReadMessageId(), 'the author has read their own message');

        $event = $realtime->published[0];
        self::assertSame('message', $event['payload']['type']);
        self::assertEqualsCanonicalizing([ChatTopics::forUser($ana->getChatPublicId()), ChatTopics::forUser($luis->getChatPublicId())], $event['topics']);

        self::assertTrue($chat->markRead($luis, $dm, (int) $message->getId()));
        self::assertFalse($chat->markRead($luis, $dm, (int) $message->getId()), 'cursor did not move: no event');
        self::assertSame(['message', 'read'], $realtime->types());
        self::assertCount(2, $realtime->published[1]['topics'], 'a DM read receipt goes to both people');

        $chat->edit($message, 'hola Luis');
        self::assertTrue($message->isEdited());
        self::assertSame('message_updated', $realtime->published[2]['payload']['type']);

        $reactions = $chat->toggleReaction($luis, $message, '👍');
        self::assertSame(['👍' => [$luis->getChatPublicId()]], $reactions);
        self::assertSame(['👍' => [$luis->getChatPublicId()], '🎉' => [$ana->getChatPublicId()]], $chat->toggleReaction($ana, $message, '🎉'));
        self::assertSame(['🎉' => [$ana->getChatPublicId()]], $chat->toggleReaction($luis, $message, '👍'), 'second toggle removes it');

        $chat->delete($ana, $message);
        self::assertTrue($message->isDeleted());
        self::assertCount(0, $this->em->getRepository(ChatReaction::class)->findAll(), 'reactions go with the message');

        $buzz = $chat->buzz($luis, $dm);
        self::assertTrue($buzz->isBuzz());
        self::assertSame('message', $realtime->published[\count($realtime->published) - 1]['payload']['type']);
    }

    public function testRejectsEmptyTooLongAndUnknownReactions(): void
    {
        $chat = $this->get(ChatService::class);
        $dm = $chat->openDirect($ana = $this->user('Ana'), $this->user('Luis'));
        $message = $chat->send($ana, $dm, 'hi');

        foreach (['  ' => 'empty', "\u{202E}" => 'empty', str_repeat('a', ChatMessage::MAX_LENGTH + 1) => 'too_long'] as $body => $error) {
            try {
                $chat->send($ana, $dm, (string) $body);
                self::fail('expected '.$error);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }

        $this->expectExceptionMessage('reaction');
        $chat->toggleReaction($ana, $message, '💩');
    }

    public function testChannelAndSupport(): void
    {
        $chat = $this->get(ChatService::class);
        $realtime = $this->get(RecordingRealtime::class);
        $ana = $this->user('Ana');
        $agent = $this->user('Agent Smith');
        $this->user('Stranger');
        $this->user('Disabled Bob');

        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        self::assertSame($channel, $chat->openChannel($agent, new ChatChannel(TestChannelProvider::KEY, 'Acme')));
        self::assertCount(2, $this->get(ChatParticipantRepository::class)->findBy(['conversation' => $channel]));
        $chat->send($ana, $channel, 'hello team');
        self::assertCount(2, $realtime->published[0]['topics'], 'members only: not the stranger, not the disabled account');

        $support = $chat->openSupport($ana);
        self::assertSame($support, $chat->openSupport($ana));
        $chat->resolveSupport($agent, $support);
        self::assertTrue($support->isResolved());
        self::assertSame('conversation', $realtime->published[1]['payload']['type']);

        $chat->send($agent, $support, 'anything else?');
        self::assertTrue($support->isResolved(), 'an agent reply does not reopen');
        $chat->reopenSupport($agent, $support);
        self::assertFalse($support->isResolved(), 'an agent reopens it explicitly');
        $chat->resolveSupport($agent, $support);
        $chat->send($ana, $support, 'yes, one more thing');
        self::assertFalse($support->isResolved(), 'the owner writing again reopens');

        $chat->markRead($agent, $channel, 999);
        self::assertSame([ChatTopics::forUser($agent->getChatPublicId())], $realtime->published[\count($realtime->published) - 1]['topics'], 'channel read receipts only go to the reader');
    }

    public function testEventsAndSupportInbox(): void
    {
        $chat = $this->get(ChatService::class);
        $events = [];
        $dispatcher = $this->get(EventDispatcherInterface::class);
        foreach ([ChatMessageSentEvent::class, ChatMessageDeletedEvent::class, ChatSupportStatusChangedEvent::class] as $class) {
            $dispatcher->addListener($class, static function (object $event) use (&$events): void {
                $events[] = $event;
            });
        }
        $ana = $this->user('Ana');
        $bob = $this->user('Bob');
        $agent = $this->user('Agent Smith');

        $support = $chat->openSupport($ana, ['url' => '/projects/acme/issues', 'label' => 'Issues']);
        self::assertSame(['url' => '/projects/acme/issues', 'label' => 'Issues'], $support->getSupportContext());
        self::assertSame($support, $chat->openSupport($ana), 'no context: the last one stays');
        self::assertSame(['url' => '/projects/acme/issues', 'label' => 'Issues'], $support->getSupportContext());
        $chat->openSupport($ana, ['url' => 7, 'label' => 'Dashboard']);
        self::assertSame(['label' => 'Dashboard'], $support->getSupportContext());

        $question = $chat->send($ana, $support, 'help');
        $bobs = $chat->openSupport($bob);
        $chat->send($bob, $bobs, 'export broken');
        $chat->send($agent, $bobs, 'looking');
        $chat->resolveSupport($agent, $support);
        $chat->send($ana, $support, 'still broken');
        $chat->delete($agent, $question);

        self::assertInstanceOf(ChatMessageSentEvent::class, $events[0]);
        self::assertSame($question, $events[0]->message);
        $resolved = array_values(array_filter($events, static fn (object $event): bool => $event instanceof ChatSupportStatusChangedEvent));
        self::assertCount(2, $resolved, 'resolved by the agent, reopened by the owner');
        self::assertSame($agent, $resolved[0]->by);
        self::assertSame($ana, $resolved[1]->by);
        $deleted = end($events);
        self::assertInstanceOf(ChatMessageDeletedEvent::class, $deleted);
        self::assertTrue($deleted->moderated);

        $inbox = $this->get(ChatSupportInbox::class);
        self::assertEqualsCanonicalizing(['Ana', 'Bob'], array_column($inbox->list($agent, 'open', null), 'title'));
        self::assertSame(['Bob'], array_column($inbox->list($agent, 'mine', null), 'title'), 'threads the agent answered');
        self::assertSame([], $inbox->list($agent, 'resolved', null));
        self::assertSame(['Bob'], array_column($inbox->list($agent, 'all', 'BOB@'), 'title'), 'search by email');
        self::assertSame(['Ana'], array_column($inbox->list($agent, 'unknown-filter', 'an'), 'title'), 'unknown filter = open; search by name');
        $row = $inbox->list($agent, 'open', 'ana')[0];
        self::assertSame(['uuid' => $ana->getChatPublicId(), 'name' => 'Ana', 'email' => 'ana@example.test'], $row['owner']);
        self::assertSame(['label' => 'Dashboard'], $row['supportContext']);
        self::assertSame(1, $row['unread']);
        self::assertSame(2, $inbox->countOpen());
    }

    public function testPresenterShapes(): void
    {
        $presenter = $this->get(ChatPresenter::class);
        $chat = $this->get(ChatService::class);
        $access = $this->get(ChatAccess::class);
        $ana = $this->user('Ana');
        $luis = $this->user('Luis');
        $agent = $this->user('Agent Smith');

        $dm = $chat->openDirect($ana, $luis);
        $last = $chat->send($luis, $dm, '**Deploy** done');
        $row = $presenter->conversation($dm, $ana, $luis, null, true, 2, $last, false, true);
        self::assertSame('Luis', $row['title']);
        self::assertSame('luis@example.test', $row['subtitle']);
        self::assertSame('L', $row['initials']);
        self::assertSame($luis->getChatPublicId(), $row['peerUuid']);
        self::assertTrue($row['peerOnline']);
        self::assertSame(['preview' => 'Deploy done', 'buzz' => false, 'deleted' => false, 'mine' => false, 'authorName' => 'Luis'], $row['lastMessage']);
        self::assertSame('Deleted user', $presenter->conversation($dm, $ana, null, null, false)['title']);
        self::assertNull($presenter->conversation($dm, $ana, null, null, false)['lastMessage']);

        $channel = $chat->openChannel($ana, $access->channel(TestChannelProvider::KEY) ?? self::fail('channel'));
        $channelRow = $presenter->conversation($channel, $ana, null, $access->channel(TestChannelProvider::KEY), true);
        self::assertSame('#Acme', $channelRow['title']);
        self::assertSame(TestChannelProvider::KEY, $channelRow['channelKey']);

        $support = $chat->openSupport($ana);
        self::assertSame('Support', $presenter->conversation($support, $ana, null, null, true)['title']);
        self::assertSame('Ana', $presenter->conversation($support, $agent, null, null, true, viewerIsAgent: true)['title']);
        self::assertSame('Support', $presenter->conversation($support, $ana, null, null, true, viewerIsAgent: true)['title'], 'an agent viewing their own thread');

        $message = $presenter->message($last, $luis, ['👍' => ['x']]);
        self::assertTrue($message['mine']);
        self::assertSame(['👍' => ['x']], $message['reactions']);
        self::assertFalse($presenter->message($last, null)['mine']);
        $orphan = new ChatMessage($dm, null, 'x', '<p>x</p>');
        self::assertSame('Deleted user', $presenter->message($orphan, $ana)['authorName']);
        self::assertSame('?', $presenter->message($orphan, $ana)['authorInitials']);
    }

    public function testPresenceRespectsTheVisibilitySetting(): void
    {
        $presence = $this->get(ChatPresence::class);
        $ana = $this->user('Ana');
        $luis = $this->user('Luis');
        $eve = $this->user('Eve');

        self::assertSame([], $presence->statusOf([]));
        $presence->heartbeat($ana, true);
        $presence->heartbeat($ana, false);
        $presence->heartbeat($luis, false);
        $settings = $this->em->getRepository(ChatUserSettings::class)->forUser($luis);
        $settings->setPresenceVisible(false);
        $this->em->persist($settings);
        $this->em->flush();

        $status = $presence->statusOf([$ana, $luis, $eve]);
        self::assertSame(['online' => true, 'appOpen' => true], $status[$ana->getChatPublicId()]);
        self::assertSame(['online' => false, 'appOpen' => false], $status[$luis->getChatPublicId()], 'hidden presence, no visible tab');
        self::assertSame(['online' => false, 'appOpen' => false], $status[$eve->getChatPublicId()]);

        $presence->forget($ana);
        self::assertFalse($presence->statusOf([$ana])[$ana->getChatPublicId()]['online']);
    }
}
