# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.14.0] - 2026-10-30](#1140---2026-10-30)
- [[1.13.2] - 2026-10-30](#1132---2026-10-30)
- [[1.13.1] - 2026-10-30](#1131---2026-10-30)
- [[1.13.0] - 2026-10-30](#1130---2026-10-30)
- [[1.12.0] - 2026-10-30](#1120---2026-10-30)
- [[1.11.3] - 2026-10-30](#1113---2026-10-30)
- [[1.11.2] - 2026-10-29](#1112---2026-10-29)
- [[1.11.1] - 2026-10-29](#1111---2026-10-29)
- [[1.11.0] - 2026-10-29](#1110---2026-10-29)
- [[1.10.1] - 2026-10-29](#1101---2026-10-29)
- [[1.10.0] - 2026-10-29](#1100---2026-10-29)
- [[1.9.2] - 2026-10-29](#192---2026-10-29)
- [[1.9.1] - 2026-10-29](#191---2026-10-29)
- [[1.9.0] - 2026-10-29](#190---2026-10-29)
- [[1.8.0] - 2026-10-29](#180---2026-10-29)
- [[1.7.0] - 2026-10-29](#170---2026-10-29)
- [[1.6.0] - 2026-10-23](#160---2026-10-23)
- [[1.5.5] - 2026-10-21](#155---2026-10-21)
- [[1.5.4] - 2026-10-20](#154---2026-10-20)
- [[1.5.3] - 2026-10-20](#153---2026-10-20)
- [[1.5.2] - 2026-10-18](#152---2026-10-18)
- [[1.5.1] - 2026-10-18](#151---2026-10-18)
- [[1.5.0] - 2026-10-18](#150---2026-10-18)
- [[1.4.1] - 2026-10-18](#141---2026-10-18)
- [[1.4.0] - 2026-10-16](#140---2026-10-16)
- [[1.3.0] - 2026-10-16](#130---2026-10-16)
- [[1.2.0] - 2026-10-16](#120---2026-10-16)
- [[1.1.1] - 2026-10-16](#111---2026-10-16)
- [[1.1.0] - 2026-10-16](#110---2026-10-16)
- [[1.0.0] - 2026-10-16](#100---2026-10-16)

## [Unreleased]

## [1.14.0] - 2026-10-30

Page forms are FormKit types with a bundle FormKit profile; requires nowo-tech/form-kit-bundle (REQ-TWIG-005 rule 11). See [UPGRADING](UPGRADING.md#to-1140).

### Changed

- **Forms are FormKit Types (REQ-TWIG-005 rule 11):** `nowo-tech/form-kit-bundle` (`^2.16`) is now a required dependency. `ChatPageMessageType`, `ChatDockSearchType`, `ChatDockComposerType`, `ChatDockEditType`, `ChatDockStatusType` and `ChatPreferencesType` carry `#[FormKitConfig('chat_kit')]` + `FormOptionsTrait` and add their fields through FormKit, themed by the FormKit profile `nowo_form_kit.profiles.chat_kit` the bundle prepends (translation domain `NowoChatKitBundle`, no auto help / placeholder keys, no framework classes: the UiKit form theme paints the fields). Host override: define `nowo_form_kit.profiles.chat_kit` yourself. Field classes / `data-part` hooks the dock script relies on are unchanged.

## [1.13.2] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1132).

### Fixed

- **Chat routes are opt-in again (REQ-SF-004 rule 7).** On Symfony >= 7.3 autoconfiguration tagged the page and API
  controllers (`#[Route]`) with `routing.controller`, so a host's default `config/routes.yaml`
  (`controllers: { resource: routing.controllers }`) registered them without importing `routes.php`, without its prefix
  (`/api/conversations`, `/{uuid}`, …). The controllers are no longer autoconfigured (explicit
  `controller.service_arguments` + `container.service_subscriber` tags); permissions are unchanged.

## [1.13.1] - 2026-10-30

### Fixed

- **Status menu height:** opening the status menu (or picking a status) no longer makes the chat list grow or shrink:
  the menu takes the list's height in the dock, and fills the column in page mode and on phones.

## [1.13.0] - 2026-10-30

### Added

- **Page mode of the dock**: the full-page chat (`/chat/`, `/chat/{uuid}`) offers everything the floating dock offers.
  When the dock script mounts on a page that holds the full-page chat, it renders the dock's list panel in the page's
  left column and one conversation in the right column — the same components and state as the dock, not a second
  implementation: your status (with the suggestions and the **More statuses** drop-down), search, the filters (All,
  Unread, Projects, Support, Closed, "Close all read"), new conversation (people picker, channels, support), close /
  reopen with Undo, and every thread feature (formatting toolbar and help, quick replies, emoji, @mentions, #references,
  edit / delete your own messages, reactions, pin and the pinned bar, read receipts, typing, link previews, the support
  ticket assistant, buzz, resolve). The floating dock (launcher, windows, edge tab) stays hidden while the page is
  mounted; its layout in other tabs and pages is left as it was.
- Deep links and history: `/chat/{uuid}` opens that conversation; picking another one updates the address with
  `history.pushState` (Back / Forward show the previous one again); `/chat/?archived=1` starts on the **Closed** filter
  and the address follows that filter (`history.replaceState`). `?chat=<uuid>` links and a support draft continued as a
  ticket rewrite the current entry instead of adding one. A new message or a buzz never switches the conversation the
  page shows (`openOnMessage` / `buzzOpenWindow` apply to the floating dock only).
- Phones (≤ 768px): one column — the list, or the conversation with the window's back button; going back puts focus on
  the conversation's row (else the search).
- `page.html.twig` marks its content element `data-nowo-chat-page-mode` (with `data-nowo-chat-current="<uuid>"` and
  `data-nowo-chat-archived="1"`). The server-rendered list, thread and composer form stay as they are: they are the page
  without JavaScript, hidden once page mode mounts (and shown again on `unmount()`).
- JavaScript API: `mount(root)` starts page mode by itself when the document holds `[data-nowo-chat-page-mode]`;
  `mount(root, { page: element })` points at another element, `mount(root, { page: null })` keeps the floating dock;
  `mountPage(root, page)`, `findChatPage()`, `PAGE_SELECTOR` and the `ChatDockOptions` type are exported, and
  `new ChatDock(root, config, { page })` takes the same option.
- CSS: `.nowo-chat-pagemode` (`__list`, `__thread`, `__title`, `__empty`, `has-thread`), `.nowo-chat-panel--page`,
  `.nowo-chat-window--page`, `.nowo-chat-row.is-current`, `.nowo-chat-page.is-page-mode`, `.nowo-chat.has-page-mode`;
  token `--nowo-chat-page-height` (default `max(28rem, 100dvh - 14rem)`; `max(24rem, 100dvh - 9rem)` on phones).

### Changed

- In page mode the conversation is a `role="region"` labelled with its name (not a dialog): no minimise, close or "open
  in a page" buttons, and its header button carries no `aria-expanded`. Rows of the list carry `data-uuid`; the row of
  the conversation shown has `aria-current="true"`.
- `VERSION` of the browser bundle reads `1.13.0` (it had stayed at `1.10.0`).

### Tests

- Vitest `page-mode.test.ts` (mount, deep link, history, Back / Forward, Closed filter from the address, search,
  filters, people picker, status menu, close / reopen, phone back button, unknown conversation, `?chat=` and support
  ticket replacement, no automatic switching, unmount); `index.test.ts` covers the page detection and `mountPage()`.
  PHPUnit checks the page-mode attributes; the demo e2e covers page mode and, with JavaScript disabled, the
  server-rendered page.

## [1.12.0] - 2026-10-30

### Added

- Status menu: a **More statuses** drop-down (a native `<select>` with option groups, labelled) under the one-click
  suggestions. *Work*: focusing 🎧 (2 hours), in a call 📞, presenting 🎤, reviewing 🔍, commuting 🚆 (1 hour), working
  remotely 💻, at the office 🏢, in training 📚, on support duty 🛟 (today), on a business trip ✈️ (this week).
  *Personal*: coffee break ☕, back soon ⏳ (30 minutes), doctor's appointment 🩺 (2 hours), offline for the day 🌙,
  sick 🤒 (today), on holiday 🌴, parental leave 🍼 (this week). Picking an option fills the emoji, the text and when it
  clears and saves it, like a suggestion button; browsing the closed drop-down with the arrow keys only fills the form
  and Enter saves. Strings in the 7 locales.
- Option `status_presets` (per profile): `quick` (the one-click buttons) and `more` (the drop-down's groups: `label`,
  `statuses`), each status `emoji`, `label` (translation key of the `NowoChatKitBundle` domain, or a text) and
  `clear_after`. A list you set replaces the default one; `more: []` hides the drop-down. `ChatDockStatusType` takes
  it as the `status_presets` option (view var `status_presets`, child `moreStatus`, `data-part="status-more"`).
- "Clear after" choice **2 hours** (`clearAfter: 2h`).

### Changed

- The suggestion buttons of `nowo_chat_template_status_menu` render from `nowo_chat_status_form.vars.status_presets`
  instead of a list in the template.

## [1.11.3] - 2026-10-30

### Fixed

- Status menu: choosing an emoji in the picker closed the menu, so a custom status could not be finished (the clicked
  emoji leaves the DOM before the click reaches the document, and the outside-click check took it for a click outside).
  The check now reads the event's path.

## [1.11.2] - 2026-10-29

### Fixed

- **The composer box no longer carries ARIA attributes a text box cannot have** (axe 4.14 `aria-allowed-attr`): the
  @mention and `/` `#` pickers drop `aria-expanded` / `aria-haspopup` from the `<textarea>` (which keeps its textbox
  role — ARIA in HTML allows no `combobox` role on it); the box keeps `aria-autocomplete="list"` and points
  `aria-controls` / `aria-activedescendant` at the open list only while it is open.
- **The two picker lists have their own classes**: the reference list is `.nowo-chat-ref-list` only (no longer also
  `.nowo-chat-mention-list`), so `.nowo-chat-mention-list` targets the @mention list alone.

## [1.11.1] - 2026-10-29

### Fixed

- **Accessible names that hid the visible text** (WCAG 2.5.3, axe `label-content-name-mismatch`): the dock's own
  avatar / status button now reads "DA — Your status: Available" (the visible initials first, as a word); the pinned
  bar's latest pin is named by its visible excerpt (the hint moved to `title`); the full-page Close / Reopen buttons read
  "Close — <conversation>" in every language. Vitest checks the status button.

## [1.11.0] - 2026-10-29

### Added

- **Automatic replies, set from the chat**: the status menu's *Automatic reply* section — text (Markdown, ≤ 500),
  *while I am away, busy or in do not disturb* or *between two dates*, and @mentions in channels on request. Posted as
  the person's own message marked "Automatic reply" (kind `auto`), at most once per conversation per
  `auto_reply_interval_hours` (4); never to an automatic or assistant message, never in support tickets; no sound, Web
  Push or digest. `POST /api/auto-reply`.
- **Quick replies**: each person's snippets (title + text) from the ⚡ button under the composer — insert, add, edit,
  delete (≤ 50). `GET` / `POST /api/quick-replies`, `PATCH` / `DELETE /api/quick-replies/{id}`; in the personal data
  export and erasure.
- **Formatting**: the **Aa** toolbar under the composer (bold, italic, strikethrough, code, link, quote, list, help),
  <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>B</kbd> / <kbd>I</kbd> / <kbd>K</kbd>; the renderer adds `[text](url)` (http(s) and
  mailto only, plain text), `www.` and e-mail autolinks and `> ` quotes. New built-in icons `format`, `quick`, `bold`,
  `italic`, `strike`, `code`, `quote`, `list`, `help`, `auto`.

### Fixed

- **Status menu fields in the request language**: the custom-status and automatic-reply labels and choices are now
  translated in the dock locale (they could show in another language than the dock).
- **Narrow windows cut cards short**: the message actions took width beside the bubble; they now float over the
  message's top corner (hover, focus, tap), so bubbles and cards keep the window's width.

## [1.10.1] - 2026-10-29

### Added

- **Custom status with emojis**: an emoji button next to the custom status opens the chat's emoji picker inside the
  status menu, and five suggestions (🍕 Lunch, 📅 In a meeting, 🏠 Working from home, 🤒 Sick, 🌴 On holiday) fill the
  emoji, the text and when it clears, and save, in one click. An emoji-only status gets an accessible label in the list.

### Fixed

- **The status menu ran off the screen**: with the conversation list open it was pushed above the viewport and some
  options were out of reach. It now takes the panel's place while open (the list steps aside), scrolls inside, fits
  short screens and phones, and its fields wrap at the dock's width.
- **Reference cards** (`/task`, `#`): a card alone in its paragraph is now a full-width card (icon, title with
  ellipsis, "type · project" below, badge on the right; several in a row stack); within text it is a compact chip
  (icon, title, badge) that keeps the line height, never wraps inside and never overflows the bubble. Every card
  carries its full text in a `title` attribute.
- **Message times moved the conversation**: hovering a grouped message revealed its time by growing the line (~18 px
  layout shift). The last message of a group always shows its time; the others show it beside the bubble on hover or
  keyboard focus with opacity / visibility only — nothing moves. Regression test on the stylesheet.
- **Close button over the row**: the time and the unread badge step aside while a row's Close button shows; a long
  custom status no longer squeezes the name; the window subtitle carries the full status in its `title`.

## [1.10.0] - 2026-10-29

### Added

- **Chat status, set from the chat itself**: the avatar button at the top of the dock's list opens a status menu (an
  ARIA menu: ↑ ↓ Home End, Enter / Space, Escape) — available (green), busy (red), away (amber), do not disturb (red
  with a bar) and invisible (offline to everyone) — with an optional custom status (an emoji and a text of at most 80
  characters) that clears itself after 30 minutes, 1 hour, 4 hours, today, this week or never. A
  `data-nowo-chat-status-open` button anywhere on the page opens the same menu; the full-page chat has one at the top of
  its list and shows the other person's status in a direct conversation's header. `POST /api/status`
  `{status, text?, emoji?, clearAfter?}`; realtime `status` (to the user's direct contacts) and `my_status` (to the
  user's other tabs) events.
- **Where statuses show**: the dot on direct conversations' avatars (list, window, people picker), the label and the
  custom status in the window subtitle and after the name in the list, the dot and text in the @mention list. Summaries
  carry `peerStatus`, `peerStatusText`, `peerStatusEmoji`; `/members` carries `status`, `statusText`, `statusEmoji`; the
  heartbeat answers `statuses` by public id and the caller's own status (`me`).
- **Auto-away** (`auto_away_minutes`, default 10, `0` = never): an available user shows as away after that long without
  keyboard or pointer input in any tab of the browser (the heartbeat carries `idle`).
- **Do not disturb**: no sounds, no buzz opening or shaking, no opening on a message, no Web Push — the badge only.
- **Chat preferences**: a sound per event (new message, @mention, buzz, support reply) from `none`, `pop`, `chime`,
  `ding` and three new ones (`bell`, `blip`, `knock`) plus `buzz`; a volume (0 – 100); sounds only while the tab is in
  the background; "a buzz opens the conversation"; "open the conversation when a message arrives" (otherwise the badge
  only); send with Enter or Ctrl/⌘+Enter (the composer hint follows); the message text in Web Push (per user, on top of
  `push_preview`). `POST /api/settings` `{key, value}` saves one preference from an allow-list
  (`ChatUserSettings::PREFERENCE_KEYS`) and the user's other tabs apply it live (realtime `prefs`); the dock config
  carries every preference (`prefs`), the own status (`status`), `autoAwayMinutes` and `me`.
- **For the application's settings screen**: `ChatPreferencesType` (a form bound to `ChatUserSettings`, each sound select
  with `data-nowo-chat-sound-select`), `saveChatPreference()` / `PREFS_EVENT` / `playSound()` / `SOUND_NAMES` exported
  by the TypeScript entry point (`playSound(name, volume)`), `ChatUserSettingsRepository::save()`.
- **Close conversations in your own list**: a Close button on each row of the dock (and the `Delete` key) with Undo, a
  **Closed** filter with Reopen, "Close all read", and Close / Reopen on the full-page chat (`?archived=1` lists the
  closed ones). Only for that person, nothing deleted (`archived_at` per participant); a new message brings it back
  unless muted, resolved / closed tickets stay closed. `POST` / `DELETE /api/conversations/{uuid}/archive`,
  `POST /api/conversations/archive-read`, `?archived=1`; realtime `list` to the person's other tabs.
- **Pinned messages**: Pin / Unpin in the message actions, a pinned bar above the thread (latest pin, jump with older
  pages loaded, "All pinned (n)" with Unpin), a translated "X pinned a message" line (no sound), realtime `pins`,
  deleting a message unpins it, `max_pins` (default 20). Direct conversations: both people; tickets: agents;
  channels: whom the application's policy allows through the new optional `ChatPinPolicyInterface` (deny by default).
  `GET /api/conversations/{uuid}/pins`, `POST` / `DELETE …/messages/{messageUuid}/pin`; summaries carry `canPin`,
  messages `pinned`; new built-in icons `archive`, `unarchive`, `pin` (UX Icons `tabler:archive`,
  `tabler:arrow-back-up`, `tabler:pin`). The personal data export lists the pins a person made.
- `ChatDockStatusType` (the menu's custom-status fields, a Form view like the other dock fields) and the template
  block `nowo_chat_template_status_menu`; the dock header gets the `me` button. Personal data export lists the new
  preferences and the status.

### Changed

- **Privacy**: an invisible user is `offline` (not online) in every presence answer, without custom text; a viewer who
  hides their own presence still sees custom status texts (no dots). The single 1.x sound is the new-message sound;
  existing rows copy it to the @mention and support-reply sounds (see UPGRADING).
## [1.9.2] - 2026-10-29

### Fixed

- **Support emojis showed as floating white panels**: the decorative emoji span (status, topic, assistant lines) used the
  class `nowo-chat-emoji`, which is the emoji picker's popover (absolute, surface, shadow), so each emoji covered the
  window header and was missing from the ticket status line. It is now `nowo-chat-deco`, inline only (no positioning,
  no surface).
- **Stale support lines in another language after an upgrade or a language change**: the per-tab message cache
  (`sessionStorage`) is keyed by a cache version and the dock locale (`nowo-chat-cache:<version>:<locale>:<user>`); the
  user's entries of another version or locale are dropped when the dock starts.

## [1.9.1] - 2026-10-29

### Added

- **Support emojis** (`support.emojis`, default `true`): a decorative emoji before the ticket status (open 🟢, waiting
  ⏳, resolved ✅, closed 🔒), before the topic label (status line, topic buttons, earlier tickets) and before each
  support assistant line (🎫 opened, 🏷️ topic changed, 🔄 continued, 🔁 reopened, ⏳ waiting, ✅ resolved, 🔒 closed,
  🙋 assigned, 👤 unassigned, 👋 greeting, 🔄 "continue or new?"; a topic's first reply uses the topic's emoji). Rendered
  as `aria-hidden` spans outside the translations — screen readers, previews, Web Push and the digest read only the
  text. New optional `emoji` per topic (built-in: incident 🐞, billing 💳, how_to ❓, account 🔐, other 💬; `""` for
  none); ticket blocks carry `topicEmoji` / `statusEmoji`, topic options and inbox counts `emoji`; dock config
  `supportEmojis`.

### Fixed

- **#reference cards in the sender's own bubble were unreadable**: the card kept its light surface but inherited the
  bubble's on-accent text (white on white in light mode, dark on dark in dark mode, ~1.1:1). The card now sets its own
  foreground on its own surface whatever the bubble (title, type, subtitle, badges; beats
  `.nowo-chat-msg--mine .nowo-chat-bubble a`); type / subtitle / option meta use a colour mix instead of `opacity`
  (≥ 5.6:1 with the default and dark tokens); status badges get the card's text on a 20 % tone tint (≥ 8.7:1) and a tone
  outline; hover / focus draw an on-accent ring inside an accent bubble; the restricted chip's icon follows its text.
- **Support assistant lines in the poster's language**: a line posted while an agent acted (resolve, assign …) reached
  the requester's open dock translated in the agent's locale. Realtime bot lines now carry `refsPending` and each dock
  fetches the line again in its own locale.

## [1.9.0] - 2026-10-29

### Added

- **References in messages (`/` commands and `#`).** `/` at the start of a message opens a command menu (`/task`,
  `/issue` … one per reference type the application registers); after a command, the text typed searches its items
  (`/task deploy`). `#` anywhere searches every type. Same combobox as @mentions (↑ ↓, Enter / Tab, Escape,
  `aria-activedescendant`). A picked item is written `#Title` and sent as the token `#[Title](type:id)`.
- `ChatReferenceProviderInterface` (autoconfigured, tag `nowo_chat_kit.reference_provider`): `key()`, `label()`,
  `icon()`, `isAvailable(scope)`, `search(scope, query, limit)`, `resolve(scope, ids)`; models `ChatReference` (type,
  id, title, url, subtitle, badge, tone) and `ChatReferenceScope` (viewer, audience, conversation). Generic: issues,
  tasks, projects, monitors, a wiki … plug in the same way.
- **Permissions, deny by default.** The command menu lists a type only when the conversation's audience may open one of
  its items; search and validation use the audience of the conversation — the writer, plus the other person of a direct
  conversation (an item only one of them may open is never offered nor posted) — and a channel's own items. On send and
  edit a forged, foreign, unknown or unreadable token is stored as plain text (the same for "does not exist" and
  "forbidden"); at most 10 references per message.
- **Cards resolved per viewer at read time.** The stored HTML holds an empty slot per reference (no type, id or title);
  each reader gets a card (icon, type, title, subtitle, status badge, link) or the neutral *Content not visible* chip
  (`chat.reference_restricted`, 7 locales). Conversation previews, Web Push bodies and the e-mail digest render the same
  way for their recipient. Viewer-neutral realtime payloads carry `refsPending`; the dock fetches the message for its
  user (`GET /api/conversations/{uuid}/messages/{messageUuid}`). Resolutions are batched per page (one `resolve()` per
  type) and cached per request.
- API `GET /api/conversations/{uuid}/references?type=&q=` (same access as reading the conversation, 404 otherwise; rate
  limited by `limits.references_per_minute`, default 120); message payloads carry `references` and `refsPending`; dock
  config `references` and endpoint `references`; built-in icons `lock`, `issue`, `task`, `project`, `monitor`,
  `report`, `ticket`, `page`.
- `/` commands that *do* something (assign, resolve …) are not part of this version: commands only insert references.

### Fixed

- Continuing a support ticket from the draft (*Continue SUP-…*) showed the ticket's cached messages without the text
  moved from the draft, and left the draft row in the list: the dock now drops both cached threads, reloads an open
  ticket window and refreshes the list.

## [1.8.0] - 2026-10-29

### Added

- **Support tickets.** A support conversation is now a ticket: readable sequential number (`SUP-000123`,
  `support.ticket_prefix` / `ticket_digits`), topic, status (`open`, `waiting` for the user, `resolved`, `closed`),
  created / last activity / resolved / closed times, requester and an optional assignee (a support agent). One user may
  hold several tickets (the one-thread-per-user unique key is gone).
- **Topics** (`support.topics`; defaults *Incident / bug*, *Billing*, *How do I…?*, *Account and access*, *Other* in
  the 7 locales): key, label and automatic reply per locale (translation keys in `support.translation_domain`), an
  optional icon and an optional default assignee. `ChatSupportTopicProviderInterface` lets an application manage them
  as data (`ChatSupportTopic`, plain text when its `domain` is null).
- **Support assistant in the dock.** Opening support with no active ticket shows a greeting with the topics as real
  buttons in a labelled group (keyboard, screen readers); choosing one opens the ticket and posts *Ticket … opened* plus
  the topic's reply (e.g. *Tell us the invoice number…*). Text typed before choosing stays the first message.
- **Continue or new.** When the latest ticket has had no message for `support.resume_after_days` (7) or is resolved,
  the assistant asks *Continue SUP-000123 (topic · status, last message date) or open a new one?*; Continue moves the
  text typed meanwhile into that ticket and reopens it, New shows the topics. Below that limit an open ticket simply
  continues.
- **Earlier tickets** listed in the assistant (number, topic, status, last activity), each opening in the dock;
  **Reopen** on a resolved ticket for its requester; a closed ticket, or one resolved more than
  `support.close_after_days` (30) ago, is read-only.
- Support-assistant messages (`ChatMessage::KIND_BOT`, no author, `meta` column) for every ticket change, translated for
  each reader; `ChatNotifier::botMessage()` publishes them live (no Web Push / digest).
- Agents: inbox filters `waiting`, `closed`, `unassigned` and `topic`; `mine` now means *assigned to me*; inbox rows
  carry the `ticket` block and `ownerTickets`; `ChatSupportInbox::countByTopic()` and `otherTickets()`; the dock's
  resolve button also shows on waiting tickets.
- API: `GET /api/support/tickets` (`?owner=` for agents), `POST /api/support/{uuid}/topic|resume|status|assign`; dock
  endpoints `supportTickets`, `supportTopic`, `supportResume`, `supportStatus`, `supportAssign`.
- Events `SupportTicketCreatedEvent`, `SupportTicketReopenedEvent`, `SupportTicketStatusChangedEvent`,
  `SupportTicketAssignedEvent`.
- Replies move the status: an agent's reply turns an open ticket to `waiting`, the requester's reply turns it back to
  `open` (no event for these automatic switches).

### Changed

- `SupportStatus` gains `Waiting` and `Closed` (and `isActive()`).
- `ChatConversationRepository::findSupportFor()` returns the user's latest support conversation; new
  `findSupportDraft()`, `findTickets()`, `countActiveByTopic()`, `countTicketsByOwner()`; `findSupportInbox()` takes the
  topic and the read-only cutoff.
- `ChatPresenter` adds `ticket` to support summaries and titles tickets `SUP-000123 · Topic`; support summaries of the
  requester's draft carry `intake` (API).
- Schema: see UPGRADING (new columns, the support unique key replaced by an index, ticket numbers for existing threads).

## [1.7.0] - 2026-10-29

### Added

- **@mentions in conversations.** Typing `@` in the composer (or the inline editor) opens a list of the
  conversation's members, filtered as you type (`↑` `↓` move, `Enter` / `Tab` pick, `Escape` closes; ARIA
  `aria-autocomplete="list"`, `listbox`, `aria-activedescendant`). A picked member is sent as the token
  `@[Display name](publicId)`; the server keeps it only for a member who may read the conversation now (never the
  author; at most 20 per message) with their current name, turns any other token into plain `@name` text, and renders
  it as `<span class="nowo-chat-mention" data-mention="publicId">@name</span>`. Previews, push bodies and digests show
  `@name`.
- `ChatMessageMention` entity (`nowo_chat_message_mention`: message, user, `created_at`, `read_at`): one row per
  mentioned member, kept in step on edit and removed with a deleted message; reading the thread past the message marks
  it read. `ChatMessageMentionRepository` answers "messages that mention me" (`findForUser()` / `countForUser()`, only
  in conversations the user may still read), `findOneForUser()` and `countUnreadByConversation()`.
- `ChatMentionEvent` (message, newly mentioned users, conversation, `edited`) after the message is stored: an edit
  carries only the people it adds, so a host notifies each person at most once per message.
- `GET /api/conversations/{uuid}/members?q=`: members the caller may mention (same access as reading the conversation,
  never the caller, sorted by name, at most 20); dock config `endpoints.members`.
- Message JSON: `mentions` (`[{uuid, name}]`, from the stored tokens, no query) and `mentionsMe`. The dock and the
  full page highlight a message that mentions the viewer (`nowo-chat-msg--mentioned`) and the chip naming the viewer
  (`is-me`).
- Theme tokens `--nowo-chat-mention`, `--nowo-chat-mention-bg`, `--nowo-chat-mention-me-bg`,
  `--nowo-chat-mentioned-bg`, `--nowo-chat-mentioned-edge`; translation key `chat.mention_list` (7 locales).

### Changed

- **Developer tooling (REQ-CS-001):** `make validate-phpdoc` is read-only and now part of `make release-check`: it fails when a class or public method in `src/` has no PHPDoc block (the former `@return` check asked for tags that `cs-fix` (`no_superfluous_phpdoc_tags`) removes; `make fix-phpdoc` is gone).

## [1.6.0] - 2026-10-23

### Added

- `dock.html.twig` variables for bundler hosts: `nowo_chat_assets` (`false` leaves out the bundle `<link>` / `<script>`)
  and `nowo_chat_root_attr` (extra attributes on the dock root, `class` appended, the dock's own attributes kept), so a
  host that mounts the dock with its own Stimulus controller no longer overrides the template.

## [1.5.5] - 2026-10-21

A sent message no longer keeps a "Sending…" bubble next to the confirmed one; on phones the folded launcher's unread badge sits on the button's corner. See [UPGRADING](UPGRADING.md#to-155).

### Fixed

- Thread: when the HTTP confirmation of a sent message arrived before its realtime event, the fast append path added the
  confirmed message but left the painted pending bubble ("Sending…") in place until the next full render. The append
  path now falls back to a full render while a pending node is still painted.
- Phones: the folded launcher's unread badge sat inside the round button, over the icon (the panel's `overflow: hidden`
  clipped it). The folded pill now lets it overflow and the badge sits on the button's corner with a surface ring.

## [1.5.4] - 2026-10-20

Demo release-check refreshes the bundle first; worktree-safe co-author check. See [UPGRADING](UPGRADING.md#to-154).

### Changed

- Demo `release-check` runs `update-bundle-all` before the demo tests (REQ-DEMO-007).

### Fixed

- `.scripts/check-no-cursor-coauthor.sh` also runs inside git worktrees (`.git` file instead of directory).

## [1.5.3] - 2026-10-20

### Fixed

- **Dock start loaded the conversation list three times** (`GET` conversations endpoint): the first realtime connection counted as a reconnection and triggered a catch-up refresh, and the `pollMs` tick armed before the connection refreshed it again. The first connection now only switches the poll to the live cadence (60 s); a real reconnection still catches up at once, and a dropped connection goes back to `pollMs`.

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README Templates link moved under Additional documentation, Spec Kit inventory refresh (97/97: `View/ChatPage.php`, `page_legacy.html.twig`).

## [1.5.2] - 2026-10-18

Host-mounted docks (data-nowo-chat-manual) are no longer mounted twice by nowo-chat.js. See [UPGRADING](UPGRADING.md#to-152).

### Fixed

- **Two docks with a host-mounted dock**: `page.html.twig` always loads `nowo-chat.js`, whose automatic start mounted
  every `[data-nowo-chat-config]` root, including a dock the host mounts itself, so two docks appeared. The automatic
  start (first paint, `turbo:load`, and the `turbo:before-render` cleanup) now skips roots marked
  `data-nowo-chat-manual` (`AUTO_ROOT_SELECTOR`); `mount()` still mounts them when called explicitly. Roots without
  the attribute behave as before.

### Added

- `dock.html.twig` variable `nowo_chat_manual` (`{{ include('@NowoChatKitBundle/dock.html.twig', {nowo_chat_manual:
  true}) }}`) renders the root with `data-nowo-chat-manual`.
- `page.html.twig` block `nowo_chat_page_script` around the `nowo-chat.js` tag, so a `web_ui.templates.page` override
  can drop the bundle script when the host ships it. Covered by `ChatPageManualDockTest` and the `index.test.ts`
  manual-root test.

## [1.5.1] - 2026-10-18

Conversation list no longer re-reads the viewer's settings and peers per row (9 queries for 5 or 50 rows). See [UPGRADING](UPGRADING.md#to-151).

### Fixed

- **N+1 queries on `GET /chat/api/conversations`** (reported from symfony-beacon): every direct-thread row ran two extra
  SQL statements — the presence-visibility check re-read the viewer's own `nowo_chat_user_settings` row, and the
  per-row `nowo_chat.send` vote re-loaded the peer through `ChatDirectoryInterface::findByIds()`. The list now runs a
  constant number of statements (9 for 5 or 50 direct threads in the test kernel; 19 / 109 before).
  `ChatUserSettingsRepository::forUser()` memoizes per user id and `ChatAccess::usersByIds()` (new) memoizes the
  directory lookups for the current request, so `directPeer()` reuses the peers the list loaded in one query. Both
  memos are cleared by `reset()` (`kernel.reset`): nothing carries over between FrankenPHP worker requests or
  Messenger messages. The message page, support inbox and contacts list were checked and already batch their rows;
  query-count regression tests (5 vs 50 rows) cover the conversation list, the support inbox and the message page.

## [1.5.0] - 2026-10-18

Chat page on the UiKit 2.2 page contract (REQ-UI-003) and dock tokens from `--nowo-ui-*`; `page_layout` deprecated in favour of `web_ui.layout_template`. See [UPGRADING](UPGRADING.md#to-150).

### Added

- **UiKit page contract (REQ-UI-003)** — requires `nowo-tech/ui-kit-bundle` **^2.2**. The full-page chat
  (`page.html.twig`) is a unique page on the UiKit admin layout (`layout ?? nowo_ui_layout.admin`): canonical blocks
  (`nowo_ui_document_title`, `nowo_ui_styles` / `nowo_ui_scripts` stacked with `parent()`, `nowo_ui_page_header`
  through the UiKit `page_header` partial, `nowo_ui_content`) and theme slots of `NowoChatKitBundle` (`section`,
  `empty`, `muted`, `flash_error`, `btn_link`, `btn_primary`, `form`).
- `profiles.<name>.web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`) and
  `profiles.<name>.web_ui.templates.page` (one-block `@!NowoChatKitBundle/page.html.twig` overrides).
- `Nowo\ChatKitBundle\View\ChatPage` (page header view model); page context `header`, `bundle`.
- `docs/TEMPLATES.md`; contract test (`PageContractTestCase`); functional test of the page on a UiKit host layout.
- Demo: UiKit host layout (`nowo_ui_kit.layouts.admin`, host theme tokens), full-page chat screenshot.

### Changed

- `nowo-chat.css`: the `--nowo-chat-*` colour, display-font and radius tokens default to the UiKit `--nowo-ui-*` tokens
  (1.x values as fallbacks), so the dock and the page follow the host's UiKit theme.
- The full-page chat title moved to the UiKit page header (`chat.page_title`); the list no longer repeats it.
- Dock root: `data-nowo-ui-bundle="NowoChatKitBundle"` (per-bundle UiKit tokens).

### Deprecated

- `profiles.<name>.page_layout` → `web_ui.layout_template`. While it is the only layout set, the page keeps its 1.x
  template (`page_legacy.html.twig`, deprecated) in that layout; its 1.x default value means the UiKit layout.
- `page_layout.html.twig`, `page_legacy.html.twig` and the Twig global `nowo_chat_kit_page_layout` (removed in 2.0).

## [1.4.1] - 2026-10-18

### Fixed

- The dock search boxes and the composer placeholders are translated once: `_templates.html.twig` renders those
  widgets with `translation_domain: false`. The form theme looked the already translated text (`Search
  conversations`, `Search people`, `Write a message…`) up again in the `NowoChatKitBundle` domain and the request
  locale — a "missing message" in the Symfony profiler, and a lookup in the wrong locale when `nowo_chat_locale`
  differs from the request one.

## [1.4.0] - 2026-10-16

### Added

- Today's messages show a **relative time** ("now", "5 min ago", "2 hr ago", `Intl.RelativeTimeFormat` in the dock
  locale), refreshed by one page-wide 30 s ticker; every message time has the **exact date and time** as its tooltip
  (also on the full-page chat). New `format.ts` helpers `relativeTimeLabel`, `exactTimeLabel`, `paintMessageTime`,
  `refreshRelativeTimes`; new strings `chat.just_now`, `chat.edit_hint`, `chat.deleted_message_mine`.
- Deleted messages read like WhatsApp: a ban icon (new `ban` icon, `tabler:ban` with `icon_set: ux_icon`) and
  "You deleted this message" for your own / "This message was deleted" for someone else's.

### Changed

- **Only the author deletes a message**, within the same 15-minute window as editing: support agents no longer delete
  other people's messages in channels or support threads (`ChatVoter::DELETE`, and the dock never offers it).
  Existing moderated tombstones still read "Removed by a moderator".
- Inline editor and delete confirmation no longer squeeze the message: the line being edited takes the whole thread
  width (taller field, "Esc to cancel · Enter to save" hint, scrolled into view) and the delete confirmation is a
  full-width strip under the message.

### Fixed

- Spec Kit baseline: `page.ts` (page read-state script) was missing from the code inventory (now **95/95** units); the
  spec covers the 1.3 behaviour (`FR-TS-008` page mark-read POST, `FR-PAGE-001` no state change on GET, `FR-TWIG-002`
  page layout global and asset blocks) and is marked Active (1.3); SDD current state 1.3.x; worker audit counts updated.

## [1.3.0] - 2026-10-16

### Added

- Dock Form types `ChatDockSearchType`, `ChatDockComposerType` and `ChatDockEditType` and the Twig function
  `nowo_chat_dock_forms()`: the dock search boxes, composer and inline editor in `_templates.html.twig` are rendered with
  Symfony Form helpers (no raw `<form>` / `<input>` / `<textarea>`; `make check-no-raw-html-form` has no exception). The
  forms keep Symfony CSRF on with the API token id `nowo_chat`.
- Twig global `nowo_chat_kit_page_layout` (the configured `page_layout`); `page_layout.html.twig` defines `stylesheets`
  and `javascripts` blocks, and the full-page chat adds the chat CSS / JS there, so `/chat/` is styled with a host
  layout that does not include the dock (each asset is still loaded once).
- Page script `markPageRead()`: the full-page chat marks the open conversation read through the CSRF-protected
  `POST /api/conversations/{uuid}/read` once the tab is visible.
- Docs: pagination caps, timeout hierarchy, multi-profile example and profile selection, the literal
  `asset(..., 'nowo_chat_kit')` snippet, Playwright e2e / screenshot targets; Spec Kit baseline with key entities,
  assumptions and the 1.1 / 1.2 features (94-unit inventory).

### Changed

- `GET /chat/{uuid}` no longer moves the read cursor (no state change on GET, REQ-SEC-005): the page script marks the
  conversation read, and without JavaScript sending a message does.
- `symfony/asset` is a required dependency (the bundle templates call `asset(..., 'nowo_chat_kit')`).
- Messenger handlers use `#[AsMessageHandler(sign: true)]` and the table prefix listener `#[AsDoctrineListener]` instead
  of manual tags (Messenger stays optional).
- Entities take the injected clock time (`ChatService` passes `ClockInterface::now()` to conversations, participants,
  reactions and support resolution); `ChatPersonalData` uses `ChatConversationRepository`.
- Twig paths compiler pass resolves the loader in the canonical order (`twig.loader.native` alias target, then
  `twig.loader.native`, then `twig.loader.native_filesystem`).
- Development: `release-check` runs its steps sequentially and includes the demo Playwright e2e; the CI coverage job
  enforces the 99% PHP gate and the TS job the 90% gate (Vitest thresholds at 90 for all four metrics); weekly CI
  schedule; Codecov upload skipped for Dependabot; `make help` grouped by block; demo `verify` / `setup` targets, `up`
  without `--build`, demo timeouts (HTTP client 5 s < PHP 30 s < Caddy write 60 s); `setup-hooks` copies the hooks into
  `.git/hooks`; the translation check also scans Twig keys used with a locale argument.

## [1.2.0] - 2026-10-16

### Added

- **Hide to the side**: a list header button tucks the whole dock (list and windows) into one tab on the right edge with
  the chat icon and the unread count; the tab brings it back as it was. Kept across pages and browser tabs; opening a
  conversation shows the dock again. New `tuck` icon (`tabler:arrow-bar-to-right`), `chat.tuck` / `chat.untuck`
  translations, `nowo_chat_template_edge` block, `--nowo-chat-edge-offset` token.
- Docs: stacking the dock with drawers and modals (`--nowo-chat-z-index`).

### Fixed

- Development: `make coverage-check` (part of `release-check`) failed silently on a failing PHPUnit test (the pipe ran
  in the container's `sh` without `pipefail`); every test run starts from a fresh test kernel cache.

## [1.1.1] - 2026-10-16

### Fixed

- Phones: the round launcher stays above the Symfony web debug toolbar and `data-nowo-chat-avoid` footers again
  (1.1.0 kept it at a fixed 12 px from the bottom, under an open toolbar).

## [1.1.0] - 2026-10-16

### Added

- Shape and size tokens: `--nowo-chat-radius-window`, `-radius-bubble`, `-radius-input`, `-radius-control`,
  `-radius-pill`, `-radius-button`, `-radius-avatar`, `-radius-avatar-square`, `--nowo-chat-shadow`, `-shadow-soft`,
  `--nowo-chat-window-width`, `-panel-width`, `-font-size` (defaults unchanged).
- The desktop dock looks like tabs coming out of the bottom edge or of the footer it rests on (upward shadow clipped at
  the edge, 1px overlap with the footer border; `--nowo-chat-shadow-dock`).
- `data-nowo-chat-avoid`: the dock rests just above the marked elements (an application footer, fixed or scrolled into
  view) and otherwise sits flush with the bottom.

### Fixed

- The dock no longer jumps too high with the Symfony web debug toolbar: its real height (collapsed, one row, two rows)
  is measured instead of a fixed 76 px / 40 px; windows and the list shrink by the same amount.

## [1.0.0] - 2026-10-16

### Added

- Floating LinkedIn-style chat dock: direct messages, application channels (`ChatChannelProviderInterface`), shared
  support inbox with resolve / reopen and agent moderation.
- Doctrine model (`nowo_chat_*` tables), `ChatUserInterface` mapped to the profile `user_class`, per-user settings.
- Contracts: `ChatDirectoryInterface`, `ChatAccessPolicyInterface` (required); channel provider, realtime transport
  (Mercure by default when a hub exists) and Web Push sender (optional).
- JSON API (CSRF, rate limits, IDOR-safe 404, no-store): list, contacts, open, messages with cursor pages, send, edit and
  delete within 15 minutes, emoji reactions, read receipts, typing, presence, buzz, resolve, realtime token.
- Realtime fan-out on private per-user topics, presence in a PSR-6 pool, Web Push selection at delivery time
  (participants, settings, mute, read, app open), `nowo:chat:purge`, GDPR export / erasure (`ChatPersonalData`).
- Twig `<template>` markup (`@NowoChatKitBundle/_templates.html.twig`, overridable per block), `nowo_chat_dock_config()`,
  `nowo_chat_icons()`; CSS tokens `--nowo-chat-*` with light / dark defaults; Symfony UX Icons (`icon_set`, Tabler).
- TypeScript dock (IIFE `nowo-chat.js` + importable source): windows that survive navigation and sync across tabs,
  typing, receipts, presence, buzz, sounds, emoji picker, inline edit / delete, reactions, mobile sheet.
- Support: agents' inbox endpoint and `ChatSupportInbox` (open count), agents resolve and reopen threads, threads never written to stay out of the inbox and counts, thread context (`Reported from`), links anywhere
  on the page (`data-nowo-chat-open="support|user:…|channel:…"`).
- Events `ChatMessageSentEvent`, `ChatMessageDeletedEvent` (`moderated`), `ChatSupportStatusChangedEvent`.
- Link previews through `ChatLinkPreviewProviderInterface` (per viewer, batched); email digest of unread conversations
  through `ChatDigestSenderInterface` (`digest.*`, Messenger delay, per-user opt-out).
- Full-page chat (`/chat/`, `/chat/{uuid}`, no JavaScript needed, `page_layout`, Symfony Form composer) and an "open in
  a page" link per window; `data-nowo-chat-open="conversation:<uuid>"`.
- `push_preview` (Web Push bodies carry the message text only when enabled), `doctrine.table_prefix` (tables, indexes and
  unique constraints), `ChatRecipients::forget()`; the session expiring (401) clears the dock's browser storage.
- Security: one read rule (`ChatAccess::canView()`) for the voter, the conversation lists and the digest; access answers
  memoized on the request only (FrankenPHP worker safe); support context restricted to application paths. Independent AI
  security audit recorded in `docs/SECURITY.md`.
- Translations in 7 locales (domain `NowoChatKitBundle`).
- Package infrastructure: QA (PHP-CS-Fixer, PHPStan level 8, Rector, Igor), PHPUnit at 100% lines, Vitest, Flex recipe,
  Spec Kit baseline (100% code inventory), Symfony 8.1 FrankenPHP demo with seeded accounts, Playwright e2e and
  screenshot.

[Unreleased]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.14.0...HEAD
[1.2.0]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/ChatKitBundle/releases/tag/v1.0.0
