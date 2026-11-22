<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Contract;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatSupportTicketItem;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Service\ChatNotFoundException;

/**
 * 2.2 (DDD-host checklist B1/B7): the support agents' inbox by ids — list, count, assign and change the status of support
 * tickets without injecting the concrete Service\ChatSupportInbox / Support\ChatSupportTickets or loading a
 * ChatConversation (aliased to Service\ChatSupportDesk). Answers are views and numbers.
 *
 * The agent is a ChatUserInterface or its public id (see ChatAccessInterface). Every method answers only for a reachable
 * support agent (ChatAccessPolicyInterface::isSupportAgent()): anybody else gets an empty list, a zero, or
 * ChatNotFoundException, never a hint about the tickets. A ticket uuid compares exactly (SQL, then PHP); an unknown uuid,
 * a look-alike and a conversation that is not a ticket give the same ChatNotFoundException.
 *
 * The writes are Support\ChatSupportTickets' own: one unit of work on ChatKit's entity manager with the ticket row locked
 * first (REQ-RUNTIME-003: two agents changing one ticket queue, the second sees the first's result), a bot line, the
 * realtime update and the ticket events after the commit. Run them outside your own transaction runner. Stateless
 * (FrankenPHP worker mode, kernel reset false).
 */
interface ChatSupportInboxInterface
{
    /** Inbox filters (anything else: `open`). `mine`: assigned to the agent; `unassigned`: open or waiting without one. */
    public const array FILTERS = ['open', 'waiting', 'resolved', 'closed', 'mine', 'unassigned', 'all'];

    /**
     * The inbox: support threads holding a message, newest activity first, filtered by status, topic and the requester's
     * name or email (case-insensitive, among the 200 newest threads of the filter).
     *
     * @param ChatUserInterface|string $agent The support agent, or its public id
     * @param string $filter One of FILTERS
     * @param string|null $topic Topic key (`none`: without a topic); null for every topic
     * @param string|null $search Text matched against the requester's name and email; null for none
     * @param int $limit Page size (clamped to 1–100)
     *
     * @return list<ChatSupportTicketItem> The rows; empty for anybody but a support agent
     */
    public function tickets(ChatUserInterface|string $agent, string $filter = 'open', ?string $topic = null, ?string $search = null, int $limit = 50): array;

    /**
     * One support thread (a ticket, or an intake draft) the agent may handle; null otherwise (unknown, look-alike, not a
     * support thread, not an agent alike).
     *
     * @param ChatUserInterface|string $agent The support agent, or its public id
     * @param string $ticketUuid Public uuid of the support thread
     *
     * @return ChatConversationView|null The view
     */
    public function ticket(ChatUserInterface|string $agent, string $ticketUuid): ?ChatConversationView;

    /**
     * Open support threads that hold a message (the agents' menu counter).
     *
     * @param ChatUserInterface|string $agent The support agent, or its public id
     *
     * @return int The count; 0 for anybody but a support agent
     */
    public function countOpen(ChatUserInterface|string $agent): int;

    /**
     * Open and waiting support threads that hold a message, per topic key (`none` for threads without a topic).
     *
     * @param ChatUserInterface|string $agent The support agent, or its public id
     *
     * @return array<string, int> topic key => count; empty for anybody but a support agent
     */
    public function countByTopic(ChatUserInterface|string $agent): array;

    /**
     * Assigns the ticket to a support agent, or unassigns it (`$assigneePublicId` null). A bot line and
     * SupportTicketAssignedEvent follow when the assignee changed.
     *
     * @param ChatUserInterface|string $agent The support agent who assigns, or its public id
     * @param string $ticketUuid Public uuid of the ticket
     * @param string|null $assigneePublicId Public id of the new assignee; null to unassign
     *
     * @throws ChatNotFoundException When the actor is not a support agent, or no ticket has exactly this uuid (`conversation`)
     * @throws InvalidArgumentException `assignee` when the assignee is unknown, a look-alike or does not answer support
     *
     * @return ChatConversationView The ticket after the change
     */
    public function assign(ChatUserInterface|string $agent, string $ticketUuid, ?string $assigneePublicId): ChatConversationView;

    /**
     * Sets the ticket's status (`open`, `waiting`, `resolved` or `closed`) as an agent. A bot line and the status events
     * follow when it changed.
     *
     * @param ChatUserInterface|string $agent The support agent, or its public id
     * @param string $ticketUuid Public uuid of the ticket
     * @param string $status The new status
     *
     * @throws ChatNotFoundException When the actor is not a support agent, or no ticket has exactly this uuid (`conversation`)
     * @throws InvalidArgumentException `status` for an unknown status
     *
     * @return ChatConversationView The ticket after the change
     */
    public function changeStatus(ChatUserInterface|string $agent, string $ticketUuid, string $status): ChatConversationView;
}
