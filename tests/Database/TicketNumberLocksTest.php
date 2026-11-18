<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Support\ChatSupportTickets;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\TransactionContract\TransientStorageFailure;

use function microtime;
use function preg_match;
use function str_replace;
use function str_starts_with;

/**
 * 1.30 (Beacon spec 239), two sessions on MySQL 8.4 / PostgreSQL 16: opening a ticket locks the draft's row and the
 * counter row only — never the conversation table — so it does not wait for other conversation writes; two openings
 * queue on the counter and get consecutive numbers; a lock wait or a deadlock on the counter answers
 * TransientStorageFailure (409 + Retry-After) and leaves the worker's connection healthy, with no number lost.
 */
final class TicketNumberLocksTest extends LoadDatabaseTestCase
{
    public function testOpeningATicketDoesNotWaitForOtherConversationWrites(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $this->firstTicket();
        $draft = $tickets->open($this->user('bea'));
        $other = $tickets->open($this->user('cai'));
        $this->em->clear();
        $this->shortLockWait();

        // Another request writes conversation rows and keeps its transaction open: a new ticket row (uncommitted) and
        // another draft's row. The 1.29 statement read MAX(ticket_number) over the whole table.
        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        // 1.35 (audit TK I2): its number sits below the one this opening takes. Above it (99), the unique-key check of the
        // new number on InnoDB locks the next index record, which is the peer's uncommitted row: on MariaDB that waited
        // now and then (a flake since 1.30, unrelated to what this test proves).
        self::assertSame('OK', $peer->execute("INSERT INTO nowo_chat_conversation (uuid, kind, ticket_number, last_message_at, created_at) VALUES ('00000000-0000-7000-8000-00000000c0de', 'support', 0, '2026-11-15 10:00:00', '2026-11-15 10:00:00')"));
        self::assertSame('OK', $peer->execute('UPDATE nowo_chat_conversation SET last_message_at = last_message_at WHERE id = ' . $other->getId()));

        $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');

        self::assertSame(2, $this->draft($draft)->getTicketNumber());
        self::assertSame('OK', $peer->execute('ROLLBACK'));
    }

    public function testTwoOpeningsQueueOnTheCounterAndGetConsecutiveNumbers(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $this->firstTicket();
        $draft = $tickets->open($this->user('bea'));
        $peer  = $this->otherSession();

        $this->connection()->beginTransaction();
        $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');
        self::assertSame('OK', $peer->execute('BEGIN'));
        // The peer opens a ticket too: it waits for the counter row until this transaction ends.
        $peer->send("UPDATE nowo_chat_ticket_counter SET last_number = last_number + 1 WHERE counter_key = 'nowo_chat_conversation'");
        $this->connection()->commit();

        self::assertSame('OK', $peer->await());
        self::assertSame('VAL 3', $this->normalize($peer->value("SELECT last_number FROM nowo_chat_ticket_counter WHERE counter_key = 'nowo_chat_conversation'")));
        self::assertSame('OK', $peer->execute('COMMIT'));
        self::assertSame(2, $this->draft($draft)->getTicketNumber());
    }

    public function testALockWaitOnTheCounterIsTransientAndLosesNoNumber(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $this->firstTicket();
        $draft = $tickets->open($this->user('bea'));
        $peer  = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("UPDATE nowo_chat_ticket_counter SET last_number = last_number WHERE counter_key = 'nowo_chat_conversation'"));
        $this->shortLockWait();

