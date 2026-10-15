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

`make test-ts` / `pnpm run test:coverage` enforce **≥ 90%** lines (`vitest.config.ts`); the target is **100%**.

## Justified exclusions

`phpunit.xml.dist` excludes `src/Resources` (Twig, compiled JS, PHP DI files that are not executable application code). No `@codeCoverageIgnore` annotations are used.

Vitest coverage excludes `src/Resources/assets/src/types/**` (TypeScript interfaces only).

Do **not** add new `@codeCoverageIgnore` or exclusions without updating this document.

## How to refresh

```bash
make coverage-check
make test-ts
```
