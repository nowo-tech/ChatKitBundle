# Plan: typed cards and host slots (2.6.0)

## Units

- New: `Card/ChatCards` (providers, isolation, batching), contracts `ChatCardProviderInterface`,
  `ChatComposerActionProviderInterface`, `ChatMessageMenuSlotProviderInterface`; value objects `ChatCard`,
  `ChatCardFact`, `ChatCardAction`, `ChatCardScope`, `ChatComposerScope`, `ChatComposerAction`, `ChatMenuSlot`,
  `ChatCardText`; enums `ChatCardState`, `ChatCardActionKind`, `ChatMenuSlotTarget`; event `ChatCardSharedEvent`;
  `slots.ts`.
- Extended: `ChatReferences` (validate, render, plain text, `withCards()`, `keepCards()`), `ChatReference` (`card`),
  `ChatService` (events, edit keeps cards), `ChatForwarder` (refusal), `ChatPresenter` (`hasCards`), push, digest,
  search, `ChatMessageTokens`, `ChatApiController` (slots, card, `cards`), `ChatClientApiController`, `ChatDockExtension`
  (`slots`, slot items), `Configuration` (`slot:<key>`), templates, `thread.ts`, `api.ts`, `index.ts`, CSS,
  translations; `ChatIdempotency::isClientKey()` (ZCG-v-I1).

## Decisions

- Cards reuse the reference token grammar: no table, no migration (contract §8.4).
- Every card stores the neutral title, secret or not: titles are rendered per reader, so nothing stale or sensitive
  is stored (stricter than the contract, which kept titles for non-secret cards).
- Edits change the text only (cards are fixed at send); the contract's "canShare on edit" therefore never triggers.
- Menu slots are asked once per conversation with a `ChatComposerScope`, not per message: the dock applies target,
  card types and `ownOnly`; the host dialog checks the message again.
- Dialog URLs are root-relative paths only (no absolute same-origin URL).
- A slash alias colliding with a reference / card type is dropped at runtime (providers answer per scope, so no
  build-time check is possible).
- Cards in the composer are a chip tray, sent after the text; they are not kept in the draft.
