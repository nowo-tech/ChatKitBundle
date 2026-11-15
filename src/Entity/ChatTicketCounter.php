<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\ChatKitBundle\Support\ChatTicketNumbers;

/**
 * 1.30 (Beacon spec 239): the last support ticket number handed out, one row per conversation table. Written only by
 * {@see ChatTicketNumbers} with single-row statements: opening a ticket locks this one row
 * (until its transaction ends) instead of reading the maximum of the conversation table, which locked the whole ticket
 * index on MySQL / MariaDB. Read-only for the application.
 *
 * @internal
 */
#[ORM\Entity]
#[ORM\Table(name: 'nowo_chat_ticket_counter')]
class ChatTicketCounter
{
    /** The conversation table whose ticket numbers this row counts. */
    #[ORM\Id]
    #[ORM\Column(name: 'counter_key', length: 190)]
    private string $key;

    /** The last number handed out. */
    #[ORM\Column(name: 'last_number')]
    private int $lastNumber;

    /**
     * @param string $key The conversation table
     * @param int $lastNumber The last number handed out
     */
    public function __construct(string $key, int $lastNumber)
    {
        $this->key        = $key;
        $this->lastNumber = $lastNumber;
    }

    /**
     * The conversation table whose ticket numbers this row counts.
     *
     * @return string The table name
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * The last number handed out.
     *
     * @return int The number
     */
    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }
}
