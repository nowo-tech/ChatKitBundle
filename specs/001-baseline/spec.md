# Feature specification: Chat Kit Bundle (baseline)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Active (1.3)
**Last reviewed**: 2026-10-16
**Inventory**: [`code-inventory.md`](code-inventory.md) — 100% of the production units under `src/`.

## Summary

A LinkedIn-style floating chat for Symfony applications. Installing the bundle gives integrators:

- **Configuration** under `nowo_chat_kit` (`default_profile` + `profiles`), with the user entity (`user_class`), retention, realtime, Web Push, email digest, icons, reactions, rate limits and the full-page layout.
- **A Doctrine model** (`nowo_chat_*` tables) for direct conversations, application channels and support threads, with participants, read cursors, messages (Markdown + sanitised HTML, tombstones, buzz), reactions and per-user settings.
- **Contracts** the application implements: who exists (`ChatDirectoryInterface`) and who may talk to whom (`ChatAccessPolicyInterface`) are required; channels, realtime transport, Web Push, link previews and the email digest are optional with null defaults.
- **A JSON API** (`/chat/api/*` under the route prefix) used by the dock, and **a server-rendered full-page chat** (`/chat/`, `/chat/{uuid}`) that works without JavaScript.
- **A TypeScript dock** (IIFE `nowo-chat.js` or importable source) rendered from overridable Twig `<template>` markup and themed with `--nowo-chat-*` CSS tokens; icons through Symfony UX Icons. It rests above marked footers and the Symfony toolbar and can be hidden to the side (one right-edge tab with the unread count).
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
**then** every member can read and write; losing access hides it; only authors delete their own messages (edit window).

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

**When** a user opens `/chat/` or "Open in a page", **then** the list and one thread render on the server inside the configured layout, and a Symfony Form posts a message (CSRF, rate limit, read-only) with a redirect back — without JavaScript. Opening the page does not change state (GET): the page script marks the conversation read with a CSRF-protected POST once the tab is visible; without JavaScript, sending a message does.

### US-08 — Customisation and dock layout (P3)

**When** the host overrides `@NowoChatKitBundle/_templates.html.twig` blocks, the CSS tokens or the icon map, **then** the dock uses them without forking the TypeScript; **when** the page marks a footer with `data-nowo-chat-avoid` (or shows the Symfony web debug toolbar), **then** the dock rests just above it.

**Given** an open dock (list and conversation windows),
**when** the user clicks **Hide to the side** in the list header,
**then** the list and every window leave the screen and a single tab on the right edge (chat icon + total unread count, positioned by `--nowo-chat-edge-offset`) remains; **when** the user clicks that tab, or opens a conversation from a link, the list or a push notification, **then** the dock comes back exactly as it was; the tucked state is kept across page loads and mirrored to the other browser tabs.

### US-09 — Privacy and retention (P2)

**When** a user exports or erases their data, **then** `ChatPersonalData` returns / removes their chat data; **when** `nowo:chat:purge` runs, **then** messages older than `retention_days` are deleted in batches.

### US-10 — Links between the app and the chat (P3)

**When** a message contains a link to the application, **then** the dock shows a card from the host's preview provider, checked for the viewer; **when** any page element has `data-nowo-chat-open`, **then** clicking it opens that conversation.

## Functional requirements

### Bundle, configuration and DI

- **FR-BUNDLE-001** — The bundle class registers the Twig namespace (`@NowoChatKitBundle`, `@!NowoChatKitBundle`) and compiler passes.
- **FR-CFG-001** — `nowo_chat_kit` accepts `default_profile` + `profiles` (enabled, user_class, retention_days ≥ 30, realtime, push, push_url, push_icon, push_preview, digest, doctrine.table_name_prefix, doctrine.table_prefix, page_layout, cache_pool, reactions, icon_set, icons — including `tuck` since 1.2 —, presence_ttl, limits); `default_profile` must be a profile key; only the default profile is active (others are validated, not used).
- **FR-CFG-002** — The extension exposes `nowo_chat_kit.*` parameters and prepends Doctrine `resolve_target_entities` (from `user_class`), the rate limiters, the `nowo_chat_kit` asset package and Messenger signing.
- **FR-DI-001** — Services are autowired; every optional contract has a null default the application can alias.
- **FR-DI-002** — The realtime transport is Mercure when a hub exists, otherwise the null transport (polling).
- **FR-ORM-001** — `TablePrefixListener` replaces the `nowo_chat_` part of the bundle's table, index and unique-constraint names with `doctrine.table_name_prefix` (default `nowo_chat_`: names unchanged), then prepends `doctrine.table_prefix` (bundle entities only). Both match `/^[a-z][a-z0-9_]*\z/`, keep every generated name within 63 characters and reject `%env()%`; only the default profile's `doctrine` options apply (a different value on another profile fails the build). The resolved names are published as `nowo_chat_kit.doctrine.tables` / `nowo_chat_kit.doctrine.table.<key>`, and native SQL reads them from the metadata.
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
- **FR-PAGE-001** — `ChatPageController` + `ChatPageMessageType`: server-rendered list and thread, Symfony Form post with redirect, same permissions and limits as the API. `GET` never moves the read cursor (1.3, REQ-SEC-005): the page wrapper carries `data-nowo-chat-read-url` / `-id` / `-csrf` for the page script (FR-TS-008).

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

