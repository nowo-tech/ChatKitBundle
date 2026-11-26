# Feature specification: typed cards, composer action slots and message menu slots (2.6.0)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Implemented (2.6.0, unreleased)
**Design**: spec 259 design contract (`.uic-work/chatkit-259/CONTRACT.md`), block A only (§5.3–5.5, §6 JS events,
§14, §17.2 cards, `composerActions`); owner decisions Q-259-1..12 as recommended, QB-259-1 = A (D-20261126-04),
QB-259-4 (BD-3 read-only cards), QB-259-8 (one `push_preview` switch). Attachments (2.7.0) and unfurls / Files tab
(2.8.0) are out of scope.
**Plan**: [`plan.md`](plan.md) · **Tasks**: [`tasks.md`](tasks.md) · **Reference**: [`docs/USAGE.md`](../../docs/USAGE.md#typed-cards-and-host-slots-26), [`docs/API.md`](../../docs/API.md#writes-25)

## Summary

Hosts share typed cards in messages (Beacon: vault items and one-time secrets) through composer action slots, act on
messages, selections and cards through message menu slots, and warn about secrets before a send. ChatKit stores only a
reference (`#[…](type:id)`) and never a secret value, key or vault title.

## User scenarios

- **US-01 (P1)**: a writer opens "One-time secret" (button or `/secret`), the host dialog creates the share and inserts
  a card; the message shows the card, and each reader sees it open, locked (request access) or not at all.
- **US-02 (P1)**: a card the writer may not share is refused (422 `card_not_allowed`): nothing is sent.
- **US-03 (P1)**: push, digest, previews and search never show a secret card's title (generic "🔑 Shared a secret").
- **US-04 (P2)**: a reader selects text and picks "Save to vault"; the selection reaches the host dialog in the
  browser only.
- **US-05 (P2)**: the host warns before a send that contains a secret; the person may send anyway.
- **US-06 (P2)**: Beacon Desktop (client API) reads cards (`card` object) and may send `cards: [{type, id}]`.

## Functional requirements

- **FR-CARD-001**: `ChatCardProviderInterface` (tag `nowo_chat_kit.card_provider`): key, label, icon, `isSecret()`,
  `canShare(ChatCardScope, id)`, `render(ChatCardScope, ids)` returning `ChatCard`s; keys unique across card and
  reference types.
- **FR-CARD-002**: a card is stored as `#[…](type:id)` (neutral title); on send `canShare()` once per card outside any
  lock; one refusal refuses the message (`card_not_allowed`); cards count towards the 10 references.
- **FR-CARD-003**: per-reader rendering in the reference pass (≤ 50 ids per call, one call per type and conversation,
  memoised per request); Open / Locked full cards, hidden = neutral chip.
- **FR-CARD-004**: secret cards: generic line in push, digest, previews and search; never the title (§14.4).
- **FR-CARD-005**: an edit keeps the message's cards (not asked again); messages with cards are not forwarded.
- **FR-CARD-006**: `ChatCardSharedEvent` (ids only) after the commit, once per card.
- **FR-SLOT-001**: `ChatComposerActionProviderInterface` (tag `nowo_chat_kit.composer_action`): buttons and slash
  aliases opening a same-origin host dialog with `?conversation=`; `nowo-chat:insert-card` inserts a card chip.
- **FR-SLOT-002**: `ChatMessageMenuSlotProviderInterface` (tag `nowo_chat_kit.message_menu_slot`): message, selection
  and card slots placed by `message_menu.order` (`slot:<key>`); the selection reaches the dialog through
  `nowo-chat:slot-payload` only.
- **FR-SLOT-003**: `nowo-chat:before-send` (cancelable, read-only text, `sendAnyway()`).
- **FR-SLOT-004**: dock routes `GET …/slots` and `GET …/cards/{type}/{id}`; dock send accepts `cards`.
- **FR-CAPC-001**: client API: `me.features.cards`, `cards` on send and thread reply (`invalid_cards`,
  `card_not_allowed`), `card` object on reference tokens, `composerActions` on a conversation; OpenAPI 1.3.0.
- **FR-IDEM-002** (audit ZCG-v-I1): the `client:` key namespace is refused in any letter case by the dock and the DDD
  facade.

## Success criteria

- **SC-001**: no table, push payload, hub payload or log holds a secret card's title or a host sentinel.
- **SC-002**: a refused card writes nothing; a card is never stored as plain text.
- **SC-003**: one `render()` per type and conversation for a page; nothing memoised across requests (worker test).
- **SC-004**: REQ-RUNTIME-003 races (card sends vs deletes, reads vs sends and deletes) answer every call on MySQL,
  MariaDB and PostgreSQL.
