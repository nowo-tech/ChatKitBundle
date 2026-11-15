<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatDirectoryInterface;
use Nowo\ChatKitBundle\Contract\ChatServiceInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Event\ChatSupportStatusChangedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketAssignedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketCreatedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketReopenedEvent;
use Nowo\ChatKitBundle\Event\SupportTicketStatusChangedEvent;
use Nowo\ChatKitBundle\Model\ChatSupportTopic;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Realtime\ChatNotifier;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Service\ChatPresenter;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function is_string;

/**
 * Support tickets: opening support (continue the active ticket, or the intake draft with the topic / "continue or
 * new?" question), choosing a topic (number, default assignee, automatic replies), continuing an earlier ticket,
 * status changes and assignment. Each change posts a bot line in the ticket and dispatches the ticket events.
 * Authorization is the caller's job (ChatApiController); this service keeps the data consistent.
 *
 * 1.30 (Beacon spec 239): every change is one unit of work run through TransactionRunnerInterface (ticket number,
 * bot lines, status, activity); the realtime updates and ticket events follow its commit.
 */
final readonly class ChatSupportTickets
{
    /** Earlier tickets listed in the intake panel. */
    public const int TICKETS_LIMIT = 10;

    private TransactionRunnerInterface $transactions;

    /**
     * Builds the ticket service.
     *
     * @param EntityManagerInterface $entityManager Persists drafts, bot messages and ticket changes
     * @param ChatConversationRepository $conversations Drafts and the requester's tickets
     * @param ChatMessageRepository $messages Moves a draft's messages into the continued ticket
     * @param ChatServiceInterface $chat Participant rows
     * @param ChatSupportTopics $topics The topics
     * @param ChatSupportPolicy $policy Resume and read-only rules
     * @param ChatTicketNumbers $numbers Sequential ticket numbers
     * @param ChatDirectoryInterface $directory Resolves a topic's default assignee
     * @param ChatAccess $access Whether that assignee answers support
     * @param ChatNotifier $notifier Realtime updates
     * @param ChatPresenter $presenter Ticket blocks of the intake panel
     * @param ClockInterface $clock The clock
     * @param EventDispatcherInterface|null $events Receives the ticket events; null dispatches none
     * @param TransactionRunnerInterface|null $transactions 1.30: runs every change; null uses ChatKit's default runner
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatServiceInterface $chat,
        private ChatSupportTopics $topics,
        private ChatSupportPolicy $policy,
        private ChatTicketNumbers $numbers,
        private ChatDirectoryInterface $directory,
        private ChatAccess $access,
        private ChatNotifier $notifier,
        private ChatPresenter $presenter,
        private ClockInterface $clock = new Clock(),
        private ?EventDispatcherInterface $events = null,
        ?TransactionRunnerInterface $transactions = null,
    ) {
        $this->transactions = $transactions ?? new ChatKitTransactionRunner($entityManager);
    }

    /**
     * What "Support" opens: the latest ticket while it is active and recent, otherwise the intake draft (created on
     * first use), where the user picks a topic or answers "continue or new?". `$context` (`url`, `label`) records
     * where the request comes from.
     *
     * @param ChatUserInterface $owner The user asking for support
     * @param array{url?: mixed, label?: mixed}|null $context Page the request comes from; null keeps the stored one
     *
     * @throws ChatUserLookAlikeException 1.28, `string` user references only: the owner's participant row would go into
     *                                    a user column that folds onto a look-alike's row (nothing is left pending)
     *
     * @return ChatConversation The active ticket or the draft
     */
    public function open(ChatUserInterface $owner, ?array $context = null): ChatConversation
    {
        // 1.28 (audit PV-L7): a look-alike refusal comes before any write and leaves nothing pending.
        $conversation = $this->target($owner);

        return ChatKitWrites::runRecovering($this->transactions, $this->entityManager, static function () use ($context, $conversation): ChatConversation {
            if ($context !== null) {
                // @igor-ignore - Doctrine entity (one row), not a shared worker service.
                $conversation->setSupportContext(is_string($context['url'] ?? null) ? $context['url'] : null, is_string($context['label'] ?? null) ? $context['label'] : null);
            }

            return $conversation;
        });
    }

    /**
     * The intake panel of a draft: `prompt` (`topic`, or `resume` when the latest ticket can be continued), the
     * topics, that ticket (`resume`) and the requester's earlier tickets.
     *
     * @param ChatUserInterface $owner The requester
     *
     * @return array{prompt: string, topics: list<array{key: string, label: string, icon: string|null, emoji: string}>, resume: array<string, mixed>|null, tickets: list<array<string, mixed>>, resumeAfterDays: int}
     */
    public function intake(ChatUserInterface $owner): array
    {
        $tickets = $this->conversations->findTickets($owner, self::TICKETS_LIMIT);
        $latest  = $tickets[0] ?? null;
        $resume  = $latest instanceof ChatConversation && !$this->policy->isReadOnly($latest) ? $this->presenter->ticket($latest) : null;

        return [
            'prompt'          => $resume === null ? 'topic' : 'resume',
            'topics'          => $this->topics->options(),
            'resume'          => $resume,
            'tickets'         => $this->rows($tickets),
            'resumeAfterDays' => $this->policy->resumeAfterDays(),
        ];
    }

    /**
     * The requester's tickets, newest activity first, as ticket blocks.
     *
     * @param ChatUserInterface $owner The requester
     * @param int $limit Maximum number of tickets
     *
     * @return list<array<string, mixed>> The ticket blocks
     */
    public function tickets(ChatUserInterface $owner, int $limit = self::TICKETS_LIMIT): array
    {
        return $this->rows($this->conversations->findTickets($owner, $limit));
    }

    /**
     * Chooses the topic. On the requester's draft it opens the ticket: next number, the topic's default assignee,
     * the "ticket opened" line and the topic's automatic reply (SupportTicketCreatedEvent). On a ticket (an agent)
     * it changes the topic and posts a line.
     *
     * @param ChatUserInterface $actor The requester (draft) or an agent (ticket)
     * @param ChatConversation $conversation The draft or the ticket
     * @param string $key Topic key
     *
     * @throws InvalidArgumentException `topic` when the key is unknown
     *
     * @return list<ChatMessage> The bot messages posted
     */
    public function chooseTopic(ChatUserInterface $actor, ChatConversation $conversation, string $key): array
    {
        $topic = $this->topics->find($key) ?? throw new InvalidArgumentException('topic');
        if (!$conversation->isSupportDraft()) {
            if ($conversation->getTicketTopic() === $topic->key) {
                return [];
            }
            [$conversation, $posted] = $this->change($conversation, function (ChatConversation $conversation) use ($actor, $topic): array {
                $conversation->changeTopic($topic->key);

                return [$this->bot($conversation, 'support.bot.topic_changed', ['by' => $actor->getChatDisplayName()], $topic->key)];
            });

            return $this->announce($conversation, $posted);
        }

        $assignee                = $this->defaultAssignee($topic);
        [$conversation, $posted] = $this->change($conversation, function (ChatConversation $conversation) use ($topic, $assignee): array {
            // 1.30: the number and the ticket commit together (gap-free); the counter row stays locked until then.
            $conversation->openTicket($this->numbers->assign($conversation), $topic->key);
            $posted = [$this->bot($conversation, 'support.bot.ticket_opened', ['number' => (string) $this->policy->format($conversation->getTicketNumber())], $topic->key)];
            if ($topic->reply !== null) {
                $posted[] = ChatMessage::bot($conversation, $topic->reply, $topic->domain, [], $topic->key, $this->clock->now());
                $this->entityManager->persist($posted[1]);
            }
            if ($assignee instanceof ChatUserInterface) {
                $conversation->assign($assignee);
            }

            return $posted;
        });
        $this->announce($conversation, $posted);
        $this->events?->dispatch(new SupportTicketCreatedEvent($conversation, $actor));
        if ($assignee instanceof ChatUserInterface) {
            $this->events?->dispatch(new SupportTicketAssignedEvent($conversation, $assignee, $actor));
        }

        return $posted;
    }

    /**
     * The requester continues an earlier ticket: the draft's messages (written before answering) move into it and an
     * idle, waiting or resolved ticket goes back to open (SupportTicketReopenedEvent).
     *
     * @param ChatUserInterface $owner The requester
     * @param ChatConversation $ticket The ticket to continue
     * @param ChatConversation|null $draft The requester's draft; null when none
     *
     * @throws InvalidArgumentException `read_only` when the ticket is read-only
     */
    public function resume(ChatUserInterface $owner, ChatConversation $ticket, ?ChatConversation $draft = null): void
    {
        if ($this->policy->isReadOnly($ticket)) {
            throw new InvalidArgumentException('read_only');
        }
        $previous          = $ticket->getSupportStatus() ?? SupportStatus::Open;
        [$ticket, $posted] = $this->change($ticket, function (ChatConversation $ticket) use ($owner, $draft): array {
            $draft = $draft instanceof ChatConversation ? ChatKitWrites::attach($this->entityManager, $draft) : $draft;
            if ($draft instanceof ChatConversation && $draft->isSupportDraft()) {
                $this->messages->moveAll($draft, $ticket);
            }
            $ticket->changeSupportStatus(SupportStatus::Open, $owner, $this->clock->now());

            return [$this->bot($ticket, 'support.bot.continued', ['by' => $owner->getChatDisplayName()])];
        });
        $this->announce($ticket, $posted);
        $this->statusEvents($ticket, $previous, $owner);
    }

    /**
     * Changes the ticket status. The requester may only reopen a waiting or resolved ticket that is not read-only;
     * agents may set any status. Posts a line and dispatches SupportTicketStatusChangedEvent (and
     * SupportTicketReopenedEvent when it goes back to open).
     *
     * @param ChatUserInterface $actor The requester or an agent
     * @param ChatConversation $ticket The ticket
     * @param SupportStatus $status The new status
     * @param bool $asAgent Whether the actor acts as a support agent
     *
     * @throws InvalidArgumentException `status` when the requester asks for anything but a reopen, `read_only` when the ticket is read-only for the requester
     *
     * @return bool True when the status changed
     */
    public function changeStatus(ChatUserInterface $actor, ChatConversation $ticket, SupportStatus $status, bool $asAgent): bool
    {
        $previous = $ticket->getSupportStatus() ?? SupportStatus::Open;
        if ($previous === $status) {
            return false;
        }
        if (!$asAgent) {
            if ($status !== SupportStatus::Open) {
                throw new InvalidArgumentException('status');
            }
            if ($this->policy->isReadOnly($ticket)) {
                throw new InvalidArgumentException('read_only');
            }
        }
        [$ticket, $posted] = $this->change($ticket, function (ChatConversation $ticket) use ($actor, $status): array {
            $ticket->changeSupportStatus($status, $actor, $this->clock->now());

            return [$this->bot($ticket, 'support.bot.status_' . $status->value, ['by' => $actor->getChatDisplayName()])];
        });
        $this->announce($ticket, $posted);
        $this->statusEvents($ticket, $previous, $actor);

        return true;
    }

    /**
     * Assigns the ticket to an agent (null: unassigned), posts a line and dispatches SupportTicketAssignedEvent.
     *
     * @param ChatUserInterface $agent The agent who assigns
     * @param ChatConversation $ticket The ticket
     * @param ChatUserInterface|null $assignee The new assignee (a support agent); null to unassign
     *
     * @throws InvalidArgumentException `assignee` when the assignee does not answer support
     *
     * @return bool True when the assignee changed
     */
    public function assign(ChatUserInterface $agent, ChatConversation $ticket, ?ChatUserInterface $assignee): bool
    {
        if ($assignee instanceof ChatUserInterface && !$this->access->isSupportAgent($assignee)) {
            throw new InvalidArgumentException('assignee');
        }
        if ($ticket->getAssignee() === $assignee || ChatUserReferences::same($ticket->getAssignee(), $assignee)) {
            return false;
        }
        [$ticket, $posted] = $this->change($ticket, function (ChatConversation $ticket) use ($agent, $assignee): array {
            $ticket->assign($assignee instanceof ChatUserInterface ? $assignee : null);

            return [$assignee instanceof ChatUserInterface
                ? $this->bot($ticket, 'support.bot.assigned', ['name' => $assignee->getChatDisplayName()])
                : $this->bot($ticket, 'support.bot.unassigned', ['by' => $agent->getChatDisplayName()])];
        });
        $this->announce($ticket, $posted);
        $this->events?->dispatch(new SupportTicketAssignedEvent($ticket, $assignee, $agent));

        return true;
    }

    /**
     * @param array<string, string> $params
     */
    private function bot(ChatConversation $ticket, string $key, array $params, ?string $topic = null): ChatMessage
    {
        $message = ChatMessage::bot($ticket, $key, ChatPresenter::DOMAIN, $params, $topic, $this->clock->now());
        $this->entityManager->persist($message);

        return $message;
    }

    /**
     * 1.30: one unit of work on a ticket (or draft): its row locked first and its activity moved forward (bot lines
     * insert after the lock, so two changes of one ticket queue instead of deadlocking), then the change, flushed with
     * its bot lines before the commit.
     *
     * @param callable(ChatConversation): list<ChatMessage> $change Applies the change, returns the bot lines posted
     *
     * @return array{0: ChatConversation, 1: list<ChatMessage>} The ticket the change was written on and its bot lines
     */
    private function change(ChatConversation $ticket, callable $change): array
    {
        return ChatKitWrites::runRecovering($this->transactions, $this->entityManager, function () use ($ticket, $change): array {
            $ticket = ChatKitWrites::attach($this->entityManager, $ticket);
            $now    = $this->clock->now();
            $stored = $this->conversations->lockActivity($ticket);
            if (!$stored instanceof DateTimeImmutable || $now > $stored) {
                $ticket->touch($now);
            }

            return [$ticket, $change($ticket)];
        });
    }

    /**
     * After the commit: the bot lines and the ticket's new state to the open clients.
     *
     * @param list<ChatMessage> $posted
     *
     * @return list<ChatMessage>
     */
    private function announce(ChatConversation $ticket, array $posted): array
    {
        foreach ($posted as $message) {
            $this->notifier->botMessage($message);
        }
        $this->notifier->supportStatusChanged($ticket);

        return $posted;
    }

    /**
     * The conversation "Support" opens: the latest ticket while it is active and recent, otherwise the intake draft
     * (persisted with the owner's participant row on first use, not flushed).
     *
     * @throws ChatUserLookAlikeException when the owner's participant row would fold onto a look-alike's (nothing stays pending)
     */
    private function target(ChatUserInterface $owner): ChatConversation
    {
        $latest = $this->conversations->findTickets($owner, 1)[0] ?? null;
        if ($latest instanceof ChatConversation && !$this->policy->needsResumeQuestion($latest)) {
            return $latest;
        }
        $conversation = $this->conversations->findSupportDraft($owner);
        if ($conversation instanceof ChatConversation) {
            return $conversation;
        }
        $conversation = ChatConversation::support($owner, $this->clock->now());
        $this->entityManager->persist($conversation);
        try {
            $this->chat->participant($conversation, $owner);
        } catch (ChatUserLookAlikeException $refused) {
            // 1.28 (audit PV-L7): nothing of the refused ticket stays pending for a later flush.
            $this->entityManager->detach($conversation);

            throw $refused;
        }

        return $conversation;
    }

    private function statusEvents(ChatConversation $ticket, SupportStatus $previous, ChatUserInterface $by): void
    {
        $status = $ticket->getSupportStatus();
        if ($status === $previous) {
            return;
        }
        $this->events?->dispatch(new SupportTicketStatusChangedEvent($ticket, $previous, $by));
        if ($status === SupportStatus::Open) {
            $this->events?->dispatch(new SupportTicketReopenedEvent($ticket, $by));
        }
        if ($status === SupportStatus::Resolved || $previous === SupportStatus::Resolved) {
            $this->events?->dispatch(new ChatSupportStatusChangedEvent($ticket, $by));
        }
    }

    private function defaultAssignee(ChatSupportTopic $topic): ?ChatUserInterface
    {
        $assignee = $topic->assignee === null ? null : $this->directory->findByPublicId($topic->assignee);

        return $assignee instanceof ChatUserInterface && $this->access->isSupportAgent($assignee) ? $assignee : null;
    }

    /**
     * @param list<ChatConversation> $tickets
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $tickets): array
    {
        return array_values(array_filter(array_map($this->presenter->ticket(...), $tickets)));
    }
}
