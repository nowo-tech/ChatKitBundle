<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Unit\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Support\ChatTicketNumbers;
use Nowo\ChatKitBundle\Tests\Support\Entity\TestUser;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A number taken by a concurrent draft (unique key) is retried; after five collisions the generator gives up.
 */
final class ChatTicketNumbersTest extends TestCase
{
    public function testACollisionIsRetried(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturnOnConsecutiveCalls(self::throwException($this->collision()), 1);
        $connection->expects(self::once())->method('fetchOne')->willReturn('7');

        self::assertSame(7, (new ChatTicketNumbers($this->entityManager($connection)))->assign($this->draft()));
    }

    public function testItGivesUpAfterFiveCollisions(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(5))->method('executeStatement')->willThrowException($this->collision());

        $this->expectException(LogicException::class);
        (new ChatTicketNumbers($this->entityManager($connection)))->assign($this->draft());
    }

    private function entityManager(Connection $connection): EntityManagerInterface
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('nowo_chat_conversation');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        return $entityManager;
    }

    private function draft(): ChatConversation
    {
        $draft = ChatConversation::support(new TestUser('Ana', null));
        (new ReflectionProperty(ChatConversation::class, 'id'))->setValue($draft, 3);

        return $draft;
    }

    private function collision(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(PdoDriverException::new(new PDOException('duplicate')), null);
    }
}
