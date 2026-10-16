# Feature specification: Chat Kit Bundle (baseline)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Active (1.0)
**Last reviewed**: 2026-10-16
**Inventory**: [`code-inventory.md`](code-inventory.md) — 100% of the production units under `src/`.

## Summary

A LinkedIn-style floating chat for Symfony applications. Installing the bundle gives integrators:

- **Configuration** under `nowo_chat_kit` (`default_profile` + `profiles`), with the user entity (`user_class`), retention, realtime, Web Push, email digest, icons, reactions, rate limits and the full-page layout.
- **A Doctrine model** (`nowo_chat_*` tables) for direct conversations, application channels and support threads, with participants, read cursors, messages (Markdown + sanitised HTML, tombstones, buzz), reactions and per-user settings.
- **Contracts** the application implements: who exists (`ChatDirectoryInterface`) and who may talk to whom (`ChatAccessPolicyInterface`) are required; channels, realtime transport, Web Push, link previews and the email digest are optional with null defaults.
- **A JSON API** (`/chat/api/*` under the route prefix) used by the dock, and **a server-rendered full-page chat** (`/chat/`, `/chat/{uuid}`) that works without JavaScript.
- **A TypeScript dock** (IIFE `nowo-chat.js` or importable source) rendered from overridable Twig `<template>` markup and themed with `--nowo-chat-*` CSS tokens; icons through Symfony UX Icons.
- **Realtime** through Mercure private topics (polling fallback), presence and typing in a PSR-6 pool, **Web Push** and an **email digest** through Messenger, **GDPR** export / erasure helpers and a retention purge command.

## User scenarios

### US-01 — Install and configure (P1)

**Given** a Symfony 7.4 / 8.x application with Doctrine and a user entity,
**when** the integrator installs the bundle (Flex recipe or manual registration), sets `user_class`, implements the two required contracts, imports the routes and renders `@NowoChatKitBundle/dock.html.twig`,
**then** signed-in, reachable users get the dock; guests and unreachable accounts get nothing; the container compiles with the defaults for every optional contract.

### US-02 — Direct messages (P1)

**Given** two users the policy allows to talk,
**when** one opens a conversation from the people picker and sends a message,
**then** the other sees it (live with Mercure, else on the next poll) with unread counts, can reply, react, see read receipts, and the author can edit or delete within 15 minutes; a pair has exactly one conversation; losing the right to talk makes it read-only.

### US-03 — Application channels (P2)

