<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Service;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Repository\ChatConversationRepository;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Support\ChatSupportPolicy;
use Nowo\ChatKitBundle\Support\ChatSupportTopics;

use function array_slice;
use function in_array;

/**
 * The support agents' inbox: support tickets by status (`open`, `waiting`, `resolved`, `closed`, `mine` = assigned
 * to the agent, `unassigned`, all) and topic, searchable by the requester's name or email, with unread counts, the
 * last message and the ticket block — plus counts per topic and a requester's other tickets — for the API and the
 * application's admin page.
 */
final readonly class ChatSupportInbox
{
    public const array FILTERS = ['open', 'waiting', 'resolved', 'closed', 'mine', 'unassigned', 'all'];
    private const int SCAN     = 200;

    /**
     * Builds the inbox from the conversation and message repositories, the presenter and the ticket rules.
     *
     * @param ChatConversationRepository $conversations Loads support threads and counts open ones
     * @param ChatMessageRepository $messages Unread counts and last messages
     * @param ChatPresenter $presenter Builds the conversation summaries and ticket blocks
     * @param ChatSupportPolicy $policy When a resolved ticket counts as closed
     * @param ChatSupportTopics $topics Topic labels of the per-topic counts
     */
    public function __construct(
        private ChatConversationRepository $conversations,
        private ChatMessageRepository $messages,
        private ChatPresenter $presenter,
        private ChatSupportPolicy $policy,
        private ChatSupportTopics $topics,
    ) {
    }

    /**
     * Lists support tickets for the agent, filtered by status, topic and the requester's name or email.
     *
     * @param ChatUserInterface $agent The support agent
     * @param string $filter One of FILTERS (anything else: `open`)
     * @param string|null $query Search text matched against the requester's name and email; null for none
     * @param int $limit Maximum number of threads
     * @param string|null $topic Topic key (`none`: without a topic); null for every topic
     *
     * @return list<array<string, mixed>> conversation summaries (as the agent sees them, with `ticket`) plus `owner` and `ownerTickets` (the requester's ticket count)
     */
    public function list(ChatUserInterface $agent, string $filter, ?string $query, int $limit = 50, ?string $topic = null): array
    {
        $filter  = in_array($filter, self::FILTERS, true) ? $filter : 'open';
        $query   = $query === null ? '' : mb_strtolower(trim($query));
        $threads = array_values(array_filter(
            $this->conversations->findSupportInbox($filter === 'all' ? null : $filter, $agent, self::SCAN, $topic === '' ? null : $topic, $this->policy->closeCutoff()),
            static function (ChatConversation $conversation) use ($query): bool {
                $owner = $conversation->getSupportOwner();

                return $query === '' || ($owner instanceof ChatUserInterface && (str_contains(mb_strtolower($owner->getChatDisplayName()), $query) || str_contains(mb_strtolower((string) $owner->getChatEmail()), $query)));
            },
        ));
        $threads = array_slice($threads, 0, $limit);
        $ids     = array_map(static fn (ChatConversation $conversation): int => (int) $conversation->getId(), $threads);
        $unread  = $this->messages->countUnreadByConversation((int) $agent->getId(), $ids);
        $latest  = $this->messages->findLatestByConversationIds($ids);
        $counts  = $this->conversations->countTicketsByOwner(array_values(array_unique(array_map(
            static fn (ChatConversation $conversation): int => (int) $conversation->getSupportOwner()?->getId(),
            $threads,
        ))));

        return array_map(function (ChatConversation $conversation) use ($agent, $unread, $latest, $counts): array {
            $id    = (int) $conversation->getId();
            $owner = $conversation->getSupportOwner();

            return $this->presenter->conversation($conversation, $agent, null, null, true, $unread[$id] ?? 0, $latest[$id] ?? null, true) + [
                'owner'        => $owner instanceof ChatUserInterface ? ['uuid' => $owner->getChatPublicId(), 'name' => $owner->getChatDisplayName(), 'email' => $owner->getChatEmail()] : null,
                'ownerTickets' => $counts[(int) $owner?->getId()] ?? 0,
            ];
        }, $threads);
    }

    /**
     * Counts open support threads that hold at least one message.
     *
     * @return int The number of open threads
     */
    public function countOpen(): int
    {
        return $this->conversations->countOpenSupport();
    }

    /**
     * Every topic with its open + waiting ticket count (topics without tickets included, `none` last when used).
     *
     * @return list<array{key: string, label: string, icon: string|null, emoji: string, count: int}> The topics in display order
     */
    public function countByTopic(): array
    {
        $counts = $this->conversations->countActiveByTopic();
        $rows   = [];
        foreach ($this->topics->options() as $option) {
            $rows[] = $option + ['count' => $counts[$option['key']] ?? 0];
            unset($counts[$option['key']]);
        }
        foreach ($counts as $key => $count) {
            $rows[] = ['key' => $key, 'label' => $this->topics->label($key === 'none' ? null : $key), 'icon' => null, 'emoji' => '', 'count' => $count];
        }

        return $rows;
    }

    /**
     * The requester's other tickets (ticket blocks), newest activity first.
     *
     * @param ChatConversation $ticket A ticket
     * @param int $limit Maximum number of tickets
     *
     * @return list<array<string, mixed>> The ticket blocks, without `$ticket`
     */
    public function otherTickets(ChatConversation $ticket, int $limit = 10): array
    {
        $owner = $ticket->getSupportOwner();
        if (!$owner instanceof ChatUserInterface) {
            return [];
        }
        $rows = [];
        foreach ($this->conversations->findTickets($owner, $limit + 1) as $other) {
            if ($other->getId() !== $ticket->getId()) {
                $rows[] = (array) $this->presenter->ticket($other);
            }
        }

        return array_slice($rows, 0, $limit);
    }
}
