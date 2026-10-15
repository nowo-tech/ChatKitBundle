# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- Support: agents' inbox endpoint and `ChatSupportInbox` (open count), thread context (`Reported from`), links anywhere
  on the page (`data-nowo-chat-open="support|user:…|channel:…"`).
- Events `ChatMessageSentEvent`, `ChatMessageDeletedEvent` (`moderated`), `ChatSupportStatusChangedEvent`.
- Link previews through `ChatLinkPreviewProviderInterface` (per viewer, batched); email digest of unread conversations
  through `ChatDigestSenderInterface` (`digest.*`, Messenger delay, per-user opt-out).
- Full-page chat (`/chat/`, `/chat/{uuid}`, no JavaScript needed, `page_layout`) and an "open in a page" link per window.
- Translations in 7 locales (domain `NowoChatKitBundle`).
- Package infrastructure: QA (PHP-CS-Fixer, PHPStan level 8, Rector, Igor), PHPUnit and Vitest at 100% lines,
  Symfony 8.1 FrankenPHP demo with seeded accounts, Playwright e2e and screenshot.