- **FR-TWIG-001** — `ChatDockExtension`: dock config (endpoints, i18n, prefs, limits, icons) for reachable users; `nowo_chat_icons()`; the dock form views (FR-TWIG-004).
- **FR-TWIG-002** — Views: `dock.html.twig` (root + assets), `_templates.html.twig` (inert `<template>` markup cloned by the TypeScript, overridable per block), `page.html.twig` / `page_layout.html.twig` (full page; 1.3: extends the Twig global `nowo_chat_kit_page_layout`, adds the chat CSS / JS through the layout's `stylesheets` / `javascripts` blocks, each asset loaded once with the dock). 1.5: `page.html.twig` renders on the UiKit page contract (REQ-UI-003) with the `ChatPage` view data (page header, theme bundle name); `page_legacy.html.twig` keeps the deprecated 1.x full page while only `page_layout` is configured (removed in 2.0).
- **FR-TWIG-003** — Hide to the side (1.2): the list header carries a `data-part="tuck"` button (`chat.tuck`, icon `tuck`) and the `nowo_chat_template_edge` block defines the `edge` template (`.nowo-chat-edge` button, `chat.untuck` label, `edge-badge` unread count), hidden by default.
- **FR-TWIG-004** — Dock form types `ChatDockSearchType` (conversation search `query`, people picker `people`), `ChatDockComposerType` (`body`) and `ChatDockEditType` (`body`): built by `ChatDockExtension` and rendered with `form_widget` in `_templates.html.twig` so the dock has no raw `<form>` / `<input>` / `<textarea>` (REQ-TWIG-005); they are the markup contract only — the TypeScript cancels the submit and talks JSON to the API (CSRF header).
- **FR-THEME-001** — `nowo-chat.css`: `--nowo-chat-*` tokens (light / dark), dock, windows, mobile sheet, full page.
- **FR-THEME-002** — Shape, shadow and size tokens (1.1): `--nowo-chat-radius-*`, `--nowo-chat-shadow`, `-shadow-soft`, `-shadow-dock` (upward dock shadow), `--nowo-chat-window-width`, `-panel-width`, `-font-size`, `--nowo-chat-z-index`.
- **FR-THEME-003** — Tucked dock (1.2): `.nowo-chat.is-tucked` moves the list and windows off screen (layout kept); `.nowo-chat-edge` is a right-edge tab placed `--nowo-chat-edge-offset` (default `24px`) above the dock baseline (`--nowo-chat-lift`).
- **FR-I18N-001** — `NowoChatKitBundle` translations in 7 locales (en, es, fr, de, it, pt, nl) with key parity (including `chat.tuck` / `chat.untuck`).

### Browser (TypeScript)

- **FR-TS-001** — `index.ts` / `iife.ts`: mount / unmount / auto start (DOMContentLoaded, Turbo).
- **FR-TS-002** — `dock.ts`: launcher, list, filters, search, people picker, windows and overflow, tab sync, polling, realtime events, sounds, `data-nowo-chat-open` links, link-preview batching.
- **FR-TS-003** — `thread.ts`: messages, day separators, receipts, typing, composer (drafts, emoji, edit, delete, reactions), previews, support context.
- **FR-TS-004** — `api.ts` (JSON client with CSRF header), `realtime.ts` (EventSource with token refresh).
- **FR-TS-005** — `state.ts` (dock store, thread cache, drafts, BroadcastChannel), `templates.ts`, `dom.ts`, `format.ts`, `emoji.ts`, `sounds.ts`, `types.ts`.
- **FR-TS-006** — Bottom bars (1.1): `dock.ts` lifts the dock (`--nowo-chat-lift`) above the visible part of elements marked `data-nowo-chat-avoid` and of the Symfony web debug toolbar (real height), recomputed on scroll and resize.
- **FR-TS-007** — Hide to the side (1.2): `dock.ts` `setTucked()` toggles `is-tucked` on the root, makes the dock `inert`, shows the edge tab (focus moves to it) and keeps its badge in sync with the total unread count; `state.ts` persists `tucked` in the shared dock layout (localStorage, mirrored across tabs by BroadcastChannel); opening a conversation (list, `data-nowo-chat-open` link, `?chat=` / push) untucks.
- **FR-TS-008** — Page read state (1.3): `page.ts` `markPageRead()` (called by `autoStart`) POSTs `/api/conversations/{uuid}/read` with the `X-CSRF-TOKEN` header once the full-page tab is visible.
- **FR-BUILD-001** — Vite builds the IIFE `Resources/public/js/nowo-chat.js` from the sources (build output, not a unit).

### Automatic replies, avatars, badges, mentions and references (1.10–1.29)

- **FR-AUTOREPLY-001** — `ChatAutoReplier`: after a message, the other person of a direct conversation (and, in channels, the @mentioned people who chose so) answer with their automatic reply when it applies (away / busy / do not disturb or an out-of-office range), at most once per conversation per interval; never in support tickets.
- **FR-AVATAR-001** — Profile pictures: `ChatAvatarUrlResolverInterface` (default `NullChatAvatarUrlResolver`: initials); `ChatAvatars` primes the people of one response with a single `urlsFor()` call; `AvatarUrlPolicy` emits only `https://host[:port]` URLs without user info or same-origin paths and drops every other scheme; `avatar.ts` checks again and removes a picture that fails to load.
- **FR-BADGE-001** — Author badges: `ChatAuthorBadgeProviderInterface` (default `NoChatAuthorBadges`, never called) gives plain-text `ChatAuthorBadge` pills with an allow-listed `ChatAuthorBadgeVariant`, asked once per response by `ChatAuthorBadges` (`authorBadges` in the API and realtime events); `badges.ts` and `_author_badges.html.twig` print them escaped.
- **FR-MENTION-001** — `ChatMentions` keeps an `@[Name](publicId)` token only for a conversation member (never the author), rewrites the name to the current display name, stores one `ChatMessageMention` row per member and dispatches `ChatMentionEvent` (on edit only the added people); `@channel` / `@here` need `ChatChannelMentionPolicyInterface` and are rate limited; `ChatMentionErasure` / `ChatMentionsErasedEvent` report mentions rewritten when a user is erased; `mention.ts` is the composer picker.
- **FR-REF-001** — References to application items (`#[Title](type:id)`): `ChatReferences` goes through tagged `ChatReferenceProviderInterface` services, searches only what every person of the `ChatReferenceScope` may open and keeps a token on send or edit only for such an item; `reference.ts` is the `/task` and `#` picker.
- **FR-QUICKREPLY-001** — Per-user saved snippets (`ChatQuickReply`, `ChatQuickReplyRepository`) inserted from the composer toolbar (`composer-tools.ts`: formatting buttons, shortcuts, help).

### Links (1.19)

- **FR-LINK-001** — `ChatLinkUrls` validates and normalises URLs, detects the configured `links.providers` (`ChatLinkProvider`) by hostname and extracts the links of a message; nothing is fetched. `ChatLinks` adds, changes and removes pinned links, enforces per-conversation limits, keeps the message link index in step and broadcasts `links`.
- **FR-LINK-002** — Persistence: `ChatConversationLink` (pinned link, kept when its author is erased) and `ChatMessageLink` (index behind the Links panel) with their repositories.
- **FR-LINK-003** — `ChatLinksController`: JSON API for pinned links and the paged Links panel (search, provider filter), members only (unknown or foreign gives 404); who manages pinned links follows `links.channel_managers` through `ChatLinkPolicyInterface`, then `ChatPinPolicyInterface`, else nobody.
- **FR-LINK-004** — `nowo:chat:links:backfill` indexes links of messages written before 1.19 in batches, safe to repeat, `--after-id` resumes.
- **FR-LINK-005** — `links.ts`: chips bar, add / edit dialog, link card attachment and Links panel, built with DOM nodes (text through `textContent`).

### Channels, sidebar and preferences

- **FR-CHANNEL-001** — Optional channel policies of the application's channel provider: `ChatChannelWritePolicyInterface` (asked on every send, edit, reaction, typing ping, pin and link change), `ChatPinPolicyInterface` (who pins in channels; deny by default) and `ChatChannelOpeningEvent` (a listener refuses creating a channel's conversation; API 403 `channel_denied` through `ChatChannelDeniedException`).
- **FR-CHANNEL-002** — A channel key is compared byte for byte (`ChatChannelKey`: 1–120 bytes of valid UTF-8, no edge whitespace or control / format characters); on MySQL / MariaDB `ChannelKeyCollationListener` gives `channel_key` an exact collation.
- **FR-SIDEBAR-001** — `ChatSidebar` and `ChatSidebarController` (`GET …/api/sidebar`) merge sections of tagged `ChatSidebarSectionProviderInterface` services (`ChatSidebarSection`, `ChatSidebarItem`, `ChatSidebarAction`), leave out rows the viewer may not read and reserved keys; `sidebar.ts` renders the grouped, collapsible list in the page and, with `web_ui.dock.sidebar_sections`, the dock.
- **FR-PREFS-001** — `ChatPreferencesType` (sounds, volume … bound to `ChatUserSettings`) for a host settings screen, `ChatDockStatusType` for the dock's custom status; `prefs.ts` saves one preference at a time through the API, `status.ts` is the status menu and activity clock, `public.ts` is the supported entry of a host bundler (preferences and sounds only).

