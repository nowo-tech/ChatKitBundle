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
- ChatKit never resets, clears or flushes the host's entity manager. With `doctrine.private_entity_manager: true` it
  writes only through its own manager.

## Write paths

| Path | Concurrency control (rule 4) | Retry |
|------|-------------------------------|-------|
| Send a message, buzz, system line | the conversation row is locked first (`lockActivity`, `SELECT … FOR UPDATE`), so lines of one conversation queue instead of deadlocking | the client resends a failed send |
| Edit, delete, pin | the conversation row lock, then the message | idempotent target state |
| React | conversation lock, then re-read under it; the insert goes through a savepoint on the unique `(message, user, emoji)` key | toggle re-read under the lock |
| Read cursor (`POST …/read`) | shared conversation lock; the cursor only moves forward | forward-only |
| 1.35 read times | inside the read / send unit, under the same lock; an advance within 60 s updates the newest row | forward-only |
| 1.35 mark unread | shared conversation lock (exclusive when the participant row must be created); the read cursor moves back, the receipt cursor never | idempotent (same target cursor) |
| 1.35 save / unsave | savepoint insert on the unique `(user, message)` key; a save racing another keeps the first row | idempotent `PUT` / `DELETE` |
| 1.35 share read times off | one unit deletes the user's read times | idempotent |
| Participant rows, direct conversations, channel conversations | unique keys and a savepoint insert; the loser re-reads the winner's row | — |
| Ticket numbers | a dedicated counter row with an atomic update | — |
| GDPR erasure | one unit for the whole erasure | — |
| `nowo:chat:purge` | small batches, each in its own statement or unit | re-run safe |

## Conflict answers

A lock conflict, deadlock, lock-wait timeout or serialization failure on a ChatKit route answers **409** with
`Retry-After` (`nowo_transaction_doctrine.conflict_response.status: 503` keeps 503). A unique key that another request
took first answers 409 `conflict`. Since 1.35 every conflict answer carries its code:

| Code | Meaning | Body | Headers |
|------|---------|------|---------|
| `CONFLICT_001` | nothing was committed: retry the same request | `{"ok": false, "error": "unavailable" \| "conflict", "code": "CONFLICT_001"}` | `X-Conflict-Code`, `Retry-After` (transient failures) |
| `CONFLICT_002` | part of the request committed: read again, do not repeat | `{"ok": false, "error": "unavailable", "code": "CONFLICT_002", "message": "…reload…"}` | `X-Conflict-Code`, no `Retry-After` |

Chat pages throw an `HttpException` with the same status and headers. The host's error page renders it, with the
original failure as previous. The code comes from transaction-doctrine's `ConflictCode::forFailure()` and
`ConcurrencyConflict::$committed`.

**Planned:** transaction-doctrine 1.6 adds a request watermark, so commits made earlier in the same request also count
as "part committed". ChatKit will adopt its helper when it ships.

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
- `tests/Integration/Service/ChatMessageActionsTest` covers saving the same message twice and repeated mark unread.

## Accepted residual risk

- **`message_menu.save.max_per_user` is a soft cap.** Two saves that race at the limit may both pass. No data is lost
  or duplicated: the unique key still holds, and the user is at most a few rows over the cap.
- **Recipients are cached for 30 s.** Someone who just lost access may still receive realtime events of the
  conversation for up to 30 s. Every API read checks access again (see `ChatRecipients::forget()`).
