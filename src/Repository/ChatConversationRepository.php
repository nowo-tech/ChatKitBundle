<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatChannelKey;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_filter;
use function array_slice;
use function in_array;

/**
 * Queries for conversations: lookups by uuid / direct key / channel / support owner, the dock list, the support inbox and counters.
 *
 * @extends ServiceEntityRepository<ChatConversation>
 */
class ChatConversationRepository extends ServiceEntityRepository
{
    /** 1.27: rows read to find the newest exact owner match (REQ-SEC-006 item 6) before giving up. */
    private const int EXACT_SCAN = 50;

    /** Support threads opened but never written to are not in the inbox (nor counted). */
    private const string HAS_MESSAGES = 'EXISTS (SELECT 1 FROM ' . ChatMessage::class . ' hm WHERE hm.conversation = c)';

    /**
     * Builds the repository for ChatConversation entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatConversation::class);
    }

    /**
     * Finds a conversation by its public uuid.
     *
     * @param string $uuid Public conversation id
     *
     * @return ChatConversation|null The conversation, or null when unknown
     */
    public function findOneByUuid(string $uuid): ?ChatConversation
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * 1.25: the conversations of these public uuids in one query (the sidebar's host items); unknown uuids are left out.
     *
     * @param list<string> $uuids Public conversation ids
     *
     * @return array<string, ChatConversation> uuid => conversation
     */
    public function findByUuids(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $byUuid = [];
        foreach ($this->findBy(['uuid' => $uuids]) as $conversation) {
            $byUuid[$conversation->getUuid()] = $conversation;
        }

        return $byUuid;
    }

    /**
     * Finds the direct conversation between two users (order does not matter).
     *
     * @param ChatUserInterface $a One person
     * @param ChatUserInterface $b The other person
     *
     * @return ChatConversation|null The direct thread, or null when none exists yet
     */
    public function findDirect(ChatUserInterface $a, ChatUserInterface $b): ?ChatConversation
    {
        return $this->findOneBy(['directKey' => ChatConversation::directKeyFor($a, $b)]);
    }

    /**
     * Finds the conversation of an application channel. The key is compared byte for byte: the SQL lookup runs on the
     * column collation (case-, accent- and trailing-space-insensitive on a MySQL / MariaDB table created before
     * the binary collation of ChannelKeyCollationListener), so the row is matched again in PHP and a look-alike key (`project:Acme` for `project:acme`) never opens
     * another channel's conversation (house rule LQ). A key that breaks the ChatChannelKey rule finds nothing.
     *
     * @param string $key Channel key
     *
     * @return ChatConversation|null The channel conversation, or null when not created yet
     */
    public function findChannel(string $key): ?ChatConversation
    {
        if (!ChatChannelKey::isValid($key)) {
            return null;
        }
        $conversation = $this->findOneBy(['channelKey' => $key]);

        return $conversation?->getChannelKey() === $key ? $conversation : null;
    }

    /**
     * The user's most recent support conversation (ticket or intake draft).
     *
     * @param ChatUserInterface $owner The user who asks for support
     *
     * @return ChatConversation|null The latest support conversation, or null when none exists yet
     */
    public function findSupportFor(ChatUserInterface $owner): ?ChatConversation
    {
        // REQ-SEC-006 item 2: exactly this owner's (the newest exact one when a folding collation returns look-alikes).
        foreach ($this->findBy($this->refs->criteria('supportOwner', $owner), ['lastMessageAt' => 'DESC', 'id' => 'DESC'], self::EXACT_SCAN) as $row) {
            if (ChatUserReferences::same($row->getSupportOwner(), $owner)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The user's intake draft (a support conversation whose topic is not chosen yet).
     *
     * @param ChatUserInterface $owner The user
     *
     * @return ChatConversation|null The draft, or null when none exists
     */
    public function findSupportDraft(ChatUserInterface $owner): ?ChatConversation
    {
        foreach ($this->findBy($this->refs->criteria('supportOwner', $owner) + ['ticketNumber' => null], ['id' => 'DESC'], self::EXACT_SCAN) as $row) {
            if (ChatUserReferences::same($row->getSupportOwner(), $owner)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The user's tickets, newest activity first.
     *
     * @param ChatUserInterface $owner The requester
     * @param int $limit Maximum number of tickets
     *
     * @return list<ChatConversation> The tickets
     */
    public function findTickets(ChatUserInterface $owner, int $limit): array
    {
        /** @var list<ChatConversation> $rows */
        $rows = $this->createQueryBuilder('c')
            ->where($this->refs->field('c', 'supportOwner') . ' = :owner AND c.ticketNumber IS NOT NULL')
            ->setParameter('owner', $this->refs->value($owner))
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($rows, static fn (ChatConversation $row): bool => ChatUserReferences::same($row->getSupportOwner(), $owner)));
    }

    /**
     * Conversations of the user, newest activity first: direct and own support threads through the user's
     * participant rows, the given channels, and (support agents) every support thread. Each branch uses an
     * index (participant user, unique channel key, kind + activity); they are merged here instead of one OR
     * query that scans the table. Channel keys come from the channel provider (the caller's access check).
     *
     * @param ChatUserInterface $user The viewer
     * @param list<string> $channelKeys Keys of the channels the viewer may use
     * @param bool $isSupportAgent Whether every support thread is included
     * @param int $limit Maximum number of conversations
     * @param DateTimeImmutable|null $before Only conversations with older activity (pagination); null for the first page
     *
     * @return list<ChatConversation> The conversations, newest activity first
     */
    public function findListForUser(ChatUserInterface $user, array $channelKeys, bool $isSupportAgent, int $limit, ?DateTimeImmutable $before = null): array
    {
        $branch = function (callable $shape) use ($limit, $before): array {
            $qb = $this->createQueryBuilder('c');
            $this->refs->join($qb, 'c.supportOwner', 'owner');
            $shape($qb);
            if ($before instanceof DateTimeImmutable) {
                $qb->andWhere('c.lastMessageAt < :before')->setParameter('before', $before);
            }

            /** @var list<ChatConversation> $rows */
            $rows = $qb->orderBy('c.lastMessageAt', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

            return $rows;
        };

        $refs = $this->refs;
        $rows = $branch(static function ($qb) use ($user, $refs): void {
            $qb->innerJoin(ChatParticipant::class, 'p', 'WITH', 'p.conversation = c AND ' . $refs->field('p', 'user') . ' = :user')
                ->andWhere('c.kind IN (:kinds)')
                ->setParameter('user', $refs->value($user))
                ->setParameter('kinds', [ConversationKind::Direct, ConversationKind::Support]);
        });
        if ($channelKeys !== []) {
            // The SQL IN runs on the column collation: keep only the exact keys (look-alikes, house rule LQ).
            $rows = [...$rows, ...array_filter($branch(static function ($qb) use ($channelKeys): void {
                $qb->andWhere('c.channelKey IN (:channels)')->setParameter('channels', $channelKeys);
            }), static fn (ChatConversation $conversation): bool => in_array($conversation->getChannelKey(), $channelKeys, true))];
        }
        if ($isSupportAgent) {
            $rows = [...$rows, ...$branch(static function ($qb): void {
                // Other people's support threads only once they hold a message.
                $qb->andWhere('c.kind = :support')->andWhere(self::HAS_MESSAGES)->setParameter('support', ConversationKind::Support);
            })];
        }

        $unique = [];
        foreach ($rows as $conversation) {
            $unique[(int) $conversation->getId()] = $conversation;
        }
        $merged = array_values($unique);
        usort($merged, static fn (ChatConversation $a, ChatConversation $b): int => [$b->getLastMessageAt(), $b->getId()] <=> [$a->getLastMessageAt(), $a->getId()]);

        return array_slice($merged, 0, $limit);
    }

    /**
     * Peers of the user's direct conversations (presence answers only about them).
     *
     * @param ChatUserInterface $user The user
     * @param int $limit Maximum number of peers
     *
     * @return list<int|string> Ids of the peers (1.27: or string references, ChatUserReferences::idOrKey())
     */
    public function findDirectPeerIds(ChatUserInterface $user, int $limit = 200): array
    {
        // 1.27 (audit OJ-M1): both references are read back and compared in PHP, byte for byte (a folding legacy column
        // would join a look-alike's rows); pages are read until `$limit` exact peers are found (REQ-SEC-006 item 6).
        $key    = (string) ChatUserReferences::key($user);
        $peers  = [];
        $offset = 0;
        do {
            /** @var list<array{mine: int|string, other: int|string}> $rows */
            $rows = $this->getEntityManager()->createQueryBuilder()
                ->select($this->refs->identity('mine', 'user') . ' AS mine', $this->refs->identity('other', 'user') . ' AS other')
                ->from(ChatParticipant::class, 'mine')
                ->innerJoin('mine.conversation', 'c')
                ->innerJoin(ChatParticipant::class, 'other', 'WITH', 'other.conversation = c AND ' . $this->refs->field('other', 'user') . ' <> ' . $this->refs->field('mine', 'user'))
                ->where($this->refs->field('mine', 'user') . ' = :user AND c.kind = :direct')
                ->setParameter('user', $this->refs->value($user))
                ->setParameter('direct', ConversationKind::Direct)
                ->orderBy('mine.id', 'ASC')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                if (count($peers) < $limit && (string) $row['mine'] === $key && (string) $row['other'] !== $key) {
                    $peers[] = ChatUserReferences::idOrKey((string) $row['other']);
                }
            }
            $offset += $limit;
        } while (count($rows) === $limit && count($peers) < $limit && $offset < self::EXACT_SCAN * $limit);

        return $peers;
    }

    /**
     * Support threads for the agents' inbox, newest activity first. Status filters: `open`, `waiting`, `resolved`
     * (resolved after `$closeCutoff`), `closed` (closed, or resolved before `$closeCutoff`), `mine` (assigned to the
     * agent), `unassigned` (open or waiting without an assignee); null for every thread. `$topic` narrows to one topic
     * key (`none`: no topic).
     *
     * @param string|null $filter Status filter, or null for all
     * @param ChatUserInterface $agent The agent viewing the inbox (used by `mine`)
     * @param int $limit Maximum number of threads
     * @param string|null $topic Topic key, `none`, or null for every topic
     * @param DateTimeImmutable|null $closeCutoff Resolved before this counts as closed; null: never
     *
     * @return list<ChatConversation> The support threads
     */
    public function findSupportInbox(?string $filter, ChatUserInterface $agent, int $limit, ?string $topic = null, ?DateTimeImmutable $closeCutoff = null): array
    {
        $qb = $this->createQueryBuilder('c');
        $this->refs->join($qb, 'c.supportOwner', 'owner');
        $this->refs->join($qb, 'c.assignee', 'assignee');
        $qb->where('c.kind = :support')
            ->andWhere(self::HAS_MESSAGES)
            ->setParameter('support', ConversationKind::Support)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit);
        $resolved = static fn (string $operator): string => $closeCutoff instanceof DateTimeImmutable ? ' AND c.resolvedAt ' . $operator . ' :cutoff' : ($operator === '<' ? ' AND 1 = 0' : '');
        match ($filter) {
            'open', 'waiting' => $qb->andWhere('c.supportStatus = :status')->setParameter('status', SupportStatus::from($filter)),
            'resolved'        => $qb->andWhere('c.supportStatus = :resolved' . $resolved('>='))->setParameter('resolved', SupportStatus::Resolved),
            'closed'          => $qb->andWhere('c.supportStatus = :closed OR (c.supportStatus = :resolved' . $resolved('<') . ')')->setParameter('closed', SupportStatus::Closed)->setParameter('resolved', SupportStatus::Resolved),
            'mine'            => $qb->andWhere($this->refs->field('c', 'assignee') . ' = :agent')->setParameter('agent', $this->refs->value($agent)),
            'unassigned'      => $qb->andWhere($this->refs->field('c', 'assignee') . ' IS NULL AND c.supportStatus IN (:active)')->setParameter('active', [SupportStatus::Open, SupportStatus::Waiting]),
            default           => null,
        };
        if (in_array($filter, ['resolved', 'closed'], true) && $closeCutoff instanceof DateTimeImmutable) {
            $qb->setParameter('cutoff', $closeCutoff);
        }
        if ($topic === 'none') {
            $qb->andWhere('c.ticketTopic IS NULL');
        } elseif ($topic !== null) {
            $qb->andWhere('c.ticketTopic = :topic')->setParameter('topic', $topic);
        }

        /** @var list<ChatConversation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Number of tickets of each requester.
     *
     * @param list<int|string> $ownerIds Keys of the requesters
     *
     * @return array<int|string, int> owner key => ticket count (owners without tickets are left out)
     */
    public function countTicketsByOwner(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }
        /** @var list<array{owner: int|string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select($this->refs->identity('c', 'supportOwner') . ' AS owner, COUNT(c.id) AS total')
            ->where($this->refs->field('c', 'supportOwner') . ' IN (:owners) AND c.ticketNumber IS NOT NULL')
            ->setParameter('owners', $this->refs->ids($ownerIds))
            ->groupBy($this->refs->field('c', 'supportOwner'))
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['owner']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Open or waiting support threads that hold a message, per topic key (`none` for threads without a topic).
     *
     * @return array<string, int> topic key => count
     */
    public function countActiveByTopic(): array
    {
        /** @var list<array{topic: string|null, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.ticketTopic AS topic, COUNT(c.id) AS total')
            ->where('c.kind = :support AND c.supportStatus IN (:active)')
            ->andWhere(self::HAS_MESSAGES)
            ->setParameter('support', ConversationKind::Support)
            ->setParameter('active', [SupportStatus::Open, SupportStatus::Waiting])
            ->groupBy('c.ticketTopic')
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['topic'] ?? 'none'] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Conversations the user has a participant row in (wrote, opened or was added), newest activity first.
     *
     * @param ChatUserInterface $user The user
     * @param int $limit Maximum number of conversations
     *
     * @return list<ChatConversation> The conversations
     */
    public function findParticipating(ChatUserInterface $user, int $limit): array
    {
        // 1.27 (audit OJ-M1): the participant rows come along and only exactly this user's count (REQ-SEC-006 items 2, 6).
        $kept   = [];
        $offset = 0;
        do {
            /** @var list<ChatParticipant> $rows */
            $rows = $this->getEntityManager()->createQueryBuilder()
                ->select('p', 'c')
                ->from(ChatParticipant::class, 'p')
                ->innerJoin('p.conversation', 'c')
                ->where($this->refs->field('p', 'user') . ' = :user')
                ->setParameter('user', $this->refs->value($user))
                ->orderBy('c.lastMessageAt', 'DESC')
                ->addOrderBy('c.id', 'DESC')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();
            foreach ($rows as $row) {
                if (count($kept) < $limit && ChatUserReferences::same($row->getUser(), $user)) {
                    $kept[] = $row->getConversation();
                }
            }
            $offset += $limit;
        } while (count($rows) === $limit && count($kept) < $limit && $offset < self::EXACT_SCAN * $limit);

        return $kept;
    }

    /**
     * Open support threads that hold at least one message (agents' menu counter).
     *
     * @return int The number of open threads
     */
    public function countOpenSupport(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.kind = :support AND c.supportStatus = :open')
            ->andWhere(self::HAS_MESSAGES)
            ->setParameter('support', ConversationKind::Support)
            ->setParameter('open', SupportStatus::Open)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
