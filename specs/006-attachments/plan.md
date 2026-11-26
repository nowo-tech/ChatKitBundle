# Plan: chat attachments and the secret hand-off (2.7.0)

- **Storage**: StorageKit 2.2 generic ports (`FileUploaderInterface`, `FileReaderInterface`,
  `FileRangeDeliveryInterface`, `FileRemoverInterface`, `FileReferencesInterface`), all behind the optional
  `Attachment` namespace registered only with `attachments.enabled` (the services that exist anyway take nullable
  collaborators: off = 2.6 behaviour).
- **Data**: one table `nowo_chat_attachment` (conversation and message FKs `SET NULL`: rows survive purges as
  `deleted` until their objects are removed), every write a DBAL compare-and-set in `ChatAttachmentRepository`.
- **Concurrency** (REQ-RUNTIME-003): no I/O under a lock; lock order conversation → attachments (id) → message;
  idempotency keys on upload and send; ChatKit's runner (rollback to entry level, close on a lost savepoint, rethrow).
- **Worker mode**: stateless services; per-call streams closed in `finally`; the HMAC key is container data.
- **Front end**: `attachments.ts` (tray, cards, downloads) and `secret-handoff.ts`, wired into `thread.ts`,
  `thread-panel.ts`, `dock.ts`; UiKit button classes via the `secret-warning` template; Modern Web Guidance (native
  buttons, `<progress>` for in-progress states, a polite status region for errors, Escape closes the warning).