### Support tickets (1.x)

- **FR-SUPPORT-002** — `ChatSupportTickets` opens support (continue the active ticket or an intake draft), chooses a topic (number, default assignee, automatic replies), continues earlier tickets, changes status and assignment, posting a bot line and dispatching `SupportTicketCreated`, `Assigned`, `Reopened` and `StatusChanged` events; `ChatSupportPolicy` decides the readable number, the "continue or new?" question and read-only tickets; topics come from `ChatSupportTopicProviderInterface` (default `ConfiguredSupportTopicProvider` over `support.topics`) through `ChatSupportTopics`; `support.ts` is the in-window assistant.
- **FR-SUPPORT-003** — Ticket numbers are sequential per conversation table from one `ChatTicketCounter` row moved with a single-row `UPDATE`, in the ticket's transaction (`ChatTicketNumbers`).

### Application ports (1.24–1.27, REQ-ARCH-001)

- **FR-PORT-001** — `ChatServiceInterface` (default `ChatService`, decoratable) and `CurrentChatUserResolverInterface` (default `SecurityChatUserResolver`: the security user is the participant when it implements `ChatUserInterface`).
- **FR-PORT-002** — Framework-free twins `ChatChannelSourceInterface`, `ChatParticipantResolverInterface` and `ChatRealtimeTransportInterface` take `ChatRequestContext` / `ChatSecurityUser` instead of HttpFoundation or Security types; `Ports` and the `*Gate` classes pick the interface a host service implements (the deprecated one wins when both), `ChatRequestContexts` copies request and user without headers, cookies, session or body, and `ChatKitPortsPass` wires them and refuses a service implementing neither.
- **FR-PORT-003** — `ChatMessagingInterface` / `ChatMessaging`: the chat's application service by public uuid (compared byte for byte), answering read-only views (`ChatConversationView`, `ChatMessageView`, `ChatParticipantView`, `ChatLinkView`) and authorizing with the API's rules; an unknown or forbidden uuid gives the same `ChatNotFoundException`.

