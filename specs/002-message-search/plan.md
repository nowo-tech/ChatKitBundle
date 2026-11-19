# Implementation plan: message search (1.37.0)

**Spec**: [`spec.md`](spec.md) · **Tasks**: [`tasks.md`](tasks.md)

## Design

- **No new table.** The search reads `nowo_chat_message`; the database maintains the full-text index inside the existing
  message writes, so there is no derived data to keep in sync, replay or erase (REQ-RUNTIME-003: no new write unit; the
  only new write path is the operator's index DDL).
- **One statement per read** (`ChatMessageSearchSql`, DBAL with the mapped column names, so table prefixes and the
  `id` / `string` user-reference modes work): `messages JOIN conversations`, the visibility branches (`direct` via the
  viewer's participant row, `support` via the owner column or every ticket for an agent, `channel` via the keys the
  provider lists now), kind, deletion, retention (`created_at` and the thread root's), the filters, the keyset
  (`id < cursor`), the text match, `ORDER BY id DESC LIMIT n + 1`.
- **Second check in PHP** (`ChatMessageSearch`): `ChatAccess::canView()` per row and the words against the viewer's
  plain text (`ChatReferences::plainText()`), so hidden `#reference` titles never match. Pages are refilled for at most
  five reads; the cursor then continues from the last readable row.
- **Oracle-free answers**: no count; unknown and forbidden filters / cursors return the same empty page before any
  text query runs; a cursor is a message uuid accepted only when readable.
- **Index** (`ChatSearchIndex`, `ChatSearchSchemaListener`, `nowo:chat:search-index`): MySQL / MariaDB FULLTEXT mapped
  through `postGenerateSchema` (a diff creates it), PostgreSQL GIN expression index built `CONCURRENTLY` (invisible to
  DBAL, so a diff never drops it). Lock conflicts during the build → `CONFLICT_001` (transaction-doctrine classifier).
- **UI**: a GET FormKit form (`ChatMessageSearchType`, empty form name → plain query parameters) on the UiKit page
  scaffold (`search.html.twig`, same structure as the Saved page), excerpt segments escaped by Twig with `<mark>`
  added by the template; a dock header link. No new TypeScript and no override.
- **SearchKit** (`ChatMessageSearchProvider`): registered by the extension only when the interfaces exist and
  `search_kit.enabled`; reuses `ChatMessageSearch`; deny by default for identifiers other than the signed-in user,
  scopes and unsupported filters.

## Assumptions (no owner answer needed to ship; listed in the PR)

1. Membership for channels is the dock's: the channels `channelsFor()` lists for the viewer, re-checked with
   `canUse()`. A channel the provider would let the viewer open but does not list is not searched.
2. "Visible at the time" = readable now (the same rule as the conversation page, evaluated on every search, never
   cached); ChatKit has no per-member history window, so a member sees the whole history of a conversation they read.
3. Messages older than the purge's retention window are hidden from search even before the purge runs; an unreadable
   retention configuration answers no rows.
4. Newest first, no relevance ranking (keyset paging stays exact); SearchKit synonyms are not expanded in SQL (the typed
   text only).
5. The palette group answers only in the signed-in user's request (no `IdentifierAwareSearchProviderInterface`), and
   scoped searches get no chat rows.
