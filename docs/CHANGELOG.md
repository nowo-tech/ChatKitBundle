# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nowo-tech/ChatKitBundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/ChatKitBundle/releases/tag/v1.0.0
