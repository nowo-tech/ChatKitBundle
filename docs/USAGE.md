# Usage

## Table of contents

- [Current state](#current-state)
- [Planned integration](#planned-integration)
- [Host extension points](#host-extension-points)
- [Customizing markup and theme](#customizing-markup-and-theme)
- [Naming conventions](#naming-conventions)

## Current state

This development line is a **skeleton**: the bundle registers its configuration (`nowo_chat_kit`) and the `nowo_chat_kit` asset package. No routes, services or templates are shipped yet. The sections below describe the integration contract that the first stable release will provide; see [ARCHITECTURE.md](ARCHITECTURE.md) for the full design.

## Planned integration

1. Install and configure the bundle ([INSTALLATION.md](INSTALLATION.md)).
2. Implement the two host interfaces (below) and register them as services.
3. Render the dock once in your base layout (a Twig function, planned name `nowo_chat_kit_dock()`).
4. Configure Mercure (FrankenPHP's built-in hub works out of the box) for realtime delivery.
5. Optionally configure Web Push VAPID keys.

## Host extension points

The host application stays the source of truth for users and permissions:

| Interface (planned) | Responsibility |
| --- | --- |
| Access / reachability provider | Decides who may start a conversation with whom, which channels a user belongs to (projects, rooms) and who is a support agent. |
| User display provider | Returns display name, avatar URL, initials and optional profile URL for a user identifier. |

The bundle never queries the host user table directly; it only stores user identifiers.

## Customizing markup and theme

- Every UI fragment (launcher, conversation list, conversation window, message bubble, composer, emoji picker, settings) is rendered by Twig into a `<template>` element; the browser code clones those templates. Override any fragment from `templates/bundles/NowoChatKitBundle/` (REQ-TWIG-001).
- Visual theming uses CSS custom properties prefixed `--nowo-chat-` (for example `--nowo-chat-accent`) on components with the `.nowo-chat-` class prefix.

## Naming conventions

| Item | Value |
| --- | --- |
| Composer package | `nowo-tech/chat-kit-bundle` |
| PHP namespace | `Nowo\ChatKitBundle` |
| Bundle class | `NowoChatKitBundle` |
| Configuration root / asset package | `nowo_chat_kit` |
| Twig namespace | `@NowoChatKit` |
| Translation domain | `nowo_chat_kit` |
| Route name prefix | `nowo_chat_kit_` |
| CSS classes / tokens | `.nowo-chat-*` / `--nowo-chat-*` |
| JS / Stimulus identifiers | `nowo-chat*` |
