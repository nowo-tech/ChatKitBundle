<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketAssignedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketCreatedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketReopenedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketStatusChangedEvent;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Service\ChatSupportInbox;
use Nowo\ChatKitBundle\Support\ChatSupportPolicy;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Support\ChatTicketNumbers;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Support tickets end to end on the services: intake draft, topic → numbered ticket with automatic replies, the
 * "continue or new?" question after inactivity or resolution, reopen, read-only, status / assignment, the inbox
 * filters and counts, and the ticket events.
 */
final class ChatSupportTicketsTest extends DatabaseTestCase
{
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dispatcher = $this->get(EventDispatcherInterface::class);
        foreach ([SupportTicketCreatedEvent::class, SupportTicketReopenedEvent::class, SupportTicketStatusChangedEvent::class, SupportTicketAssignedEvent::class, ChatSupportStatusChangedEvent::class] as $class) {
            $dispatcher->addListener($class, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function topics(): iterable
    {
        yield 'incident' => ['incident', 'Incident / bug'];
        yield 'billing' => ['billing', 'Billing'];
        yield 'how_to' => ['how_to', 'How do I…? (feature question)'];
        yield 'account' => ['account', 'Account and access'];
        yield 'other' => ['other', 'Other'];
    }

    #[DataProvider('topics')]
    public function testChoosingATopicOpensANumberedTicketWithItsReply(string $key, string $label): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $ana     = $this->user('Ana');

        $draft = $tickets->open($ana, ['url' => '/billing', 'label' => 'Billing']);
        self::assertTrue($draft->isSupportDraft());
        self::assertSame(['url' => '/billing', 'label' => 'Billing'], $draft->getSupportContext());
        self::assertSame($draft, $tickets->open($ana), 'the same draft until a topic is chosen');
        $intake = $tickets->intake($ana);
        self::assertSame('topic', $intake['prompt']);
        self::assertNull($intake['resume']);
        self::assertSame(['incident', 'billing', 'how_to', 'account', 'other'], array_column($intake['topics'], 'key'));
        self::assertSame(7, $intake['resumeAfterDays']);

        $posted = $tickets->chooseTopic($ana, $draft, $key);
        self::assertTrue($draft->isTicket());
        self::assertSame(1, $draft->getTicketNumber());
        self::assertSame($key, $draft->getTicketTopic());
        self::assertSame(SupportStatus::Open, $this->statusOf($draft));
        $presenter = $this->get(ChatPresenter::class);
        self::assertSame('Ticket SUP-000001 opened: ' . $label . '.', $presenter->botText($posted[0]));
        self::assertCount(2, $posted, 'the opening line and the topic reply');
        self::assertTrue($posted[1]->isBot());
        self::assertStringStartsNotWith('support.', $presenter->botText($posted[1]), 'the reply is translated');
        $message = $presenter->message($posted[0], $ana);
        self::assertSame('Support assistant', $message['authorName']);
        self::assertSame('bot', $message['kind']);
        self::assertStringStartsWith('<p><span class="nowo-chat-emoji" aria-hidden="true">🎫</span>Ticket SUP-000001', $message['html']);
        self::assertFalse($message['refsPending']);
        self::assertTrue($presenter->message($posted[0], null)['refsPending'], 'a viewer-neutral bot line is fetched again in the reader\'s locale');
        self::assertInstanceOf(SupportTicketCreatedEvent::class, $this->recorded()[0]);
        self::assertSame($ana, $this->recorded()[0]->requester);

        $ticket = $presenter->ticket($draft);
        self::assertNotNull($ticket);
        self::assertSame(['SUP-000001', $key, $label, 'open', 'Open', false], [$ticket['number'], $ticket['topic'], $ticket['topicLabel'], $ticket['status'], $ticket['statusLabel'], $ticket['readOnly']]);
        self::assertSame('🟢', $ticket['statusEmoji']);
        self::assertNotSame('', $ticket['topicEmoji']);
        $summary = $presenter->conversation($draft, $ana, null, null, true, 0, $posted[1]);
        self::assertSame('SUP-000001 · ' . $label, $summary['title']);
        self::assertSame(mb_substr($presenter->botText($posted[1]), 0, 90), $summary['lastMessage']['preview']);
    }

    public function testFreeTextBeforeTheTopicIsKeptAndNumbersAreSequential(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $bob     = $this->user('Bob');

        $draft = $tickets->open($ana);
        $chat->send($ana, $draft, 'My export is broken');
        self::assertTrue($draft->isSupportDraft(), 'writing does not choose a topic');
        $tickets->chooseTopic($ana, $draft, 'incident');
        $messages = $this->get(ChatMessageRepository::class)->findPage($draft, 10);
        self::assertSame('My export is broken', $messages[0]->getBody(), 'the text stays the first message');

        $second = $tickets->open($bob);
        $tickets->chooseTopic($bob, $second, 'billing');
        self::assertSame(2, $second->getTicketNumber());
        self::assertSame($draft, $tickets->open($ana), 'an active, recent ticket is continued without a question');

        try {
            $tickets->chooseTopic($bob, $tickets->open($this->user('Carla')), 'nope');
            self::fail('unknown topic');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('topic', $exception->getMessage());
        }
    }

    public function testIdleTicketAsksContinueOrNew(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $first   = $tickets->open($ana);
        $tickets->chooseTopic($ana, $first, 'billing');
        $first->touch(new DateTimeImmutable('-8 days'));
        $this->em->flush();
        $this->events = [];

        $draft = $tickets->open($ana);
        self::assertTrue($draft->isSupportDraft(), 'idle for more than 7 days: back to the intake');
        $intake = $tickets->intake($ana);
        self::assertSame('resume', $intake['prompt']);
        self::assertSame('SUP-000001', $intake['resume']['number'] ?? null);
        self::assertSame(['SUP-000001'], array_column($intake['tickets'], 'number'));

        // Continue: the text typed meanwhile moves into the ticket.
        $chat->send($ana, $draft, 'About invoice 42 again');
        $tickets->resume($ana, $first, $draft);
        self::assertSame(SupportStatus::Open, $this->statusOf($first));
        $bodies = array_map(static fn (ChatMessage $message): string => $message->getBody(), $this->get(ChatMessageRepository::class)->findPage($first, 20));
        self::assertContains('About invoice 42 again', $bodies);
        self::assertContains('support.bot.continued', $bodies);
        self::assertSame([], $this->recorded(), 'continuing an open ticket changes no status');
        self::assertSame($first, $tickets->open($ana), 'active again');

        // New: idle again, the user picks a topic in the draft → a second ticket.
        $first->touch(new DateTimeImmutable('-9 days'));
        $this->em->flush();
        $draft = $tickets->open($ana);
        $tickets->chooseTopic($ana, $draft, 'incident');
        self::assertSame(2, $draft->getTicketNumber());
        self::assertSame(['SUP-000002', 'SUP-000001'], array_column($tickets->tickets($ana), 'number'));
    }

    public function testResolvedTicketIsReopenedAndTurnsReadOnlyAfterTheThreshold(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $agent   = $this->user('Agent Smith');
        $ticket  = $tickets->open($ana);
        $tickets->chooseTopic($ana, $ticket, 'incident');
        $this->events = [];

        self::assertTrue($tickets->changeStatus($agent, $ticket, SupportStatus::Resolved, true));
        self::assertFalse($tickets->changeStatus($agent, $ticket, SupportStatus::Resolved, true), 'same status: nothing to do');
        self::assertInstanceOf(SupportTicketStatusChangedEvent::class, $this->recorded()[0]);
        self::assertSame(SupportStatus::Open, $this->recorded()[0]->previous);
        self::assertInstanceOf(ChatSupportStatusChangedEvent::class, $this->recorded()[1]);
        self::assertSame('resume', $tickets->intake($ana)['prompt'], 'resolved: the user is asked');
        self::assertTrue($tickets->open($ana)->isSupportDraft());

        try {
            $tickets->changeStatus($ana, $ticket, SupportStatus::Closed, false);
            self::fail('the requester only reopens');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('status', $exception->getMessage());
        }
        $this->events = [];
        self::assertTrue($tickets->changeStatus($ana, $ticket, SupportStatus::Open, false), 'Reopen');
        self::assertSame([SupportTicketStatusChangedEvent::class, SupportTicketReopenedEvent::class, ChatSupportStatusChangedEvent::class], $this->names());

        // Resolved 31 days ago: read-only, not offered to continue, nobody writes.
        $ticket->changeSupportStatus(SupportStatus::Resolved, $agent, new DateTimeImmutable('-31 days'));
        $this->em->flush();
        $policy = $this->get(ChatSupportPolicy::class);
        self::assertTrue($policy->isReadOnly($ticket));
        self::assertSame('topic', $tickets->intake($ana)['prompt']);
        self::assertTrue($tickets->intake($ana)['tickets'][0]['readOnly']);
        $voter = $this->get(ChatVoter::class);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($ana, 'main', []), $ticket, [ChatVoter::SEND]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($ana, 'main', []), $ticket, [ChatVoter::VIEW]));
        foreach ([static fn () => $tickets->resume($ana, $ticket), static fn () => $tickets->changeStatus($ana, $ticket, SupportStatus::Open, false)] as $attempt) {
            try {
                $attempt();
                self::fail('read-only');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('read_only', $exception->getMessage());
            }
        }
        // An agent may still reopen it; closing makes it read-only at once.
        self::assertTrue($tickets->changeStatus($agent, $ticket, SupportStatus::Open, true));
        self::assertFalse($policy->isReadOnly($ticket));
        $tickets->changeStatus($agent, $ticket, SupportStatus::Closed, true);
        self::assertTrue($policy->isReadOnly($ticket));
        self::assertNotNull($ticket->getClosedAt());
    }

    public function testRepliesMoveTheStatusAndTheOwnerReopensByWriting(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $chat    = $this->get(ChatService::class);
        $ana     = $this->user('Ana');
        $agent   = $this->user('Agent Smith');
        $ticket  = $tickets->open($ana);
        $tickets->chooseTopic($ana, $ticket, 'how_to');
        $this->events = [];

        $chat->send($agent, $ticket, 'Which page?');
        self::assertSame(SupportStatus::Waiting, $this->statusOf($ticket));
        $chat->send($ana, $ticket, 'Settings');
        self::assertSame(SupportStatus::Open, $this->statusOf($ticket));
        self::assertSame([], $this->recorded(), 'the automatic open ↔ waiting switch dispatches nothing');

        $tickets->changeStatus($agent, $ticket, SupportStatus::Resolved, true);
        $this->events = [];
        $chat->send($ana, $ticket, 'Still there?');
        self::assertSame(SupportStatus::Open, $this->statusOf($ticket));
        $expected = [ChatSupportStatusChangedEvent::class, SupportTicketStatusChangedEvent::class, SupportTicketReopenedEvent::class];
        self::assertSame($expected, $this->names());
    }

    public function testAssignmentTopicChangeAndInbox(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $inbox   = $this->get(ChatSupportInbox::class);
        $ana     = $this->user('Ana');
        $bob     = $this->user('Bob');
        $agent   = $this->user('Agent Smith');
        $other   = $this->user('Agent Jones');
        $billing = $tickets->open($ana);
        $tickets->chooseTopic($ana, $billing, 'billing');
        $incident = $tickets->open($bob);
        $tickets->chooseTopic($bob, $incident, 'incident');
        $silent       = $tickets->open($this->user('Silent Sam'));
        $this->events = [];

        self::assertTrue($tickets->assign($agent, $billing, $agent));
        self::assertFalse($tickets->assign($agent, $billing, $agent), 'already assigned');
        self::assertSame($agent, $billing->getAssignee());
        self::assertInstanceOf(SupportTicketAssignedEvent::class, $this->recorded()[0]);
        try {
            $tickets->assign($agent, $billing, $bob);
            self::fail('only agents');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('assignee', $exception->getMessage());
        }
        self::assertTrue($tickets->assign($other, $incident, $other));
        self::assertTrue($tickets->assign($other, $incident, null));
        self::assertNull($incident->getAssignee());

        self::assertSame([], $tickets->chooseTopic($agent, $incident, 'incident'), 'same topic: nothing posted');
        $posted = $tickets->chooseTopic($agent, $incident, 'account');
        self::assertSame('Agent Smith changed the topic to Account and access.', $this->get(ChatPresenter::class)->botText($posted[0]));

        self::assertSame(['Ana'], array_column($inbox->list($agent, 'mine', null), 'title'));
        self::assertSame(['Bob'], array_column($inbox->list($agent, 'unassigned', null), 'title'));
        self::assertSame(['Ana'], array_column($inbox->list($agent, 'all', null, 50, 'billing'), 'title'));
        self::assertSame([], $inbox->list($agent, 'all', null, 50, 'none'), 'drafts without messages stay out');
        $byTopic = array_column($inbox->countByTopic(), 'count', 'key');
        self::assertSame(['incident' => 0, 'billing' => 1, 'how_to' => 0, 'account' => 1, 'other' => 0], $byTopic);
        $row = $inbox->list($agent, 'open', 'ana')[0];
        self::assertSame('SUP-000001', $row['ticket']['number']);
        self::assertSame(['uuid' => $agent->getChatPublicId(), 'name' => 'Agent Smith'], $row['ticket']['assignee']);
        self::assertSame(1, $row['ownerTickets']);
        self::assertSame([], $this->get(ChatConversationRepository::class)->countTicketsByOwner([]));

        // A draft with free text reaches the inbox without a topic.
        $this->get(ChatService::class)->send($silent->getSupportOwner() ?? $ana, $silent, 'hello?');
        self::assertSame(['Silent Sam'], array_column($inbox->list($agent, 'all', null, 50, 'none'), 'title'));
        self::assertContains(['key' => 'none', 'label' => 'No topic', 'icon' => null, 'emoji' => '', 'count' => 1], $inbox->countByTopic());

        // Status filters with the read-only cutoff.
        $tickets->changeStatus($agent, $billing, SupportStatus::Resolved, true);
        $tickets->changeStatus($agent, $incident, SupportStatus::Waiting, true);
        self::assertSame(['Ana'], array_column($inbox->list($agent, 'resolved', null), 'title'));
        self::assertSame(['Bob'], array_column($inbox->list($agent, 'waiting', null), 'title'));
        self::assertSame([], $inbox->list($agent, 'closed', null));
        $billing->changeSupportStatus(SupportStatus::Resolved, $agent, new DateTimeImmutable('-40 days'));
        $this->em->flush();
        self::assertSame(['Ana'], array_column($inbox->list($agent, 'closed', null), 'title'));
        self::assertSame([], $inbox->list($agent, 'resolved', null));

        // The requester's other tickets.
        $next = $tickets->open($ana);
        $tickets->chooseTopic($ana, $next, 'other');
        self::assertSame(['SUP-000001'], array_column($inbox->otherTickets($next), 'number'));
        self::assertSame([], $inbox->otherTickets(ChatConversation::channel('x')));
    }

    /**
     * @return list<object>
     */
    private function recorded(): array
    {
        return $this->events;
    }

    /**
     * @phpstan-impure
     */
    private function statusOf(ChatConversation $ticket): ?SupportStatus
    {
        return $ticket->getSupportStatus();
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->events);
    }

    public function testNumbersNeedAPersistedDraft(): void
    {
        $this->expectException(LogicException::class);
        $this->get(ChatTicketNumbers::class)->assign(ChatConversation::support(new TestUser('Ana', null)));
    }
}
