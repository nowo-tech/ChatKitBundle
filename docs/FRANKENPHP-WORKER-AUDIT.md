# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/chat-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.x-dev` (skeleton) |
| Audit date | 2026-10-15 |
| Method | Manual review of every PHP file under `src/` (bundle class, DI extension, configuration, `Resources/config/services.php`) |
| **Verdict** | ✅ **Viable under scenario B** — the skeleton registers no runtime services; only compile-time configuration and container parameters |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests.

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` are reset between requests.
- **B — no reset at all:** any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | No runtime services yet |
| Static properties / `static` locals | ✅ | None |
| Request / user / locale captured in services | ✅ | None |
| Superglobals, `putenv`, `ini_set`, `setlocale` | ✅ | None |
| Blocking I/O and timeouts | ✅ | None (REQ-RUNTIME-001 applies once Mercure / Web Push clients are added) |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` (REQ-CS-008) |

## Rules for upcoming code

- Presence, typing and unread counters must live in storage (database / cache / Mercure), never in service properties.
- Realtime publisher and Web Push clients must use explicit timeouts (REQ-RUNTIME-001) and must not keep per-request data.
- Any service that buffers data during a request must implement `ResetInterface` and be tagged `kernel.reset`.

Re-run this audit (and update the table) whenever services are added under `src/`.
