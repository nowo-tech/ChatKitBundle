# Plan: typed card updates (2.8.0)

## Units

- New: `Contract/ChatCardUpdatesInterface` (BC promise, constants `MAX_MESSAGES`, `SCAN_WINDOW`),
  `Card/ChatCardUpdates` (default, aliased in `services.php`).
- Extended: `ChatMessageRepository::findCardHolders()` (window floor, then `LIKE … ESCAPE '!'` page with
  `HINT_REFRESH`).

## Decisions

- No new event type and no API change: the existing `message_updated` hint already makes the dock re-resolve the
  references of a viewer-less payload (`refsPending`) and Desktop re-read the message (`GET …/messages/{uuid}`), where
  cards are rendered for the reader.
- The conversation is required: no global search by card id (no cross-conversation or cross-tenant scan, no new
  table or migration). Beacon knows the conversation it posted the call card in.
- Post-commit is the caller's rule (documented): ChatKit runs on its own connection and cannot see the host's
  transaction; the service only reads, so calling it inside ChatKit's transaction is harmless.
- Failures never throw (the hint is best effort, like every realtime publish); a malformed type or id does (a
  programming error).
