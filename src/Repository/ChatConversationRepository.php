<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Enum\ConversationKind;
use Nowo\ChatKitBundle\Enum\SupportStatus;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * @extends ServiceEntityRepository<ChatConversation>
 */
class ChatConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatConversation::class);
    }

    public function findOneByUuid(string $uuid): ?ChatConversation
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    public function findDirect(ChatUserInterface $a, ChatUserInterface $b): ?ChatConversation
    {
        return $this->findOneBy(['directKey' => ChatConversation::directKeyFor($a, $b)]);
    }

    public function findChannel(string $key): ?ChatConversation
    {
        return $this->findOneBy(['channelKey' => $key]);
    }

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
     * @param list<string> $channelKeys
     *
     * @return list<ChatConversation>
     */
    public function findListForUser(ChatUserInterface $user, array $channelKeys, bool $isSupportAgent, int $limit, ?\DateTimeImmutable $before = null): array
    {
        $branch = function (callable $shape) use ($limit, $before): array {
            $qb = $this->createQueryBuilder('c')
                ->leftJoin('c.supportOwner', 'owner')->addSelect('owner');
            $shape($qb);
            if ($before instanceof \DateTimeImmutable) {
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
        if ([] !== $channelKeys) {
            $rows = [...$rows, ...$branch(static function ($qb) use ($channelKeys): void {
                $qb->andWhere('c.channelKey IN (:channels)')->setParameter('channels', $channelKeys);
            })];
        }
        if ($isSupportAgent) {
            $rows = [...$rows, ...$branch(static function ($qb): void {
                $qb->andWhere('c.kind = :support')->setParameter('support', ConversationKind::Support);
            })];
        }

        $unique = [];
        foreach ($rows as $conversation) {
            $unique[(int) $conversation->getId()] = $conversation;
        }
        $merged = array_values($unique);
        usort($merged, static fn (ChatConversation $a, ChatConversation $b): int => [$b->getLastMessageAt(), $b->getId()] <=> [$a->getLastMessageAt(), $a->getId()]);

        return \array_slice($merged, 0, $limit);
    }

    /**
     * Peers of the user's direct conversations (presence answers only about them).
     *
     * @return list<int>
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
     * @return list<ChatConversation>
     */
    public function findSupportInbox(?string $filter, ChatUserInterface $agent, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.supportOwner', 'owner')->addSelect('owner')
            ->where('c.kind = :support')
            ->setParameter('support', ConversationKind::Support)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit);
        if ('open' === $filter || 'resolved' === $filter) {
            $qb->andWhere('c.supportStatus = :status')->setParameter('status', SupportStatus::from($filter));
        } elseif ('mine' === $filter) {
            $qb->innerJoin(ChatParticipant::class, 'p', 'WITH', 'p.conversation = c AND p.user = :agent')->setParameter('agent', $agent);
        }

        /** @var list<ChatConversation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Conversations the user has a participant row in (wrote, opened or was added), newest activity first.
     *
     * @return list<ChatConversation>
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

    /** Open support threads (agents' menu counter). */
    public function countOpenSupport(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.kind = :support AND c.supportStatus = :open')
            ->setParameter('support', ConversationKind::Support)
            ->setParameter('open', SupportStatus::Open)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
