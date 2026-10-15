# Architecture and planned features

This document describes the target design of **Chat Kit Bundle**. Items are **planned** until listed in [CHANGELOG.md](CHANGELOG.md).

## Table of contents

- [Goals](#goals)
- [Conversation types](#conversation-types)
- [Realtime](#realtime)
- [Presence, typing and read receipts](#presence-typing-and-read-receipts)
- [Buzz, sounds and emoji](#buzz-sounds-and-emoji)
- [Web Push](#web-push)
- [Front end](#front-end)
- [Host extension points](#host-extension-points)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## Goals

- A floating, LinkedIn-style chat dock that any Symfony application can drop into its layout.
- The host keeps ownership of users, permissions and branding; the bundle owns conversations and messages.
- Works without a JavaScript framework; markup and styles are fully overridable.

## Conversation types

| Type | Description |
| --- | --- |
| Direct message | One-to-one conversation between two users allowed to reach each other. |
| Channel | Conversation bound to a host resource (project, room); members resolved by the host. |
| Support | Users write to "Support"; any agent can answer from a shared inbox. |

## Realtime

Messages, presence, typing and read-receipt events are published to a **Mercure** hub (the FrankenPHP built-in hub or a standalone hub) on per-user private topics. The browser keeps one `EventSource` per tab and shares state across tabs.

## Presence, typing and read receipts

- Presence: online / away / offline, derived from heartbeats with a TTL.
- Typing: debounced "is typing" events, never persisted.
- Read receipts: last-read message per participant, shown as "seen".

## Buzz, sounds and emoji

- Buzz: a rate-limited attention signal with a subtle visual shake.
- Sounds: configurable per event (new message, buzz), user-level mute.
- Emoji: lightweight picker, no external CDN.

## Web Push

Push notifications are sent only when the application is **not** open in any tab of the user (cross-tab visibility tracking), using standard Web Push with VAPID keys provided through environment variables.

## Front end

- TypeScript sources under `src/Resources/assets/`, built with Vite into `src/Resources/public/js/nowo-chat.js` and served through the `nowo_chat_kit` asset package.
- Stimulus-compatible identifiers prefixed `nowo-chat`.
- UI fragments are Twig-rendered `<template>` elements (override from `templates/bundles/NowoChatKitBundle/`).
- Theme tokens: CSS custom properties `--nowo-chat-*`; class prefix `.nowo-chat-`.

## Host extension points

- **Access / reachability provider** — who may contact whom, channel membership, support agents.
- **User display provider** — name, avatar, initials, profile URL.

## FrankenPHP worker mode

Services are stateless between requests; per-request data lives in the request or is reset through `kernel.reset`. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
