# Concurrency (REQ-RUNTIME-003)

How ChatKit stays correct when the same data is written at the same time: two users, two tabs, two workers, a retried
request.

## Table of contents

- [Write units](#write-units)
- [Write paths](#write-paths)
- [Conflict answers](#conflict-answers)
- [Host options](#host-options)
- [Tests](#tests)
- [Accepted residual risk](#accepted-residual-risk)

## Write units

Every user action that writes runs as **one unit of work** through ChatKit's own runner:

- `ChatKitWrites::runRecovering()` wraps `ChatKitTransactionRunner`, which implements nowo-tech/transaction-contract's
  `TransactionRunnerInterface` on the chat manager.
- The chat manager is flushed inside the unit.
- Side effects run after the commit, never inside the unit: realtime publishes, events, Web Push and digests.
- After a failure the runner leaves no transaction open and repairs the nesting level (savepoint-aware), and the next
  request of the worker finds a healthy manager.
- ChatKit never resets, clears or flushes the host's entity manager: it writes only through its own private manager
  (2.0: the only mode).

## Write paths

| Path | Concurrency control (rule 4) | Retry |
|------|-------------------------------|-------|
| Send a message, buzz, system line | the conversation row is locked first (`lockActivity`, `SELECT … FOR UPDATE`), so lines of one conversation queue instead of deadlocking | the client resends a failed send |
| Edit, delete, pin | the conversation row lock, then the message | idempotent target state |
| React | conversation lock, then re-read under it; the insert goes through a savepoint on the unique `(message, user, emoji)` key | toggle re-read under the lock |
| Read cursor (`POST …/read`) | shared conversation lock, then the reader's participant row `FOR UPDATE` (1.35, audit TK-7); the cursor only moves forward | forward-only |
| 1.35 read times | inside the read / send unit, under the same locks; an advance within 60 s of the newest row's first time updates it | forward-only |
| 1.35 mark unread | shared conversation lock (exclusive when the participant row must be created), then the participant row `FOR UPDATE`; the read cursor moves back, the receipt cursor never | idempotent (same target cursor) |
| 1.35 save / unsave | savepoint insert on the unique `(user, message)` key; a save racing another keeps the first row | idempotent `PUT` / `DELETE` |
| 1.35 share read times off | the settings flush deletes the user's read times (`ChatReadTimesOptOutListener`, same transaction); a read racing it writes a row that stays hidden (it is older than the next re-enable) | idempotent |
| 1.36 forward | every target checked first; one unit claims the `Idempotency-Key` (savepoint insert on the unique `(user, key)`), then locks the target conversations in id order and writes the comment and the copy of each | `Idempotency-Key` (required): a replay answers the first forward |
| 1.36 thread reply | the `Idempotency-Key` claim, the conversation row lock (as send, without moving its activity), then an atomic `thread_reply_count + 1` on the root | `Idempotency-Key` (required) |
| 1.36 thread read | savepoint insert on the unique `(root, user)` key; the cursor moves forward in one statement | forward-only |
| 1.36 edit + revision | the conversation row lock, then the message is read again under it: the text another edit committed is the previous text; the revision insert and the prune happen in the same unit | the same text again is no edit |
| 1.36 send with `Idempotency-Key` | the key claim first in the send's unit | a replay answers the first message |
| Participant rows, direct conversations, channel conversations | unique keys and a savepoint insert; the loser re-reads the winner's row | — |
| Ticket numbers | a dedicated counter row with an atomic update | — |
| GDPR erasure | one unit for the whole erasure | — |
| `nowo:chat:purge` | small batches, each in its own statement or unit | re-run safe |
| 2.0 `deleteConversation()` / `purgeChannel()` | one unit on ChatKit's runner: the conversation row `FOR UPDATE` first, then children before parents; a concurrent purge of the same conversation waits on the lock and then finds no row (`false`, nothing written, no event). `ChatConversationDeletedEvent` follows ChatKit's unit; inside an outer host transaction it is a savepoint, so the deletion (and the event's facts) is durable only when the host commits | idempotent (`false` when gone) |
| 2.0 bulk DQL writes and the private manager | after each bulk DELETE / UPDATE, the managed instances of deleted rows are detached and those of updated rows read again (`ChatKitManagedRows`); the manager is never cleared, so a worker without kernel reset never answers a deleted row | — |
| 2.0 `markMentionRead()` / `markMentionsRead()` | one unit; `UPDATE … SET read_at WHERE id IN (…) AND read_at IS NULL` on the user's exact rows, so the first read time wins and a concurrent mark counts nothing twice | idempotent |
| 1.37 message search (page, API, palette) | read-only: no write unit, no lock | — |
| 1.37 `search_text` (audit VA2-1) | written by the entity with the text it derives from, in the same unit of work as send, edit, delete and GDPR erasure (no extra statement, no extra lock); the full-text index follows inside the database | — |
| 1.37 `nowo:chat:search-backfill` | batches by id, one short connection-level transaction each (DBAL only: no entity manager flushed, cleared or reset); `UPDATE … WHERE search_text IS NULL`, so a concurrent send or edit always wins; a lock wait timeout, deadlock or `lock_timeout` rolls the batch back and stops with `CONFLICT_001` (exit 75), the batches before it kept | `--after-id` resumes; re-run safe |
| 1.37 `nowo:chat:search-index --force` | one DDL statement outside any transaction (`CREATE INDEX CONCURRENTLY` cannot run in one; MySQL commits DDL itself); a lock wait timeout, deadlock or `lock_timeout` stops it with `CONFLICT_001` (exit 75): no usable index was built; on PostgreSQL an INVALID index may remain until `--force` runs again, which drops it before building (if that build fails too, no index is left); PostgreSQL search keeps working without it (slower), MySQL / MariaDB search needs it | re-run safe |

## Conflict answers

A lock conflict, deadlock, lock-wait timeout or serialization failure on a ChatKit route answers **409** with
`Retry-After` (`nowo_transaction_doctrine.conflict_response.status: 503` keeps 503). A unique key that another request
took first answers 409 `conflict`. Since 1.35 every conflict answer carries its code:

| Code | Meaning | Body | Headers |
|------|---------|------|---------|
| `CONFLICT_001` | nothing was committed: retry the same request | `{"ok": false, "error": "unavailable" \| "conflict", "code": "CONFLICT_001"}` | `X-Conflict-Code`, `Retry-After` (transient failures) |
| `CONFLICT_002` | part of the request committed: read again, do not repeat | `{"ok": false, "error": "unavailable", "code": "CONFLICT_002", "message": "…reload…"}` | `X-Conflict-Code`, no `Retry-After` |

Chat pages throw an `HttpException` with the same status and headers. The host's error page renders it, with the
original failure as previous. The code comes from `ConcurrencyConflict::$committed` and transaction-doctrine's `ConflictCode::forRequest()`.

**Request-level detection.** Since transaction-doctrine 1.6 the code also counts commits made earlier in the same
request: the request's `CommitMark` (taken at `kernel.request` with `commit_detection` on) goes through
`ConflictCode::forRequest()`. A conflict after any commit of the request answers `CONFLICT_002`, and
`ConflictResponsePolicy::headersFor()` then leaves out `Retry-After`.

## Host options

- `nowo_transaction_doctrine.conflict_response.status` (409 / 503) and `retry_after`.
- `ChatConflictResponseFormatter` shapes the library responder's answer on `nowo_chat_api_*` routes, with the same body,
  code and header.

## Tests

- `tests/Database` runs two connections against MySQL 8.4, MariaDB 11 and PostgreSQL 16 (CI job `database`): concurrent
  writers, publish locks and ticket numbers.
- `tests/Functional/ChatLoadReadinessWorkerTest` checks that a lock wait or a unique violation answers 409 with the code
  and leaves the next request healthy.
- `tests/Unit/Http/ChatApiExceptionListenerStorageTest` covers each failure kind and its code.
- `tests/Database/ConcurrentWritersTest` (two worker processes, one database) also races the message menu against a
  sender, two saves at the cap, mark unread against reads, and the opt-out against reads.
- `tests/Integration/Service/ChatMessageActionsTest` covers saving the same message twice and repeated mark unread.
- 1.36: `ConcurrentWritersTest` also sends one forward from two workers under one key (one copy), concurrent replies
  in one thread (the count matches the replies) and concurrent edits of one message (every previous text is kept once).
- 2.0: `ConcurrentWritersTest` also purges the same channel conversations from two workers at once (every call
  answers, nothing of them is left).
- 1.37: `tests/Database/MessageSearchDatabaseTest` also runs the backfill while a second session holds a row of the
  batch: `CONFLICT_001` after the one-second wait, the first batch kept, the next run completes. It builds the search index while a second session holds a write lock on
  the message table (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the command answers `CONFLICT_001` after the one-second
  lock wait, no usable index is built (PostgreSQL may keep an INVALID one until the next `--force` drops it), and the connection builds
  the index on the next try.

## Accepted residual risk

- **1.37: a first MySQL / MariaDB FULLTEXT index blocks message writes while it builds** (InnoDB rebuilds the table).
  Hosts build it in a maintenance window or with an online schema tool; see UPGRADING.md. PostgreSQL builds it
  `CONCURRENTLY`.
- **`message_menu.save.max_per_user` is a soft cap.** Two saves that race at the limit may both pass. No data is lost
  or duplicated: the unique key still holds, and the user is at most a few rows over the cap.
- **Recipients are cached for 30 s.** Someone who just lost access may still receive realtime events of the
  conversation for up to 30 s. Every API read checks access again (see `ChatRecipients::forget()`).
