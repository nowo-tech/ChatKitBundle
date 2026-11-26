# Tasks: client API v1 writes (2.5.0)

- [x] T001 Configuration (`client_api.writes`, write budget), extension, services, limiter, container parameter
- [x] T002 Guard: write scope, budgets, `writes()`, `written()`; `ChatClientApiWriteEvent`
- [x] T003 `ChatService::editWith()`, `deleteWith()`, `setReaction()`
- [x] T004 Controller: send, thread reply, reactions, edit, delete; `me` features, limits and reactions
- [x] T005 Tests: functional (routes, codes, side effects, context, worker loop), edges, configuration
- [x] T006 REQ-RUNTIME-003 race modes (`capisend`, `capireact`, `capichat`) on MySQL, MariaDB and PostgreSQL
- [x] T007 Docs: API.md, OpenAPI 1.2.0, CONFIGURATION, USAGE, SECURITY, CONCURRENCY, worker audit, UPGRADING, CHANGELOG,
      code inventory
