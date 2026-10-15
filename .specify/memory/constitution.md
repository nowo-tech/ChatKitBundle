# ChatKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`, `ARCHITECTURE.md`, `SECURITY.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit, PHPStan, and Vitest are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production unit under `src/` (PHP, Twig, translation YAML, TypeScript sources) must appear in `specs/001-baseline/code-inventory.md`. The Vite IIFE output is documented as `FR-BUILD-001`, not an extra inventory row. New files require spec updates in the same PR.

### IV. The host owns users and permissions
The bundle stores user identifiers only. Who may reach whom, channel membership and support agents are decided by the host access / reachability provider; names and avatars come from the host user display provider. The bundle never trusts a user identifier sent by the browser.

### V. Privacy and safety defaults
Message bodies are never logged and never rendered as HTML. Every session-authenticated mutation is CSRF-protected (fail-closed). Mercure subscriptions are scoped to the current user.

### VI. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### VII. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges. FrankenPHP worker mode: no per-request state in services. See `docs/FRANKENPHP-WORKER-AUDIT.md`.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-10-15 | **Last Amended**: 2026-10-15
