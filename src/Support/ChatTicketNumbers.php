<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Support;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;

/**
 * Sequential ticket numbers (1, 2, 3 … per application). The number is written with one UPDATE that reads the
 * current maximum, so two drafts turning into tickets at the same moment cannot both get it: the unique key rejects
 * the second, which retries with the next number.
 */
final readonly class ChatTicketNumbers
{
    private const int ATTEMPTS = 5;

    /**
     * Builds the generator from the entity manager (its connection and the conversation table name).
     *
     * @param EntityManagerInterface $entityManager The entity manager
     */
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Gives the persisted draft the next free number in the database and returns it (the entity is not changed).
     *
     * @param ChatConversation $draft A persisted support draft
     *
     * @throws LogicException When the draft is not persisted or no number could be written
     *
     * @return int The new ticket number
     */
    public function assign(ChatConversation $draft): int
    {
        $id         = $draft->getId() ?? throw new LogicException('Only a persisted draft gets a ticket number.');
        $connection = $this->entityManager->getConnection();
        $table      = $this->entityManager->getClassMetadata(ChatConversation::class)->getTableName();
        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            try {
                // The derived table lets MySQL read the table it updates; SQLite and PostgreSQL accept it too.
                $connection->executeStatement(
                    'UPDATE ' . $table . ' SET ticket_number = (SELECT seq.next_number FROM (SELECT COALESCE(MAX(ticket_number), 0) + 1 AS next_number FROM ' . $table . ') seq) WHERE id = ? AND ticket_number IS NULL',
                    [$id],
                );

                return (int) $connection->fetchOne('SELECT ticket_number FROM ' . $table . ' WHERE id = ?', [$id]);
            } catch (UniqueConstraintViolationException) {
                // Another draft took that number in the meantime: read the new maximum and try again.
            }
        }

        throw new LogicException('No free ticket number after ' . self::ATTEMPTS . ' attempts.');
    }
}
