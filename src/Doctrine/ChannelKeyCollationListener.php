<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Entity\ChatConversation;

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
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final readonly class ChannelKeyCollationListener
{
    /** The mapped field that gets the exact collation. */
    public const string FIELD = 'channelKey';

    /**
     * Builds the listener.
     *
     * @param ManagerRegistry $doctrine Finds the entity manager (and so the platform) of ChatConversation
     */
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
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
     * Sets the collation of `channel_key` while the schema of ChatConversation is generated.
     *
     * @param GenerateSchemaTableEventArgs $args Doctrine schema tool event
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== ChatConversation::class) {
            return;
        }
        $manager   = $this->doctrine->getManagerForClass(ChatConversation::class);
        $collation = $manager instanceof EntityManagerInterface ? self::collationFor($manager->getConnection()->getDatabasePlatform()) : null;
        if ($collation === null) {
            return;
        }

        /** @var non-empty-string $column mapped column names are never empty */
        $column = $metadata->getColumnName(self::FIELD);
        // Table::edit() exists from DBAL 4.5 and GenerateSchemaTableEventArgs::setClassTable() from ORM 3.6.
        // @phpstan-ignore function.alreadyNarrowedType
        $tableEditor = method_exists(Table::class, 'edit');
        // @phpstan-ignore function.alreadyNarrowedType
        $classTableSetter = method_exists($args, 'setClassTable');
        $edited           = self::collate($args->getClassTable(), $column, $collation, $tableEditor && $classTableSetter);
        if ($edited instanceof Table) {
            $args->setClassTable($edited);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL or ORM; returns null).
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

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
