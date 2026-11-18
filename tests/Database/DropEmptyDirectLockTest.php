<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Service\ChatService;
use Nowo\ChatKitBundle\Tests\Database\Support\LoadDatabaseTestCase;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function implode;

/**
 * 1.36 (audit UB2-1): ChatService::dropEmptyDirect() — what a failed forward runs on the direct conversation it opened —
 * takes the conversation's row lock before anything else of its unit. A first message another session commits while the
 * drop waits for that lock is seen: the conversation and the message stay (on MySQL / MariaDB a plain read before the
 * lock fixed the REPEATABLE READ snapshot, and the message was deleted with the conversation).
 */
final class DropEmptyDirectLockTest extends LoadDatabaseTestCase
{
    public function testAFirstMessageCommittedWhileTheDropWaitsSurvives(): void
    {
        $ana    = $this->user('ana');
        $luis   = $this->user('luis');
        $bea    = $this->user('bea');
        $chat   = $this->get(ChatService::class);
        $seed   = $chat->send($ana, $chat->openDirect($ana, $luis), 'seed');
        $new    = $chat->openDirect($ana, $bea);
        $newId  = (int) $new->getId();
        $seedId = (int) $seed->getId();

        // The other session's first message: a copy of the seed row in the new conversation.
        $columns = array_values(array_filter(array_keys($this->connection()->createSchemaManager()->listTableColumns('nowo_chat_message')), static fn (string $column): bool => $column !== 'id'));
        $select  = array_map(static fn (string $column): string => match ($column) {
            'conversation_id' => (string) $newId,
            'uuid'            => "'01a1f09f-0000-7000-8000-00000000beef'",
            'body'            => "'first real message'",
            default           => $column,
        }, $columns);
        $insert = 'INSERT INTO nowo_chat_message (' . implode(', ', $columns) . ') SELECT ' . implode(', ', $select) . ' FROM nowo_chat_message WHERE id = ' . $seedId;
        $sleep  = $this->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SELECT SLEEP(2)' : 'SELECT pg_sleep(2)';

        // It holds the conversation's row (as a sender's unit does), then writes and commits while the drop waits.
        $other = $this->otherSession();
        self::assertSame('OK', $other->execute('START TRANSACTION'));
        self::assertSame('OK', $other->execute('SELECT id FROM nowo_chat_conversation WHERE id = ' . $newId . ' FOR UPDATE'));
        $other->send($sleep);
        $other->send($insert);
        $other->send('COMMIT');

        $this->em->clear();
        $conversation = $this->em->find(ChatConversation::class, $newId);
        self::assertInstanceOf(ChatConversation::class, $conversation);
        $this->em->detach($conversation);
        $dropped = $chat->dropEmptyDirect($conversation);

        self::assertSame(['OK', 'OK', 'OK'], [$other->await(), $other->await(), $other->await()]);
        self::assertFalse($dropped);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_conversation WHERE id = ?', [$newId]));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM nowo_chat_message WHERE conversation_id = ?', [$newId]));
    }
}
