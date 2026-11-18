<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Entity\ChatReadMark;

use function array_map;
use function is_string;

/**
 * 1.35: read times of participants (message info, `message_info.read_times`).
 *
 * @extends ServiceEntityRepository<ChatReadMark>
 */
class ChatReadMarkRepository extends ServiceEntityRepository
{
    /** Advances of the receipt within this many seconds update the newest row instead of adding one. */
    public const int COMPACT_SECONDS = 60;

    /** Rows a purge deletes per statement. */
    public const int PURGE_BATCH = 500;

    /**
     * Builds the repository for ChatReadMark entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatReadMark::class);
    }

    /**
     * Records that the participant's receipt reached a message, inside the caller's unit of work (the participant's
     * conversation row is locked by it): an advance within COMPACT_SECONDS of the newest row extends that row.
     *
     * @param ChatParticipant $participant Whose receipt moved (persisted)
     * @param int $upTo The receipt cursor after the move
     * @param DateTimeImmutable $now When
     */
    public function record(ChatParticipant $participant, int $upTo, DateTimeImmutable $now): void
    {
        /** @var ChatReadMark|null $newest */
        $newest = $this->createQueryBuilder('r')
            ->where('r.participant = :p')
            ->setParameter('p', $participant)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($newest instanceof ChatReadMark && $now->getTimestamp() - $newest->getReadAt()->getTimestamp() < self::COMPACT_SECONDS) {
            $newest->extend($upTo, $now);

            return;
        }
        $this->getEntityManager()->persist(new ChatReadMark($participant, $upTo, $now));
    }

    /**
     * When each participant's receipt first reached a message.
     *
     * @param list<int> $participantIds Participant rows
     * @param int $messageId The message
     *
     * @return array<int, DateTimeImmutable> participant id => read time (participants without a row are left out)
     */
    public function readTimes(array $participantIds, int $messageId): array
    {
        if ($participantIds === []) {
            return [];
        }
        /** @var list<array{p: int|string, at: DateTimeImmutable|string}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.participant) AS p', 'MIN(r.readAt) AS at')
            ->where('IDENTITY(r.participant) IN (:ids)')
            ->andWhere('r.upToMessageId >= :m')
            ->setParameter('ids', $participantIds)
            ->setParameter('m', $messageId)
            ->groupBy('r.participant')
            ->getQuery()
            ->getArrayResult();
        $times = [];
        foreach ($rows as $row) {
            $times[(int) $row['p']] = is_string($row['at']) ? new DateTimeImmutable($row['at']) : $row['at'];
        }

        return $times;
    }

    /**
     * Deletes the read times of participant rows (a user who stopped sharing them, an erasure), inside the caller's
     * unit of work.
     *
     * @param list<int> $participantIds Participant rows
     *
     * @return int Rows deleted
     */
    public function deleteFor(array $participantIds): int
    {
        if ($participantIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')->delete()
            ->where('IDENTITY(r.participant) IN (:ids)')
            ->setParameter('ids', array_map('intval', $participantIds))
            ->getQuery()
            ->execute();
    }

    /**
     * Ids of up to PURGE_BATCH rows read before the cutoff (the retention purge deletes them in small units).
     *
     * @param DateTimeImmutable $cutoff Rows read before this time
     *
     * @return list<int> Row ids
     */
    public function idsBefore(DateTimeImmutable $cutoff): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('r')->select('r.id')
            ->where('r.readAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('r.id', 'ASC')
            ->setMaxResults(self::PURGE_BATCH)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    /**
     * Deletes rows by id, inside the caller's unit of work.
     *
     * @param list<int> $ids Row ids
     */
    public function deleteIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->createQueryBuilder('r')->delete()->where('r.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->execute();
    }
}
