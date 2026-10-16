<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

/**
 * Prepends `doctrine.table_prefix` to the bundle's own tables, indexes and unique constraints (REQ-ORM-002), so several
 * applications can share one database. Only entities of this bundle are touched; an empty prefix changes nothing.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final readonly class TablePrefixListener
{
    private const string NAMESPACE = 'Nowo\\ChatKitBundle\\Entity\\';

    /**
     * Builds the listener from the configured prefix.
     *
     * @param string $prefix Table prefix (letters, digits and underscores; empty for none)
     */
    public function __construct(
        private string $prefix = '',
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
        if ($this->prefix === '' || !str_starts_with($metadata->getName(), self::NAMESPACE) || $metadata->isMappedSuperclass) {
            return;
        }

        $table         = $metadata->table;
        $table['name'] = $this->prefix . $metadata->getTableName();
        foreach (['indexes', 'uniqueConstraints'] as $kind) {
            if (!isset($table[$kind])) {
                continue;
            }
            $renamed = [];
            foreach ($table[$kind] as $name => $definition) {
                $renamed[$this->prefix . $name] = $definition;
            }
            $table[$kind] = $renamed;
        }
        $metadata->setPrimaryTable($table);
    }
}
