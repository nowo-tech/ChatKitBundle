<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\ChatKitBundle\Entity\ChatMessage;

/**
 * 1.37: adds the FULLTEXT index of message search to the schema Doctrine generates on MySQL / MariaDB, so
 * `doctrine:migrations:diff` (or `doctrine:schema:update`) creates it and never drops it:
 *
 * - with `search.enabled` and `search.fulltext` the index is always part of the schema (a diff creates it);
 * - otherwise (audit VA-4) it is part of the schema only while it exists in the database, so turning search or the
 *   full-text option off never makes the next diff drop an index a host built, and a host that never used search never
 *   gets one.
 *
 * Always registered: the two options are read at run time (an `%env()%` value is resolved by then). PostgreSQL's
 * expression index is not in the generated schema (DBAL cannot describe it, so a diff never drops it either) and is
 * built with `nowo:chat:search-index`; other platforms get nothing. Stateless.
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
     * @param bool $enabled `search.enabled`
     * @param bool $fulltext `search.fulltext`
     */
    public function __construct(
        private string $tablePrefix = '',
        private string $tableNamePrefix = 'nowo_chat_',
        private bool $enabled = false,
        private bool $fulltext = true,
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
        if (!$table->hasIndex($index->name()) && (($this->enabled && $this->fulltext) || $index->state() === ChatSearchIndex::READY)) {
            $table->addIndex([$index->column()], $index->name(), ['fulltext']);
        }
    }
}
