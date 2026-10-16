<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

use function array_slice;

/**
 * Queries for conversations: lookups by uuid / direct key / channel / support owner, the dock list, the support inbox and counters.
 *
 * @extends ServiceEntityRepository<ChatConversation>
 */
class ChatConversationRepository extends ServiceEntityRepository
{
    /** Support threads opened but never written to are not in the inbox (nor counted). */
    private const string HAS_MESSAGES = 'EXISTS (SELECT 1 FROM ' . ChatMessage::class . ' hm WHERE hm.conversation = c)';

    /**
     * Builds the repository for ChatConversation entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
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
     * Finds the conversation of an application channel.
     *
     * @param string $key Channel key
     *
     * @return ChatConversation|null The channel conversation, or null when not created yet
     */
    public function findChannel(string $key): ?ChatConversation
    {
        return $this->findOneBy(['channelKey' => $key]);
    }

    /**
     * Finds the support thread owned by the user.
     *
     * @param ChatUserInterface $owner The user who asks for support
     *
     * @return ChatConversation|null The support thread, or null when none exists yet
     */
    public function findSupportFor(ChatUserInterface $owner): ?ChatConversation
    {
        return $this->findOneBy(['supportOwner' => $owner]);
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
            $qb = $this->createQueryBuilder('c')
                ->leftJoin('c.supportOwner', 'owner')->addSelect('owner');
            $shape($qb);
            if ($before instanceof DateTimeImmutable) {
                $qb->andWhere('c.lastMessageAt < :before')->setParameter('before', $before);
            }

            /** @var list<ChatConversation> $rows */
            $rows = $qb->orderBy('c.lastMessageAt', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult();

            return $rows;
        };

        $rows = $branch(static function ($qb) use ($user): void {
            $qb->innerJoin(ChatParticipant::class, 'p', 'WITH', 'p.conversation = c AND p.user = :user')
                ->andWhere('c.kind IN (:kinds)')
                ->setParameter('user', $user)
                ->setParameter('kinds', [ConversationKind::Direct, ConversationKind::Support]);
        });
        if ($channelKeys !== []) {
            $rows = [...$rows, ...$branch(static function ($qb) use ($channelKeys): void {
                $qb->andWhere('c.channelKey IN (:channels)')->setParameter('channels', $channelKeys);
            })];
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
     * @return list<int> Doctrine ids of the peers
     */
    public function findDirectPeerIds(ChatUserInterface $user, int $limit = 200): array
    {
        $ids = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(other.user)')
            ->from(ChatParticipant::class, 'mine')
            ->innerJoin('mine.conversation', 'c')
            ->innerJoin(ChatParticipant::class, 'other', 'WITH', 'other.conversation = c AND other.user <> mine.user')
            ->where('mine.user = :user AND c.kind = :direct')
            ->setParameter('user', $user)
            ->setParameter('direct', ConversationKind::Direct)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * Support threads for the agents' inbox, newest activity first: `open`, `resolved`, `mine` (threads the agent
     * took part in) or every thread (null).
     *
     * @param string|null $filter `open`, `resolved`, `mine` or null for all
     * @param ChatUserInterface $agent The agent viewing the inbox (used by `mine`)
     * @param int $limit Maximum number of threads
     *
     * @return list<ChatConversation> The support threads
     */
    public function findSupportInbox(?string $filter, ChatUserInterface $agent, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.supportOwner', 'owner')->addSelect('owner')
            ->where('c.kind = :support')
            ->andWhere(self::HAS_MESSAGES)
            ->setParameter('support', ConversationKind::Support)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit);
        if ($filter === 'open' || $filter === 'resolved') {
            $qb->andWhere('c.supportStatus = :status')->setParameter('status', SupportStatus::from($filter));
        } elseif ($filter === 'mine') {
            $qb->innerJoin(ChatParticipant::class, 'p', 'WITH', 'p.conversation = c AND p.user = :agent')->setParameter('agent', $agent);
        }

        /** @var list<ChatConversation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
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
        /** @var list<ChatConversation> $rows */
        $rows = $this->createQueryBuilder('c')
            ->innerJoin(ChatParticipant::class, 'p', 'WITH', 'p.conversation = c AND p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
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
