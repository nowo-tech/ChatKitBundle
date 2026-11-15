<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use DateTimeImmutable;
use Nowo\ChatKitBundle\Contract\ChatMessagingInterface;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatConversationLink;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Entity\ChatReaction;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Link\ChatLinks;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Privacy\ChatPersonalData;
use Nowo\ChatKitBundle\Repository\ChatQuickReplyRepository;
use Nowo\ChatKitBundle\Repository\ChatUserSettingsRepository;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Support\Chat\RecordingRealtime;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\Transaction\ScriptedTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;

use function array_column;
use function count;

/**
 * 1.30 (Beacon spec 239, ARCH-001): a host that binds its own TransactionRunnerInterface (a DDD host, or
 * DoctrineDeadlockRetryBundle's retrying adapter) runs every ChatKit write. A runner that replays a unit of work after a
 * transient failure (the manager's state discarded in between) gets exactly one effect: every write re-attaches what it
 * captured and reads again what it needs. Side effects (realtime, events) happen once, after the commit.
 */
final class HostRunnerReplayTest extends DatabaseTestCase
{
    private ScriptedTransactionRunner $runner;

    protected function scriptedTransactionRunner(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner = $this->get(ScriptedTransactionRunner::class);
    }

    public function testTheHostsRunnerRunsEveryChatWrite(): void
    {
        $chat = $this->get(ChatService::class);
        $dm   = $chat->openDirect($this->user('Ana'), $this->user('Luis'));
        $chat->send($this->fresh('Ana'), $dm, 'hi');

        self::assertGreaterThanOrEqual(2, $this->runner->runs);
        $this->runner->script = ['unavailable'];
        $this->expectException(TransientStorageFailure::class);
        $chat->send($this->fresh('Ana'), $dm, 'again');
    }

    public function testConversationsAndMessagesAreWrittenOnceWhenReplayed(): void
    {
        $chat = $this->get(ChatService::class);
        $dm   = $this->replay(fn (): ChatConversation => $chat->openDirect($this->user('Ana'), $this->user('Luis')));
        self::assertSame(1, $this->rows(ChatConversation::class));
        self::assertSame(2, $this->rows(ChatParticipant::class));

        $realtime = $this->get(RecordingRealtime::class);
        $before   = count($realtime->published);
        $message  = $this->replay(fn (): ChatMessage => $chat->send($this->fresh('Ana'), $dm, 'hello'));
        self::assertSame(1, $this->rows(ChatMessage::class));
        self::assertTrue($this->em->contains($message));
        self::assertSame((int) $message->getId(), $this->participantOf('Ana', $dm)->getLastReadMessageId());
        self::assertGreaterThan($before, count($realtime->published), 'published after the commit');

        $buzz = $this->replay(fn (): ChatMessage => $chat->buzz($this->fresh('Luis'), $this->conversation($dm)));
        self::assertSame(ChatMessage::KIND_BUZZ, $buzz->getKind());
        self::assertSame(2, $this->rows(ChatMessage::class));

        $this->replay(fn () => $chat->edit($this->message($message), 'hello again'));
        self::assertSame('hello again', $this->message($message)->getBody());

        $emoji = $this->reaction();
        $this->replay(fn (): array => $chat->toggleReaction($this->fresh('Luis'), $this->message($message), $emoji));
        self::assertSame(1, $this->rows(ChatReaction::class), 'toggled once');

        self::assertTrue($this->replay(fn (): bool => $chat->markRead($this->fresh('Ana'), $this->conversation($dm), (int) $buzz->getId())));
        self::assertSame((int) $buzz->getId(), $this->participantOf('Ana', $dm)->getLastReadMessageId());

        $this->replay(fn () => $chat->pin($this->fresh('Ana'), $this->message($message), true));
        self::assertNotNull($this->message($message)->getPinnedAt());
        self::assertSame(1, $this->em->getRepository(ChatMessage::class)->count(['kind' => ChatMessage::KIND_PIN]));

        $this->replay(fn (): ChatMessage => $chat->postSystemLine($this->conversation($dm), 'renamed'));
        self::assertSame(1, $this->em->getRepository(ChatMessage::class)->count(['kind' => ChatMessage::KIND_SYSTEM]));

        $this->replay(fn () => $chat->mute($this->fresh('Luis'), $this->conversation($dm), new DateTimeImmutable('+1 hour')));
        self::assertNotNull($this->participantOf('Luis', $dm)->getMutedUntil());

        $this->replay(fn () => $chat->archive($this->fresh('Ana'), [$this->conversation($dm)], true));
        self::assertNotNull($this->participantOf('Ana', $dm)->getArchivedAt());

        $this->replay(fn () => $chat->delete($this->fresh('Ana'), $this->message($message)));
        self::assertTrue($this->message($message)->isDeleted());
        self::assertSame(0, $this->rows(ChatReaction::class));
    }

