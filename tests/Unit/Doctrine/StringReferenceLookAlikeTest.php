<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserLookAlikeException;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Tests\Support\Ddd\RefChatUser;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * 1.28: a read by user that comes back with a look-alike's row warns once per user key and degrades; the write that
 * would follow such a read is refused before it clashes with the look-alike's unique key inside a flush.
 */
final class StringReferenceLookAlikeTest extends TestCase
{
    private const string PARTICIPANT = 'nowo_chat_participant.user_id';

    private const string SETTINGS = 'nowo_chat_user_settings.user_id';

    public function testTheReadWarnsOnceAndTheWriteIsRefusedInThatColumnOnly(): void
    {
        $logger = $this->logger();
        $guard  = new StringReferenceSchemaGuard($this->createMock(ManagerRegistry::class), $logger, 'test-secret');
        $refs   = new ChatUserReferences(ChatUserReferences::STRING, $guard);
        $bigB   = new RefChatUser('Bob', 'pub-Bob', 'Bob upper');
        $ana    = new RefChatUser('ana', 'pub-ana', 'Ana');

        // Nothing was read yet: every write is allowed.
        $refs->assertNoLookAlike(self::PARTICIPANT, $bigB);
        self::assertSame([], $logger->records);

        $refs->reportLookAlike(self::PARTICIPANT, $bigB);
        $refs->reportLookAlike(self::PARTICIPANT, $bigB);
        self::assertCount(1, $logger->records, 'once per column and user key, however many reads found the row');
        self::assertStringContainsString(self::PARTICIPANT, $logger->records[0]);
        self::assertStringContainsString($guard->hashOf('Bob'), $logger->records[0]);

        // Only that user, and only in that column (audit PV-L1): the other tables may compare exactly.
        $refs->assertNoLookAlike(self::PARTICIPANT, $ana);
        $refs->assertNoLookAlike(self::SETTINGS, $bigB);
        try {
            $refs->assertNoLookAlike(self::PARTICIPANT, $bigB);
            self::fail('the write of a user whose look-alike was read must be refused');
        } catch (ChatUserLookAlikeException $exception) {
            self::assertStringContainsString(self::PARTICIPANT, $exception->getMessage());
            self::assertStringContainsString($guard->hashOf('Bob'), $exception->getMessage());
        }

        // Another column of the same user warns once more and is refused on its own.
        $refs->reportLookAlike(self::SETTINGS, $bigB);
        self::assertCount(2, $logger->records);
        $this->expectException(ChatUserLookAlikeException::class);
        try {
            $refs->assertNoLookAlike(self::SETTINGS, $bigB);
        } finally {
            // The request ends: nothing is carried to the next one (worker mode).
            $guard->reset();
            $refs->assertNoLookAlike(self::PARTICIPANT, $bigB);
            $refs->assertNoLookAlike(self::SETTINGS, $bigB);
        }
    }

    /** Audit PV-L3 (REQ-OBS-001): neither the warning nor the refusal carries a user reference. */
    public function testNoReferenceReachesTheLogOrTheException(): void
    {
        $logger = $this->logger();
        $guard  = new StringReferenceSchemaGuard($this->createMock(ManagerRegistry::class), $logger, 'test-secret');
        $guard->sawLookAlike(self::SETTINGS, 'alice@example.com');
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsStringIgnoringCase('alice', $logger->records[0]);
        self::assertSame([self::SETTINGS, $guard->hashOf('alice@example.com')], array_values($logger->contexts[0]));
        try {
            $guard->assertNoLookAlike(self::SETTINGS, 'alice@example.com');
            self::fail('refused');
        } catch (ChatUserLookAlikeException $exception) {
            self::assertStringNotContainsStringIgnoringCase('alice', $exception->getMessage());
        }

        // A keyed hash: stable for one secret, different under another, 16 hex digits.
        self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $guard->hashOf('alice@example.com'));
        self::assertSame($guard->hashOf('alice@example.com'), $guard->hashOf('alice@example.com'));
        $other = new StringReferenceSchemaGuard($this->createMock(ManagerRegistry::class), null, 'another-secret');
        self::assertNotSame($guard->hashOf('alice@example.com'), $other->hashOf('alice@example.com'));
    }

    /** Audit PV-L2: which keys a folding column may treat as one (case, accents, width, trailing spaces). */
    public function testMayFold(): void
    {
        foreach ([['bob', 'Bob'], ['bob', 'BÓB'], ['bob', 'bob '], ['bob', 'ｂｏｂ'], ['straße', 'STRASSE']] as [$a, $b]) {
            self::assertTrue(ChatUserReferences::mayFold($a, $b), $a . ' / ' . $b);
        }
        foreach ([['bob', 'bob'], ['bob', 'rob'], ['bob', ' bob'], ["\xff", "\xfe"]] as [$a, $b]) {
            self::assertFalse(ChatUserReferences::mayFold($a, $b), $a . ' / ' . $b);
        }
    }

    public function testTheOtherModesAndUsersWithoutAKeyAreUntouched(): void
    {
        $logger = $this->logger();
        $guard  = new StringReferenceSchemaGuard($this->createMock(ManagerRegistry::class), $logger);
        $bigB   = new RefChatUser('Bob', 'pub-Bob', 'Bob upper');
        foreach ([ChatUserReferences::RELATION, ChatUserReferences::ID] as $mode) {
            $refs = new ChatUserReferences($mode, $guard);
            $refs->reportLookAlike(self::PARTICIPANT, $bigB);
            $refs->assertNoLookAlike(self::PARTICIPANT, $bigB);
        }
        self::assertSame([], $logger->records, 'only `string` mode keys users by a reference');

        // A user without a key (never persisted) cannot be reported or refused.
        $refs = new ChatUserReferences(ChatUserReferences::STRING, $guard);
        $refs->reportLookAlike(self::PARTICIPANT, new TestUser('Nobody'));
        $refs->assertNoLookAlike(self::PARTICIPANT, new TestUser('Nobody'));
        self::assertSame([], $logger->records);
    }

    /**
     * @return AbstractLogger&object{records: list<string>, contexts: list<array<string, mixed>>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $line = (string) $message;
                foreach ($context as $key => $value) {
                    $line = str_replace('{' . $key . '}', (string) $value, $line);
                }
                $this->records[]  = $level . ': ' . $line;
                $this->contexts[] = $context;
            }
        };
    }
}
