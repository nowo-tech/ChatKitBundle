# Architecture

## Layers

| Layer | Namespace / path | Role |
| --- | --- | --- |
| Model | `Model`, `Entity`, `Enum`, `Repository` | `ChatUserInterface` (your user), conversations, participants (read cursors, mute), messages (Markdown + sanitised HTML, tombstones, buzz), reactions, user settings |
| Contracts | `Contract` | What the application provides: directory, access policy, channels, realtime, push |
| Security | `Security` | `ChatAccess` (per-request memo over the contracts, `ResetInterface`), `ChatVoter` (view, send, moderate, resolve, edit, delete, react) |
| Domain | `Service` | `ChatService` (lifecycle), `ChatMarkdownRenderer` (escape-first), `ChatPresenter` (JSON shapes) |
| Realtime | `Realtime` | `ChatNotifier` → `ChatRecipients` (30 s cache) → `ChatPublisher` → `ChatRealtimeInterface`; `ChatPresence` (heartbeats in a PSR-6 pool) |
| Push | `Push` | `DeliverChatPushMessage` → `ChatPushDispatcher` (recipient selection) → `ChatPushSenderInterface` |
| HTTP | `Controller`, `Http` | JSON API; guards throw `ChatApiException`, rendered as JSON |
| View | `Twig`, `Resources/views` | `nowo_chat_dock_config()`, `nowo_chat_icons()`, the `<template>` markup |
| Browser | `Resources/assets/src` | TypeScript dock cloning the templates; IIFE build in `Resources/public/js` |

## Request flow (send)

1. `POST /api/conversations/{uuid}/messages` → CSRF, action and send limiters → `ChatVoter::SEND`.
2. `ChatService::send()` cleans and renders the Markdown, stores the message, moves the author's read cursor.
3. `ChatNotifier::messageCreated()` publishes a viewer-neutral event to every recipient's private topic and queues
   `DeliverChatPushMessage` for the others.
4. Each open dock merges the event (no reload), plays a sound when the conversation is not in front, and shares it
   with the user's other tabs (BroadcastChannel).

## Design rules

- The application decides who exists and who may talk to whom; the bundle never queries the user table directly.
- Every API read re-checks access; only the realtime recipient list is cached (30 s).
- No request state in services without `ResetInterface` (FrankenPHP worker mode).
- Text reaches the DOM through `textContent`; only the server-sanitised message HTML uses `innerHTML` (lint rule).
