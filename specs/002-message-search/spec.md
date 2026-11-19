# Feature specification: message search (1.37.0)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Implemented (1.37.0, unreleased)
**Owner decision**: 2026-11-18, ROADMAP "Decided" item 2 — message search is a built-in database full-text search,
filtered to the messages each viewer can see, plus a SearchKit provider for the Ctrl/⌘+K palette.
**Plan**: [`plan.md`](plan.md) · **Tasks**: [`tasks.md`](tasks.md)

## Summary

A viewer types words and gets the messages that contain them, newest first, from every conversation they may read
now. The search runs in the database (MySQL / MariaDB `FULLTEXT`, PostgreSQL `tsvector`, a `LIKE` fallback elsewhere)
and the permission filter is part of the same SQL statement. Results come as a server-rendered page (works without
JavaScript), as a JSON endpoint, and optionally as a group of the SearchKit palette.

The feature is off by default (`search.enabled: false`) and stores no new data: it reads the existing message table.

## User scenarios

### US-01 — Find a past message (P1)

**Given** search is enabled and the viewer belongs to some conversations,
**when** the viewer searches for `invoice march`,
**then** the page lists the messages that contain both words (prefixes match: `inv` finds `invoice`), newest first,
each with the conversation title, the author, the time, a short plain-text excerpt with the words marked, and a link to
the message in its conversation; a "More results" link loads the next page.

### US-02 — Never see what one may not read (P1)

**Given** a message in a conversation the viewer is not part of (another person's direct conversation, a channel the
host's provider does not grant, a support ticket of someone else for a non-agent),
**when** the viewer searches for a word it contains,
**then** nothing about it shows: no row, no count, no excerpt, no different response or page size, no different
answer for an unknown and a forbidden conversation filter, author filter or cursor.

### US-03 — Removed text stays removed (P1)

Deleted messages, erased authors' messages (GDPR erasure turns them into tombstones), messages older than the
retention window (they are due for the purge) and their thread replies, system, bot, buzz and pin lines are never
found. A `#reference` the viewer may not open never matches its hidden title.

### US-04 — Narrow the search (P2)

Filters: one conversation, one author (`me` or a public id), a date range, "has a link". An unknown or unreadable value
gives the same empty answer as a value with no match.

### US-05 — The palette (P2)

**Given** SearchKit ≥ 1.8 is installed and `search_kit.enabled: true`,
**when** the viewer types in the Ctrl/⌘+K palette,
**then** a **Messages** group lists the same rows the search page would list (same permission rules), with a plain-text
excerpt; "See all" opens the search page; the results page pages the group; recents are re-checked on every display.

### US-06 — Operate it (P2)

The integrator generates the index with `doctrine:migrations:diff` (MySQL / MariaDB) or the SQL printed by
`nowo:chat:search-index` (PostgreSQL, built `CONCURRENTLY`), reads the build-time notes in UPGRADING, and enables the
option.

## Functional requirements

- **FR-001** `search.enabled` (default false) turns on the page `GET /chat/search`, the endpoint `GET /chat/api/search`
  and the dock header link (`search.dock_entry`, default true). Off: both routes answer 404 and the dock shows nothing.
- **FR-002** Engines: MySQL 8.4 and MariaDB 11.4 use `MATCH (body) AGAINST (… IN BOOLEAN MODE)` with `+word*` terms
  for words of letters and digits at least `search.mysql_min_token_length` long; PostgreSQL 16 uses
  `to_tsvector('<config>', body) @@ to_tsquery('<config>', 'word:* & …')`; every other word, and every word on other
  platforms or with `search.fulltext: false`, is a case-insensitive `LIKE` with escaped wildcards. No operator of the
  user's text ever reaches the server.
- **FR-003** Visibility in SQL, evaluated at query time: direct conversations where the viewer has a participant row;
  support tickets the viewer owns, or every ticket for a support agent (`ChatAccessPolicyInterface::isSupportAgent()`);
  channels the provider grants now (`channelsFor()` plus the channels the viewer has a participant row in, each
  re-checked with `canUse()`). Every row is re-checked with `ChatAccess::canView()` before it is shown.
- **FR-004** Only `text` and `auto` messages that are not deleted, created inside the retention window (the same days
  the purge uses), whose thread root (if any) is inside it too. If the retention cannot be read, the search answers no
  rows (fail closed).
- **FR-005** The words must also appear in the text the viewer sees (`#references` resolved for the viewer, mention
  tokens as names, Markdown removed), checked after the SQL filter; rows that only matched hidden text are skipped
  without shortening the page (bounded rounds).
- **FR-006** Bounds: the text is trimmed, at least `search.min_query_length` (default 2) and at most
  `search.max_query_length` (default 200) characters, at most 8 distinct words; longer text answers `400
  query_too_long`; a rate limit `limits.searches_per_minute` (default 30) answers 429.
- **FR-007** Paging by keyset: newest first (`id` descending), `search.page_size` rows (default 20), `next` is the uuid
  of the last row; a cursor the viewer may not read answers like the end of the list.
- **FR-008** Snippets are plain text (at most 200 characters around the first match). The page marks the words with
  `<mark>` around Twig-escaped segments; the JSON carries plain text only; the palette receives plain text (SearchKit
  escapes it).
- **FR-009** The SearchKit provider (`ChatMessageSearchProvider`) is registered only when SearchKit's provider
  interface exists and `search_kit.enabled` is true. It answers only for the signed-in user whose identifier is the
  query's identifier (no rows for a search made by identifier), supports the `from:` filter (`me` or a public id),
  pages through `PaginatedSearchProviderInterface`, and re-checks recents (`SearchReferenceResolverInterface`). No total
  is reported (SearchKit shows the number of returned rows).
- **FR-010** Stateless services (FrankenPHP worker mode with kernel reset off); nothing is memoised outside the current
  request's attributes (ChatAccess memo).
- **FR-011** No query text is logged.

## Non-goals

- Relevance ranking (rows are newest first, as in the timeline).
- Searching edit history, attachments (not shipped yet) or other people's deleted text.
- Search inside one open conversation with jump-to-result in the timeline (roadmap item kept for later; the page's
  conversation filter covers the server side).

## Success criteria

- Real-database tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (job "Message search") prove the engines, the
  index and the visibility matrix.
- Functional tests prove the permission matrix, byte-identical answers for forbidden and unknown filters and cursors,
  escaping of `<script>` in excerpts (page, JSON, palette) and the worker-mode kept-alive kernel.
- 100% line coverage; PHPStan, CS, Rector and Igor green.
