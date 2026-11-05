<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;

use function mb_strtolower;
use function str_replace;

/**
 * 1.19: the link index of the messages (ChatLinks keeps it in step), read by the "Links" panel one page at a time.
 *
 * @extends ServiceEntityRepository<ChatMessageLink>
 */
class ChatMessageLinkRepository extends ServiceEntityRepository
{
    /** The `provider` filter value of links without a known provider. */
    public const string OTHER = 'other';

    /**
     * Builds the repository for ChatMessageLink entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessageLink::class);
    }

    /**
     * Removes the index rows of the given messages.
     *
     * @param non-empty-list<int> $messageIds Message ids
     *
     * @return int Rows removed
     */
    public function removeForMessageIds(array $messageIds): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(ChatMessageLink::class, 'ml')
            ->where('IDENTITY(ml.message) IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->execute();
    }

    /**
     * One page of the links shared in a conversation, newest first: only messages that are not deleted; optional text
     * search (title, URL) and provider filter (a key, or {@see self::OTHER}); the message and its author are fetched in
     * the same query.
     *
     * @param ChatConversation $conversation The conversation
     * @param string|null $query Text to find in the title or the URL (case-insensitive)
     * @param string|null $provider Provider key, {@see self::OTHER}, or null for all
     * @param int|null $before Only rows with a smaller id (the previous page's `next`)
     * @param int $limit Maximum rows
     *
     * @return list<ChatMessageLink>
     */
    public function page(ChatConversation $conversation, ?string $query, ?string $provider, ?int $before, int $limit): array
    {
        $qb = $this->createQueryBuilder('ml')
            ->addSelect('m', 'author')
            ->innerJoin('ml.message', 'm')
            ->leftJoin('m.author', 'author')
            ->where('m.conversation = :conversation')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conversation', $conversation)
            ->orderBy('ml.id', 'DESC')
            ->setMaxResults($limit);
        if ($query !== null && $query !== '') {
            // `!` escapes the wildcards (a backslash would need quoting on MySQL).
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)) . '%';
            $qb->andWhere("(LOWER(COALESCE(ml.title, '')) LIKE :q ESCAPE '!' OR LOWER(ml.url) LIKE :q ESCAPE '!')")->setParameter('q', $like);
        }
        if ($provider === self::OTHER) {
            $qb->andWhere('ml.provider IS NULL');
        } elseif ($provider !== null && $provider !== '') {
            $qb->andWhere('ml.provider = :provider')->setParameter('provider', $provider);
        }
        if ($before !== null) {
            $qb->andWhere('ml.id < :before')->setParameter('before', $before);
        }
        /** @var list<ChatMessageLink> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * Ids of the messages (from an id on, by id) whose text may hold a link, for the backfill.
     *
     * @param int $afterId Only messages with a greater id
     * @param int $limit Maximum ids
     *
     * @return list<int>
     */
    public function candidateMessageIds(int $afterId, int $limit): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->createQueryBuilder()
            ->select('m.id')
            ->from(ChatMessage::class, 'm')
            ->where('m.id > :after')
            ->andWhere('m.deletedAt IS NULL')
            ->andWhere('m.kind IN (:kinds)')
            ->andWhere("(m.body LIKE '%http%' OR m.body LIKE '%www.%')")
            ->setParameter('after', $afterId)
            ->setParameter('kinds', [ChatMessage::KIND_TEXT, ChatMessage::KIND_AUTO])
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $ids);
    }
}