    public function testChannelsSupportAndJoinsAreWrittenOnceWhenReplayed(): void
    {
        $chat    = $this->get(ChatService::class);
        $channel = $this->replay(fn (): ChatConversation => $chat->openChannel($this->user('Ana'), new ChatChannel(TestChannelProvider::KEY, 'Acme')));
        self::assertSame(1, $this->em->getRepository(ChatParticipant::class)->count(['conversation' => $channel->getId()]));

        $view = $this->replay(fn () => $this->get(ChatMessagingInterface::class)->join($channel->getUuid(), $this->user('Luis')));
        self::assertSame(2, $this->em->getRepository(ChatParticipant::class)->count(['conversation' => $channel->getId()]));
        self::assertNotNull($view);

        $support = $this->replay(fn (): ChatConversation => $chat->openSupport($this->user('Bea')));
        $this->replay(fn (): ChatConversation => $chat->openSupport($this->fresh('Bea'), ['url' => '/x', 'label' => 'X']));
        self::assertSame(['url' => '/x', 'label' => 'X'], $this->conversation($support)->getSupportContext());

        $chat->send($this->fresh('Bea'), $this->conversation($support), 'help');
        $this->replay(fn () => $chat->resolveSupport($this->fresh('Ana'), $this->conversation($support)));
        self::assertTrue($this->conversation($support)->isResolved());
        $this->replay(fn () => $chat->reopenSupport($this->fresh('Ana'), $this->conversation($support)));
        self::assertFalse($this->conversation($support)->isResolved());
    }

    public function testTicketsGetOneNumberWhenReplayed(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $draft   = $this->replay(fn (): ChatConversation => $tickets->open($this->user('Ana'), ['url' => '/a', 'label' => 'A']));
        self::assertTrue($this->conversation($draft)->isSupportDraft());

        $posted = $this->replay(fn (): array => $tickets->chooseTopic($this->fresh('Ana'), $this->conversation($draft), 'billing'));
        self::assertNotSame([], $posted);
        self::assertSame(1, $this->conversation($draft)->getTicketNumber(), 'gap-free: the replayed attempt\'s number was rolled back');

        $this->replay(fn (): array => $tickets->chooseTopic($this->fresh('Ana'), $this->conversation($draft), 'incident'));
        self::assertSame('incident', $this->conversation($draft)->getTicketTopic());

        $agent = $this->user('Agent');
        $this->replay(fn (): bool => $tickets->changeStatus($agent, $this->conversation($draft), SupportStatus::Resolved, true));
        self::assertSame(SupportStatus::Resolved, $this->conversation($draft)->getSupportStatus());
        $this->replay(fn () => $tickets->resume($this->fresh('Ana'), $this->conversation($draft)));
        self::assertSame(SupportStatus::Open, $this->conversation($draft)->getSupportStatus());

        $second = $tickets->open($this->user('Bea'));
        $this->replay(fn (): array => $tickets->chooseTopic($this->fresh('Bea'), $this->conversation($second), 'billing'));
        self::assertSame(2, $this->conversation($second)->getTicketNumber());
    }

