<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Transaction;

use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Model\ChatChannel;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Support\Chat\TestChannelProvider;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use Nowo\ChatKitBundle\Tests\Support\Doctrine\QueryCounter;
use PHPUnit\Framework\Attributes\Group;

use function str_contains;

/**
 * 1.30, audit QD-M2, ported from the audit's PoC (QdHostManagerTest) with the assertions inverted: a lost insert race
 * is resolved in a savepoint, so the caller's next write of the same request works. 2.0: the QD-M1 cases of the removed
 * shared-manager mode (a chat failure on the application's manager) are gone with the mode; the private manager's
 * failure handling is covered by PrivateEntityManagerKernelTest.
 */
#[Group('private-manager')]
final class HostManagerSafetyTest extends DatabaseTestCase
{
    /**
     * QD-M2 (Beacon's ShareToProjectChat / MCP flow): openChannel() then send() with the user the request holds. The
     * membership insert meets the row another request of the same person wrote first: only its savepoint is rolled
     * back, that row is read, and the next write of the same request works with the same user.
     */
    public function testAWriteAfterALostMembershipRaceWorksWithTheCallersUser(): void
    {
        $ana     = $this->user('Ana');
        $channel = $this->get(ChatService::class)->openChannel($this->user('Luis'), new ChatChannel(TestChannelProvider::KEY, 'Acme'));
        $counter = $this->get(QueryCounter::class);
        $em      = $this->em;
        // "Another request" writes Ana's row just before this one's insert (SQLite: on the same connection, before the
        // savepoint, so the insert meets it).
        $counter->before = static function (string $sql) use ($em, $channel, $ana): bool {
            if (!str_contains($sql, 'SAVEPOINT nowo_chat_kit_row_') || str_contains($sql, 'RELEASE') || str_contains($sql, 'ROLLBACK')) {
                return false;
            }
            $em->getConnection()->insert('nowo_chat_participant', ['conversation_id' => $channel->getId(), 'user_id' => $ana->getId(), 'joined_at' => '2026-11-15 10:00:00']);

            return true;
        };

        $conversation = $this->get(ChatService::class)->openChannel($ana, new ChatChannel(TestChannelProvider::KEY, 'Acme'));

        self::assertNull($counter->before, 'the race was played');
        self::assertTrue($this->em->contains($ana) || $this->em instanceof ChatKitEntityManager, 'the caller\'s user stays managed');
        $message = $this->get(ChatService::class)->send($ana, $conversation, 'shared a link');
        self::assertNotNull($message->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_participant WHERE conversation_id = ? AND user_id = ?', [$channel->getId(), $ana->getId()]));
    }
}
