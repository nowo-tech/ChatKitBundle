<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\ChatKitBundle\Entity\ChatMessage;

/**
 * 1.37 (registered only with `search.enabled` and `search.fulltext`): adds the FULLTEXT index of message search to the
 * schema Doctrine generates on MySQL / MariaDB, so `doctrine:migrations:diff` (or `doctrine:schema:update`) creates it
 * and never drops one a host built with `nowo:chat:search-index`. PostgreSQL's expression index is not in the generated
 * schema (DBAL cannot describe it) and is built with the command; other platforms get nothing. Stateless.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatSearchSchemaListener
{
    /**
     * @param string $tablePrefix `doctrine.table_prefix`
     * @param string $tableNamePrefix `doctrine.table_name_prefix`
     */
    public function __construct(
        private string $tablePrefix = '',
        private string $tableNamePrefix = 'nowo_chat_',
    ) {
    }

    /**
     * Adds the index to the message table of the generated schema (MySQL / MariaDB only).
     *
     * @param GenerateSchemaEventArgs $args The generated schema and its entity manager
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $entityManager = $args->getEntityManager();
        if (!$entityManager->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform || $entityManager->getMetadataFactory()->isTransient(ChatMessage::class)) {
            return;
        }
        $index  = new ChatSearchIndex($entityManager, ChatSearchIndex::DEFAULT_POSTGRES_CONFIG, $this->tablePrefix, $this->tableNamePrefix);
        $schema = $args->getSchema();
        if (!$schema->hasTable($index->table())) {
            return;
        }
        $table = $schema->getTable($index->table());
        if (!$table->hasIndex($index->name())) {
            $table->addIndex([$index->column()], $index->name(), ['fulltext']);
        }
    }
}
