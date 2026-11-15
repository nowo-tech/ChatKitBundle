<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatQuickReply;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Transaction\ChatKitWrites;
use Nowo\TransactionContract\TransactionRunnerInterface;

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
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
        private readonly ?TransactionRunnerInterface $transactions = null,
    ) {
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
            ->where($this->refs->field('q', 'user') . ' = :user')
            ->setParameter('user', $this->refs->value($user))
            ->orderBy('q.title', 'ASC')
            ->addOrderBy('q.id', 'ASC')
            // 1.30 (Beacon spec 239): bounded read; the API keeps a person at MAX_PER_USER snippets.
            ->setMaxResults(ChatQuickReply::MAX_PER_USER)
            ->getQuery()
            ->getResult();

        // REQ-SEC-006 item 2: exactly this user's rows (a folding collation may return a look-alike's).
        return array_values(array_filter($rows, static fn (ChatQuickReply $row): bool => ChatUserReferences::same($row->getUser(), $user)));
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
        $row = $this->findOneBy(['id' => $id] + $this->refs->criteria('user', $user));

        return $row instanceof ChatQuickReply && ChatUserReferences::same($row->getUser(), $user) ? $row : null;
    }

    /**
     * Stores a new or changed snippet.
     *
     * @param ChatQuickReply $reply The snippet
     */
    public function save(ChatQuickReply $reply): void
    {
        $manager = $this->getEntityManager();
        // 1.30: one unit of work through the transaction runner.
        $stored = $reply->getId() !== null;
        ChatKitWrites::runRecovering($this->transactions ?? new ChatKitTransactionRunner($manager), $manager, static function (bool $replay) use ($manager, $reply, $stored): void {
            $manager->persist($replay ? ChatKitWrites::carry($manager, $reply, $stored) : $reply);
        });
    }

    /**
     * Deletes a snippet.
     *
     * @param ChatQuickReply $reply The snippet
     */
    public function remove(ChatQuickReply $reply): void
    {
        $manager = $this->getEntityManager();
        $id      = $reply->getId();
        ChatKitWrites::runRecovering($this->transactions ?? new ChatKitTransactionRunner($manager), $manager, static function (bool $replay) use ($manager, $reply, $id): void {
            // A replay looks the row up by id again: the attempt that removed it cleared the entity's id.
            $reply = $replay ? $manager->find(ChatQuickReply::class, $id) : $reply;
            if ($reply instanceof ChatQuickReply) {
                // @igor-ignore - Unit of work of this call (the runner discards it on failure), not worker state.
                $manager->remove($reply);
            }
        });
    }
}
