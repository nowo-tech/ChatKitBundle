<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagedRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageRevision;

use function array_map;
use function array_slice;
use function max;

/**
 * 1.36: the previous texts of edited messages (`message_menu.edit_history`).
 *
 * @extends ServiceEntityRepository<ChatMessageRevision>
 */
class ChatMessageRevisionRepository extends ServiceEntityRepository
{
    /**
     * Builds the repository for ChatMessageRevision entities.
     *
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessageRevision::class);
    }

    /**
     * Records the text a message had before an edit and drops the oldest ones beyond the limit, inside the caller's unit
     * of work (under the conversation's lock: two edits of one message queue).
     *
     * @param ChatMessage $message The edited message
     * @param string $body Its text before the edit
     * @param DateTimeImmutable $editedAt When it was replaced
     * @param int $max Previous texts kept per message
     *
     * @return ChatMessageRevision The new row (persisted; the unit of work flushes it)
     */
    public function record(ChatMessage $message, string $body, DateTimeImmutable $editedAt, int $max): ChatMessageRevision
    {
        // The oldest ones beyond the limit go first (the new row makes it `$max` again; flushed with the unit).
        $ids = $this->createQueryBuilder('r')->select('r.id')
            ->where('r.message = :message')
            ->setParameter('message', $message)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getSingleColumnResult();
        $drop = array_slice($ids, max(0, $max - 1));
        if ($drop !== []) {
            ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('r')->delete()->where('r.id IN (:ids)')->setParameter('ids', $drop)->getQuery()->execute());
            // 2.0: a dropped row the manager held is forgotten (the DQL delete bypassed it).
            ChatKitManagedRows::forgetGone($this->getEntityManager(), [ChatMessageRevision::class]);
        }
        $revision = new ChatMessageRevision($message, $body, $editedAt);
        $this->getEntityManager()->persist($revision);

        return $revision;
    }

    /**
     * The previous texts of a message, newest first.
     *
     * @param ChatMessage $message The message
     *
     * @return list<ChatMessageRevision> The rows
     */
    public function forMessage(ChatMessage $message): array
    {
        /** @var list<ChatMessageRevision> $rows */
        $rows = $this->createQueryBuilder('r')
            ->where('r.message = :message')
            ->setParameter('message', $message)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * When a message was edited (one time per kept revision), newest first.
     *
     * @param ChatMessage $message The message
     *
     * @return list<DateTimeImmutable> The edit times
     */
    public function editTimes(ChatMessage $message): array
    {
        return array_map(static fn (ChatMessageRevision $row): DateTimeImmutable => $row->getEditedAt(), $this->forMessage($message));
    }

    /**
     * Deletes the previous texts of messages (a deleted message keeps no text), inside the caller's unit of work.
     *
     * @param non-empty-list<int> $messageIds The messages
     *
     * @return int Rows deleted
     */
    public function deleteFor(array $messageIds): int
    {
        $deleted = (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): mixed => $this->createQueryBuilder('r')->delete()->where('IDENTITY(r.message) IN (:ids)')->setParameter('ids', $messageIds)->getQuery()->execute());
        ChatKitManagedRows::forgetGone($this->getEntityManager(), [ChatMessageRevision::class]);

        return $deleted;
    }
}
