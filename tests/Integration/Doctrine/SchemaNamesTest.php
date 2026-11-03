<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_keys;
use function array_values;
use function assert;
use function implode;
use function ksort;
use function max;
use function str_repeat;
use function str_starts_with;
use function strlen;

/**
 * Golden schema names (REQ-ORM-002): with the default `doctrine.table_name_prefix` every table, index, unique and
 * foreign-key name must stay byte-identical to the 1.x names; a custom prefix must reach every derived name.
 */
final class SchemaNamesTest extends TestCase
{
    public function testDefaultNamesAreUnchanged(): void
    {
        self::assertSame(self::DEFAULT_NAMES, $this->schemaNames([]));
    }

    public function testExplicitDefaultsAreUnchanged(): void
    {
        self::assertSame(self::DEFAULT_NAMES, $this->schemaNames(['table_name_prefix' => 'nowo_chat_', 'table_prefix' => '']));
    }

    public function testTableNamePrefixReachesEveryDerivedName(): void
    {
        self::assertSame(self::CHAT_NAMES, $this->schemaNames(['table_name_prefix' => 'chat_']));
    }

    public function testTableMapMatchesTheMappedTables(): void
    {
        self::assertSame(array_keys(self::DEFAULT_NAMES), array_values(TablePrefixListener::TABLES));
    }

    public function testLongestAllowedPrefixKeepsEveryNameWithinTheIdentifierLimit(): void
    {
        $prefix = 'c' . str_repeat('x', 37);
        TablePrefixListener::validate('', $prefix);

        $longest = 0;
        foreach ($this->schemaNames(['table_name_prefix' => $prefix]) as $table => $names) {
            $longest = max($longest, strlen($table . '_id_seq'));
            foreach ([...array_keys($names['indexes']), ...array_keys($names['unique']), ...array_keys($names['fk'])] as $name) {
                $longest = max($longest, strlen($name));
            }
        }
        self::assertSame(TablePrefixListener::MAX_IDENTIFIER_LENGTH, $longest, 'the budget is exact: one more character would overflow');
    }

    public function testTablePrefixIsPrependedAfterTheNamePrefix(): void
    {
        $names = $this->schemaNames(['table_name_prefix' => 'chat_', 'table_prefix' => 'app_']);

        self::assertArrayHasKey('app_chat_message', $names);
        self::assertSame('created_at', $names['app_chat_message']['indexes']['app_idx_chat_message_created']);
        self::assertSame('uuid', $names['app_chat_message']['unique']['app_uniq_chat_message_uuid']);
    }

    /**
     * Builds the bundle part of the schema the way `doctrine:schema:update` does and lists its names.
     *
     * @param array<string, string> $doctrine `nowo_chat_kit.profiles.default.doctrine` options
     *
     * @return array<string, array{indexes: array<string, string>, unique: array<string, string>, fk: array<string, string>}>
     */
    private function schemaNames(array $doctrine): array
    {
        $kernel = new TestKernel($doctrine === [] ? [] : ['profiles' => ['default' => ['doctrine' => $doctrine]]]);
        $kernel->boot();
        $em = $kernel->getContainer()->get(TestKernel::ENTITY_MANAGER_ALIAS);
        assert($em instanceof EntityManagerInterface);
        $metadata = array_filter(
            $em->getMetadataFactory()->getAllMetadata(),
            static fn (ClassMetadata $class): bool => str_starts_with($class->getName(), 'Nowo\\ChatKitBundle\\Entity\\'),
        );
        $schema = (new SchemaTool($em))->getSchemaFromMetadata(array_values($metadata));
        $kernel->shutdown();

        $names = [];
        foreach ($schema->getTables() as $table) {
            if ($table->getName() === 'test_user') {
                continue;
            }
            $entry = ['indexes' => [], 'unique' => [], 'fk' => []];
            foreach ($table->getIndexes() as $index) {
                if (!$index->isPrimary()) {
                    $entry[$index->isUnique() ? 'unique' : 'indexes'][$index->getName()] = implode(',', $index->getColumns());
                }
            }
            foreach ($table->getForeignKeys() as $foreignKey) {
                $entry['fk'][$foreignKey->getName()] = implode(',', $foreignKey->getLocalColumns()) . ' -> ' . $foreignKey->getForeignTableName();
            }
            ksort($entry['indexes']);
            ksort($entry['unique']);
            ksort($entry['fk']);
            $names[$table->getName()] = $entry;
        }
        ksort($names);

        return $names;
    }

