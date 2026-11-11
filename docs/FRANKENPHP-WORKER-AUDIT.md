# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/chat-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0` (`main`, 2026-10-16) |
| Audit date | 2026-10-16 |
| Method | Manual review of every service under `src/` (95 production units, see `specs/001-baseline/code-inventory.md`) + Igor deep audit (`make igor`: 70 service files, 0 KO) + PHPStan FrankenPHP rulesets |
| **Verdict** | ✅ **Viable under scenario B** — no per-request data is kept in a shared service; the only memo lives on the `Request` object |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests.

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` are reset between requests.
  This is what the stock Symfony kernel does in FrankenPHP worker mode, also with `FRANKENPHP_RESET_KERNEL=false`:
  `Kernel::handle()` calls `boot()`, which runs `services_resetter` before every request after the first (the flag
  only decides whether the kernel is cloned).
- **B — no reset at all:** any per-request state kept in a service leaks into the next request. This happens wherever
  the services resetter does not run: a host that removes or bypasses `services_resetter` or drives `http_kernel`
  directly, Messenger consumers started with `messenger:consume --no-reset`, and long-running console commands.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Services hold only constructor-injected collaborators and configuration (most are `final readonly`; controllers and `ChatAccess` keep no request data in properties). `ChatAccess` memoizes access answers in the current main request's attributes, never in a property: nothing crosses requests, even without `services_resetter`; console / Messenger compute every time (including the `usersByIds()` / `directPeer()` directory lookups). The property memos (`ChatReferences`, `ChatAvatars`, `ChatUserSettingsRepository`, `UserReferenceListener`) are emptied at every request / command / message boundary by `ChatRequestBoundaryListener` (since 1.26.3; see below), and by `kernel.reset`. |
| Caches | ✅ | Presence, recipient lists and digest markers live in the configured PSR-6 pool (`cache_pool`) with TTLs — shared storage, not process memory. |
| Static properties / `static` locals | ✅ | None |
| Request / user / locale captured in services | ✅ | Controllers and the Twig extension read the user and locale per call; no service stores them. |
| Superglobals, `putenv`, `ini_set`, `setlocale`, `exit` | ✅ | None in `src/` |
| Doctrine entities | ✅ | Per-row state (annotated `@igor-ignore` with the reason); not services. |
| Blocking I/O and timeouts | ✅ | No outbound HTTP or subprocess in the bundle; Mercure publishing uses the host's `HubInterface` client (timeouts in [USAGE.md](USAGE.md#timeouts)); Web Push / email run in Messenger workers. |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |
| Igor worker-state audit | ✅ | `make igor` in `release-check` (REQ-CS-008) |

## 1.26.0 additions (2026-11-10)

| Unit | Status | Notes |
|------|--------|-------|
| `ChatApiController::csrf()` (`GET …/api/csrf`, route `nowo_chat_api_csrf`) | ✅ | Reads the user and the session's `nowo_chat` token per call (token manager over the current request's session); nothing kept in the controller. |
| `ChatDockExtension` `$dialogRuntime` / `$packages` (`web_ui.dock.dialog_runtime`) | ✅ | A config boolean and the stateless `assets.packages` service; the runtime URL is computed per call. |
| Verified without the services resetter | ✅ | `ChatCsrfRefreshTest` drives the container's `http_kernel` (`handle()` + `terminate()`) directly: two people served in turn (50 rounds: each gets their own id, the other's token is refused) and 1,000 heartbeats (`presence` + `csrf`) under 64 KiB on stable Symfony. On Symfony 8.2.0-BETA1 the main firewall's `kernel.request` listener itself keeps about 0.7-1.5 KB per request (anonymous requests too, no bundle code involved); there the test compares the heartbeat's per-request slope with a host route behind the same firewall. |

## 1.26.3 fix: memos cleared at the request boundary, not only by `kernel.reset` (2026-11-11)

Scenario B above was not met by 1.9.0 to 1.26.2: four property memos were emptied only by `kernel.reset`, so wherever
the services resetter does not run (scenario B: a host that removes or bypasses `services_resetter` or drives
`http_kernel` directly, `messenger:consume --no-reset`, long-running commands) they crossed requests, messages or
commands. A stock web worker (scenario A, including `FRANKENPHP_RESET_KERNEL=false`) was not affected. The
security-relevant one was `ChatReferences::$resolved` (access-filtered reference cards per viewer): a revoked or deleted
item kept its card for that viewer until the process restarted. The bundle-owned path is the e-mail digest:
`ChatDigestDispatcher` under `messenger:consume --no-reset` kept a revoked card's title, subtitle and badge in the
preview, and kept sending digests to a user who had opted out (memoised `ChatUserSettings` row). 1.26.3 clears every memo at the boundaries the bundle sees itself
(REQ-RUNTIME-002 rule 1); `ResetInterface` stays as a second line (rule 2).

| Stateful unit | Holds | Cleared by |
|---------------|-------|------------|
| `ChatReferences::$resolved` | viewer id => `type:id` => card or "not readable" (access-filtered) | `ChatRequestBoundaryListener` + `kernel.reset` |
| `ChatAvatars::$urls` | public id => sanitised picture URL | `ChatRequestBoundaryListener` + `kernel.reset` |
| `ChatUserSettingsRepository::$memo` | user id => managed `ChatUserSettings` | `ChatRequestBoundaryListener` + `kernel.reset` |
| `UserReferenceListener::$resolved` (id mode only) | `WeakMap` of entities whose users are set | `ChatRequestBoundaryListener` + `kernel.reset` |
| `ChatAccess` | access answers on the main request's attributes | the request itself (no property) |

**Boundary events** (`src/Worker/ChatRequestBoundaryListener.php`, registered in the bundle's `services.php`): main
`kernel.request` (priority 2048, before the firewall), `kernel.terminate`, `console.terminate`, Messenger
`WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`. Sub-requests keep the memos of their main request. The
listener's references are lazy (an iterator of `IGNORE_ON_UNINITIALIZED_REFERENCE` references): only memos already
built in the process are reset, so a request that never touches the chat builds none of them.

**Worker tests** (one kernel, the container's `http_kernel` called directly: no reboot, no `services_resetter`):

- `tests/Functional/ChatWorkerAccessMemoTest.php`: Luis is granted document 7 and served its card; the grant is deleted
  with plain SQL; the next request shows the neutral "Content not visible" chip and no reference; granted again, the
  card comes back. Then two people (Ana, Luis) in turn for 200 rounds (400 requests) while Luis is granted and revoked
  every round and the host changes Ana's picture every round: each response holds only what that person may open now,
  and the picture of that request (the card is asserted first, so it is proven on every round); memory may grow by at
  most 4 MiB after a 20-round warm-up. The `/doc` test provider reads its grants from the database on every call.
- `tests/Integration/Worker/ChatRequestBoundaryListenerTest.php`: the listener is on every boundary event; the main
  `kernel.request` and the other boundaries empty the reference, avatar and settings memos; a sub-request does not;
  memos not built yet are skipped (the listener builds none), and built ones are emptied.
- `tests/Unit/Doctrine/UserReferenceListenerResetTest.php`: `reset()` forgets the resolved entities.
- Memory: `ChatCsrfRefreshTest` (1.26.0) keeps the 1,000-heartbeat flat-memory check (64 KiB on stable Symfony).

## Rules for future code

- Keep presence, typing and counters in storage (database / cache pool / Mercure), never in service properties.
- Per-request memoization goes on the `Request` (as `ChatAccess` does), or in a service implementing `ResetInterface`
  that is also cleared by `ChatRequestBoundaryListener` (add it there). `kernel.reset` alone is not enough.
- Any outbound client added later needs explicit timeouts (REQ-RUNTIME-001).

Re-run this audit (and update the table) whenever services are added under `src/`.
