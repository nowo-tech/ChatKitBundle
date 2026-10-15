# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/chat-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for `src/` and `tests/`. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Planned** | Realtime / push failures will be logged through `Psr\Log\LoggerInterface`. |
| PSR-6 / PSR-16 Cache | **To evaluate** | Presence TTL and rate limits may use a cache pool. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface. |
| PSR-18 HTTP client | **To evaluate** | Web Push delivery may use an HTTP client with explicit timeouts. |
| PSR-11 Container | **N/A** | Constructor injection only. |
| PSR-14 Event dispatcher | **Already satisfied via Symfony** | Domain events will use Symfony contracts events. |
| PSR-15 HTTP middleware | **N/A** | Symfony controllers / subscribers. |
| PSR-20 Clock | **Planned** | Message timestamps and presence TTL via `Psr\Clock\ClockInterface`. |

## Summary

- **Adopted beyond baseline:** none yet (skeleton).
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.

---

_REQ-CS-007 evaluation date: 2026-10-15._
