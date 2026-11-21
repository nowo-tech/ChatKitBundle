# Architecture

## Layers

| Layer | Namespace / path | Role |
| --- | --- | --- |
| Model | `Model`, `Entity`, `Enum`, `Repository` | `ChatUserInterface` (your user), conversations, participants (read cursors, mute), messages (Markdown + sanitised HTML, tombstones, buzz), reactions, user settings |
| Contracts | `Contract` | What the application provides: directory, access policy, channels, realtime, push. 1.27: framework-free twins (`ChatChannelSourceInterface`, `ChatRealtimeTransportInterface`, `ChatParticipantResolverInterface`) and the id-based application port `ChatMessagingInterface` |
| Ports | `Port` | 1.27, internal: `Ports` picks the deprecated interface or the twin's gate per host service; `ChatRequestContexts` copies the request / security user into `ChatRequestContext` / `ChatSecurityUser` |
| Persistence | `Doctrine` | Table prefixes, channel-key collation, user references; 1.27 opt-in private entity manager (`ChatKitEntityManager`, `ChatKitManagerRegistry`, rollback middleware, per-manager repository factory, `ChatKitSavepoint`) |
| Security | `Security` | `ChatAccess` (per-request memo over the contracts, `ResetInterface`), `ChatVoter` (view, send, moderate, resolve, edit, delete, react) |
| Domain | `Service` | `ChatService` (lifecycle), `ChatMarkdownRenderer` (escape-first), `ChatPresenter` (JSON shapes) |
| Realtime | `Realtime` | `ChatNotifier` → `ChatRecipients` (30 s cache) → `ChatPublisher` → the realtime port (Mercure, the null transport or the host's `ChatRealtimeTransportInterface`); `ChatPresence` (heartbeats in a PSR-6 pool) |
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
- No request state in services without `ResetInterface` (FrankenPHP worker mode); memos and the private entity manager are cleared at every request / message / command boundary by `ChatRequestBoundaryListener`.
- REQ-ARCH-001: standard Symfony is the zero-config default; the DDD path is opt-in — `ChatMessagingInterface` by uuid, `doctrine.user_reference: id` / `string`. 2.0 status: rule 3 ✅ (the three framework-typed ports were removed; their twins with bundle value objects are the only host interfaces, and a service still on a removed one fails the build), rule 6 ✅ (the private entity manager is the only mode: the bundle never flushes, clears or closes the application's manager; host users on chat rows become private references by id, chat rows loaded by the host's manager are re-loaded by id).
- Text reaches the DOM through `textContent`; only the server-sanitised message HTML uses `innerHTML` (lint rule).
