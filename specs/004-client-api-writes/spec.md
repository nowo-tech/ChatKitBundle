# Feature specification: client API v1 writes (2.5.0)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Implemented (2.5.0, unreleased)
**Design**: time-track-desktop `docs/design/BEACON_DESKTOP.md` (phase BD-3); owner decisions Q-BD-1..19 (2026-11-25) and
the prototype review Q8 (thread replies and reactions in BD-3); spec 259 (attachments, cards) comes after, in 2.6–2.8.
**Plan**: [`plan.md`](plan.md) · **Tasks**: [`tasks.md`](tasks.md) · **Reference**: [`docs/API.md`](../../docs/API.md#writes-25)

## Summary

Native clients (Beacon Desktop) write through the client API v1: send a message, reply in a thread, add and remove a
reaction, and edit or delete their own message. Same rules and side effects as the web UI, an `Idempotency-Key` on every
write, off until the host turns writes on and grants `chat:write`.

## User scenarios

- **US-01 (P1)**: a desktop holding `chat:read` + `chat:write` sends a message; the recipients get the unread badge,
  the mention, the Web Push and the realtime hint exactly as for a message sent in the dock.
- **US-02 (P1)**: the network drops the answer; the desktop retries with the same `Idempotency-Key` and gets the first
  message back (`replayed: true`); no duplicate is ever stored, also when the retry races the first attempt.
- **US-03 (P1)**: a read-only channel, a blocked direct conversation or a closed ticket refuses the write (403
  `read_only`), as the dock's composer would.
- **US-04 (P2)**: the desktop replies in a thread and sets / removes reactions from two devices without duplicates.
- **US-05 (P2)**: a token approved for reading only, or a server with writes off, never writes (403 / 404), and
  `me.features` tells the desktop which actions to show.
- **US-06 (P3)**: the desktop edits or deletes its own message within the edit window.

## Functional requirements

- **FR-CAPW-001**: write routes `POST conversations/{uuid}/messages`, `POST …/messages/{messageUuid}/thread`,
  `PUT`/`DELETE …/messages/{messageUuid}/reactions/{emoji}`, `PATCH`/`DELETE …/messages/{messageUuid}`; OpenAPI kept in
  step by the parity test.
- **FR-CAPW-002**: off unless `client_api.writes: true` (404); scope `chat:read` and `chat:write` (403
  `insufficient_scope`); ChatKit grants no scope.
- **FR-CAPW-003**: lookups through the context policy and the web UI's voter (`SEND`, `THREAD`, `REACT`, `EDIT`,
  `DELETE`); unknown, foreign and out-of-context uuids 404 (audited).
- **FR-CAPW-004**: writes go through `ChatService` (mentions, references, links, support status, read cursors, unread
  counters, realtime, Web Push, digest, search index, events), never a second copy of the pipeline.
- **FR-CAPW-005**: `Idempotency-Key` required (400), scoped to token + conversation (hash), claimed in the write's unit
  of work; same request replays (`replayed: true`, nothing written), another request 422 `idempotency_mismatch`.
- **FR-CAPW-006**: body `{text}` only; other fields 422 `unsupported_field` (forward-compatible with 2.6 typed card
  references behind `me.features.cards`).
- **FR-CAPW-007**: budgets: per-token writes (`writes_per_token_per_minute`), the person's action budget, and the
  dock's send budget for messages and replies; 429 + `Retry-After`.
- **FR-CAPW-008**: `me.features` (`send`, `threadReply`, `reactions`, `editOwn`, `deleteOwn`, `cards`), `me.limits`
  (`textMaxLength`, `editWindowSeconds`, `idempotencyKeyMaxLength`) and `me.reactions` (`quick`, `accepted`).
- **FR-CAPW-009**: `ChatClientApiWriteEvent` after each write or replay (ids only).
- **FR-CAPW-010** (REQ-RUNTIME-003): duplicate sends under retry, concurrent reactions and two senders' unread counters
  are tested with two worker processes on MySQL, MariaDB and PostgreSQL.
- **FR-CAPW-011** (worker mode): no request data in services; a write loop on one kernel without resetter.

## Out of scope

Attachments, secret cards, composer slots, unfurl (spec 259, 2.6–2.8), pins, mute and archive through the client API.