    public function testLinksSettingsQuickRepliesAndErasureAreWrittenOnceWhenReplayed(): void
    {
        $dm    = $this->get(ChatService::class)->openDirect($this->user('Ana'), $this->user('Luis'));
        $links = $this->get(ChatLinks::class);
        $link  = $this->replay(fn (): ChatConversationLink => $links->add($this->fresh('Ana'), $this->conversation($dm), 'https://example.com/a', 'A'));
        self::assertSame(1, $this->rows(ChatConversationLink::class));
        $this->replay(fn () => $links->update($this->fresh('Ana'), $this->link($link), 'https://example.com/b', 'B'));
        self::assertSame('B', $this->link($link)->getTitle());
        $this->replay(fn () => $links->remove($this->fresh('Ana'), $this->link($link)));
        self::assertSame(0, $this->rows(ChatConversationLink::class));
        $gone = $links->add($this->fresh('Ana'), $this->conversation($dm), 'https://example.com/c', null);
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_conversation_link');
        $this->replay(fn () => $links->remove($this->fresh('Ana'), $gone));
        self::assertSame(0, $this->rows(ChatConversationLink::class), 'removed meanwhile: nothing to do');

        $settings = $this->get(ChatUserSettingsRepository::class);
        $mine     = $settings->forUser($this->fresh('Ana'));
        $mine->setAutoReply(true, 'Away', 'range', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), false);
        $this->replay(static fn () => $settings->save($mine));
        self::assertSame(1, $this->rows(ChatUserSettings::class), 'a new row: inserted once');
        $mine = $settings->forUser($this->fresh('Ana'));
        $mine->setAutoReply(true, 'Back soon', 'range', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), false);
        $this->replay(static fn () => $settings->save($mine));
        $this->em->clear();
        self::assertSame('Back soon', $this->em->getRepository(ChatUserSettings::class)->findAll()[0]->autoReplyView()['text'], 'a stored row: the caller\'s change is written by the replay');

        $replies = $this->get(ChatQuickReplyRepository::class);
        $reply   = new ChatQuickReply($this->fresh('Ana'), 'Hi', 'Hello there');
        $this->replay(static fn () => $replies->save($reply));
        self::assertSame(1, $this->rows(ChatQuickReply::class));
        $stored = $this->em->getRepository(ChatQuickReply::class)->findAll()[0];
        $stored->change('Hey', 'Hey there');
        $this->replay(static fn () => $replies->save($stored));
        $this->em->clear();
        self::assertSame('Hey there', $this->em->getRepository(ChatQuickReply::class)->findAll()[0]->getBody());
        $this->replay(fn () => $replies->remove($this->em->getRepository(ChatQuickReply::class)->findAll()[0]));
        self::assertSame(0, $this->rows(ChatQuickReply::class));

        // Luis writes; Ana's automatic reply is its own unit of work, after the message's commit: replayed, it is one.
        $this->runner->script = ['pass', 'replay'];
        $this->get(ChatService::class)->send($this->fresh('Luis'), $this->conversation($dm), 'are you there?');
        self::assertSame(1, $this->em->getRepository(ChatMessage::class)->count(['kind' => ChatMessage::KIND_AUTO]));

        $this->replay(fn () => $this->get(ChatPersonalData::class)->eraseMentions($this->fresh('Ana')));
        $this->replay(fn () => $this->get(ChatPersonalData::class)->erase($this->fresh('Ana')));
        self::assertSame([], array_column($this->get(ChatPersonalData::class)->export($this->fresh('Ana'))['messages'], 'text'));
    }

    public function testATransientFailureOfAnAutomaticReplyNeverFailsTheSentMessage(): void
    {
        $chat     = $this->get(ChatService::class);
        $dm       = $chat->openDirect($this->user('Ana'), $this->user('Luis'));
        $settings = $this->get(ChatUserSettingsRepository::class);
        $away     = $settings->forUser($this->fresh('Ana'));
        $away->setAutoReply(true, 'Away', 'range', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 day'), false);
        $settings->save($away);

        // The message commits; its automatic reply (a unit of work of its own, after the commit) cannot be written.
        $this->runner->script = ['pass', 'unavailable'];
        $message              = $chat->send($this->fresh('Luis'), $this->conversation($dm), 'are you there?');

        self::assertNotNull($message->getId());
        self::assertSame(1, $this->rows(ChatMessage::class), 'the message, without its automatic reply');
    }

    /**
     * Runs the call with one replay scripted and checks that the runner was asked once and made two attempts.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function replay(callable $call): mixed
    {
        $runs                 = $this->runner->runs;
        $attempts             = $this->runner->attempts;
        $this->runner->script = ['replay'];
        $result               = $call();
        self::assertSame([], $this->runner->script, 'the call ran through the host\'s runner');
        self::assertSame($runs + 1, $this->runner->runs);
        self::assertSame($attempts + 2, $this->runner->attempts);

        return $result;
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class): int
    {
        return $this->em->getRepository($class)->count([]);
    }

    private function fresh(string $name): TestUser
    {
        return $this->em->getRepository(TestUser::class)->findOneBy(['name' => $name]) ?? self::fail($name);
    }

    private function conversation(ChatConversation $conversation): ChatConversation
    {
        return $this->em->getRepository(ChatConversation::class)->findOneBy(['uuid' => $conversation->getUuid()]) ?? self::fail('conversation');
    }

    private function message(ChatMessage $message): ChatMessage
    {
        return $this->em->getRepository(ChatMessage::class)->findOneBy(['uuid' => $message->getUuid()]) ?? self::fail('message');
    }

    private function link(ChatConversationLink $link): ChatConversationLink
    {
        return $this->em->getRepository(ChatConversationLink::class)->findOneBy(['uuid' => $link->getUuid()]) ?? self::fail('link');
    }

    private function participantOf(string $name, ChatConversation $conversation): ChatParticipant
    {
        return $this->em->getRepository(ChatParticipant::class)->findOneBy(['conversation' => $this->conversation($conversation), 'user' => $this->fresh($name)]) ?? self::fail('participant');
    }

    private function reaction(): string
    {
        $reactions = $this->kernel->getContainer()->getParameter('nowo_chat_kit.reactions');
        self::assertIsArray($reactions);
        self::assertIsString($reactions[0]);

        return $reactions[0];
    }
}
