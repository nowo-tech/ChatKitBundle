<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;

use function array_keys;
use function array_unique;
use function method_exists;

/**
 * Exact-match collation for `nowo_chat_conversation.channel_key` on MySQL / MariaDB (house rule LQ, look-alike keys).
 *
 * The default MySQL / MariaDB collations fold case and accents (`utf8mb4_0900_ai_ci`, `utf8mb4_uca1400_ai_ci`,
 * `utf8mb4_unicode_ci`, `utf8mb4_general_ci`) and most of them are PAD SPACE, so `project:Acme`, `project:acme` and
 * `project:acme ` were one channel. The repositories match every row again with `===`; this collation keeps the
 * database itself exact, so look-alike keys are distinct rows under the unique key: MariaDB `utf8mb4_nopad_bin`, MySQL
 * 8.0+ `utf8mb4_0900_bin` (both NO PAD), MySQL 5.7 `utf8mb4_bin` (PAD SPACE, but ChatChannelKey refuses trailing
 * whitespace). PostgreSQL and SQLite compare exactly and are untouched. Applied to the generated schema (schema tool,
 * `doctrine:migrations:diff`); an existing table needs the `ALTER TABLE` in UPGRADING.md. Stateless (REQ-RUNTIME-002).
 *
 * 1.27 (REQ-SEC-006 item 3): with `doctrine.user_reference: string` every user reference column of the bundle's tables
 * (`author_id`, `user_id`, `support_owner_id`, … and `direct_user_low` / `direct_user_high`) gets the same collation,
 * so string references (ChatUserReferenceInterface) such as `Bob`, `bob` and `bob ` are different users in the database.
 *
 * 1.40.1 (FK loss): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * 1.26.4 to 1.40.0 replaced the table on `postGenerateSchemaTable` (`setClassTable()`, doctrine/dbal 4.5+), and the
 * doctrine/orm 3.7 / 3.8 schema tool then added the foreign keys it defers to the table it had replaced: on MySQL /
 * MariaDB the generated schema lost every foreign key starting on a collated table (and its index) —
 * `nowo_chat_conversation`'s user foreign keys and, in `user_reference: string` mode, the message, mention, reaction,
 * participant… foreign keys too (see UPGRADING.md "To 1.40.1"). Now:
 *
 * - doctrine/dbal 4.5+ with doctrine/orm 3.6+: each table is rebuilt with its table editor (foreign keys and indexes
 *   included) and swapped into a new schema given back with `GenerateSchemaEventArgs::setSchema()`;
 * - older doctrine/dbal or doctrine/orm: the columns' platform options are set in place (on doctrine/dbal 4.5+ the
 *   setter's deprecation is silenced).
 *
 * The listener never calls `setClassTable()`. It skips an entity manager that does not map the bundle entities and
 * reads the platform from the schema tool's own connection.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class ChannelKeyCollationListener
{
    /** The mapped field that gets the exact collation. */
    public const string FIELD = 'channelKey';

    /**
     * Builds the listener.
     *
     * @param ManagerRegistry|null $doctrine Unused since 1.40.1 (the platform comes from the schema tool's entity manager); kept for BC
     * @param ChatUserReferences $refs 1.27: in `doctrine.user_reference: string` the user reference columns get the collation too
     */
    public function __construct(
        ?ManagerRegistry $doctrine = null,
        private ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        unset($doctrine);
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact.
     *
     * @param AbstractPlatform $platform The database platform
     *
     * @return non-empty-string|null The collation, or null for none
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default                                    => null,
        };
    }

    /**
     * Gives `channel_key` (and, in `string` mode, the user reference columns) their exact-match collation in the
     * generated schema, keeping every foreign key and index of the tables.
     *
     * @param GenerateSchemaEventArgs $args Doctrine schema tool event
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        $factory = $manager->getMetadataFactory();
        if ($factory->isTransient(ChatConversation::class)) {
            return;
        }
        $collation = self::collationFor($manager->getConnection()->getDatabasePlatform());
        if ($collation === null) {
            return;
        }

        $schema = $args->getSchema();
        // Table::edit() and Schema::edit() exist from doctrine/dbal 4.5, GenerateSchemaEventArgs::setSchema() from
        // doctrine/orm 3.6.
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType, function.alreadyNarrowedType
        $editor = method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit') && method_exists($args, 'setSchema');
        /** @var array<non-empty-string, Table> $edited */
        $edited  = [];
        $classes = [ChatConversation::class, ...array_keys(UserReferenceListener::RELATIONS)];
        foreach (array_unique($classes) as $class) {
            $fields = self::fieldsOf($class, $this->refs->isStringMode());
            if ($fields === [] || $factory->isTransient($class)) {
                continue;
            }
            $metadata  = $manager->getClassMetadata($class);
            $tableName = $metadata->getTableName();
            if ($tableName === '' || !$schema->hasTable($tableName)) {
                continue;
            }
            $original = $table = $edited[$tableName] ?? $schema->getTable($tableName);
            foreach ($fields as $field) {
                if (!$metadata->hasField($field)) {
                    continue;
                }
                /** @var non-empty-string $column mapped column names are never empty */
                $column = $metadata->getColumnName($field);
                $table  = self::collate($table, $column, $collation, $editor) ?? $table;
            }
            if ($table !== $original) {
                $edited[$tableName] = $table;
            }
        }
        if ($edited === []) {
            return;
        }

        $schemaEditor = $schema->edit();
        foreach ($edited as $tableName => $table) {
            $schemaEditor->dropTableByUnquotedName($tableName)->addTable($table);
        }
        $args->setSchema($schemaEditor->create());
    }

    /**
     * The fields of a bundle entity that get the exact collation: `channelKey`; 1.27, in `doctrine.user_reference: id`,
     * every user reference column (REQ-SEC-006 item 3: string references are compared byte for byte).
     *
     * @param string $class The entity class
     * @param bool $idMode Whether users are keyed by string references (`string` mode)
     *
     * @return list<string> The mapped field names
     */
    public static function fieldsOf(string $class, bool $idMode): array
    {
        $fields = $class === ChatConversation::class ? [self::FIELD] : [];
        if ($idMode) {
            foreach (array_keys(UserReferenceListener::RELATIONS[$class] ?? []) as $relation) {
                $fields[] = $relation . 'Ref';
            }
            foreach (array_keys(UserReferenceListener::EXTRA_FIELDS[$class] ?? []) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL or ORM; returns null). Since 1.40.1 only called on postGenerateSchema.
     *
     * @param Table $table The generated table
     * @param non-empty-string $column Column name
     * @param non-empty-string $collation Collation name
     * @param bool $editor Whether the table editor API is available
     *
     * @return Table|null The new table, or null when the table was changed in place
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        // In place: doctrine/dbal < 4.5 or doctrine/orm < 3.6; @ silences the setter's deprecation on doctrine/dbal 4.5+.
        @$table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