### User reference modes and private entity manager (1.24–1.30)

- **FR-USERREF-001** — `doctrine.user_reference`: `relation` (default, foreign keys to `user_class`), `id` (string column per former relation through `UserReferenceListener`, users loaded by `ChatUserReferenceDirectoryInterface`, a missing user stands in as `UnresolvedChatUser`) or `string` (`ChatUserReferenceInterface`); `ChatUserReferences` states the mode and asserts the user kind.
- **FR-USERREF-002** — A user reference is 1–64 characters of valid UTF-8 without edge whitespace or control characters (`ChatUserReference`, never trimmed); in string mode `StringReferenceSchemaGuard` fails closed when user columns still fold on MySQL / MariaDB and `ChatUserLookAlikeException` refuses an insert that would clash with a look-alike's row.
- **FR-USERREF-003** — `ErasedChatUser` and `ErasedChatUserReference` describe a gone account for `ChatPersonalData::erase()` / `eraseFormerAccount()` (rows by reference, mention tokens, bot-line parameters).
- **FR-PEM-001** — With `doctrine.private_entity_manager: true` ChatKit uses its own `ChatKitEntityManager` behind `ChatKitManagerRegistry`; `PrivateEntityManagerPass` and `PrivateEntityManagerReferencesPass` attach it to the source connection only and make every bundle service use it; each manager has its own `PrivateManagerRepositoryFactory`.
- **FR-PEM-002** — A DBAL middleware (`PrivateEntityManagerMiddleware`, `Driver`, `Connection`) reports rollbacks, savepoint releases and failed commits so a stale identity map is replaced before the next write.
- **FR-PEM-003** — `ChatKitSavepoint` runs a bundle write inside a savepoint when a transaction is open, so a failing statement never aborts the application's transaction.
- **FR-CONC-001** — `ChatKitRows::insert` writes rows whose unique key another request may take (participant, reaction, direct conversation) through DBAL in a savepoint, rolling back only the savepoint on a violation; `ChatKitRowLock::fetchOne` reads one row with a lock (`FOR UPDATE`, `FOR NO KEY UPDATE` on PostgreSQL, shared optional).
- **FR-CONC-002** — `ChatKitTransactionRunner` runs every ChatKit write in one transaction (never a runner the application binds) and `ChatKitWrites` flushes the chat manager inside it; realtime, events, push and mail stay with the caller after it returns.
- **FR-WORKER-001** — `ChatRequestBoundaryListener` resets the bundle's per-request memos (`ResetInterface` services) at every request and worker-message boundary it sees, so a kept-alive kernel without a services resetter never serves one request's memo to the next.

