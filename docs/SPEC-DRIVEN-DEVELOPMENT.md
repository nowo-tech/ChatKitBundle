# Spec-driven development

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **ChatKitBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [User stories](#user-stories)
- [Bundle functional scope](#bundle-functional-scope)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req-)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram / external checklists](#relationship-to-engram--external-checklists)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## User stories

The IDs match the user scenarios of the baseline spec ([`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md#user-scenarios)), where each has Given/When/Then acceptance criteria.

| ID | Story | Integrator docs |
| --- | --- | --- |
| US-01 | **As an** integrator, **I want** to install the bundle, point it at my user entity and implement two contracts **so that** signed-in, reachable users get the dock. | [INSTALLATION](INSTALLATION.md#requirements) ([user entity](INSTALLATION.md#your-user-entity), [routes](INSTALLATION.md#routes), [database](INSTALLATION.md#database), [contracts](INSTALLATION.md#contracts-to-implement), [render the dock](INSTALLATION.md#render-the-dock)) · [CONFIGURATION — Profiles](CONFIGURATION.md#profiles) · [USAGE — Contracts](USAGE.md#contracts) |
| US-02 | **As a** user, **I want** direct messages with the people I may reach (unread counts, reactions, receipts, edit / delete window) **so that** I can talk one to one. | [USAGE — Conversations and permissions](USAGE.md#conversations-and-permissions) · [USAGE — JSON API](USAGE.md#json-api) · [CONFIGURATION — Rate limits](CONFIGURATION.md#rate-limits) |
| US-03 | **As a** project member, **I want** application channels (e.g. one per project) **so that** the whole team shares one conversation. | [USAGE — `ChatChannelProviderInterface`](USAGE.md#chatchannelproviderinterface-optional) · [USAGE — Conversations and permissions](USAGE.md#conversations-and-permissions) |
| US-04 | **As a** user, **I want** to write to "Support" **so that** any agent can answer, resolve and reopen my thread from a shared inbox. | [USAGE — Support inbox](USAGE.md#support-inbox) · [USAGE — Opening conversations from your pages](USAGE.md#opening-conversations-from-your-pages) · [USAGE — Events](USAGE.md#events) |
| US-05 | **As a** user, **I want** typing indicators, presence, read receipts, buzzes and sounds across tabs **so that** the chat feels live. | [INSTALLATION — Realtime (Mercure)](INSTALLATION.md#realtime-mercure) · [USAGE — User settings](USAGE.md#user-settings) · [USAGE — Timeouts](USAGE.md#timeouts) · [CONFIGURATION — Reference](CONFIGURATION.md#reference) (`realtime`, `cache_pool`, `presence_ttl`) |
| US-06 | **As a** user, **I want** Web Push when no tab is visible and an email digest of what stays unread **so that** I do not miss messages nor get notified twice. | [USAGE — Web Push](USAGE.md#web-push) · [USAGE — Email digest](USAGE.md#email-digest) · [USAGE — Messenger](USAGE.md#messenger-retries-and-failures) · [CONFIGURATION — Reference](CONFIGURATION.md#reference) (`push`, `digest.*`) |
| US-07 | **As a** user, **I want** a full-page chat that works without JavaScript **so that** I can read and answer in a page of the application. | [USAGE — Full-page chat](USAGE.md#full-page-chat) · [CONFIGURATION — Reference](CONFIGURATION.md#reference) (`page_layout`) |
| US-08 | **As an** integrator, **I want** overridable Twig `<template>` markup, `--nowo-chat-*` tokens, icons, bottom-bar avoidance and a dock that hides to the side **so that** it matches my design system. | [USAGE — Customising the markup](USAGE.md#customising-the-markup) · [USAGE — Theme tokens](USAGE.md#theme-tokens) ([tucking the dock away](USAGE.md#tucking-the-dock-away)) · [USAGE — Translations](USAGE.md#translations) · [INSTALLATION — Icons](INSTALLATION.md#icons) · [USAGE — Front end](USAGE.md#front-end-iife-or-your-bundler) |
| US-09 | **As a** data controller, **I want** export / erasure helpers and a retention purge **so that** chat data follows GDPR rules. | [USAGE — Privacy](USAGE.md#privacy) · [INSTALLATION — Retention purge](INSTALLATION.md#retention-purge) · [CONFIGURATION — Reference](CONFIGURATION.md#reference) (`retention_days`) |
| US-10 | **As a** user, **I want** links to the application shown as preview cards and page elements that open a conversation **so that** the app and the chat stay connected. | [USAGE — Link previews](USAGE.md#link-previews) · [USAGE — Opening conversations from your pages](USAGE.md#opening-conversations-from-your-pages) |

**Out of scope:** end-to-end encryption, video/voice calls, federation with external chat networks, and behavior outside declared PHP/Symfony compatibility ranges.

---

## Bundle functional scope

**Goal:** floating LinkedIn-style chat for Symfony: direct messages, channels and a support inbox with realtime (Mercure), presence, read receipts, typing, Web Push, customizable Twig templates and theme tokens.

**Current state:** `1.2.x` — implemented and covered by the baseline spec [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) (user scenarios US-01…US-10, `FR-*` by domain) and its 100% code inventory. See [`ARCHITECTURE.md`](ARCHITECTURE.md) for the design.

**In scope**

- Direct messages, application channels (`ChatChannelProviderInterface`) and a support inbox (agents, resolve / reopen, context, moderation, events).
- JSON API for the dock and a server-rendered full-page chat (Symfony Form, works without JavaScript).
- Realtime through Mercure private topics (polling fallback), presence, typing, read receipts, buzz, reactions, edit / delete window.
- Web Push and an unread-messages email digest through Messenger; link previews through the host's provider.
- GDPR export / erasure helpers and the `nowo:chat:purge` retention command.
- Overridable Twig `<template>` markup, `--nowo-chat-*` tokens (colours, shapes, shadows, sizes), bottom-bar avoidance (`data-nowo-chat-avoid`, Symfony toolbar), the dock tucked into a right-edge tab (1.2), Symfony UX Icons; 7 locales.
- Documented integration (root `README.md` and `docs/`).
- Configuration in [`CONFIGURATION.md`](CONFIGURATION.md) and runtime behavior in [`USAGE.md`](USAGE.md).
- Browser entry `nowo-chat.js` built from TypeScript via Vite.
- Consumer-facing changes in [`CHANGELOG.md`](CHANGELOG.md) and [`UPGRADING.md`](UPGRADING.md).

**Explicit non-goals**

- Managing host users, roles or permissions (delegated to host providers through the contracts).
- Running a Mercure hub, a Web Push stack or a mailer (the host provides them).
- Attachments, message search and threads inside conversations (not in 1.x).
- **`demo/`** UI: illustrative unless explicitly documented as stable API.

---

## Validating the functional spec

- Run **`make qa`** or **`make release-check`** as documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).
- Run **PHPUnit** and **Vitest** locally and in CI for code changes.
- New or changed behavior should add or adjust tests under `tests/` and `src/Resources/assets/tests/`.

---

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| `REQ-DEMO-005` | `demo/symfony8/Makefile` | Canonical `make up`: `Waiting…`, `Installing…`, `Demo started at:` from `PORT`. |
| `REQ-DEMO-007` | `demo/Makefile`, demo Makefiles | `update-bundle` before demo tests in `release-check`. |
| `REQ-MAKE-001` | Root `Makefile` | Docker-driven development workflow for the bundle. |
| `REQ-MAKE-008` | Root `Makefile` | `update-deps` via shared `.scripts/`. |
| `REQ-MAKE-002` | Root `Makefile` | `release-check` runs its steps sequentially in spec order. |
| `REQ-CS-008` | Root `Makefile` | `make igor` worker-state audit (FrankenPHP). |
| `REQ-TWIG-005` | Root `Makefile` | `check-no-raw-html-form`: no raw `<form>` / `<input>` / `<textarea>` / `<select>` in Twig. |
| `REQ-GIT-001` | Root `Makefile` | `setup-hooks`, `check-no-cursor-coauthor`, `strip-cursor-coauthor-from-history`. |
| `REQ-TEST-011` | Root / demo `Makefile` | `demo-smoke`: the demo boots and answers HTTP 200. |
| `REQ-DEMO-013` | `demo/symfony8/Makefile` | `test-e2e` (Playwright) and `demo-screenshots` (WebP under `docs/images/demo/`). |

When you change scripted behavior, update the existing `REQ-*` comment or add a new ID and document it here.

---

## Suggested workflow for contributors

1. Clarify behavior in an issue or draft PR (product + Makefiles/demos).
2. Implement with tests and static analysis.
3. Anchor scripts and demos when dev UX changes.
4. Ship integrator docs when configuration or public behavior changes.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## Relationship to Engram / external checklists

[`ENGRAM.md`](ENGRAM.md) covers Nowo-wide documentation checklist items. This document ties together **what the package does**, **how we verify it**, and **local `REQ-*` habits**. Both coexist: Engram for org-level compliance, this file for product + traceability.

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual (install, structure, usage)
- [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md)
- [`USAGE.md`](USAGE.md)
- [`CONFIGURATION.md`](CONFIGURATION.md)
- [`INSTALLATION.md`](INSTALLATION.md)
- [`CONTRIBUTING.md`](CONTRIBUTING.md)
- [`RELEASE.md`](RELEASE.md)
- [`DEMO-FRANKENPHP.md`](DEMO-FRANKENPHP.md)
