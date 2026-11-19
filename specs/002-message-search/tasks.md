# Tasks: message search (1.37.0)

- [x] T001 Spec, plan, owner decision reference (ROADMAP "Decided" 2).
- [x] T002 Configuration `search.*`, `search_kit.*`, `limits.searches_per_minute`; extension parameters, rate limiter,
      conditional schema listener and SearchKit provider.
- [x] T003 `ChatSearchText`, `ChatMessageSearchQuery`, `ChatSearchQueryException`, `ChatMessageSearchHit`,
      `ChatMessageSearchPage`, `ChatSearchScope`.
- [x] T004 `ChatMessageSearchSql` (visibility, retention, filters, keyset, per-platform text match).
- [x] T005 `ChatMessageSearch` (scope, cursor, author, retention, per-row re-check, refill, `find()`).
- [x] T006 `ChatSearchIndex`, `ChatSearchSchemaListener`, `nowo:chat:search-index` (CONFLICT_001 on lock conflicts).
- [x] T007 `ChatSearchController` (page + API), `ChatMessageSearchType`, `search.html.twig`, dock header link, CSS,
      translations (7 locales).
- [x] T008 `ChatMessageSearchProvider` (SearchKit: group, `from:`, paging, recents).
- [x] T009 Tests: unit (text rules, platform SQL, index, listener, command), integration (service edges, provider),
      functional (permission matrix, oracle-free answers, escaping, bounds, rate limit, paging, kept-alive kernel),
      real databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16), Playwright e2e on the demo.
- [x] T010 Docs: USAGE, CONFIGURATION, UPGRADING, CONCURRENCY, SECURITY, FRANKENPHP-WORKER-AUDIT, ROADMAP, CHANGELOG
      `[Unreleased]`, code inventory.
