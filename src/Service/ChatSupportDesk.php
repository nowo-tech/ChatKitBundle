<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatSupportInboxInterface;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatConversationView;
use Nowo\ChatKitBundle\Model\ChatMessageView;
use Nowo\ChatKitBundle\Model\ChatSupportTicketItem;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Security\ChatAccess;
use Nowo\ChatKitBundle\Security\ChatActorResolver;
use Nowo\ChatKitBundle\Security\ChatVoter;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function max;
use function min;

/**
 * 2.2: default ChatSupportInboxInterface (aliased to it): the agent is resolved (ChatActorResolver) and must be a reachable
 * support agent; the threads come from ChatSupportInbox, the writes go through ChatSupportTickets (its unit of work,
 * events and bot lines), the answers are views. A ticket uuid is looked up in SQL and re-checked byte for byte in PHP
 * (house rule LQ). Stateless (FrankenPHP worker mode, kernel reset false).
 */
final readonly class ChatSupportDesk implements ChatSupportInboxInterface
{
    /** Most rows one page holds. */
    private const int MAX_LIMIT = 100;

    /**
     * @param ChatSupportInbox $inbox The inbox threads and counts
     * @param ChatSupportTickets $tickets Assignment and status changes
     * @param ChatConversationRepository $conversations Tickets by uuid, ticket counts per requester
     * @param ChatMessageRepository $messages Unread counts and last messages
     * @param ChatAccess $access Reachability and the support-agent role
     * @param ChatVoter $voter The API's rules
     * @param ChatActorResolver $actors Users by public id
     * @param ChatMarkdownRenderer|null $markdown Renders the HTML of the message views; null keeps the stored render
     */
    public function __construct(
        private ChatSupportInbox $inbox,
        private ChatSupportTickets $tickets,
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatAccess $access,
        private ChatVoter $voter,
        private ChatActorResolver $actors,
        private ?ChatMarkdownRenderer $markdown = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function tickets(ChatUserInterface|string $agent, string $filter = 'open', ?string $topic = null, ?string $search = null, int $limit = 50): array
    {
        $actor = $this->agent($agent);
        if (!$actor instanceof ChatUserInterface) {
            return [];
        }
        $threads = $this->inbox->threads($actor, $filter, $search, max(1, min(self::MAX_LIMIT, $limit)), $topic);
        $ids     = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $threads);
        $unread  = $this->messages->countUnreadByConversation((string) ChatUserReferences::key($actor), $ids);
        $latest  = $this->messages->findLatestByConversationIds($ids);
        $owners  = array_values(array_unique(array_filter(array_map($this->ownerKey(...), $threads), static fn (string $key): bool => $key !== '')));
        $counts  = $this->conversations->countTicketsByOwner($owners);

        return array_map(function (ChatConversation $conversation) use ($unread, $latest, $counts): ChatSupportTicketItem {
            $id    = (int) $conversation->getId();
            $last  = $latest[$id] ?? null;
            $owner = $this->ownerKey($conversation);

            return new ChatSupportTicketItem(
                ChatConversationView::of($conversation),
                $unread[$id] ?? 0,
                $owner === '' ? 0 : $counts[$owner] ?? 0,
                $last instanceof ChatMessage ? ChatMessageView::of($last, $this->markdown) : null,
            );
        }, $threads);
    }

    /**
     * {@inheritDoc}
     */
    public function ticket(ChatUserInterface|string $agent, string $ticketUuid): ?ChatConversationView
    {
        $actor  = $this->agent($agent);
        $thread = $actor instanceof ChatUserInterface ? $this->find($ticketUuid) : null;

        return $thread instanceof ChatConversation && $actor instanceof ChatUserInterface && $this->voter->decide(ChatVoter::RESOLVE, $thread, $actor)
            ? ChatConversationView::of($thread) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function countOpen(ChatUserInterface|string $agent): int
    {
        return $this->agent($agent) instanceof ChatUserInterface ? $this->inbox->countOpen() : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function countByTopic(ChatUserInterface|string $agent): array
    {
        return $this->agent($agent) instanceof ChatUserInterface ? $this->conversations->countActiveByTopic() : [];
    }

    /**
     * {@inheritDoc}
     */
    public function assign(ChatUserInterface|string $agent, string $ticketUuid, ?string $assigneePublicId): ChatConversationView
    {
        [$actor, $ticket] = $this->handled($agent, $ticketUuid);
        $assignee         = null;
        if ($assigneePublicId !== null) {
            // Unknown, look-alike and "not an agent" are one answer (ChatSupportTickets::assign() checks the role).
            $assignee = $this->actors->resolve($assigneePublicId) ?? throw new InvalidArgumentException('assignee');
        }
        $this->tickets->assign($actor, $ticket, $assignee);

        return $this->after($ticketUuid, $ticket);
    }

    /**
     * {@inheritDoc}
     */
    public function changeStatus(ChatUserInterface|string $agent, string $ticketUuid, string $status): ChatConversationView
    {
        [$actor, $ticket] = $this->handled($agent, $ticketUuid);
        $this->tickets->changeStatus($actor, $ticket, SupportStatus::tryFrom($status) ?? throw new InvalidArgumentException('status'), true);

        return $this->after($ticketUuid, $ticket);
    }

    /**
     * The resolved user when a reachable support agent; null otherwise.
     */
    private function agent(ChatUserInterface|string $agent): ?ChatUserInterface
    {
        $actor = $this->actors->resolve($agent);

        return $actor instanceof ChatUserInterface && $this->access->isReachable($actor) && $this->access->isSupportAgent($actor) ? $actor : null;
    }

    private function find(string $uuid): ?ChatConversation
    {
        $conversation = $uuid === '' ? null : $this->conversations->findOneByUuid($uuid);

        return $conversation instanceof ChatConversation && $conversation->getUuid() === $uuid ? $conversation : null;
    }

    /**
     * The agent and the ticket the agent may handle; anything else is "not found".
     *
     * @return array{0: ChatUserInterface, 1: ChatConversation}
     */
    private function handled(ChatUserInterface|string $agent, string $ticketUuid): array
    {
        $actor  = $this->agent($agent);
        $ticket = $actor instanceof ChatUserInterface ? $this->find($ticketUuid) : null;
        if (!$actor instanceof ChatUserInterface || !$ticket instanceof ChatConversation || !$ticket->isTicket() || !$this->voter->decide(ChatVoter::RESOLVE, $ticket, $actor)) {
            throw new ChatNotFoundException('conversation', $ticketUuid);
        }

        return [$actor, $ticket];
    }

    /**
     * The ticket as stored after a write (a write retried after a lost lock may have run on a re-loaded row).
     */
    private function after(string $uuid, ChatConversation $ticket): ChatConversationView
    {
        return ChatConversationView::of($this->find($uuid) ?? $ticket);
    }

    private function ownerKey(ChatConversation $conversation): string
    {
        $owner = $conversation->getSupportOwner();

        return $owner instanceof ChatUserInterface ? (string) ChatUserReferences::key($owner) : '';
    }
}
