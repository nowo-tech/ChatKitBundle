<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

use function str_starts_with;
use function strlen;
use function strpos;
use function substr_replace;

/**
 * Applies the configurable naming of the bundle's own tables, indexes and unique constraints (REQ-ORM-002).
 *
 * `doctrine.table_name_prefix` replaces the `nowo_chat_` part of every name (`chat_` gives chat_message and
 * idx_chat_message_created); the optional `doctrine.table_prefix` is then prepended to the result (`app_` gives
 * app_nowo_chat_message and app_idx_nowo_chat_message_created). Only entities of this bundle are touched; with both
 * defaults every name is left exactly as mapped.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final readonly class TablePrefixListener
{
    /** The name prefix every table, index and unique constraint of the bundle is mapped with. */
    public const string DEFAULT_TABLE_NAME_PREFIX = 'nowo_chat_';

    private const string NAMESPACE = 'Nowo\\ChatKitBundle\\Entity\\';

    /**
     * Builds the listener from the configured prefixes.
     *
     * @param string $prefix Optional prefix prepended to every name (letters, digits and underscores; empty for none)
     * @param string $tableNamePrefix Replaces `nowo_chat_` in every table, index and unique-constraint name
     */
    public function __construct(
        private string $prefix = '',
        private string $tableNamePrefix = self::DEFAULT_TABLE_NAME_PREFIX,
    ) {
    }

    /**
     * Rewrites the table, index and unique-constraint names of a bundle entity while its metadata loads.
     *
     * @param LoadClassMetadataEventArgs $event Doctrine metadata event
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (($this->prefix === '' && $this->tableNamePrefix === self::DEFAULT_TABLE_NAME_PREFIX)
            || !str_starts_with($metadata->getName(), self::NAMESPACE)
            || $metadata->isMappedSuperclass) {
            return;
        }

        $table         = $metadata->table;
        $table['name'] = $this->rename($metadata->getTableName());
        foreach (['indexes', 'uniqueConstraints'] as $kind) {
            if (!isset($table[$kind])) {
                continue;
            }
            $renamed = [];
            foreach ($table[$kind] as $name => $definition) {
                $renamed[$this->rename((string) $name)] = $definition;
            }
            $table[$kind] = $renamed;
        }
        $metadata->setPrimaryTable($table);
    }

    /**
     * Swaps the first `nowo_chat_` of a mapped name for the configured name prefix, then prepends the optional prefix.
     *
     * @param string $name Table, index or unique-constraint name as mapped on the entity
     */
    private function rename(string $name): string
    {
        $position = strpos($name, self::DEFAULT_TABLE_NAME_PREFIX);
        if ($position !== false) {
            $name = substr_replace($name, $this->tableNamePrefix, $position, strlen(self::DEFAULT_TABLE_NAME_PREFIX));
        }

        return $this->prefix . $name;
    }
}
