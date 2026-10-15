# Chat Kit Bundle — baseline code inventory

**Package**: `nowo-tech/chat-kit-bundle`  
**Last audited**: 2026-10-15  
**Production units**: **5/5**  
**Audit command**: count `src/**/*.php` + `src/**/*.twig` + `src/Resources/translations/*.yaml` + `src/Resources/assets/src/**/*.ts` (exclude `src/Resources/assets/tests/**`).

## Scope notes

- Vitest files under `src/Resources/assets/tests/` are **excluded** (`FR-TEST-TS-001`).
- Vite IIFE `src/Resources/public/js/nowo-chat.js` is a **build output** (`FR-BUILD-001`), not a separate production unit.
- `src/Resources/assets/README.md` is documentation, not a runtime unit.
- `demo/` is out of inventory scope.

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle & DI | 4 |
| Browser entry | 1 |
| **Total** | **5** |

## Bundle & DI

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/NowoChatKitBundle.php` | US-01 | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | US-01, US-02 | FR-CFG-001 |
| `src/DependencyInjection/NowoChatKitExtension.php` | US-01, US-03 | FR-DI-001, FR-ASSETS-001 |
| `src/Resources/config/services.php` | US-01 | FR-DI-001 |

## Browser entry

| Source path | Spec section | Requirement ID(s) |
| --- | --- | --- |
| `src/Resources/assets/src/index.ts` | US-04 | FR-TS-001, FR-BUILD-001 |
