<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatKitRows;
use Nowo\ChatKitBundle\Doctrine\ChatKitSavepoint;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Entity\ChatAttachment;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Nowo\ChatKitBundle\Enum\ChatAttachmentState;

use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function sort;

/**
 * 2.7 (spec 259 block B, REQ-RUNTIME-003): every read and write of the attachment rows, through DBAL on ChatKit's
 * private connection. State changes are compare-and-set statements (`WHERE id = ? AND state = ? [AND state_token = ?]`):
 * one statement decides, a lost race changes nothing and reports false. Statements outside a unit of work run in a
 * savepoint when a transaction is already open (a host transaction, a Messenger middleware), so a failure never aborts it.
 *
 * Rows come back as arrays with exactly the asked uuid (REQ-SEC-006: re-checked byte for byte after the SQL lookup).
 *
 * @phpstan-type AttachmentRow array{id: int, uuid: string, conversation_id: int|null, conversation_uuid: string, message_id: int|null, uploader_id: int|string|null, storage_uuid: string, name: string, extension: string, media_type: string, kind: string, size_bytes: int, sha256: string, state: string, state_token: string|null, scan_attempts: int, position: int, idempotency_key: string|null, request_hash: string|null, created_at: string, attached_at: string|null}
 *
 * @extends ServiceEntityRepository<ChatAttachment>
 */
class ChatAttachmentRepository extends ServiceEntityRepository
{
    /** Columns every read returns. */
    private const string COLUMNS = 'a.id, a.uuid, a.conversation_id, a.conversation_uuid, a.message_id, a.uploader_id, a.storage_uuid, a.name, a.extension, a.media_type, a.kind, a.size_bytes, a.sha256, a.state, a.state_token, a.scan_attempts, a.position, a.idempotency_key, a.request_hash, a.created_at, a.attached_at';

    /** States a draft counts in (`max_pending_per_user`). */
    public const array DRAFT_STATES = ['uploaded', 'scanning', 'clean', 'blocked', 'failed'];

    /**
     * @param ManagerRegistry $registry Doctrine manager registry
     */
    public function __construct(
        ManagerRegistry $registry,
        private readonly ChatUserReferences $refs = new ChatUserReferences(),
    ) {
        parent::__construct($registry, ChatAttachment::class);
    }

    /**
     * Writes a new upload row through a savepoint (inside the caller's unit of work).
     *
     * @return UniqueConstraintViolationException|null What refused it (the uploader's idempotency key was taken meanwhile)
     */
    public function insert(ChatAttachment $attachment): ?UniqueConstraintViolationException
    {
        return ChatKitRows::insert($this->getEntityManager(), $attachment);
    }

    /**
     * The row whose uuid is exactly `$uuid`.
     *
     * @return AttachmentRow|null
     */
    public function findRow(string $uuid, bool $lock = false): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE a.uuid = ?' . ($lock ? ' ' . $this->lockClause() : ''), [$uuid]);

