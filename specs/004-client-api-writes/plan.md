# Plan: client API v1 writes (2.5.0)

## Units

- `ChatClientApiController`: write routes, key and body validation, error mapping (`WRITE_ERRORS`).
- `ChatClientApiGuard`: `writes()`, the write scope (read + write), the per-token write budget and the send budget,
  `written()` (event).
- `ChatService`: `editWith()`, `deleteWith()`, `setReaction()` (claim/complete closures inside the unit of work);
  `sendWith()` and `ChatThreads::reply()` already took them (1.36).
- `ChatClientApiWriteEvent` (new).
- Configuration: `client_api.writes`, `client_api.limits.writes_per_token_per_minute`; limiter `nowo_chat_client_write`;
  parameter `nowo_chat_kit.client_api.writes`.

## Decisions

- Reuse `ChatIdempotency` and its table (no schema change). The stored key is `client:` + sha256(token id, conversation
  uuid, key): dedup per token and conversation, fits the 80-character column.
- Reactions are set / removed, not toggled: a retry or a second device can never flip them.
- A host that replaced `ChatServiceInterface` gets no write route (its writes cannot join the key's unit of work).
- Posting rights are checked before the key replay (no answer leaks to a person who lost the right).
- Unknown body fields are refused, never dropped (2.6 card references are added beside `text`).
- Edit and delete are included: the dock already has them, the voter and service are reused (cheap).
