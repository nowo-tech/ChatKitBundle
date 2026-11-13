<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use LogicException;
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
    public function testTheReadWarnsOnceAndTheWriteIsRefused(): void
    {
        $logger = $this->logger();
        $guard  = new StringReferenceSchemaGuard($this->createMock(ManagerRegistry::class), $logger);
        $refs   = new ChatUserReferences(ChatUserReferences::STRING, $guard);
        $bob    = new RefChatUser('bob', 'pub-bob', 'Bob lower');
        $bigB   = new RefChatUser('Bob', 'pub-Bob', 'Bob upper');
        $ana    = new RefChatUser('ana', 'pub-ana', 'Ana');

        // Nothing was read yet: every write is allowed.
        $refs->assertNoLookAlike($bigB);
        self::assertSame([], $logger->records);

        $refs->reportLookAlike('nowo_chat_participant.user_id', $bigB, $bob);
        $refs->reportLookAlike('nowo_chat_user_settings.user_id', $bigB, $bob);
        self::assertCount(1, $logger->records, 'once per user key, however many reads found the row');
        self::assertStringContainsString('nowo_chat_participant.user_id', $logger->records[0]);
        self::assertStringContainsString('Bob', $logger->records[0]);

        // Only that user is refused.
        $refs->assertNoLookAlike($ana);
        try {
            $refs->assertNoLookAlike($bigB);
            self::fail('the write of a user whose look-alike was read must be refused');
        } catch (LogicException $exception) {
            self::assertStringContainsString('nowo_chat_participant.user_id', $exception->getMessage());
            self::assertStringContainsString('Bob', $exception->getMessage());
        }

        // The request ends: nothing is carried to the next one (worker mode).
        $guard->reset();
        $refs->assertNoLookAlike($bigB);
        $refs->reportLookAlike('nowo_chat_participant.user_id', $bigB, $bob);
        self::assertCount(2, $logger->records);
    }

    public function testTheOtherModesAndUsersWithoutAKeyAreUntouched(): void
    {
        $logger = $this->logger();
        $guard  = new StringReferenceSchemaGuard($this->createMock(ManagerRegistry::class), $logger);
        $bigB   = new RefChatUser('Bob', 'pub-Bob', 'Bob upper');
        foreach ([ChatUserReferences::RELATION, ChatUserReferences::ID] as $mode) {
            $refs = new ChatUserReferences($mode, $guard);
            $refs->reportLookAlike('nowo_chat_participant.user_id', $bigB);
            $refs->assertNoLookAlike($bigB);
        }
        self::assertSame([], $logger->records, 'only `string` mode keys users by a reference');

        // A user without a key (never persisted) cannot be reported or refused.
        $refs = new ChatUserReferences(ChatUserReferences::STRING, $guard);
        $refs->reportLookAlike('nowo_chat_participant.user_id', new TestUser('Nobody'));
        $refs->assertNoLookAlike(new TestUser('Nobody'));
        self::assertSame([], $logger->records);
    }

    /**
     * @return AbstractLogger&object{records: list<string>}
     */
    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $line = (string) $message;
                foreach ($context as $key => $value) {
                    $line = str_replace('{' . $key . '}', (string) $value, $line);
                }
                $this->records[] = $level . ': ' . $line;
            }
        };
    }
}