    private const array DEFAULT_NAMES = [
        'nowo_chat_conversation' => [
            'indexes' => [
                'IDX_D7385CFF6713A32B'                => 'resolved_by_id',
                'IDX_D7385CFFCA8AEAC6'                => 'support_owner_id',
                'idx_nowo_chat_conversation_activity' => 'kind,last_message_at',
                'idx_nowo_chat_conversation_assignee' => 'assignee_id',
                'idx_nowo_chat_conversation_support'  => 'support_owner_id,last_message_at',
            ],
            'unique' => [
                'uniq_nowo_chat_conversation_channel' => 'channel_key',
                'uniq_nowo_chat_conversation_direct'  => 'direct_key',
                'uniq_nowo_chat_conversation_ticket'  => 'ticket_number',
                'uniq_nowo_chat_conversation_uuid'    => 'uuid',
            ],
            'fk' => [
                'FK_D7385CFF59EC7D60' => 'assignee_id -> test_user',
                'FK_D7385CFF6713A32B' => 'resolved_by_id -> test_user',
                'FK_D7385CFFCA8AEAC6' => 'support_owner_id -> test_user',
            ],
        ],
        'nowo_chat_message' => [
            'indexes' => [
                'IDX_A40D82CC59662AC1'          => 'pinned_by_id',
                'IDX_A40D82CC9AC0396'           => 'conversation_id',
                'IDX_A40D82CCC76F1F52'          => 'deleted_by_id',
                'IDX_A40D82CCF675F31B'          => 'author_id',
                'idx_nowo_chat_message_created' => 'created_at',
                'idx_nowo_chat_message_unread'  => 'conversation_id,id,author_id,deleted_at',
            ],
            'unique' => [
                'uniq_nowo_chat_message_uuid' => 'uuid',
            ],
            'fk' => [
                'FK_A40D82CC59662AC1' => 'pinned_by_id -> test_user',
                'FK_A40D82CC9AC0396'  => 'conversation_id -> nowo_chat_conversation',
                'FK_A40D82CCC76F1F52' => 'deleted_by_id -> test_user',
                'FK_A40D82CCF675F31B' => 'author_id -> test_user',
            ],
        ],
        'nowo_chat_message_mention' => [
            'indexes' => [
                'IDX_9DF33B9B537A1329'       => 'message_id',
                'IDX_9DF33B9BA76ED395'       => 'user_id',
                'idx_nowo_chat_mention_user' => 'user_id,read_at,created_at',
            ],
            'unique' => [
                'uniq_nowo_chat_mention' => 'message_id,user_id',
            ],
            'fk' => [
                'FK_9DF33B9B537A1329' => 'message_id -> nowo_chat_message',
                'FK_9DF33B9BA76ED395' => 'user_id -> test_user',
            ],
        ],
        'nowo_chat_participant' => [
            'indexes' => [
                'IDX_9FE7FEB9AC0396'             => 'conversation_id',
                'idx_nowo_chat_participant_user' => 'user_id',
            ],
            'unique' => [
                'uniq_nowo_chat_participant' => 'conversation_id,user_id',
            ],
            'fk' => [
                'FK_9FE7FEB9AC0396'  => 'conversation_id -> nowo_chat_conversation',
                'FK_9FE7FEBA76ED395' => 'user_id -> test_user',
            ],
        ],
        'nowo_chat_quick_reply' => [
            'indexes' => [
                'idx_nowo_chat_quick_reply_user' => 'user_id',
            ],
            'unique' => [],
            'fk'     => [
                'FK_7B6D659EA76ED395' => 'user_id -> test_user',
            ],
        ],
        'nowo_chat_reaction' => [
            'indexes' => [
                'IDX_F6AD5573537A1329' => 'message_id',
                'IDX_F6AD5573A76ED395' => 'user_id',
            ],
            'unique' => [
                'uniq_nowo_chat_reaction' => 'message_id,user_id,emoji',
            ],
            'fk' => [
                'FK_F6AD5573537A1329' => 'message_id -> nowo_chat_message',
                'FK_F6AD5573A76ED395' => 'user_id -> test_user',
            ],
        ],
        'nowo_chat_user_settings' => [
            'indexes' => [],
            'unique'  => [
                'uniq_nowo_chat_user_settings_user' => 'user_id',
            ],
            'fk' => [
                'FK_F14147EEA76ED395' => 'user_id -> test_user',
            ],
        ],
    ];

