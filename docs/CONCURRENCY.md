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
| 2.7 U1 upload | no lock and no transaction while the bytes travel to StorageKit (rule 9); then one unit: the conversation row lock, write access and the person's draft quota re-checked, a savepoint insert on the unique `(uploader, Idempotency-Key)`; a failed unit expires the stored copy | `Idempotency-Key` (optional on the dock, required on the API): a replay answers the first upload; a lost insert race answers the winner's row and expires its own copy |
| 2.7 C1 scan claim | `UPDATE … SET state='scanning', state_token=?, lease_until=?, scan_attempts+1 WHERE id=? AND attempts left AND (uploaded and due OR scanning with an expired lease)`: one worker per file | Messenger replay and the sweep are no-ops |
| 2.7 scan | outside any transaction and lock; StorageKit's bounded stream, closed in `finally` | |
| 2.7 C2 verdict | `UPDATE … WHERE id=? AND state='scanning' AND state_token=?`: a verdict whose lease was lost (file removed, lease taken over) is discarded; a retry goes back to `uploaded` with a backoff under the same guard | Messenger `DelayStamp`, then the sweep |
| 2.7 U2 send with files | the send unit's conversation lock first, then the files `SELECT … FOR UPDATE ORDER BY id` (lock order conversation → attachments → message), each a clean draft of the author here, else the whole send is refused (`409 attachment_not_ready`, `422 attachment_invalid`) | the send's `Idempotency-Key` |
| 2.7 C3 remove / C4 block | the conversation lock, the row re-read `FOR UPDATE`, then a compare-and-set (`deleted`; `clean` → `blocked`); a draft a send attached meanwhile is left to its message; objects removed after the commit | idempotent |
| 2.7 files with their message | message delete, retention purge, conversation purge and GDPR erasure mark the rows `deleted` in their own unit; objects removed after the commit (Messenger), the sweep removes what a lost message left | re-run safe |
| 2.7 sweep (`nowo:chat:attachments:sweep`) | each step its own statement or batch of 200 (fail exhausted scans, re-queue due ones, delete stale drafts, remove objects); a lock wait exits 75 (`CONFLICT_001`) | re-run safe |
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
| 2.6 send with typed cards | `canShare()` of every card is asked **before** the unit (REQ-RUNTIME-003 rule 9: no host call under a lock); the send unit is then the plain send's (conversation row lock first). A refusal writes nothing. `ChatCardSharedEvent` after the commit | the client resends (with its `Idempotency-Key`: a replay) |
| 2.6 edit of a message with cards | as edit: the cards are the stored message's (read before the unit), never asked again; the text is compared under the conversation lock | the same text again is no edit |
| 2.6 card render | read-only, per request: no lock, no write; a card deleted, revoked or burnt between two requests is rendered as the host answers on the next one | — |

2.6 typed cards (spec 259): a message with cards is written by the same unit as any send, so a card send racing the
deletion of its conversation or of earlier messages queues on the conversation's row lock. `ConcurrentWritersTest`
races card sends against deletes (`testCardSendsAgainstDeletes`: every call answers, no deleted message keeps a card
token, every live card message is whole) and card reads against sends and deletes (`testCardReadsAgainstDeletes`: every
read answers, a deleted message never shows a card) with two worker processes. Revealing a one-time secret is a host
page (Beacon) and never a ChatKit write: its race with a membership removal is the host's (the reveal checks live
membership), while ChatKit never memoises a card across requests (`ChatCardsWorkerTest`).

2.5 client API writes (send, thread reply, reactions, edit and delete of one's own message) are `ChatService`'s own units
of work, the dock's: the conversation's row is locked first (one lock order), the `Idempotency-Key` (scoped to the token
and the conversation) is claimed as the unit's first statement, so a retry racing its first attempt waits and replays
instead of writing twice (`ConcurrentWritersTest::testClientApiDuplicateSendsUnderRetry`). Reactions are set or removed
(not toggled) and read again under the lock, so two devices of one person never store two rows or flip each other
(`testClientApiConcurrentReactions`). The author's read cursor only moves forward (`markReadUpTo`), so two senders never
set each other's cursor back and unread counters stay exact (`testClientApiUnreadCountersOfTwoSenders`). All three run
with two worker processes on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 in CI. A lock conflict answers `409`
`CONFLICT_001`: nothing was written, retry with the same key.

2.4 client API: its only write is the read mark (`POST <prefix>/chat/v1/conversations/{uuid}/read`). It goes through
`ChatService::markRead()`, the same unit as the web UI. The participant row is locked (`PESSIMISTIC_WRITE`) and the
cursor only moves forward, so two devices never lose an update or move it back. A lock conflict answers `409` with
`CONFLICT_001` and `Retry-After`.

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
- 2.6: `ConcurrentWritersTest` also races card sends against deletes and card reads against sends and deletes;
  `ChatCardsWorkerTest` grants and revokes a card every request on one kernel.
- 2.0: `ConcurrentWritersTest` also purges the same channel conversations from two workers at once (every call
  answers, nothing of them is left).
- 1.37: `tests/Database/MessageSearchDatabaseTest` also runs the backfill while a second session holds a row of the
  batch: `CONFLICT_001` after the one-second wait, the first batch kept, the next run completes. It builds the search index while a second session holds a write lock on
  the message table (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the command answers `CONFLICT_001` after the one-second
  lock wait, no usable index is built (PostgreSQL may keep an INVALID one until the next `--force` drops it), and the connection builds
  the index on the next try.

- 2.7: `tests/Database/AttachmentRacesTest` (two sessions, MySQL 8.4, MariaDB 11.4, PostgreSQL 16, DBAL 3 and 4): a
  verdict after the file was removed is discarded, a second worker never scans a file twice, a late verdict after a
  lease takeover is discarded, a send waits for the conversation and refuses a file removed or blocked meanwhile
  (nothing written), a draft-only remove after the send changes nothing, and the sweep exits 75 under a held row lock
  and completes on the next run. `ChatAttachmentsWorkerTest` runs uploads, scans, sends and downloads for 300 rounds on
  one kernel.

## Accepted residual risk

- **2.7: the draft quota (`max_pending_per_user`) is a soft cap across conversations.** It is re-checked under the
  conversation's lock, so two uploads of one person into two conversations at the same moment may both pass (one over
  the cap). No file is served unscanned either way.
- **2.7: a reader whose membership ends between the check and the end of a stream keeps the bytes already sent**; every
  new request is checked again. A file blocked after a download cannot be recalled.
- **2.7: no content dedup.** The same file sent twice is stored twice (one row, one object keeps deletion simple).

- **1.37: a first MySQL / MariaDB FULLTEXT index blocks message writes while it builds** (InnoDB rebuilds the table).
  Hosts build it in a maintenance window or with an online schema tool; see UPGRADING.md. PostgreSQL builds it
  `CONCURRENTLY`.
- **`message_menu.save.max_per_user` is a soft cap.** Two saves that race at the limit may both pass. No data is lost
  or duplicated: the unique key still holds, and the user is at most a few rows over the cap.
- **Recipients are cached for 30 s.** Someone who just lost access may still receive realtime events of the
  conversation for up to 30 s. Every API read checks access again (see `ChatRecipients::forget()`).
