<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Nowo\ChatKitBundle\Attachment\ChatAttachmentRefused;
use Nowo\ChatKitBundle\Attachment\ChatAttachments;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentScan;
use Nowo\ChatKitBundle\Attachment\ChatAttachmentsSweepCommand;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Model\ChatScanSubject;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Attachment\TestScanner;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use Symfony\Component\Console\Tester\CommandTester;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * 2.7 (spec 259 §9, REQ-RUNTIME-003): the attachment write paths against a second session on a real database (MySQL 8.4,
 * MariaDB 11.4, PostgreSQL 16; DBAL 3 and 4 in CI). Every race ends in one consistent state: a verdict whose file was
 * removed or whose lease was taken over is discarded; a send waits for the conversation's lock and then refuses a file
 * removed or blocked meanwhile, writing nothing; a second worker never scans a file twice; the sweep exits 75 on a lock
 * conflict and leaves nothing half-done.
 */
final class AttachmentRacesTest extends LoadDatabaseTestCase
{
    protected function createKernel(): TestKernel
    {
        return new TestKernel(['profiles' => ['default' => ['attachments' => ['enabled' => true, 'scanner' => TestScanner::class]]]], attachments: true);
    }

    public function testAVerdictAfterTheFileWasRemovedIsDiscarded(): void
    {
        [$ana, $channel]                       = $this->seed();
        $id                                    = $this->draft($ana, $channel);
        $this->get(TestScanner::class)->during = function () use ($id): void {
            // The uploader removes the file while it is scanned; the remove holds the row until it commits.
            $other = $this->otherSession();
            self::assertSame('OK', $other->execute('START TRANSACTION'));
            self::assertSame('OK', $other->execute("UPDATE nowo_chat_attachment SET state = 'deleted' WHERE id = " . $id));
            $other->send($this->sleepStatement(1));
            $other->send('COMMIT');
        };

        self::assertNull($this->get(ChatAttachmentScan::class)->scan($id));
        self::assertSame(['OK', 'OK'], [$this->otherSession()->await(), $this->otherSession()->await()]);
        self::assertSame('deleted', $this->state($id));
        $this->assertHealthy();
    }

    public function testASecondWorkerNeverScansTheSameFile(): void
    {
        [$ana, $channel] = $this->seed();
        $id              = $this->draft($ana, $channel);
        // Worker 2 claimed it first (a live lease).
        self::assertSame('OK', $this->otherSession()->execute("UPDATE nowo_chat_attachment SET state = 'scanning', state_token = 'w2', lease_until = '2999-01-01 00:00:00', scan_attempts = 1 WHERE id = " . $id));

        self::assertNull($this->get(ChatAttachmentScan::class)->scan($id));
        self::assertSame([], $this->get(TestScanner::class)->scanned);
        self::assertSame('scanning', $this->state($id));
    }

    public function testALateVerdictOfAnExpiredLeaseIsDiscarded(): void
    {
        [$ana, $channel]                       = $this->seed();
        $id                                    = $this->draft($ana, $channel);
        $this->get(TestScanner::class)->during = function (ChatScanSubject $subject) use ($id): void {
            // This worker's lease expired and worker 2 took the file over (another token).
            self::assertSame('OK', $this->otherSession()->execute("UPDATE nowo_chat_attachment SET state_token = 'w2', lease_until = '2999-01-01 00:00:00' WHERE id = " . $id));
        };

        self::assertNull($this->get(ChatAttachmentScan::class)->scan($id));
        self::assertSame('scanning', $this->state($id), 'worker 2 owns it');
        self::assertSame('w2', (string) $this->connection()->fetchOne('SELECT state_token FROM nowo_chat_attachment WHERE id = ?', [$id]));
    }