### Retention policy and events

- **FR-RET-002** — `RetentionPolicyProviderInterface` (default `NullRetentionPolicyProvider`) lets the application give the retention days at run time; `ChatRetention` uses it when usable, else `retention_days`, clamped to its minimum and maximum, asked again at every purge run; `InvalidRetentionConfigurationException` makes the purge refuse a non-positive configured value.
- **FR-EVT-002** — Additional events carry read-only views and scalars (no entity, no user object): link added / updated / removed, mention, mentions erased, channel opening, support ticket created / assigned / reopened / status changed.

### Browser modules

- **FR-TS-009** — Feature modules `avatar.ts`, `badges.ts`, `composer-tools.ts`, `mention.ts`, `reference.ts`, `sidebar.ts`, `status.ts`, `support.ts`, `prefs.ts`, `public.ts` extend the dock and thread; everything is built with DOM nodes and `textContent`, never HTML from user text.
- **FR-TS-010** — `motion.ts` closes dialogs with an effect (a fade plus a timer fallback) and `overlay.ts` makes the dock step aside through `nowo:overlay-open` / `nowo:overlay-close` and `data-nowo-overlay`.

## Key entities

| Entity | Source | Description |
| --- | --- | --- |
| **Conversation** | `Entity/ChatConversation`, `Enum/ConversationKind` | Table `nowo_chat_conversation`; public `uuid` (`PublicIdTrait`); `kind` is `direct`, `channel` or `support`; `lastMessageAt` orders the list. |
| — direct | `directKey` (unique) | Exactly one conversation per pair of users (key built from both user ids). |
| — channel | `channelKey` (unique, ≤ 120 chars) | Bound to a `ChatChannel` of the host's `ChatChannelProviderInterface`; membership is the provider's decision. |
| — support | `supportOwner` (unique), `Enum/SupportStatus` | One thread per owner; `open` / `resolved` (`resolvedBy`, `resolvedAt`), optional JSON `supportContext` (page URL / label). Every agent may read and answer. |
| **Participant** | `Entity/ChatParticipant` | Table `nowo_chat_participant`, unique per conversation + user; holds the **read cursor** (`lastReadMessageId`, only moves forward), `mutedUntil` and `joinedAt`. |
| **Message** | `Entity/ChatMessage` | Table `nowo_chat_message`; public `uuid`, author (nullable after erasure), Markdown `body` (≤ 4000 chars) and server-rendered `bodyHtml`, `kind` `text` or `buzz`, `editedAt` (15-minute edit window), tombstone `deletedAt` / `deletedBy` (moderated when deleted by someone else). |
| **Reaction** | `Entity/ChatReaction` | Table `nowo_chat_reaction`, unique per message + user + emoji (emoji from the configured `reactions` list). |
| **Read cursor** | `ChatParticipant::lastReadMessageId` | Last message id the user has read in a conversation; drives unread counts, receipts ("Seen by N"), push and digest eligibility. |
| **User settings** | `Entity/ChatUserSettings` | Table `nowo_chat_user_settings`, one row per user: `sound` (`none`, `pop`, `chime`, `ding`), `pushEnabled` (opt-in), `buzzEnabled`, `presenceVisible` (reciprocal), `emailDigest` (opt-out). |
| **Chat user** | `Model/ChatUserInterface` | Implemented by the host's user entity (mapped through `resolve_target_entities` from `user_class`): `getId()`, `getChatPublicId()`, `getChatDisplayName()`, `getChatInitials()`, `getChatEmail()`, `getChatLocale()`. |
| **Value objects** | `Model/ChatChannel`, `ChatContact`, `ChatLinkPreview`, `ChatDigestItem` | Data exchanged with the host contracts (channels, picker contacts, link cards, digest lines). |

