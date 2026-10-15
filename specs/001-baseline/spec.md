# Feature Specification: ChatKitBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active (skeleton)  

**Package**: `nowo-tech/chat-kit-bundle`  
**Configuration root**: `nowo_chat_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Floating LinkedIn-style chat for Symfony: direct messages, channels and a support inbox with realtime (Mercure), presence, read receipts, typing, Web Push, customizable Twig templates and theme tokens.

This baseline covers the **skeleton** only (bundle, configuration, asset package, browser entry placeholder). Chat features are specified in follow-up specs as they are migrated; the target design is in [`docs/ARCHITECTURE.md`](../../docs/ARCHITECTURE.md).

---

## User Scenarios

### US-01 — Install and configure (P1)

**Given** the bundle is registered, **When** no configuration is provided, **Then** `nowo_chat_kit.default_profile` is `default` and `profiles` contains `default` with `enabled: true`.

### US-02 — Named profiles (P1)

**Given** `default_profile` and `profiles`, **When** `default_profile` is not a key of `profiles`, **Then** container compilation fails with `InvalidConfigurationException`.

### US-03 — Published assets (P1)

**Given** FrameworkBundle is enabled, **When** the container is built, **Then** the asset package `nowo_chat_kit` resolves to `/bundles/nowochatkit`.

### US-04 — Theme tokens (P2)

**Given** the browser entry, **When** `cssToken('accent')` is called, **Then** it returns `--nowo-chat-accent`; empty names are rejected.

---

## Requirements

- **FR-BUNDLE-001**: `NowoChatKitBundle` exposes the extension alias `nowo_chat_kit`.
- **FR-CFG-001**: `default_profile` + `profiles` (at least one profile; `default_profile` must exist).
- **FR-DI-001**: `src/Resources/config/services.php` holds service wiring (empty in the skeleton); config is exposed as container parameters `nowo_chat_kit.default_profile` and `nowo_chat_kit.profiles`.
- **FR-ASSETS-001**: Prepend `framework.assets.packages.nowo_chat_kit` (`base_path: /bundles/nowochatkit`) when FrameworkBundle is present.
- **FR-TS-001**: Browser entry exports `VERSION`, `CSS_TOKEN_PREFIX` (`--nowo-chat-`) and `cssToken()`.
- **FR-BUILD-001**: Vite builds `src/Resources/assets/src/index.ts` into the IIFE `src/Resources/public/js/nowo-chat.js`.
- **FR-TEST-TS-001**: Vitest suites under `src/Resources/assets/tests/` (not inventory rows).

---

## Success Criteria

- **SC-001**: PHPUnit, PHPStan (level 8, no baseline), PHP-CS-Fixer and Vitest pass.
- **SC-002**: Every production unit appears in `code-inventory.md`.