**Given** a channel provider (e.g. one channel per project),
**when** a user with access opens the channel (or the page's context channel in the picker),
**then** every member can read and write; losing access hides it; agents can moderate.

### US-04 — Support (P1)

**Given** support agents defined by the policy,
**when** a user writes to "Support" (from the dock or a `data-nowo-chat-open="support"` link with page context),
**then** every agent sees the thread, can answer, resolve and reopen it; the owner's new message reopens it; the inbox API and `ChatSupportInbox` list threads with filters and an open count; threads never written to stay out.

### US-05 — Live conversation state (P2)

**When** users chat, **then** typing indicators, presence (reciprocal opt-out), read receipts, buzzes and sounds work across tabs (BroadcastChannel) and reconnect after network loss.

### US-06 — Notifications outside the app (P2)

**Given** Messenger and the host's push sender / digest sender,
**when** a message arrives and the recipient has no visible tab, **then** a Web Push notification is sent (opt-in, not muted, unread); **when** it is still unread after the digest delay, **then** one email per interval lists the conversations (opt-out).

### US-07 — Full-page chat (P2)

**When** a user opens `/chat/` or "Open in a page", **then** the list and one thread render on the server inside the configured layout, and a Symfony Form posts a message (CSRF, rate limit, read-only) with a redirect back — without JavaScript.

### US-08 — Customisation (P3)

**When** the host overrides `@NowoChatKitBundle/_templates.html.twig` blocks, the CSS tokens or the icon map, **then** the dock uses them without forking the TypeScript.

### US-09 — Privacy and retention (P2)

**When** a user exports or erases their data, **then** `ChatPersonalData` returns / removes their chat data; **when** `nowo:chat:purge` runs, **then** messages older than `retention_days` are deleted in batches.

### US-10 — Links between the app and the chat (P3)

**When** a message contains a link to the application, **then** the dock shows a card from the host's preview provider, checked for the viewer; **when** any page element has `data-nowo-chat-open`, **then** clicking it opens that conversation.

## Functional requirements

### Bundle, configuration and DI

- **FR-BUNDLE-001** — The bundle class registers the Twig namespace (`@NowoChatKitBundle`, `@!NowoChatKitBundle`) and compiler passes.
- **FR-CFG-001** — `nowo_chat_kit` accepts `default_profile` + `profiles` (enabled, user_class, retention_days ≥ 30, realtime, push, push_url, push_icon, digest, page_layout, cache_pool, reactions, icon_set, icons, presence_ttl, limits); `default_profile` must be a profile key.
- **FR-CFG-002** — The extension exposes `nowo_chat_kit.*` parameters and prepends Doctrine `resolve_target_entities` (from `user_class`), the rate limiters, the `nowo_chat_kit` asset package and Messenger signing.
- **FR-DI-001** — Services are autowired; every optional contract has a null default the application can alias.
- **FR-DI-002** — The realtime transport is Mercure when a hub exists, otherwise the null transport (polling).
- **FR-ROUTE-001** — One attribute-route loader (`Resources/config/routes.php`) imported with a prefix.

### Domain model and persistence

- **FR-MODEL-001** — Conversations are `direct` (unique pair key), `channel` (unique key) or `support` (one per owner, open / resolved, optional context); messages keep Markdown and sanitised HTML, edits, tombstones (moderated or not) and buzzes.
- **FR-MODEL-002** — Participants hold read cursors and mute; reactions are unique per user, message and emoji; per-user settings (sound, push, buzz, presence, email digest).
- **FR-MODEL-003** — Repositories provide the list per user (DMs, channels, support), cursor pages, unread counts, the support inbox, recipients and batched purge.
- **FR-MODEL-004** — Value objects for contracts: `ChatChannel`, `ChatContact`, `ChatLinkPreview`, `ChatDigestItem`; `ChatUserInterface` for the user entity.

### Contracts

- **FR-CONTRACT-001** — `ChatDirectoryInterface`: users by public id / ids, picker contacts, support agents.
- **FR-CONTRACT-002** — `ChatAccessPolicyInterface`: reachability, support agents, who may message whom.
- **FR-CONTRACT-003** — `ChatChannelProviderInterface`: channels per user, lookup, page context channel, access, members.
- **FR-CONTRACT-004** — `ChatRealtimeInterface`, `ChatPushSenderInterface`, `ChatLinkPreviewProviderInterface`, `ChatDigestSenderInterface` (optional, null defaults).

### Access control

- **FR-AUTHZ-001** — `ChatAccess` combines the policy, directory and channel provider (memoised per request only).
- **FR-AUTHZ-002** — `ChatVoter`: VIEW, SEND, MODERATE, RESOLVE on conversations; EDIT (own, 15 min), DELETE (own or moderator), REACT on messages.

### Messaging

- **FR-MSG-001** — `ChatService` opens conversations, sends (bounded, sanitised), buzzes, edits, deletes, toggles reactions, marks read, resolves / reopens support threads and records support context.
- **FR-MSG-002** — `ChatMarkdownRenderer`: escape-first Markdown subset, http(s) autolinks with `rel="noopener nofollow ugc"`, control / bidi character stripping, plain-text previews.
- **FR-MSG-003** — `ChatPresenter` shapes conversations and messages for the API, the page and realtime payloads.
- **FR-EVT-001** — Events `ChatMessageSentEvent`, `ChatMessageDeletedEvent` (moderated flag), `ChatSupportStatusChangedEvent`.
- **FR-SUPPORT-001** — `ChatSupportInbox`: agents' list (open / resolved / mine / all, search) and the open count.

### HTTP

- **FR-API-001** — `ChatApiController`: list, contacts, open (direct / channel / support with context), messages (cursor pages, send, edit, delete, reactions), read, typing, buzz, resolve / reopen, support inbox, link previews, realtime token, presence; CSRF header on writes, rate limits, bounded bodies, foreign UUID → 404, `no-store`.
- **FR-API-002** — `ChatApiException` + listener turn API failures into JSON errors with stable codes.
- **FR-PAGE-001** — `ChatPageController` + `ChatPageMessageType`: server-rendered list and thread, Symfony Form post with redirect, same permissions and limits as the API.

### Realtime, presence and notifications

- **FR-RT-001** — `ChatPublisher`, `ChatNotifier`, `ChatRecipients`, `ChatTopics`: events published on private per-user topics.
- **FR-RT-002** — `MercureChatRealtime` / `NullChatRealtime`: publish and 15-minute subscriber tokens limited to the caller's topic.
- **FR-RT-003** — `ChatPresence`: heartbeat TTL in the PSR-6 pool, reciprocal visibility.
- **FR-PUSH-001** — `ChatPushDispatcher` + `DeliverChatPushMessage` + `NullChatPushSender`: recipients chosen at delivery time (opt-in, unread, not muted, no visible tab).
- **FR-DIGEST-001** — `ChatDigestScheduler`, `ChatDigestDispatcher`, `SendChatDigestMessage`, `NullChatDigestSender`: delayed check, one email per interval, opt-out.
- **FR-PREVIEW-001** — `NullLinkPreviewProvider`; previews requested in batches of ten, per viewer.

### Privacy and retention

- **FR-PRIV-001** — `ChatPersonalData`: export and erasure of a user's chat data.
- **FR-RET-001** — `nowo:chat:purge`: deletes messages older than `retention_days` in bounded batches.

### Twig, markup and theme

- **FR-TWIG-001** — `ChatDockExtension`: dock config (endpoints, i18n, prefs, limits, icons) for reachable users; `nowo_chat_icons()`.
- **FR-TWIG-002** — Views: `dock.html.twig` (root + assets), `_templates.html.twig` (inert `<template>` markup cloned by the TypeScript, overridable per block), `page.html.twig` / `page_layout.html.twig` (full page).
- **FR-THEME-001** — `nowo-chat.css`: `--nowo-chat-*` tokens (light / dark), dock, windows, mobile sheet, full page.
- **FR-I18N-001** — `NowoChatKitBundle` translations in 7 locales (en, es, fr, de, it, pt, nl) with key parity.

### Browser (TypeScript)

- **FR-TS-001** — `index.ts` / `iife.ts`: mount / unmount / auto start (DOMContentLoaded, Turbo).
- **FR-TS-002** — `dock.ts`: launcher, list, filters, search, people picker, windows and overflow, tab sync, polling, realtime events, sounds, `data-nowo-chat-open` links, link-preview batching.
- **FR-TS-003** — `thread.ts`: messages, day separators, receipts, typing, composer (drafts, emoji, edit, delete, reactions), previews, support context.
- **FR-TS-004** — `api.ts` (JSON client with CSRF header), `realtime.ts` (EventSource with token refresh).
- **FR-TS-005** — `state.ts` (dock store, thread cache, drafts, BroadcastChannel), `templates.ts`, `dom.ts`, `format.ts`, `emoji.ts`, `sounds.ts`, `types.ts`.
- **FR-BUILD-001** — Vite builds the IIFE `Resources/public/js/nowo-chat.js` from the sources (build output, not a unit).

## Success criteria

- **SC-01** — `code-inventory.md` maps **90/90** production units (see its audit command).
- **SC-02** — PHPUnit: 100% line coverage of `src/`.
- **SC-03** — Vitest: 100% line coverage of `Resources/assets/src`.
- **SC-04** — PHPStan level 8 (with the FrankenPHP rulesets), Rector and PHP-CS-Fixer report nothing; Igor passes.
- **SC-05** — Translation key parity across the 7 locales.
- **SC-06** — The Symfony 8.1 demo boots (healthcheck), its PHPUnit and Playwright suites pass.

## Non-goals

- User accounts, authentication or the people directory (the application owns them through the contracts).
- Running a Mercure hub, a Web Push stack or a mailer (the host provides them).
- File attachments, message search and threads inside conversations (not in 1.0).

## Validation

```bash
make release-check          # hygiene, assets, QA, Igor, 100% coverage, Vitest, translations, demos
make test-coverage          # PHPUnit with coverage
make test-ts                # Vitest with coverage
make -C demo/symfony8 test-e2e
```