    public function testASendWaitsForTheConversationAndRefusesAFileRemovedMeanwhile(): void
    {
        [$ana, $channel] = $this->seed();
        $id              = $this->draft($ana, $channel, true);
        $uuid            = $this->uuid($id);
        $other           = $this->otherSession();
        self::assertSame('OK', $other->execute('START TRANSACTION'));
        self::assertSame('OK', $other->execute('SELECT id FROM nowo_chat_conversation WHERE id = ' . (int) $channel->getId() . ' FOR UPDATE'));
        self::assertSame('OK', $other->execute("UPDATE nowo_chat_attachment SET state = 'deleted' WHERE id = " . $id));
        $other->send($this->sleepStatement(1));
        $other->send('COMMIT');

        try {
            $this->get(ChatAttachments::class)->send($this->fresh($ana), $channel->getUuid(), 'x', [$uuid]);
            self::fail('The send must be refused.');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::INVALID, $refused->error);
        }
        self::assertSame(['OK', 'OK'], [$other->await(), $other->await()]);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'), 'only the seed message');
        $this->assertHealthy();
    }

    public function testASendWaitsAndRefusesAFileBlockedMeanwhile(): void
    {
        [$ana, $channel] = $this->seed();
        $id              = $this->draft($ana, $channel, true);
        $uuid            = $this->uuid($id);
        $other           = $this->otherSession();
        self::assertSame('OK', $other->execute('START TRANSACTION'));
        self::assertSame('OK', $other->execute('SELECT id FROM nowo_chat_conversation WHERE id = ' . (int) $channel->getId() . ' FOR UPDATE'));
        self::assertSame('OK', $other->execute("UPDATE nowo_chat_attachment SET state = 'blocked' WHERE id = " . $id . " AND state = 'clean'"));
        $other->send($this->sleepStatement(1));
        $other->send('COMMIT');

        try {
            $this->get(ChatAttachments::class)->send($this->fresh($ana), $channel->getUuid(), 'x', [$uuid]);
            self::fail('The send must be refused.');
        } catch (ChatAttachmentRefused $refused) {
            self::assertSame(ChatAttachmentRefused::INVALID, $refused->error);
        }
        self::assertSame(['OK', 'OK'], [$other->await(), $other->await()]);
        self::assertSame('blocked', $this->state($id));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message'));
        $this->assertHealthy();
    }

    public function testARemoveAfterTheSendCommittedLeavesTheSentFile(): void
    {
        [$ana, $channel] = $this->seed();
        $id              = $this->draft($ana, $channel, true);
        $uuid            = $this->uuid($id);
        $this->get(ChatAttachments::class)->send($this->fresh($ana), $channel->getUuid(), 'sent', [$uuid]);
        // The other session tries the draft-only remove (C3): the row is attached now, nothing changes.
        self::assertSame('OK', $this->otherSession()->execute("UPDATE nowo_chat_attachment SET state = 'deleted' WHERE id = " . $id . ' AND message_id IS NULL AND attached_at IS NULL'));
        self::assertSame('clean', $this->state($id));
    }

    public function testTheSweepExitsWithAConflictUnderAHeldRowLock(): void
    {
        [$ana, $channel] = $this->seed();
        $id              = $this->draft($ana, $channel);
        $this->connection()->executeStatement("UPDATE nowo_chat_attachment SET created_at = '2000-01-01 00:00:00', state = 'failed' WHERE id = " . $id);
        $other = $this->otherSession();
        self::assertSame('OK', $other->execute('START TRANSACTION'));
        self::assertSame('OK', $other->execute('SELECT id FROM nowo_chat_attachment WHERE id = ' . $id . ' FOR UPDATE'));
        $this->shortLockWait();

        $tester = new CommandTester($this->get(ChatAttachmentsSweepCommand::class));
        self::assertSame(ChatAttachmentsSweepCommand::CONFLICT, $tester->execute([]));
        self::assertStringContainsString('CONFLICT_001', $tester->getDisplay());
        self::assertSame('OK', $other->execute('ROLLBACK'));
        self::assertSame('failed', $this->state($id), 'nothing half-done');
        // Run again: the stale draft goes.
        self::assertSame(0, (new CommandTester($this->get(ChatAttachmentsSweepCommand::class)))->execute([]));
        self::assertNotSame('failed', $this->state($id));
    }

    /**
     * @return array{TestUser, ChatConversation}
     */
    private function seed(): array
    {
        $ana     = $this->user('ana');
        $chat    = $this->get(ChatService::class);
        $channel = $chat->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $chat->send($ana, $channel, 'seed');

        return [$ana, $channel];
    }

    private function draft(TestUser $ana, ChatConversation $channel, bool $clean = false): int
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'chat-race-');
        file_put_contents($path, 'plain text');
        $view = $this->get(ChatAttachments::class)->upload($this->fresh($ana), $channel->getUuid(), $path, 'notes.txt');
        $id   = (int) $this->connection()->fetchOne('SELECT id FROM nowo_chat_attachment WHERE uuid = ?', [$view->uuid]);
        if ($clean) {
            self::assertSame(ChatAttachmentState::Clean, $this->get(ChatAttachmentScan::class)->scan($id));
        }

        return $id;
    }

    private function uuid(int $id): string
    {
        return (string) $this->connection()->fetchOne('SELECT uuid FROM nowo_chat_attachment WHERE id = ?', [$id]);
    }

    private function state(int $id): string
    {
        return (string) $this->connection()->fetchOne('SELECT state FROM nowo_chat_attachment WHERE id = ?', [$id]);
    }
}
