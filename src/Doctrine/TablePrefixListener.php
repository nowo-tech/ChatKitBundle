<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use InvalidArgumentException;

use function max;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

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

    /**
     * Short key => mapped (default) name of every bundle table; the extension publishes the resolved names as the
     * `nowo_chat_kit.doctrine.tables` map and one `nowo_chat_kit.doctrine.table.<key>` parameter per table.
     */
    public const array TABLES = [
        'conversation'      => 'nowo_chat_conversation',
        'conversation_link' => 'nowo_chat_conversation_link',
        'message'           => 'nowo_chat_message',
        'message_link'      => 'nowo_chat_message_link',
        'message_mention'   => 'nowo_chat_message_mention',
        'participant'       => 'nowo_chat_participant',
        'quick_reply'       => 'nowo_chat_quick_reply',
        'reaction'          => 'nowo_chat_reaction',
        'user_settings'     => 'nowo_chat_user_settings',
    ];

    /** Longest identifier PostgreSQL keeps (MySQL allows 64): every generated name must fit. */
    public const int MAX_IDENTIFIER_LENGTH = 63;

    /** Allowed prefix: lowercase letter first, then lowercase letters, digits and underscores (no trailing newline). */
    public const string PREFIX_PATTERN = '/^[a-z][a-z0-9_]*\z/';

    /** Longest mapped name once `DEFAULT_TABLE_NAME_PREFIX` is removed: uniq_nowo_chat_conversation_channel without `nowo_chat_`. */
    private const int LONGEST_NAME_REST = 25;

    /** Longest named index without `DEFAULT_TABLE_NAME_PREFIX` (only `table_prefix` lengthens it): every named index contains `nowo_chat_`. */
    private const int LONGEST_FIXED_NAME = 0;

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
        self::validate($this->prefix, $this->tableNamePrefix);
    }

    /**
     * Rejects prefixes that are not lowercase identifiers or that make a generated name longer than 63 characters.
     * Run by the extension for literal values and by the constructor for resolved `%env()%` values.
     *
     * @param string $tablePrefix Optional prefix prepended to every name (empty for none)
     * @param string $tableNamePrefix Replacement of `DEFAULT_TABLE_NAME_PREFIX`
     *
     * @throws InvalidArgumentException when a prefix is malformed or a name would be too long
     */
    public static function validate(string $tablePrefix, string $tableNamePrefix): void
    {
        if (preg_match(self::PREFIX_PATTERN, $tableNamePrefix) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid doctrine.table_name_prefix "%s": use a lowercase letter, then lowercase letters, digits and underscores.', $tableNamePrefix));
        }
        if ($tablePrefix !== '' && preg_match(self::PREFIX_PATTERN, $tablePrefix) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid doctrine.table_prefix "%s": use a lowercase letter, then lowercase letters, digits and underscores (or empty).', $tablePrefix));
        }
        $longest = max(strlen($tablePrefix) + strlen($tableNamePrefix) + self::LONGEST_NAME_REST, strlen($tablePrefix) + self::LONGEST_FIXED_NAME);
        if ($longest > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(sprintf('doctrine.table_prefix "%s" + doctrine.table_name_prefix "%s" make a table, index or sequence name of %d characters; the limit is %d.', $tablePrefix, $tableNamePrefix, $longest, self::MAX_IDENTIFIER_LENGTH));
        }
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
        return self::physicalName($name, $this->prefix, $this->tableNamePrefix);
    }

    /**
     * Physical name of a mapped table, index or unique-constraint name under the given prefixes (also used for
     * `%env()%` placeholders, which stay intact because only the mapped name is cut).
     *
     * @param string $name Name as mapped on the entity (e.g. `nowo_chat_message`)
     * @param string $tablePrefix Optional prefix prepended to the result
     * @param string $tableNamePrefix Replacement of `nowo_chat_`
     */
    public static function physicalName(string $name, string $tablePrefix, string $tableNamePrefix): string
    {
        $position = strpos($name, self::DEFAULT_TABLE_NAME_PREFIX);
        if ($position !== false) {
            $name = substr($name, 0, $position) . $tableNamePrefix . substr($name, $position + strlen(self::DEFAULT_TABLE_NAME_PREFIX));
        }

        return $tablePrefix . $name;
    }
}
