<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Support;

use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatTicketCounter;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Support\ChatTicketNumbers;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Transaction\CountingTransactionRunner;

/**
 * 1.30 (Beacon spec 239): ticket numbers come from one counter row, in the ticket's transaction. They are sequential,
 * gap-free (a rolled-back opening hands nothing out), never reused, start after the numbers already in the table and
 * catch up when a number is taken. The two-connection lock behaviour runs on MySQL / PostgreSQL in tests/Database.
 */
final class ChatTicketNumbersTest extends DatabaseTestCase
{
    public function testNumbersAreSequentialAndTheCounterRowHoldsTheLast(): void
    {
        $numbers = $this->get(ChatTicketNumbers::class);

        self::assertSame(1, $numbers->assign($this->draft('Ana')));
        self::assertSame(2, $numbers->assign($this->draft('Bea')));
        self::assertSame(3, $numbers->assign($this->draft('Cai')));
        self::assertSame(3, $this->counter());
    }

    public function testADraftWithANumberKeepsItAndTakesNone(): void
    {
        $numbers = $this->get(ChatTicketNumbers::class);
        $draft   = $this->draft('Ana');

        self::assertSame(1, $numbers->assign($draft));
        self::assertSame(1, $numbers->assign($draft));
        self::assertSame(2, $numbers->assign($this->draft('Bea')));
    }

    public function testTheCounterStartsAfterTheNumbersAlreadyThere(): void
    {
        $taken = $this->draft('Old');
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_conversation SET ticket_number = 41 WHERE id = ?', [$taken->getId()]);

        self::assertSame(42, $this->get(ChatTicketNumbers::class)->assign($this->draft('Ana')));
    }

    public function testATakenNumberMakesTheCounterCatchUp(): void
    {
        $numbers = $this->get(ChatTicketNumbers::class);
        self::assertSame(1, $numbers->assign($this->draft('Ana')));
        // A number written outside ChatKit (a restored backup, an import).
        $other = $this->draft('Bea');
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_conversation SET ticket_number = 2 WHERE id = ?', [$other->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE nowo_chat_conversation SET ticket_number = 5 WHERE id = ?', [$this->draft('Cai')->getId()]);

        self::assertSame(6, $numbers->assign($this->draft('Dan')));
        self::assertSame(6, $this->counter());
    }

    public function testARolledBackOpeningHandsNoNumberOut(): void
    {
        $numbers    = $this->get(ChatTicketNumbers::class);
        $connection = $this->em->getConnection();
        self::assertSame(1, $numbers->assign($this->draft('Ana')));
        $draft = $this->draft('Bea');

        $connection->beginTransaction();
        self::assertSame(2, $numbers->assign($draft));
        $connection->rollBack();

        self::assertSame(1, $this->counter(), 'the counter rolled back with the ticket');
        self::assertSame(2, $numbers->assign($draft), 'gap-free: the same number again');
    }

    public function testADeletedTicketsNumberIsNeverGivenAgain(): void
    {
        $numbers = $this->get(ChatTicketNumbers::class);
        $numbers->assign($this->draft('Ana'));
        $last = $this->draft('Bea');
        self::assertSame(2, $numbers->assign($last));
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_participant WHERE conversation_id = ?', [$last->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM nowo_chat_conversation WHERE id = ?', [$last->getId()]);

        self::assertSame(3, $numbers->assign($this->draft('Cai')));
    }

    public function testACallOutsideATransactionRunsInAUnitOfWorkOfItsOwn(): void
    {
        $runner  = new CountingTransactionRunner();
        $numbers = new ChatTicketNumbers($this->em, $runner);

        self::assertSame(1, $numbers->assign($this->draft('Ana')));
        self::assertSame(1, $runner->runs);

        $this->em->getConnection()->beginTransaction();
        self::assertSame(2, $numbers->assign($this->draft('Bea')));
        $this->em->getConnection()->commit();
        self::assertSame(1, $runner->runs, 'inside the caller\'s transaction: no unit of its own');
    }

    public function testADraftThatIsNotStoredGetsNoNumber(): void
    {
        $this->expectException(LogicException::class);
        $this->get(ChatTicketNumbers::class)->assign(ChatConversation::support($this->user('Ana')));
    }

    public function testItGivesUpWhenEveryNumberIsTaken(): void
    {
        $draft = $this->draft('Ana');
        // SQLite reports this message as a unique key violation, whatever the number.
        $this->em->getConnection()->executeStatement("CREATE TRIGGER always_taken BEFORE UPDATE OF ticket_number ON nowo_chat_conversation BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: nowo_chat_conversation.ticket_number'); END");

        try {
            $this->get(ChatTicketNumbers::class)->assign($draft);
            self::fail('five taken numbers: no number');
        } catch (LogicException $exception) {
            self::assertStringContainsString('5 attempts', $exception->getMessage());
        }
        self::assertFalse($this->em->getConnection()->isTransactionActive(), 'its own unit of work was rolled back');
    }

    private function draft(string $name): ChatConversation
    {
        return $this->get(ChatSupportTickets::class)->open($this->user($name));
    }

    private function counter(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT last_number FROM ' . $this->em->getClassMetadata(ChatTicketCounter::class)->getTableName());
    }
}