## Assumptions

- The host application provides the **user entity** (implementing `ChatUserInterface`), authentication (SecurityBundle) and the people directory / access rules through `ChatDirectoryInterface` and `ChatAccessPolicyInterface`.
- **Doctrine ORM** is installed and the host generates and runs the migrations for the `nowo_chat_*` tables (renamed by `doctrine.table_name_prefix`, optionally prefixed by `doctrine.table_prefix`).
- **Realtime** needs a **Mercure hub** (`symfony/mercure-bundle`; FrankenPHP's built-in hub works) reachable by the browser; without one the dock falls back to polling.
- **Web Push** needs the host's push stack (VAPID keys, subscription storage, a Web Push library) behind `ChatPushSenderInterface`, plus a service worker that shows the notification.
- The **email digest** needs a mailer behind `ChatDigestSenderInterface`.
- **Symfony Messenger** is optional: routed to an async transport, Web Push delivery and the digest check run in workers; without Messenger, Web Push is delivered inline and the digest is not scheduled (it needs a `DelayStamp`, i.e. an async transport).
- A **shared PSR-6 cache pool** (e.g. Redis) is used in production so presence and recipient lists are consistent across workers / replicas.
- Twig, Symfony UX Icons and `assets:install` (or the host's bundler importing the TypeScript sources) are available to render the dock.

## Success criteria

- **SC-01** — `code-inventory.md` maps **223/223** production units (see its audit command).
- **SC-02** — PHPUnit: 100% line coverage of `src/`.
- **SC-03** — Vitest: 100% line coverage of `Resources/assets/src`.
- **SC-04** — PHPStan level 8 (with the FrankenPHP rulesets), Rector and PHP-CS-Fixer report nothing; Igor passes.
- **SC-05** — Translation key parity across the 7 locales.
- **SC-06** — The Symfony 8.1 demo boots (healthcheck), its PHPUnit and Playwright suites pass.

## Non-goals

- User accounts, authentication or the people directory (the application owns them through the contracts).
- Running a Mercure hub, a Web Push stack or a mailer (the host provides them).
- File attachments, message search and threads inside conversations (not in 1.x).
- End-to-end encryption, voice / video calls and federation with external chat networks.
- Per-request (or per-entity) profile selection: only `default_profile` is active.

## Validation

```bash
make release-check          # hygiene, assets, QA, Igor, 100% coverage, Vitest, translations, demos
make qa                     # cs-check, PHPStan, PHPUnit, Vitest (composer qa inside the container for the PHP part)
make test-coverage          # PHPUnit with coverage
make test-ts                # Vitest with coverage
make validate-translations  # key parity across the 7 locales
make -C demo/symfony8 test-e2e
# Inventory count (must equal SC-01):
find src -type f \( -name '*.php' -o -name '*.twig' -o -name '*.yaml' -o -name '*.ts' -o -name '*.css' \) -not -path 'src/Resources/assets/tests/*' | wc -l
```

## Links

- [Spec-driven development](../../docs/SPEC-DRIVEN-DEVELOPMENT.md) — user stories, scope, `REQ-*` anchors
- [GitHub Spec Kit manual](../../docs/SPEC-KIT.md) — install, structure, maintainer checklist
- [Code inventory](code-inventory.md) — 100% `src/` traceability
- [Constitution](../../.specify/memory/constitution.md)
- Integrator docs: [Installation](../../docs/INSTALLATION.md) · [Configuration](../../docs/CONFIGURATION.md) · [Usage](../../docs/USAGE.md) · [Architecture](../../docs/ARCHITECTURE.md) · [Security](../../docs/SECURITY.md) · [Changelog](../../docs/CHANGELOG.md)
