<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Model\ChatUserInterface;

/**
 * A user's quick replies (1.11).
 *
 * @extends ServiceEntityRepository<ChatQuickReply>
 */
class ChatQuickReplyRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatQuickReply entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatQuickReply::class);
    }

    /**
     * The user's quick replies, by title.
     *
     * @param ChatUserInterface $user The owner
     *
     * @return list<ChatQuickReply> The snippets
     */
    public function forUser(ChatUserInterface $user): array
    {
        /** @var list<ChatQuickReply> $rows */
        $rows = $this->createQueryBuilder('q')
            ->where('q.user = :user')
            ->setParameter('user', $user)
            ->orderBy('q.title', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * One of the user's quick replies (never someone else's).
     *
     * @param ChatUserInterface $user The owner
     * @param int $id The snippet id
     *
     * @return ChatQuickReply|null The snippet
     */
    public function findOwned(ChatUserInterface $user, int $id): ?ChatQuickReply
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * Stores a new or changed snippet.
     *
     * @param ChatQuickReply $reply The snippet
     */
    public function save(ChatQuickReply $reply): void
    {
        $this->getEntityManager()->persist($reply);
        $this->getEntityManager()->flush();
    }

    /**
     * Deletes a snippet.
     *
     * @param ChatQuickReply $reply The snippet
     */
    public function remove(ChatQuickReply $reply): void
    {
        $this->getEntityManager()->remove($reply);
        $this->getEntityManager()->flush();
    }
}
