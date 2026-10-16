# Coverage policy

## Table of contents

- [PHP line coverage gate](#php-line-coverage-gate)
- [TypeScript line coverage gate](#typescript-line-coverage-gate)
- [Justified exclusions](#justified-exclusions)
- [How to refresh](#how-to-refresh)

## PHP line coverage gate

`make coverage-check` enforces **≥ 99%** line coverage on the PHPUnit includable `src/` set (`REQ-TEST-003` / `REQ-TEST-006`); the target is **100%**.

The README percentage must match the latest coverage text / CI artifact.

## TypeScript line coverage gate

`make test-ts` / `pnpm run test:coverage` enforce **≥ 90%** lines, functions, statements and branches (`vitest.config.ts` `coverage.thresholds`); the target is **100%**.

## Justified exclusions

`phpunit.xml.dist` excludes `src/Resources` (Twig, compiled JS, PHP DI files that are not executable application code). No `@codeCoverageIgnore` annotations are used.

Vitest coverage (`vitest.config.ts` `coverage.exclude`) leaves out:

- `**/types.ts` and `**/types/**` — TypeScript interfaces and type aliases only (no runtime code);
- `**/iife.ts` — the IIFE bootstrap (auto start on `DOMContentLoaded` / Turbo for `nowo-chat.js`); the `mount` / `unmount` logic it calls lives in `index.ts`, which is covered;
- `**/*.test.ts` and `**/node_modules/**` — test files and dependencies.

Do **not** add new `@codeCoverageIgnore` or exclusions without updating this document.

## How to refresh

```bash
make coverage-check
make test-ts
```
