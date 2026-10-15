# Security

## Table of contents

- [Scope](#scope)
- [Security model (planned)](#security-model-planned)
- [Secrets](#secrets)
- [FrankenPHP](#frankenphp)
- [AI security audit](#ai-security-audit)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

The `1.0.x-dev` skeleton registers configuration and an asset package only: no routes, no persistence, no network I/O. This document records the security model the chat features must follow as they are added.

## Security model (planned)

- **Authorization is host-owned**: every conversation start, message send and channel read goes through the host access / reachability provider. The bundle never trusts a user identifier sent by the browser.
- **CSRF** on every session-authenticated mutation (send, mark read, buzz, settings), fail-closed (REQ-SEC-005).
- **Output escaping**: message bodies are plain text rendered with Twig auto-escaping / `textContent`; no user-provided HTML.
- **Mercure**: private topics per user, subscriber JWTs scoped to the current user's topics only, short TTL.
- **Rate limits** on send and buzz.
- **Web Push**: VAPID private key from environment variables only; payloads contain no message body unless the host enables it.
- **Logs** contain identifiers, never message contents.

## Secrets

No secrets are committed. Demo values in `demo/symfony8/.env.example` are placeholders for local Docker use only; `.env` / `.env.local` are git-ignored.

## FrankenPHP

The skeleton holds no per-request state in services. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## AI security audit

| Field | Value |
| --- | --- |
| Date | — |
| Method | — |
| Grade | **Not audited yet** (skeleton; no runtime surface) |
| Overall risk | — |
| Open residuals | Audit required before the first tagged release (REQ-SEC-004). |

## Reporting a vulnerability

Report security issues **privately**:

1. Do **not** open a public GitHub issue for security-sensitive bugs.
2. Use [GitHub Security Advisories](https://github.com/nowo-tech/ChatKitBundle/security/advisories) or email **hectorfranco@nowo.tech**.
3. Include steps to reproduce, affected versions, and impact.
4. We will acknowledge and coordinate disclosure after a fix is available.

See also [`.github/SECURITY.md`](../.github/SECURITY.md) for supported versions and reporting policy.

## Release security checklist (12.4.1)

Before each release, the maintainer confirms each item (note in the release PR or tag message is sufficient).

| Item | Check |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` up to date | ☐ |
| `.env` / secrets in `.gitignore`; no credentials in repo | ☐ |
| Flex recipe / published config contains no secrets | ☐ |
| Authorization delegated to the host provider on every endpoint | ☐ |
| CSRF on all session-authenticated mutations (fail-closed) | ☐ |
| Message bodies escaped; no user HTML rendered | ☐ |
| Mercure subscriber tokens scoped to the current user | ☐ |
| `composer audit` run; known issues reviewed | ☐ |
| Rate limits on send / buzz; consumer apps should also apply HTTP limits | ☐ |
