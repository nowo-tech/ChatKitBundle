# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.5.1] - 2026-10-18](#151---2026-10-18)
  - [Fixed](#fixed)
- [[1.5.0] - 2026-10-18](#150---2026-10-18)
  - [Added](#added)
  - [Changed](#changed)
  - [Deprecated](#deprecated)
- [[1.4.1] - 2026-10-18](#141---2026-10-18)
  - [Fixed](#fixed-1)
- [[1.4.0] - 2026-10-16](#140---2026-10-16)
  - [Added](#added-1)
  - [Changed](#changed-1)
  - [Fixed](#fixed-2)
- [[1.3.0] - 2026-10-16](#130---2026-10-16)
  - [Added](#added-2)
  - [Changed](#changed-2)
- [[1.2.0] - 2026-10-16](#120---2026-10-16)
  - [Added](#added-3)
  - [Fixed](#fixed-3)
- [[1.1.1] - 2026-10-16](#111---2026-10-16)
  - [Fixed](#fixed-4)
- [[1.1.0] - 2026-10-16](#110---2026-10-16)
  - [Added](#added-4)
  - [Fixed](#fixed-5)
- [[1.0.0] - 2026-10-16](#100---2026-10-16)
  - [Added](#added-5)

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.5.1...HEAD
[1.2.0]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/ChatKitBundle/releases/tag/v1.0.0
