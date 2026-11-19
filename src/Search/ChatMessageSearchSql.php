<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Search;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ChatKitBundle\Entity\ChatConversation;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Entity\ChatMessageLink;
use Nowo\ChatKitBundle\Entity\ChatParticipant;
use Nowo\ChatKitBundle\Enum\ConversationKind;

use function array_map;
use function implode;
use function intval;
use function mb_strlen;
use function preg_match;
use function strtr;

/**
 * 1.37: the SQL of message search (DBAL, one statement): the permission filter, the retention window and the text
 * match run together, before the limit, so a hidden row is never read, counted or ranked.
 *
 * Text match per platform (FR-002): MySQL / MariaDB `MATCH (search_text) AGAINST ('+word* …' IN BOOLEAN MODE)` for words of
 * letters and digits at least `$mysqlMinTokenLength` long; PostgreSQL `to_tsvector('<config>', search_text) @@
 * to_tsquery('<config>', 'word:* & …')` for words of letters and digits; any other word, platform, or `$fulltext: false`
 * a lower-case `LIKE` with `!`-escaped wildcards. Only letters and digits ever reach a full-text operator, so no
 * operator of the user's text reaches the server. Stateless (FrankenPHP worker mode).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ChatMessageSearchSql
{
    /** The message kinds people write (system, bot, buzz and pin lines are never found). */
    public const array KINDS = [ChatMessage::KIND_TEXT, ChatMessage::KIND_AUTO];

    /** A word a full-text operator may receive: letters and digits only. */
    private const string PLAIN_WORD = '/^[\p{L}\p{N}]+$/uD';

    /**
     * @param EntityManagerInterface $entityManager ChatKit's manager (the private one when configured)
     * @param bool $fulltext Whether to use the database full-text operators (`search.fulltext`)
     * @param string $postgresConfig PostgreSQL text search configuration (`search.postgresql_config`)
     * @param int $mysqlMinTokenLength `innodb_ft_min_token_size` of the server (`search.mysql_min_token_length`)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private bool $fulltext = true,
        private string $postgresConfig = ChatSearchIndex::DEFAULT_POSTGRES_CONFIG,
        private int $mysqlMinTokenLength = 3,
    ) {
        ChatSearchIndex::assertPostgresConfig($this->postgresConfig);
    }

    /**
     * Ids of matching messages the scope may read, newest first.
     *
     * @param ChatSearchScope $scope What the viewer may read now
     * @param ChatMessageSearchQuery $query Text and filters
     * @param int|string|null $author SQL value of the author filter (null: any author)
     * @param DateTimeImmutable $cutoff Retention start: older messages (and replies to older roots) are left out
     * @param int|null $beforeId Only messages with a smaller id (keyset)
     * @param int $limit Maximum ids
     *
     * @return list<int> The ids
     */
    public function candidateIds(ChatSearchScope $scope, ChatMessageSearchQuery $query, int|string|null $author, DateTimeImmutable $cutoff, ?int $beforeId, int $limit): array
    {
        $qb = new QueryBuilder($this->entityManager->getConnection());
        if ($query->words === [] || !$this->visible($qb, $scope)) {
            return [];
        }
        $messages = $this->meta(ChatMessage::class);
        $qb->select('m.' . $messages->getSingleIdentifierColumnName())
            ->leftJoin('m', $messages->getTableName(), 'r', 'r.' . $messages->getSingleIdentifierColumnName() . ' = m.' . $messages->getSingleAssociationJoinColumnName('threadRoot'))
            ->andWhere('m.' . $messages->getColumnName('kind') . ' IN (:kinds)')
            ->andWhere('m.' . $messages->getColumnName('deletedAt') . ' IS NULL')
            ->andWhere('m.' . $messages->getColumnName('createdAt') . ' >= :cutoff')
            ->andWhere('(m.' . $messages->getSingleAssociationJoinColumnName('threadRoot') . ' IS NULL OR r.' . $messages->getColumnName('createdAt') . ' >= :cutoff)')
            ->setParameter('kinds', self::KINDS, ArrayParameterType::STRING)
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->orderBy('m.' . $messages->getSingleIdentifierColumnName(), 'DESC')
            ->setMaxResults($limit);
        if ($query->conversation !== null) {
            $qb->andWhere('c.' . $this->meta(ChatConversation::class)->getColumnName('uuid') . ' = :conversation')->setParameter('conversation', $query->conversation);
        }
        if ($author !== null) {
            $qb->andWhere('m.' . $this->userColumn($messages, 'author') . ' = :author')->setParameter('author', $author);
        }
        if ($query->after instanceof DateTimeImmutable) {
            $qb->andWhere('m.' . $messages->getColumnName('createdAt') . ' >= :after')->setParameter('after', $query->after, Types::DATETIME_IMMUTABLE);
        }
        if ($query->before instanceof DateTimeImmutable) {
            $qb->andWhere('m.' . $messages->getColumnName('createdAt') . ' < :before')->setParameter('before', $query->before, Types::DATETIME_IMMUTABLE);
        }
        if ($query->hasLink) {
            $links = $this->meta(ChatMessageLink::class);
            $qb->andWhere('EXISTS (SELECT 1 FROM ' . $links->getTableName() . ' ml WHERE ml.' . $links->getSingleAssociationJoinColumnName('message') . ' = m.' . $messages->getSingleIdentifierColumnName() . ')');
        }
        if ($beforeId !== null) {
            $qb->andWhere('m.' . $messages->getSingleIdentifierColumnName() . ' < :beforeId')->setParameter('beforeId', $beforeId);
        }
        // Audit VA2-1: the viewer-neutral search text, never the raw body (a NULL, not yet backfilled row never matches).
        if (!$this->match($qb, 'm.' . $messages->getColumnName('searchText'), $query->words)) {
            // Full-text platforms only (audit VA-7): covered by tests/Database on MySQL, MariaDB and PostgreSQL.
            return []; // @codeCoverageIgnore
        }

        return array_map(intval(...), $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * The id of a message the scope may read (the keyset cursor), or null for an unknown or unreadable uuid alike.
     *
     * @param ChatSearchScope $scope What the viewer may read now
     * @param string $uuid The message uuid
     *
     * @return int|null The id
     */
    public function readableId(ChatSearchScope $scope, string $uuid): ?int
    {
        $qb = new QueryBuilder($this->entityManager->getConnection());
        if (!$this->visible($qb, $scope)) {
            return null;
        }
        $messages = $this->meta(ChatMessage::class);
        $id       = $qb->select('m.' . $messages->getSingleIdentifierColumnName())
            ->andWhere('m.' . $messages->getColumnName('uuid') . ' = :uuid')
            ->setParameter('uuid', $uuid)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $id === false ? null : (int) $id;
    }

    /**
     * Messages joined to their conversation, restricted to what the scope may read (a fresh query builder of this call);
     * false when it may read nothing.
     */
    private function visible(QueryBuilder $qb, ChatSearchScope $scope): bool
    {
        $messages      = $this->meta(ChatMessage::class);
        $conversations = $this->meta(ChatConversation::class);
        $participants  = $this->meta(ChatParticipant::class);
        $kind          = 'c.' . $conversations->getColumnName('kind');
        $qb->from($messages->getTableName(), 'm')
            ->innerJoin('m', $conversations->getTableName(), 'c', 'c.' . $conversations->getSingleIdentifierColumnName() . ' = m.' . $messages->getSingleAssociationJoinColumnName('conversation'));

        $branches = [];
        if ($scope->viewer !== null) {
            // Direct conversations of the viewer (their participant row) and the viewer's own support tickets.
            $branches[] = '(' . $kind . ' = :direct AND EXISTS (SELECT 1 FROM ' . $participants->getTableName() . ' p WHERE p.'
                . $participants->getSingleAssociationJoinColumnName('conversation') . ' = c.' . $conversations->getSingleIdentifierColumnName()
                . ' AND p.' . $this->userColumn($participants, 'user') . ' = :viewer))';
            $branches[] = '(' . $kind . ' = :support AND c.' . $this->userColumn($conversations, 'supportOwner') . ' = :viewer)';
            $qb->setParameter('viewer', $scope->viewer)->setParameter('direct', ConversationKind::Direct->value);
        }
        if ($scope->supportAgent) {
            $branches[] = '(' . $kind . ' = :support)';
        }
        if ($branches !== []) {
            $qb->setParameter('support', ConversationKind::Support->value);
        }
        if ($scope->channelKeys !== []) {
            $branches[] = '(' . $kind . ' = :channel AND c.' . $conversations->getColumnName('channelKey') . ' IN (:channels))';
            $qb->setParameter('channel', ConversationKind::Channel->value)->setParameter('channels', $scope->channelKeys, ArrayParameterType::STRING);
        }
        if ($branches === []) {
            return false;
        }
        $qb->where('(' . implode(' OR ', $branches) . ')');

        return true;
    }

    /**
     * @param list<string> $words
     */
    private function match(QueryBuilder $qb, string $body, array $words): bool
    {
        $match = $this->textMatch($this->entityManager->getConnection()->getDatabasePlatform(), $body, $words);
        if ($match === null) {
            // Full-text platforms only (audit VA-7): covered by tests/Database; textMatch() itself by the unit tests.
            return false; // @codeCoverageIgnore
        }
        [$conditions, $parameters] = $match;
        foreach ($conditions as $condition) {
            $qb->andWhere($condition);
        }
        foreach ($parameters as $name => $value) {
            $qb->setParameter($name, $value);
        }

        return true;
    }

    /**
     * The text conditions of the words on a platform (FR-002), as SQL fragments and their parameters.
     *
     * @param AbstractPlatform $platform The database platform
     * @param string $body The qualified search text column
     * @param list<string> $words The query words (lower case)
     *
     * @return array{0: list<string>, 1: array<string, string>}|null The conditions (all must hold) and their parameters;
     *                                                               null (audit VA-7) on a full-text platform when no
     *                                                               word can use the index (only short or punctuated
     *                                                               words: a LIKE scan of the whole table), which
     *                                                               answers no rows like any query without a match
     */
    public function textMatch(AbstractPlatform $platform, string $body, array $words): ?array
    {
        $mysql    = $this->fulltext && $platform instanceof AbstractMySQLPlatform;
        $postgres = $this->fulltext && $platform instanceof PostgreSQLPlatform;
        $terms    = [];
        $like     = [];
        foreach ($words as $word) {
            $plain = preg_match(self::PLAIN_WORD, $word) === 1;
            if ($mysql && $plain && mb_strlen($word) >= $this->mysqlMinTokenLength) {
                $terms[] = '+' . $word . '*';
            } elseif ($postgres && $plain) {
                $terms[] = $word . ':*';
            } else {
                $like[] = $word;
            }
        }
        if ($terms === [] && ($mysql || $postgres)) {
            return null;
        }
        $conditions = [];
        $parameters = [];
        if ($terms !== [] && $mysql) {
            $conditions[]        = 'MATCH (' . $body . ') AGAINST (:terms IN BOOLEAN MODE)';
            $parameters['terms'] = implode(' ', $terms);
        } elseif ($terms !== []) {
            // The configuration is a validated identifier, inlined so that the expression is the index's expression.
            $config              = "'" . $this->postgresConfig . "'::regconfig";
            $conditions[]        = 'to_tsvector(' . $config . ', ' . $body . ') @@ to_tsquery(' . $config . ', :terms)';
            $parameters['terms'] = implode(' & ', $terms);
        }
        foreach ($like as $index => $word) {
            $conditions[]                = 'LOWER(' . $body . ') LIKE :like' . $index . " ESCAPE '!'";
            $parameters['like' . $index] = '%' . strtr($word, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }

        return [$conditions, $parameters];
    }

    /**
     * The column of a user reference: the join column of the relation, or (`doctrine.user_reference: id|string`) the
     * mapped `<relation>Ref` column.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function userColumn(ClassMetadata $metadata, string $relation): string
    {
        return $metadata->hasAssociation($relation) ? $metadata->getSingleAssociationJoinColumnName($relation) : $metadata->getColumnName($relation . 'Ref');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return ClassMetadata<T>
     */
    private function meta(string $class): ClassMetadata
    {
        return $this->entityManager->getClassMetadata($class);
    }
}
