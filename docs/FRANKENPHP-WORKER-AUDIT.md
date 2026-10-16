# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/chat-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0` (`main`, 2026-10-16) |
| Audit date | 2026-10-16 |
| Method | Manual review of every service under `src/` (94 production units, see `specs/001-baseline/code-inventory.md`) + Igor deep audit (`make igor`: 68 service files, 0 KO) + PHPStan FrankenPHP rulesets |
| **Verdict** | ✅ **Viable under scenario B** — no per-request data is kept in a shared service; the only memo lives on the `Request` object |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests.

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` are reset between requests.
- **B — no reset at all:** any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Services hold only constructor-injected collaborators and configuration (most are `final readonly`; controllers and `ChatAccess` keep no request data in properties). `ChatAccess` memoizes access answers in the current main request's attributes, never in a property: nothing crosses requests, even without `services_resetter`; console / Messenger compute every time. |
| Caches | ✅ | Presence, recipient lists and digest markers live in the configured PSR-6 pool (`cache_pool`) with TTLs — shared storage, not process memory. |
| Static properties / `static` locals | ✅ | None |
| Request / user / locale captured in services | ✅ | Controllers and the Twig extension read the user and locale per call; no service stores them. |
| Superglobals, `putenv`, `ini_set`, `setlocale`, `exit` | ✅ | None in `src/` |
| Doctrine entities | ✅ | Per-row state (annotated `@igor-ignore` with the reason); not services. |
| Blocking I/O and timeouts | ✅ | No outbound HTTP or subprocess in the bundle; Mercure publishing uses the host's `HubInterface` client (timeouts in [USAGE.md](USAGE.md#timeouts)); Web Push / email run in Messenger workers. |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` in `release-check` (REQ-CS-008) |

## Rules for future code

- Keep presence, typing and counters in storage (database / cache pool / Mercure), never in service properties.
- Per-request memoization goes on the `Request` (as `ChatAccess` does) or in a service implementing `ResetInterface`.
- Any outbound client added later needs explicit timeouts (REQ-RUNTIME-001).

Re-run this audit (and update the table) whenever services are added under `src/`.
