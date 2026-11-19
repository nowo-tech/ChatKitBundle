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
        if ($query->words === [] || !$this->readableOn($qb, $scope, $author, $cutoff)) {
            return [];
        }
        $messages = $this->meta(ChatMessage::class);
        $qb->setMaxResults($limit);
        if ($query->conversation !== null) {
            $qb->andWhere('c.' . $this->meta(ChatConversation::class)->getColumnName('uuid') . ' = :conversation')->setParameter('conversation', $query->conversation);
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
        if (!$this->matchWords($qb, $query->words)) {
            // Full-text platforms only (audit VA-7): covered by tests/Database on MySQL, MariaDB and PostgreSQL.
            return []; // @codeCoverageIgnore
        }

        return array_map(intval(...), $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * 1.38: the ids of the messages the scope may read now (a fresh DBAL query builder of this call, alias `m` for the
     * message and `c` for its conversation): the permission filter, the kinds people write, not deleted, inside the
     * retention window (replies to an older root left out too) and, with `$author`, only that author's; newest first, no
     * limit and no text condition (the caller adds them). Null when the scope may read nothing. The SearchKit message
     * group builds on it (ChatMessageSearchDefinition), so both searches share one permission filter.
     *
     * @param ChatSearchScope $scope What the viewer may read now
     * @param int|string|null $author SQL value of the author filter (null: any author)
     * @param DateTimeImmutable $cutoff Retention start
     *
     * @return QueryBuilder|null The query; null when nothing is readable
     */
    public function readable(ChatSearchScope $scope, int|string|null $author, DateTimeImmutable $cutoff): ?QueryBuilder
    {
        $qb = new QueryBuilder($this->entityManager->getConnection());

        return $this->readableOn($qb, $scope, $author, $cutoff) ? $qb : null;
    }

    /**
     * Builds readable() on a fresh query builder of this call; false when the scope may read nothing.
     */
    private function readableOn(QueryBuilder $qb, ChatSearchScope $scope, int|string|null $author, DateTimeImmutable $cutoff): bool
    {
        if (!$this->visible($qb, $scope)) {
            return false;
        }
        $messages = $this->meta(ChatMessage::class);
        $qb->select('m.' . $messages->getSingleIdentifierColumnName() . ' AS id')
            ->leftJoin('m', $messages->getTableName(), 'r', 'r.' . $messages->getSingleIdentifierColumnName() . ' = m.' . $messages->getSingleAssociationJoinColumnName('threadRoot'))
            ->andWhere('m.' . $messages->getColumnName('kind') . ' IN (:kinds)')
            ->andWhere('m.' . $messages->getColumnName('deletedAt') . ' IS NULL')
            ->andWhere('m.' . $messages->getColumnName('createdAt') . ' >= :cutoff')
            ->andWhere('(m.' . $messages->getSingleAssociationJoinColumnName('threadRoot') . ' IS NULL OR r.' . $messages->getColumnName('createdAt') . ' >= :cutoff)')
            ->setParameter('kinds', self::KINDS, ArrayParameterType::STRING)
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->orderBy('m.' . $messages->getSingleIdentifierColumnName(), 'DESC');
        if ($author !== null) {
            $qb->andWhere('m.' . $this->userColumn($messages, 'author') . ' = :author')->setParameter('author', $author);
        }

        return true;
    }

    /**
     * 1.38: a query of readable()'s shape that matches no row (the answer to a viewer who may read nothing, an unknown
     * author or an unreadable retention: no rows, no hint).
     *
     * @return QueryBuilder The query
     */
    public function none(): QueryBuilder
    {
        $messages = $this->meta(ChatMessage::class);

        return (new QueryBuilder($this->entityManager->getConnection()))
            ->select('m.' . $messages->getSingleIdentifierColumnName() . ' AS id')
            ->from($messages->getTableName(), 'm')
            ->where('1 = 0');
    }

    /**
     * 1.38: adds the text condition of the words to a query of readable(): the viewer-neutral search text (audit VA2-1,
     * never the raw body; a NULL, not yet backfilled row never matches), through the platform's full-text operator or an
     * escaped LIKE ({@see self::textMatch()}).
     *
     * @param QueryBuilder $qb A query of readable()
     * @param list<string> $words The query words (lower case)
     *
     * @return bool False (nothing added) on a full-text platform when no word can use the index: the caller answers no rows
     */
    public function matchWords(QueryBuilder $qb, array $words): bool
    {
        return $this->match($qb, 'm.' . $this->meta(ChatMessage::class)->getColumnName('searchText'), $words);
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
            // Direct conversations of the viewer (their participant row, and 1.38: the viewer is one of the pair, so a
            // participant row that grants nothing never makes a candidate) and the viewer's own support tickets.
            $branches[] = '(' . $kind . ' = :direct AND EXISTS (SELECT 1 FROM ' . $participants->getTableName() . ' p WHERE p.'
                . $participants->getSingleAssociationJoinColumnName('conversation') . ' = c.' . $conversations->getSingleIdentifierColumnName()
                . ' AND p.' . $this->userColumn($participants, 'user') . ' = :viewer) AND ' . $this->pairCondition($qb, $conversations, (string) $scope->viewer) . ')';
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
     * 1.38: the direct pair holds the viewer (SQL of ChatConversation::involvesDirectly()): `<low>:<high>` integer keys
     * through the direct key, string references (`doctrine.user_reference: string`) through `direct_user_low` /
     * `direct_user_high`. ChatAccess::canView() still compares the pair byte for byte.
     *
     * @param ClassMetadata<ChatConversation> $conversations
     */
    private function pairCondition(QueryBuilder $qb, ClassMetadata $conversations, string $viewer): string
    {
        $key = 'c.' . $conversations->getColumnName('directKey');
        $qb->setParameter('pair_low', strtr($viewer, ['!' => '!!', '%' => '!%', '_' => '!_']) . ':%')
            ->setParameter('pair_high', '%:' . strtr($viewer, ['!' => '!!', '%' => '!%', '_' => '!_']));
        $conditions = [$key . " LIKE :pair_low ESCAPE '!'", $key . " LIKE :pair_high ESCAPE '!'"];
        if ($conversations->hasField('directUserLow') && $conversations->hasField('directUserHigh')) {
            $conditions[] = 'c.' . $conversations->getColumnName('directUserLow') . ' = :viewer';
            $conditions[] = 'c.' . $conversations->getColumnName('directUserHigh') . ' = :viewer';
        }

        return '(' . implode(' OR ', $conditions) . ')';
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