        try {
            $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');
            self::fail('the counter row is locked by the peer');
        } catch (TransientStorageFailure $failure) {
            self::assertNotNull($failure->retryAfterSeconds());
        }
        $this->assertHealthy();
        self::assertNull($this->draft($draft)->getTicketNumber(), 'nothing of the failed opening stays');

        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');
        self::assertSame(2, $this->draft($draft)->getTicketNumber(), 'gap-free');
    }

    public function testADeadlockOnATicketIsTransientAndTheNextOpeningWorks(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $this->firstTicket();
        $draft = $tickets->open($this->user('bea'));
        $peer  = $this->otherSession();
        // The peer holds the counter row and, after a moment, asks for the draft's row, which this opening locks first.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("UPDATE nowo_chat_ticket_counter SET last_number = last_number WHERE counter_key = 'nowo_chat_conversation'"));
        $this->ballast();
        $peer->send($this->sleepStatement(0.5));
        $peer->send('SELECT ticket_number FROM nowo_chat_conversation WHERE id = ' . $draft->getId() . ' FOR UPDATE');

        $failed = false;
        try {
            $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');
        } catch (TransientStorageFailure $failure) {
            $failed = true;
            self::assertTrue($this->causedByDeadlock($failure), 'the deadlock, not a lock wait');
        }
        self::assertSame('OK', $peer->await(), 'the sleep');
        $peerAnswer = $peer->await();
        // One of the two is the deadlock's victim: this opening (TransientStorageFailure) or the peer (40P01 / 1213).
        self::assertSame($failed, str_starts_with($peerAnswer, 'OK'), 'exactly one side lost: ' . $peerAnswer);
        self::assertTrue($failed || preg_match('/^ERR (1213|\\d+ 40P01)/', $peerAnswer) === 1, $peerAnswer);
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        $this->assertHealthy();

        if ($failed) {
            $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');
        }
        self::assertSame(2, $this->draft($draft)->getTicketNumber(), 'one number, gap-free');
        $started = microtime(true);
        $cai     = $this->user('cai');
        $tickets->chooseTopic($cai, $tickets->open($cai), 'billing');
        self::assertLessThan(5.0, microtime(true) - $started, 'the next opening of this worker runs normally');
    }

    public function testTwoFirstTicketsAtTheSameMomentGetConsecutiveNumbers(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $draft   = $tickets->open($this->user('bea'));
        $peer    = $this->otherSession();
        // The peer's first ticket writes the counter row (number 1) and commits a moment later.
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute("INSERT INTO nowo_chat_ticket_counter (counter_key, last_number) VALUES ('nowo_chat_conversation', 1)"));
        $peer->send($this->sleepStatement(0.5));
        $peer->send('COMMIT');

        $tickets->chooseTopic($this->userNamed('bea'), $this->draft($draft), 'billing');

        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the peer committed');
        self::assertSame(2, $this->draft($draft)->getTicketNumber(), 'the row the peer wrote, moved on');
        $this->assertHealthy();
    }

    /**
     * Audit QD-L3, ported with the assertion inverted: two real first openings after the upgrade (no counter row yet,
     * tickets already numbered). The peer runs ChatKitTicketNumbers' 1.30 statements for its own draft and commits a
     * moment later; this opening waits for the peer's counter row, then moves it on. No deadlock (1.30's first read of
     * the maximum takes no lock), numbers unique and gap-free.
     */
    public function testTwoRealFirstOpeningsAfterTheUpgradeDoNotDeadlock(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $bea     = $tickets->open($this->user('bea'));
        $cai     = $tickets->open($this->user('cai'));
        $this->connection()->executeStatement("INSERT INTO nowo_chat_conversation (uuid, kind, ticket_number, last_message_at, created_at) VALUES ('00000000-0000-7000-8000-0000000000f1', 'support', 1, '2026-11-15 10:00:00', '2026-11-15 10:00:00'), ('00000000-0000-7000-8000-0000000000f2', 'support', 2, '2026-11-15 10:00:00', '2026-11-15 10:00:00')");
        $this->em->clear();

        $peer = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute('SELECT ticket_number FROM nowo_chat_conversation WHERE id = ' . $cai->getId() . ' FOR UPDATE'));
        self::assertSame('OK', $peer->execute("SELECT 1 FROM nowo_chat_ticket_counter WHERE counter_key = 'nowo_chat_conversation'"));
        self::assertSame('OK', $peer->execute('SELECT COALESCE(MAX(ticket_number), 0) FROM nowo_chat_conversation'));
        self::assertSame('OK', $peer->execute("INSERT INTO nowo_chat_ticket_counter (counter_key, last_number) VALUES ('nowo_chat_conversation', 3)"));
        $peer->send($this->sleepStatement(0.7));
        $peer->send('UPDATE nowo_chat_conversation SET ticket_number = 3 WHERE id = ' . $cai->getId() . ' AND ticket_number IS NULL');
        $peer->send('COMMIT');

        $tickets->chooseTopic($this->userNamed('bea'), $this->draft($bea), 'billing');

        self::assertSame('OK', $peer->await(), 'the sleep');
        self::assertSame('OK', $peer->await(), 'the peer\'s number');
        self::assertSame('OK', $peer->await(), 'the peer committed');
        $this->assertHealthy();
        self::assertSame(4, $this->draft($bea)->getTicketNumber());
        self::assertSame([1, 2, 3, 4], array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT ticket_number FROM nowo_chat_conversation WHERE ticket_number IS NOT NULL ORDER BY ticket_number')));
    }

    private function firstTicket(): void
    {
        $tickets = $this->get(ChatSupportTickets::class);
        $ana     = $this->user('ana');
        $tickets->chooseTopic($ana, $tickets->open($ana), 'billing');
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT last_number FROM nowo_chat_ticket_counter WHERE counter_key = 'nowo_chat_conversation'"));
    }

    private function draft(ChatConversation $draft): ChatConversation
    {
        $this->em->clear();

        return $this->em->find(ChatConversation::class, $draft->getId()) ?? self::fail('draft');
    }

    private function userNamed(string $name): TestUser
    {
        return $this->em->getRepository(TestUser::class)->findOneBy(['name' => $name]) ?? self::fail($name);
    }

    private function normalize(string $answer): string
    {
        return str_replace("'", '', $answer);
    }
}
