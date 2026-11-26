# Feature specification: chat attachments and the secret hand-off (2.7.0)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Implemented (2.7.0, unreleased)
**Design**: spec 259 design contract (`.uic-work/chatkit-259/CONTRACT.md`), block B attachments only (§4–§10, §16,
§17, §18 `attachment`, §19–§22); owner decisions Q-259-1..12 as recommended (scan before send, scan state machine in
ChatKit, signed per-reader URLs + session, no forward of messages with files, archives opt-in, unscannable blocked),
QB-259-2 (DEVOPS clamd, fail-closed, 5-minute alert hook as an event), QB-259-3 = A (scope resolver), QB-259-4 (Desktop
BD-3 read-only), QB-259-6 (25 MB, worker limits), QB-259-8 (one `push_preview` switch); Beacon D-20261126-11 (owner
OK): "Send as a secret". Previews, unfurls and the Files and links tab are 2.8.0.
**Plan**: [`plan.md`](plan.md) · **Tasks**: [`tasks.md`](tasks.md) · **Reference**: [`docs/USAGE.md`](../../docs/USAGE.md#attachments-27), [`docs/API.md`](../../docs/API.md#attachments-27)

## User scenarios

- **US-01 (P1)**: a writer attaches files; each is scanned before anyone else sees it; the message goes out when every
  file is clean ("send when ready"); readers download through a URL signed for them.
- **US-02 (P1)**: an infected or uncheckable file is blocked; its uploader sees why; nobody else ever sees it.
- **US-03 (P1)**: deleting a message, the retention purge, a conversation purge or a GDPR erasure takes the files with
  it, objects included.
- **US-04 (P2)**: Beacon Desktop (client API) reads files with signed URLs (BD-3) and later uploads (BD-4).
- **US-05 (P2)**: the before-send detector flags a secret; the person picks "Send as a secret" and the host's dialog
  receives the draft in the browser; nothing is sent.

## Functional requirements

- **FR-ATT-001**: upload through StorageKit ^2.2 (context `chat`, `readable()` = []), name sanitized, type sniffed and
  allow-listed (magic and extension agree), bytes before any lock, one short unit (lock, access, quota, savepoint
  insert under the idempotency key), a failed unit expires the stored copy; the host's scope resolver picks the bucket.
- **FR-ATT-002**: mandatory scan (build fails without a scanner), CAS claim and verdict on a lease token, backoff,
  `failed` after `max_attempts`, unscannable blocked unless allowed, no fail-open path; the sweep re-queues and alerts
  (`ChatAttachmentScanOverdueEvent`).
- **FR-ATT-003**: send with files under the conversation lock, files locked in id order, all-or-nothing
  (`attachment_not_ready`, `attachment_invalid`, `too_many_attachments`); the text may be empty.
- **FR-ATT-004**: downloads signed per reader (5 min), bound to the session (web) or the token and context (API),
  re-checked on every request, one 404 for every refusal, StorageKit range delivery with a 60 s bucket URL, `nosniff`,
  sandbox CSP, `attachment`.
- **FR-ATT-005**: message payloads (dock, API, realtime `full`) carry clean files only; the dock shows file cards and a
  draft tray; the uploader gets an `attachment` realtime event.
- **FR-ATT-006**: files go with their message (delete, purges, erasure) in the same unit; objects removed after the
  commit (Messenger, sweep).
- **FR-ATT-007**: GDPR export lists the person's uploads (StorageKit uuids for the bytes); erasure deletes them.
- **FR-ATT-008**: limits: size (smallest of config, PHP, StorageKit), per message, drafts per person, uploads per
  minute, bytes per hour, downloads per minute.
- **FR-ATT-009**: events after the commit, ids only: uploaded, scanned, blocked, removed, downloaded (opt-in).
- **FR-ATT-010**: no forward of a message with files (`has_attachments`).
- **FR-HANDOFF-001**: `nowo-chat:before-send` gains `warn()` and, with both opt-ins (`web_ui.dock.secret_handoff` and a
  probe listener), `handOff()`; the hand-off dispatches `nowo-chat:secret-handoff` `{text, conversation, kind}` in the
  browser, clears the draft and its stored copy and sends nothing; without the opt-ins the dock is as in 2.6.

## Success criteria

- **SC-002**: no reader ever sees a draft or a non-clean file (dock, API, hub, push, digest), races included.
- **SC-ATT-1**: two-session races on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 end in one consistent state.
- **SC-ATT-2**: 300 worker rounds on one kernel: access follows the rows, memory flat.
- **SC-ATT-3**: 100 % PHP line coverage of the new code; Vitest gate.
