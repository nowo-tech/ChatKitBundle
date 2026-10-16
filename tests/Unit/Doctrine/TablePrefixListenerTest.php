<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    public function testPrefixesBundleTablesIndexesAndConstraintsOnly(): void
    {
        $message = $this->metadata(ChatMessage::class, 'nowo_chat_message');
        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($message));
        self::assertSame('app_nowo_chat_message', $message->getTableName());
        self::assertSame(['app_idx_message'], array_keys($message->table['indexes'] ?? []));
        self::assertSame(['app_uniq_message'], array_keys($message->table['uniqueConstraints'] ?? []));

        $host = $this->metadata(stdClass::class, 'user');
        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($host));
        self::assertSame('user', $host->getTableName(), 'host entities are never renamed');

        $plain = $this->metadata(ChatMessage::class, 'nowo_chat_message');
        (new TablePrefixListener())->loadClassMetadata($this->event($plain));
        self::assertSame('nowo_chat_message', $plain->getTableName(), 'empty prefix: unchanged');
        self::assertSame(['idx_message'], array_keys($plain->table['indexes'] ?? []));

        $bare = new ClassMetadata(ChatMessage::class);
        $bare->setPrimaryTable(['name' => 'nowo_chat_message']);
        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($bare));
        self::assertSame('app_nowo_chat_message', $bare->getTableName(), 'no indexes to rename');
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, string $table): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name'              => $table,
            'indexes'           => ['idx_message' => ['columns' => ['created_at']]],
            'uniqueConstraints' => ['uniq_message' => ['columns' => ['uuid']]],
        ]);

        return $metadata;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function event(ClassMetadata $metadata): LoadClassMetadataEventArgs
    {
        return new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));
    }
}
