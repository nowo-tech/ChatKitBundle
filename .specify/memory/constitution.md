# ChatKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `SECURITY.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit, PHPStan, and Vitest are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production unit under `src/` (PHP, Twig, translation YAML, TypeScript sources, the stylesheet) must appear in `specs/001-baseline/code-inventory.md`. The Vite IIFE output is documented as `FR-BUILD-001`, not an extra inventory row. New files require spec updates in the same PR.

### IV. The host owns users and permissions
The bundle maps the host's user entity through `ChatUserInterface` (`resolve_target_entities`) and never owns accounts. Who exists comes from `ChatDirectoryInterface`; who may reach whom and who is a support agent from `ChatAccessPolicyInterface`; channel membership from `ChatChannelProviderInterface`. The bundle never trusts a user identifier sent by the browser.

### V. Privacy and safety defaults
Message bodies are never logged. They are displayed only as **server-sanitised HTML** produced by `ChatMarkdownRenderer` (escape-first Markdown subset: the whole body is HTML-escaped before the few supported constructs and http(s) autolinks are added; control / bidi characters stripped); raw user HTML is never rendered, and the TypeScript inserts only that server output or text nodes. Every session-authenticated mutation is CSRF-protected (fail-closed). Mercure subscriptions are scoped to the current user.

### VI. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### VII. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges. FrankenPHP worker mode: no per-request state in services. See `docs/FRANKENPHP-WORKER-AUDIT.md`.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

Versioning (SemVer): **MAJOR** when a principle is removed or redefined incompatibly; **MINOR** when a principle or section is added or materially expanded; **PATCH** for clarifications and factual corrections that do not change intent.

**Version**: 1.0.1 | **Ratified**: 2026-10-15 | **Last Amended**: 2026-10-16
