# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies](#dependencies)
- [Permissions and exposure](#permissions-and-exposure)
- [Host responsibilities (residual risks)](#host-responsibilities-residual-risks)
- [AI security audit](#ai-security-audit)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Scope

This document is the security analysis of **ChatKitBundle** (`nowo-tech/chat-kit-bundle`): what it accepts, what can go
wrong and how the bundle prevents it, plus what the host application must do. The short reporting policy lives in
[`.github/SECURITY.md`](../.github/SECURITY.md).

**Covered:** the bundle's PHP code (controllers, voter, services, Doctrine entities and repositories, realtime, Web
Push and digest dispatch, link previews, GDPR helpers, purge command, DI defaults), its Twig views, the TypeScript dock
and the Flex recipe. **Not covered:** the host's users, authentication, firewall, Mercure hub, Messenger transports,
mailer and push stack, and the host's implementations of the contracts (they decide who exists and who may talk to
whom).

## Attack surface

| Input | Where | Who |
| --- | --- | --- |
| JSON API `/chat/api/*` (GET + POST / PATCH / DELETE) | `ChatApiController` | Signed-in users only (401 otherwise) |
| Full-page chat `/chat/`, `/chat/{uuid}` (GET + form POST) | `ChatPageController`, `ChatPageMessageType` | Signed-in users only (403 otherwise) |
| Message bodies (Markdown, ≤ 4000 characters), reactions, support context (path + label) | API / page | Conversation members |
| Link-preview URLs (`url[]`, ≤ 10 per request) | `/chat/api/link-previews` → host `ChatLinkPreviewProviderInterface` | Signed-in users |
| Realtime: private Mercure topic, subscriber JWT | `MercureChatRealtime`, `/chat/api/realtime` | The user's own topic only |
| Messenger messages `DeliverChatPushMessage`, `SendChatDigestMessage` (ids only) | Push / Digest dispatchers | Async workers |
| Configuration (`nowo_chat_kit`), Flex recipe | DI extension | Deployer |
| CLI `nowo:chat:purge` | `ChatPurgeCommand` | Operator |
| Browser storage (`nowo-chat*` keys), BroadcastChannel | TypeScript dock | Same browser, same origin |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Unauthenticated access** | Every API and page action requires a signed-in `ChatUserInterface` user the policy says is reachable (401 / 403). No access policy or directory ships: the container fails closed until the application provides them. |
| **Authorization / IDOR** | Conversations and messages are looked up by UUID, messages scoped to their conversation, then checked with `ChatVoter` (VIEW, SEND, MODERATE, RESOLVE, EDIT, DELETE, REACT). Unknown or foreign → **404**, never 403 (no existence oracle). |
| **Stale access after revocation** | `ChatAccess::canView()` is the single read rule for the voter, both conversation lists and the email digest — participant rows left behind by a channel the user left or a lost agent role grant nothing. Access answers are memoized on the request object only (no cross-request cache, also in FrankenPHP workers). Realtime recipient lists are cached ≤ 30 s; `ChatRecipients::forget()` drops them at once. |
| **CSRF** | API mutations require the `X-CSRF-TOKEN` header (token id `nowo_chat`); the page form uses Symfony Form CSRF (`nowo_chat_page`). Fail-closed. |
| **XSS** | `ChatMarkdownRenderer` escapes everything first (`ENT_QUOTES`), then adds a closed set of tags; autolinks are http(s) only with `rel="noopener nofollow ugc"`; control, private-use and bidi characters are stripped. The dock fills text with `textContent`; only the server-rendered message HTML is inserted as HTML (`innerHTML` / `|raw`). `bodyHtml` must only ever come from the renderer. |
| **Phishing of agents** | The support "Reported from" context comes from the reporter's browser: only root-relative paths of the application are kept (no absolute, `//host` or `/\host` URLs), control / bidi characters are removed from the label. |
| **Injection** | Doctrine query builder / parameters only; no raw SQL with user input; no shell, no `eval`, no `unserialize`. JSON bodies are decoded with depth 16 and `JSON_THROW_ON_ERROR`, capped at 32 KB. |
| **SSRF** | The bundle makes no outbound HTTP call. Link-preview URLs go to the host provider, which must resolve its **own routes only** (no remote fetch) and check them for the viewer. |
| **Information leaks** | Error responses carry stable codes, never exception text. Every JSON response (errors included) is `Cache-Control: no-store, private`. Web Push bodies carry a generic "New message" unless `push_preview: true`. Deleted messages blank body and HTML. |
| **Realtime eavesdropping** | Mercure updates are `private`, on per-user topics; the subscriber JWT is limited to the caller's topic, subscribe-only, 15 minutes. |
| **Abuse / DoS** | Sliding-window rate limits per user: send, actions, typing, reads, buzz (per conversation and per minute); 4000-character bodies; edit / delete window 15 minutes; paging limits on every list; purge in bounded batches. |
| **Tampered async messages** | Messenger messages carry ids only; handlers are registered with `sign: true` and re-check reachability, settings, mute, read state and open tabs at delivery. |
| **Shared devices** | Chat browser storage is cleared on logout links / forms and when the API answers 401 (expired session). |
| **Worker-mode state leaks** | No per-request data in shared services (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)); Igor audit in `release-check`. |

