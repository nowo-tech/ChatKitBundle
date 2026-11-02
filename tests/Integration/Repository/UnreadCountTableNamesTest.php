<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Repository;

use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Repository\ChatMessageRepository;
use Nowo\ChatKitBundle\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression (REQ-ORM-002): the native unread-count query reads the renamed tables, never the 1.x literal names.
 */
final class UnreadCountTableNamesTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function tableNamings(): iterable
    {
        yield 'defaults' => [[], 'nowo_chat_message', 'nowo_chat_participant'];
        yield 'table_name_prefix chat_' => [['table_name_prefix' => 'chat_'], 'chat_message', 'chat_participant'];
        yield 'table_prefix app_' => [['table_prefix' => 'app_'], 'app_nowo_chat_message', 'app_nowo_chat_participant'];
        yield 'both' => [['table_name_prefix' => 'chat_', 'table_prefix' => 'app_'], 'app_chat_message', 'app_chat_participant'];
    }

    /**
     * @param array<string, string> $doctrine `doctrine` options of the default profile
     */
    #[DataProvider('tableNamings')]
    public function testUnreadCountReadsTheConfiguredTables(array $doctrine, string $messageTable, string $participantTable): void
    {
        self::assertSame($messageTable, $this->em->getClassMetadata(ChatMessage::class)->getTableName());
        self::assertSame($participantTable, $this->em->getClassMetadata(ChatParticipant::class)->getTableName());
        $tables = $this->em->getConnection()->createSchemaManager()->listTableNames();
        self::assertContains($messageTable, $tables);
        if ($doctrine !== []) {
            self::assertNotContains('nowo_chat_message', $tables, 'only the renamed tables exist');
        }

        $ana  = $this->user('Ana');
        $luis = $this->user('Luis');
        $dm   = ChatConversation::direct($ana, $luis);
        $this->em->persist($dm);
        $first  = new ChatMessage($dm, $luis, 'one', '<p>one</p>');
        $second = new ChatMessage($dm, $luis, 'two', '<p>two</p>');
        $own    = new ChatMessage($dm, $ana, 'mine', '<p>mine</p>');
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist($own);
        $cursor = new ChatParticipant($dm, $ana);
        $this->em->persist($cursor);
        $this->em->flush();
        $cursor->markReadUpTo((int) $first->getId());
        $this->em->flush();

        $messages = $this->em->getRepository(ChatMessage::class);
        self::assertInstanceOf(ChatMessageRepository::class, $messages);
        self::assertSame([(int) $dm->getId() => 1], $messages->countUnreadByConversation((int) $ana->getId(), [(int) $dm->getId()]));
    }

    protected function chatKitConfig(): array
    {
        /** @var array<string, string> $doctrine */
        $doctrine = $this->providedData()[0] ?? [];

        return $doctrine === [] ? [] : ['profiles' => ['default' => ['doctrine' => $doctrine]]];
    }
}
