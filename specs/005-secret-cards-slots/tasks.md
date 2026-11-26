# Tasks: typed cards and host slots (2.6.0)

- [x] T001 Contracts, value objects, enums, `ChatCards`, autoconfiguration tags
- [x] T002 `ChatReferences`: card validation (canShare outside the lock, refusal, cap), per-reader rendering, secret
      plain text, `withCards()`, `keepCards()`; `ChatService` events and edits; forward refusal
- [x] T003 Dock: slots and card routes, `cards` on send, `hasCards`, dock config `slots`, slot dialog, menu slot items
- [x] T004 Client API: `me.features.cards`, `cards` on send / thread reply, `card` tokens with the API scope,
      `composerActions`; OpenAPI 1.3.0
- [x] T005 Front end: `slots.ts` (tray, insert, slot dialog, payload, before-send), `thread.ts`, `api.ts`, `index.ts`,
      CSS, `key` icon; translations (7 locales)
- [x] T006 ZCG-v-I1: `ChatIdempotency::isClientKey()` in the dock and `ChatMessageMenu::assertKey()`
- [x] T007 Tests: unit, integration, functional (SC-001 leak matrix), worker, REQ-RUNTIME-003 races, Vitest
- [x] T008 Docs: CHANGELOG, UPGRADING (Beacon adoption), USAGE, API.md, SECURITY, CONCURRENCY, CONFIGURATION, worker
      audit, code inventory
