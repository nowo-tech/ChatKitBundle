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

| PSR | Decision | Where |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `Psr\Log\LoggerInterface` in `Realtime/ChatPublisher` (failed hub publishes are logged without message bodies). `psr/log` is required. |
| PSR-6 Cache | **Adopted** | `Psr\Cache\CacheItemPoolInterface` (profile `cache_pool`) in `Realtime/ChatPresence` (presence TTL), `Realtime/ChatRecipients` (recipient lists), `Digest/ChatDigestScheduler` and `Digest/ChatDigestDispatcher` (one digest per interval). `psr/cache` is required. Rate limits use Symfony RateLimiter (its own storage). |
| PSR-16 Simple cache | **N/A** | PSR-6 pools already cover every need (tags not used). |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface for controllers. |
| PSR-18 HTTP client | **N/A** | The bundle makes no outgoing HTTP call itself: Mercure publishing goes through the host's `HubInterface`, Web Push through the host's `ChatPushSenderInterface`. |
| PSR-11 Container | **N/A** | Constructor injection only. |
| PSR-14 Event dispatcher | **Adopted (through Symfony contracts)** | `ChatService` dispatches `ChatMessageSentEvent`, `ChatMessageDeletedEvent`, `ChatSupportStatusChangedEvent` with `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` (a PSR-14 dispatcher). |
| PSR-15 HTTP middleware | **N/A** | Symfony controllers / subscribers. |
| PSR-20 Clock | **Adopted (through Symfony Clock)** | `Symfony\Component\Clock\ClockInterface` (extends `Psr\Clock\ClockInterface`) in `ChatService`, `ChatVoter` (edit window), `ChatPresence`, `ChatPushDispatcher`; tests use `MockClock`. |

## Summary

- **Adopted beyond baseline:** PSR-3, PSR-6, PSR-14 (Symfony contracts), PSR-20 (Symfony Clock).
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.

---

_REQ-CS-007 evaluation date: 2026-10-16._