## Secrets and cryptography

The bundle ships no secret and stores none. It signs nothing itself: Mercure JWTs are created by the host's hub
configuration (`HubInterface` / token factory), Messenger signing uses the host's key, Web Push VAPID keys belong to the
host's push sender. Keep them in environment variables or the Symfony secrets vault. Identifiers exposed to clients are
UUIDs (no sequential ids in URLs).

## Logging

The bundle logs **no message content and no personal data**: failed hub publishes (`ChatPublisher`: the event type and the
exception, never the payload), and `nowo:chat:purge` start / end / failure with counts only. Host senders should follow the same rule.

## Dependencies

`composer audit` runs in CI (`Composer audit (Critical/High)` step) and before each release; Dependabot keeps
dependencies current. Security fixes are released as patch versions of the supported line (see
[`.github/SECURITY.md`](../.github/SECURITY.md)).

## Permissions and exposure

| Exposure | Restriction |
| --- | --- |
| `/chat/api/*`, `/chat/`, `/chat/{uuid}` | Behind the host's session firewall; the bundle re-checks the user on every request |
| Support inbox endpoint, resolve / reopen, moderation | Support agents only (policy); others get 404 |
| Contacts endpoint | Exactly what `ChatDirectoryInterface::contactsFor()` returns (it may include e-mail addresses — decide in the directory) |
| Mercure topics | `/nowo-chat/users/<public id>`, private |

## Host responsibilities (residual risks)

- **Mercure:** strong publisher secret, **no anonymous subscribers** in production, HTTPS. The subscriber JWT travels as
  an `authorization` query parameter — prefer the hub cookie when same-site, or scrub hub / proxy access logs.
- **Messenger:** asynchronous, secured transports for `DeliverChatPushMessage` and `SendChatDigestMessage`; stable signing
  key; failure transport (see [USAGE.md](USAGE.md#messenger-retries-and-failures)).
- **Shared storage:** rate limiters and the `cache_pool` (presence, recipients, digest markers) must be shared by every
  worker / replica (e.g. Redis), or limits and presence only hold per process.
- **Contracts:** the policy, directory and channel provider decide reachability and membership — keep `canUse()` and
  `members()` consistent; call `ChatRecipients::forget()` when revoking access to a known conversation for an immediate
  effect.
- **Link previews:** resolve only your own routes, authorize per viewer, never fetch remote URLs.
- **Digest and push senders:** escape `ChatDigestItem` fields in email templates; keep VAPID keys secret; set HTTP / SMTP
  timeouts.
- **Browser:** a Content-Security-Policy on pages with the dock is recommended (the bundle needs no inline script; its
  assets load from the `nowo_chat_kit` package).
- **Retention:** schedule `nowo:chat:purge` (cron / Scheduler).
- **Accepted:** `GET /chat/{uuid}` marks the conversation read (needs the conversation UUID; harmless state change).

## AI security audit

| Field | Value |
| --- | --- |
| Date | 2026-10-16 |
| Method | Independent AI static review (Claude Code agent) against BUNDLES_SECURITY_ANALYSIS.md §1 / REQ-SEC-004 |
| First result | Overall **Medium**, **Pass (conditional)** — no Critical / High |
| Findings | CHAT-SEC-01 (Medium) revoked-access previews → **fixed** (`ChatAccess::canView()` on lists and digest); CHAT-SEC-02 (Medium) outdated security doc → **fixed** (this document); CHAT-SEC-03 (Low) push body text → **fixed** (`push_preview`, default off); CHAT-SEC-04 (Low) off-site support context → **fixed** (root-relative paths only, label sanitised); CHAT-SEC-05 (Low) 30 s recipient cache → **mitigated** (`ChatRecipients::forget()`, documented); CHAT-SEC-10 (Info) storage after session timeout → **fixed** (cleared on 401); CHAT-SEC-06 / 07 / 08 / 09 (Info) → documented above as host responsibilities / accepted |
| Recorded in | `BUNDLES_SECURITY_ANALYSIS.md` (monorepo security report) |

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
| `.env` / secrets in `.gitignore`; no credentials in the repository | ☐ |
| Flex recipe / published config contains no secrets | ☐ |
| Input validated and output escaped (Markdown renderer, `textContent`, support context) | ☐ |
| Authorization on every endpoint (signed-in user, voter, 404 for foreign UUIDs) | ☐ |
| CSRF on all session-authenticated mutations (fail-closed) | ☐ |
| Mercure subscriber tokens scoped to the current user's topic | ☐ |
| `composer audit` run; known issues reviewed | ☐ |
| Logs contain no message content or secrets | ☐ |
| Limits / DoS: rate limits, body and JSON caps, paging, bounded purge | ☐ |
| AI security audit (REQ-SEC-004) passed — latest grade in `BUNDLES_SECURITY_ANALYSIS.md` | ☐ |