    private const array CHAT_NAMES = [
        'chat_conversation' => [
            'indexes' => [
                'IDX_74654F686713A32B'           => 'resolved_by_id',
                'IDX_74654F68CA8AEAC6'           => 'support_owner_id',
                'idx_chat_conversation_activity' => 'kind,last_message_at',
                'idx_chat_conversation_assignee' => 'assignee_id',
                'idx_chat_conversation_support'  => 'support_owner_id,last_message_at',
            ],
            'unique' => [
                'uniq_chat_conversation_channel' => 'channel_key',
                'uniq_chat_conversation_direct'  => 'direct_key',
                'uniq_chat_conversation_ticket'  => 'ticket_number',
                'uniq_chat_conversation_uuid'    => 'uuid',
            ],
            'fk' => [
                'FK_74654F6859EC7D60' => 'assignee_id -> test_user',
                'FK_74654F686713A32B' => 'resolved_by_id -> test_user',
                'FK_74654F68CA8AEAC6' => 'support_owner_id -> test_user',
            ],
        ],
        'chat_message' => [
            'indexes' => [
                'IDX_FAB3FC1659662AC1'     => 'pinned_by_id',
                'IDX_FAB3FC169AC0396'      => 'conversation_id',
                'IDX_FAB3FC16C76F1F52'     => 'deleted_by_id',
                'IDX_FAB3FC16F675F31B'     => 'author_id',
                'idx_chat_message_created' => 'created_at',
                'idx_chat_message_unread'  => 'conversation_id,id,author_id,deleted_at',
            ],
            'unique' => [
                'uniq_chat_message_uuid' => 'uuid',
            ],
            'fk' => [
                'FK_FAB3FC1659662AC1' => 'pinned_by_id -> test_user',
                'FK_FAB3FC169AC0396'  => 'conversation_id -> chat_conversation',
                'FK_FAB3FC16C76F1F52' => 'deleted_by_id -> test_user',
                'FK_FAB3FC16F675F31B' => 'author_id -> test_user',
            ],
        ],
        'chat_message_mention' => [
            'indexes' => [
                'IDX_119AF996537A1329'  => 'message_id',
                'IDX_119AF996A76ED395'  => 'user_id',
                'idx_chat_mention_user' => 'user_id,read_at,created_at',
            ],
            'unique' => [
                'uniq_chat_mention' => 'message_id,user_id',
            ],
            'fk' => [
                'FK_119AF996537A1329' => 'message_id -> chat_message',
                'FK_119AF996A76ED395' => 'user_id -> test_user',
            ],
        ],
        'chat_participant' => [
            'indexes' => [
                'IDX_E8ED9C899AC0396'       => 'conversation_id',
                'idx_chat_participant_user' => 'user_id',
            ],
            'unique' => [
                'uniq_chat_participant' => 'conversation_id,user_id',
            ],
            'fk' => [
                'FK_E8ED9C899AC0396'  => 'conversation_id -> chat_conversation',
                'FK_E8ED9C89A76ED395' => 'user_id -> test_user',
            ],
        ],
        'chat_quick_reply' => [
            'indexes' => [
                'idx_chat_quick_reply_user' => 'user_id',
            ],
            'unique' => [],
            'fk'     => [
                'FK_9A7E86FCA76ED395' => 'user_id -> test_user',
            ],
        ],
        'chat_reaction' => [
            'indexes' => [
                'IDX_90F5D0C7537A1329' => 'message_id',
                'IDX_90F5D0C7A76ED395' => 'user_id',
            ],
            'unique' => [
                'uniq_chat_reaction' => 'message_id,user_id,emoji',
            ],
            'fk' => [
                'FK_90F5D0C7537A1329' => 'message_id -> chat_message',
                'FK_90F5D0C7A76ED395' => 'user_id -> test_user',
            ],
        ],
        'chat_user_settings' => [
            'indexes' => [],
            'unique'  => [
                'uniq_chat_user_settings_user' => 'user_id',
            ],
            'fk' => [
                'FK_9F891C1AA76ED395' => 'user_id -> test_user',
            ],
        ],
    ];
}