        return is_array($row) && $row['uuid'] === $uuid ? $this->normalize($row) : null;
    }

    /**
     * The row of an id.
     *
     * @return AttachmentRow|null
     */
    public function findRowById(int $id): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE a.id = ?', [$id], [ParameterType::INTEGER]);

        return is_array($row) ? $this->normalize($row) : null;
    }

    /**
     * The uploader's row of an idempotency key (exactly this key).
     *
     * @param int|string $uploaderKey ChatUserReferences::key() of the uploader
     *
     * @return AttachmentRow|null
     */
    public function findByKey(int|string $uploaderKey, string $key): ?array
    {
        $row = $this->connection()->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE a.uploader_id = ? AND a.idempotency_key = ?', [$this->refs->sqlValue($uploaderKey), $key], [$this->userType($uploaderKey), ParameterType::STRING]);

        return is_array($row) && $row['idempotency_key'] === $key ? $this->normalize($row) : null;
    }

    /**
     * Drafts (not sent, not removed) of an uploader, in every conversation.
     */
    public function countDrafts(int|string $uploaderKey): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table() . ' a WHERE a.uploader_id = ? AND a.message_id IS NULL AND a.attached_at IS NULL AND a.state IN (?)',
            [$this->refs->sqlValue($uploaderKey), self::DRAFT_STATES],
            [$this->userType($uploaderKey), ArrayParameterType::STRING],
        );
    }

    /**
     * The uploader's drafts of a conversation, oldest first.
     *
     * @return list<AttachmentRow>
     */
    public function drafts(int|string $uploaderKey, string $conversationUuid): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE a.uploader_id = ? AND a.conversation_uuid = ? AND a.message_id IS NULL AND a.attached_at IS NULL AND a.state IN (?) ORDER BY a.id',
            [$this->refs->sqlValue($uploaderKey), $conversationUuid, self::DRAFT_STATES],
            [$this->userType($uploaderKey), ParameterType::STRING, ArrayParameterType::STRING],
        );

        return $this->exactly($rows, 'conversation_uuid', $conversationUuid);
    }

    /**
     * The clean files of these messages, in one query (one per message page).
     *
     * @param list<int> $messageIds
     *
     * @return array<int, list<AttachmentRow>> message id => files in order
     */
    public function forMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE a.message_id IN (?) AND a.state = ? ORDER BY a.message_id, a.position, a.id',
            [array_values($messageIds), ChatAttachmentState::Clean->value],
            [ArrayParameterType::INTEGER, ParameterType::STRING],
        );
        $byMessage = [];
        foreach ($rows as $row) {
            $row                                   = $this->normalize($row);
            $byMessage[(int) $row['message_id']][] = $row;
        }

        return $byMessage;
    }

    /**
     * C1 (claim): takes the scan lease of a file that waits for it (`uploaded` and due, or `scanning` with an expired
     * lease) while attempts are left. One row changed: this worker owns the scan under `$token`.
     */
    public function claim(int $id, string $token, DateTimeImmutable $now, DateTimeImmutable $leaseUntil, int $maxAttempts): bool
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = ?, lease_until = ?, scan_attempts = scan_attempts + 1'
            . ' WHERE id = ? AND scan_attempts < ? AND ((state = ? AND (lease_until IS NULL OR lease_until <= ?)) OR (state = ? AND lease_until < ?))',
            [ChatAttachmentState::Scanning->value, $token, $leaseUntil, $id, $maxAttempts, ChatAttachmentState::Uploaded->value, $now, ChatAttachmentState::Scanning->value, $now],
            [ParameterType::STRING, ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        ) === 1;
    }

    /**
     * C2 (verdict): ends this worker's lease with a final state. Zero rows: the file was removed, or the lease expired and
     * another worker took it; the verdict is discarded.
     */
    public function verdict(int $id, string $token, ChatAttachmentState $state, string $engine, ?string $signature, DateTimeImmutable $now): bool
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, scan_engine = ?, scan_signature = ?, scanned_at = ? WHERE id = ? AND state = ? AND state_token = ?',
            [$state->value, $engine === '' ? null : $engine, $signature, $now, $id, ChatAttachmentState::Scanning->value, $token],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        ) === 1;
    }

    /**
     * Retry: hands the file back (`uploaded`, due at `$retryAt`) under this worker's lease.
     */
    public function release(int $id, string $token, DateTimeImmutable $retryAt): bool
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = ? WHERE id = ? AND state = ? AND state_token = ?',
            [ChatAttachmentState::Uploaded->value, $retryAt, $id, ChatAttachmentState::Scanning->value, $token],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING],
        ) === 1;
    }

    /**
     * U2 (send): the rows of these uuids, locked `FOR UPDATE` in id order (lock order: conversation → attachments by
     * id). Inside the send's unit of work, after the conversation's row lock. Only rows whose uuid is exactly one asked.
     *
     * @param list<string> $uuids
     *
     * @return array<string, AttachmentRow> uuid => row
     */
    public function lockForSend(array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        // Audit ZCO-I1: the ids first (no lock), then the rows locked by primary key in id order. A `uuid IN (…)` lock
        // would follow the uuid index on InnoDB (MySQL / MariaDB), not the id order the other writers use.
        $ids = array_values(array_map(intval(...), $this->connection()->fetchFirstColumn(
            'SELECT a.id FROM ' . $this->table() . ' a WHERE a.uuid IN (?)',
            [array_values($uuids)],
            [ArrayParameterType::STRING],
        )));
        if ($ids === []) {
            return [];
        }
        sort($ids);
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE a.id IN (?) ORDER BY a.id ' . $this->lockClause(),
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $found = [];
        foreach ($rows as $row) {
            if (in_array($row['uuid'], $uuids, true)) {
                $found[(string) $row['uuid']] = $this->normalize($row);
            }
        }

        return $found;
    }

    /**
     * U2: attaches a locked clean draft to the new message.
     */
    public function attach(int $id, int $messageId, int $position, DateTimeImmutable $now): bool
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET message_id = ?, position = ?, attached_at = ? WHERE id = ? AND state = ? AND message_id IS NULL AND attached_at IS NULL',
            [$messageId, $position, $now, $id, ChatAttachmentState::Clean->value],
            [ParameterType::INTEGER, ParameterType::INTEGER, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::STRING],
        ) === 1;
    }

    /**
     * C3 / sent-file removal: marks one row `deleted` (any state but deleted). Idempotent.
     */
    public function markDeleted(int $id, DateTimeImmutable $now): bool
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, deleted_at = ? WHERE id = ? AND state <> ?',
            [ChatAttachmentState::Deleted->value, $now, $id, ChatAttachmentState::Deleted->value],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::STRING],
        ) === 1;
    }

    /**
     * Marks every file of these messages `deleted` (message delete, retention purge, conversation purge), in the
     * caller's unit of work.
     *
     * @param list<int> $messageIds
     *
     * @return int Rows marked
     */
    public function markDeletedForMessages(array $messageIds, DateTimeImmutable $now): int
    {
        if ($messageIds === []) {
            return 0;
        }

        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, deleted_at = ? WHERE message_id IN (?) AND state <> ?',
            [ChatAttachmentState::Deleted->value, $now, array_values($messageIds), ChatAttachmentState::Deleted->value],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER, ParameterType::STRING],
        );
    }

    /**
     * Marks every file of a conversation `deleted` (drafts included), in the caller's unit of work.
     */
    public function markDeletedForConversation(int $conversationId, DateTimeImmutable $now): int
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, deleted_at = ? WHERE conversation_id = ? AND state <> ?',
            [ChatAttachmentState::Deleted->value, $now, $conversationId, ChatAttachmentState::Deleted->value],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::STRING],
        );
    }

    /**
     * GDPR erasure: every file the person uploaded is `deleted`, and the row forgets the uploader and the key.
     */
    public function eraseUploader(int|string $uploaderKey, DateTimeImmutable $now): int
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, deleted_at = COALESCE(deleted_at, ?), uploader_id = NULL, idempotency_key = NULL, request_hash = NULL WHERE uploader_id = ?',
            [ChatAttachmentState::Deleted->value, $now, $this->refs->sqlValue($uploaderKey)],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, $this->userType($uploaderKey)],
        );
    }

    /**
     * C4 (host block): clean → blocked. Idempotent: a file in any other state is left alone.
     */
    public function block(int $id): bool
    {
        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ? WHERE id = ? AND state = ?',
            [ChatAttachmentState::Blocked->value, $id, ChatAttachmentState::Clean->value],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING],
        ) === 1;
    }

    /**
     * Rows whose StorageKit objects must go: `deleted` rows, and `blocked` rows whose objects are still stored.
     *
     * @param int $limit Rows at most
     * @param list<int>|null $ids Only these rows (null: any)
     *
     * @return list<AttachmentRow>
     */
    public function objectsToRemove(int $limit, ?array $ids = null): array
    {
        $sql    = 'SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' a WHERE (a.state = ? OR (a.state = ? AND a.objects_removed_at IS NULL))';
        $params = [ChatAttachmentState::Deleted->value, ChatAttachmentState::Blocked->value];
        $types  = [ParameterType::STRING, ParameterType::STRING];
        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }
            $sql .= ' AND a.id IN (?)';
            $params[] = array_values($ids);
            $types[]  = ArrayParameterType::INTEGER;
        }
        $rows = $this->connection()->fetchAllAssociative($this->connection()->getDatabasePlatform()->modifyLimitQuery($sql . ' ORDER BY a.id', $limit), $params, $types);

        return array_values(array_map($this->normalize(...), $rows));
    }

    /**
     * After its objects were removed: a `deleted` row goes; a `blocked` one stays (its uploader sees "Blocked") with
     * `objects_removed_at` set.
     */
    public function objectsRemoved(int $id, DateTimeImmutable $now): void
    {
        ChatKitSavepoint::write($this->getEntityManager(), function () use ($id, $now): void {
            $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE id = ? AND state = ?', [$id, ChatAttachmentState::Deleted->value], [ParameterType::INTEGER, ParameterType::STRING]);
            $this->connection()->executeStatement('UPDATE ' . $this->table() . ' SET objects_removed_at = ? WHERE id = ? AND state = ?', [$now, $id, ChatAttachmentState::Blocked->value], [Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::STRING]);
        });
    }

    /**
     * Ids of files due for a scan: `uploaded` and due, or `scanning` with an expired lease, attempts left.
     *
     * @return list<int>
     */
    public function dueForScan(DateTimeImmutable $now, int $maxAttempts, int $limit): array
    {
        $sql = 'SELECT a.id FROM ' . $this->table() . ' a WHERE a.scan_attempts < ? AND ((a.state = ? AND (a.lease_until IS NULL OR a.lease_until <= ?)) OR (a.state = ? AND a.lease_until < ?)) ORDER BY a.id';

        return array_values(array_map(intval(...), $this->connection()->fetchFirstColumn(
            $this->connection()->getDatabasePlatform()->modifyLimitQuery($sql, $limit),
            [$maxAttempts, ChatAttachmentState::Uploaded->value, $now, ChatAttachmentState::Scanning->value, $now],
            [ParameterType::INTEGER, ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        )));
    }

    /**
     * Files whose scans are exhausted (attempts used up, no live lease) become `failed` (fail-closed).
     *
     * @return list<int> The ids marked
     */
    public function failExhausted(DateTimeImmutable $now, int $maxAttempts): array
    {
        $ids = array_values(array_map(intval(...), $this->connection()->fetchFirstColumn(
            'SELECT a.id FROM ' . $this->table() . ' a WHERE a.scan_attempts >= ? AND ((a.state = ? AND (a.lease_until IS NULL OR a.lease_until <= ?)) OR (a.state = ? AND a.lease_until < ?))',
            [$maxAttempts, ChatAttachmentState::Uploaded->value, $now, ChatAttachmentState::Scanning->value, $now],
            [ParameterType::INTEGER, ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        )));
        $marked = [];
        foreach ($ids as $id) {
            if ($this->update(
                'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, scanned_at = ? WHERE id = ? AND scan_attempts >= ? AND ((state = ? AND (lease_until IS NULL OR lease_until <= ?)) OR (state = ? AND lease_until < ?))',
                [ChatAttachmentState::Failed->value, $now, $id, $maxAttempts, ChatAttachmentState::Uploaded->value, $now, ChatAttachmentState::Scanning->value, $now],
                [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
            ) === 1) {
                $marked[] = $id;
            }
        }

        return $marked;
    }

    /**
     * Ids of drafts older than `$cutoff` (never sent), and of rows a purge detached from their message without marking
     * them (a safety net: an attached row without a message is never a draft again).
     *
     * @return list<int>
     */
    public function staleIds(DateTimeImmutable $cutoff, int $limit): array
    {
        $sql = 'SELECT a.id FROM ' . $this->table() . ' a WHERE a.state <> ? AND a.message_id IS NULL AND (a.attached_at IS NOT NULL OR a.created_at < ?) ORDER BY a.id';

        return array_values(array_map(intval(...), $this->connection()->fetchFirstColumn(
            $this->connection()->getDatabasePlatform()->modifyLimitQuery($sql, $limit),
            [ChatAttachmentState::Deleted->value, $cutoff],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        )));
    }

    /**
     * Marks these stale rows `deleted` in one statement (the same condition re-checked: a draft sent meanwhile stays).
     *
     * @param list<int> $ids
     */
    public function deleteStale(array $ids, DateTimeImmutable $cutoff, DateTimeImmutable $now): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->update(
            'UPDATE ' . $this->table() . ' SET state = ?, state_token = NULL, lease_until = NULL, deleted_at = ? WHERE id IN (?) AND state <> ? AND message_id IS NULL AND (attached_at IS NOT NULL OR created_at < ?)',
            [ChatAttachmentState::Deleted->value, $now, array_values($ids), ChatAttachmentState::Deleted->value, $cutoff],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * What the scan alert reports: files waiting since before `$since` (count and oldest upload time) and files that
     * ended `failed` after `$since`.
     *
     * @return array{0: int, 1: string|null, 2: int}
     */
    public function overdue(DateTimeImmutable $since): array
    {
        $connection = $this->connection();
        $waiting    = $connection->fetchAssociative(
            'SELECT COUNT(*) AS n, MIN(a.created_at) AS oldest FROM ' . $this->table() . ' a WHERE a.state IN (?) AND a.created_at < ?',
            [[ChatAttachmentState::Uploaded->value, ChatAttachmentState::Scanning->value], $since],
            [ArrayParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );
        $failed = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table() . ' a WHERE a.state = ? AND a.scanned_at >= ?',
            [ChatAttachmentState::Failed->value, $since],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );
        $oldest = is_array($waiting) && is_string($waiting['oldest'] ?? null) ? $waiting['oldest'] : null;

        return [is_array($waiting) ? (int) $waiting['n'] : 0, $oldest, $failed];
    }

    /**
     * The person's uploads for the GDPR export (any state but deleted), oldest first, a page at a time.
     *
     * @return list<array{id: int, uuid: string, name: string, media_type: string, size_bytes: int, conversation_uuid: string, message_uuid: string|null, state: string, storage_uuid: string, created_at: string}>
     */
    public function exportPage(int|string $uploaderKey, int $afterId, int $limit): array
    {
        $message = $this->getEntityManager()->getClassMetadata(ChatMessage::class)->getTableName();
        $sql     = 'SELECT a.id, a.uuid, a.name, a.media_type, a.size_bytes, a.conversation_uuid, m.uuid AS message_uuid, a.state, a.storage_uuid, a.created_at FROM ' . $this->table() . ' a LEFT JOIN ' . $message . ' m ON m.id = a.message_id WHERE a.uploader_id = ? AND a.state <> ? AND a.id > ? ORDER BY a.id';
        $rows    = $this->connection()->fetchAllAssociative(
            $this->connection()->getDatabasePlatform()->modifyLimitQuery($sql, $limit),
            [$this->refs->sqlValue($uploaderKey), ChatAttachmentState::Deleted->value, $afterId],
            [$this->userType($uploaderKey), ParameterType::STRING, ParameterType::INTEGER],
        );

        return array_values(array_map(static fn (array $row): array => [
            'id'                => (int) $row['id'],
            'uuid'              => (string) $row['uuid'],
            'name'              => (string) $row['name'],
            'media_type'        => (string) $row['media_type'],
            'size_bytes'        => (int) $row['size_bytes'],
            'conversation_uuid' => (string) $row['conversation_uuid'],
            'message_uuid'      => is_string($row['message_uuid']) ? $row['message_uuid'] : null,
            'state'             => (string) $row['state'],
            'storage_uuid'      => (string) $row['storage_uuid'],
            'created_at'        => (string) $row['created_at'],
        ], $rows));
    }

    /**
     * ChatKit's private connection.
     */
    public function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    /** The table name (with `doctrine.table_prefix`). */
    public function table(): string
    {
        return $this->getClassMetadata()->getTableName();
    }

    /**
     * Runs one write statement (a savepoint inside an open transaction).
     *
     * @param list<mixed> $params
     * @param list<mixed> $types
     */
    private function update(string $sql, array $params, array $types): int
    {
        return (int) ChatKitSavepoint::write($this->getEntityManager(), fn (): int|string => $this->connection()->executeStatement($sql, $params, $types));
    }

    /**
     * The Doctrine type name of the uploader column's value: an integer id in relation mode, a reference string in id
     * mode (a type name, valid with DBAL 3 and 4 alike: DBAL 3's ParameterType constants are integers).
     */
    private function userType(int|string $uploaderKey): string
    {
        return is_int($this->refs->sqlValue($uploaderKey)) ? Types::INTEGER : Types::STRING;
    }

    /** The exclusive row lock clause of the platform ('' on SQLite: a writing transaction locks the database). */
    private function lockClause(): string
    {
        $platform = $this->connection()->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform, $platform instanceof MariaDBPlatform, $platform instanceof AbstractMySQLPlatform => 'FOR UPDATE',
            default                                                                                                                   => '',
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<AttachmentRow>
     */
    private function exactly(array $rows, string $column, string $value): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if ($row[$column] === $value) {
                $kept[] = $this->normalize($row);
            }
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return AttachmentRow
     */
    private function normalize(array $row): array
    {
        /** @var AttachmentRow $normalized */
        $normalized = [
            'id'                => (int) $row['id'],
            'uuid'              => (string) $row['uuid'],
            'conversation_id'   => $row['conversation_id'] === null ? null : (int) $row['conversation_id'],
            'conversation_uuid' => (string) $row['conversation_uuid'],
            'message_id'        => $row['message_id'] === null ? null : (int) $row['message_id'],
            'uploader_id'       => $row['uploader_id'] === null ? null : (string) $row['uploader_id'],
            'storage_uuid'      => (string) $row['storage_uuid'],
            'name'              => (string) $row['name'],
            'extension'         => (string) $row['extension'],
            'media_type'        => (string) $row['media_type'],
            'kind'              => (string) $row['kind'],
            'size_bytes'        => (int) $row['size_bytes'],
            'sha256'            => (string) $row['sha256'],
            'state'             => (string) $row['state'],
            'state_token'       => $row['state_token'] === null ? null : (string) $row['state_token'],
            'scan_attempts'     => (int) $row['scan_attempts'],
            'position'          => (int) $row['position'],
            'idempotency_key'   => $row['idempotency_key'] === null ? null : (string) $row['idempotency_key'],
            'request_hash'      => $row['request_hash'] === null ? null : (string) $row['request_hash'],
            'created_at'        => (string) $row['created_at'],
            'attached_at'       => $row['attached_at'] === null ? null : (string) $row['attached_at'],
        ];

        return $normalized;
    }
}
