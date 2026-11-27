# Tasks: typed card updates (2.8.0)

- [x] T001 `ChatCardUpdatesInterface`, `ChatCardUpdates`, service alias
- [x] T002 `ChatMessageRepository::findCardHolders()` (bounded, escaped, refreshed)
- [x] T003 Tests: integration (holders, scope, payload without card content, bounds, failure)
- [x] T004 Docs: CHANGELOG, UPGRADING, USAGE (typed cards), API.md (realtime hint), code inventory
- [x] T005 Audit ZCW: window floor on top-level messages (L1), cut after the exact-token filter (I1), repository and
      real-server test of the escaping (I2), `HINT_REFRESH` note (I3)
