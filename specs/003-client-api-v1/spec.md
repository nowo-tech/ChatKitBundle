# Feature specification: client API v1 (2.4.0)

**Package**: `nowo-tech/chat-kit-bundle`
**Status**: Implemented (2.4.0, unreleased)
**Design**: time-track-desktop `docs/design/BEACON_DESKTOP.md` §3.2–§3.5, §4.3–§4.4 (phase BD-2); Beacon constraints R1–R7;
owner decisions Q-BD-1..19 as recommended (2026-11-25).
**Plan**: [`plan.md`](plan.md) · **Tasks**: [`tasks.md`](tasks.md) · **Reference**: [`docs/API.md`](../../docs/API.md)

## Summary

A versioned, stateless, read-only JSON API for native clients (Beacon Desktop) at `<prefix>/chat/v1`. It uses Bearer
tokens resolved by a host port, takes the tenant explicitly on every request, and returns message bodies as structured
tokens filtered per reader. It is off unless the host configures a principal resolver.

## User scenarios

- **US-01 (P1)**:
  - **Given** a desktop holding a `chat:read` token,
  - **when** it lists conversations in context `org:acme`,
  - **then** it gets only the conversations it may read that belong to that context, with unread counts, and
    `unreadTotal` excludes muted conversations.
- **US-02 (P1)**: a reader never receives the title, type or id of a referenced item they may not open. They get a
  neutral "not visible" placeholder (R2).
- **US-03 (P1)**: a revoked token, a disabled account or a lost membership is refused on the next request, even in a
  long-lived worker (R7).
- **US-04 (P2)**: the desktop clears its own unread state with `chat:read` only (Q-BD-8).
- **US-05 (P2)**: the desktop subscribes to its own topic with a short-lived token issued by the host's transport, or
  polls when the hub is off (R1). With `realtime_events: ids`, no message body reaches the hub (Q-BD-13).

## Functional requirements

- **FR-CAPI-001**: routes under `<client_api.prefix>/chat/v1`. The full path is exposed as
  `%nowo_chat_kit.client_api.v1_path_prefix%`. The OpenAPI document is kept in step by a test.
- **FR-CAPI-002**: Bearer only. No session, cookie or CSRF. The token is resolved by
  `ChatClientPrincipalResolverInterface` on every request. Without a resolver, there is no route.
- **FR-CAPI-003**: the scope `chat:read` is required on every v1 route.
- **FR-CAPI-004**: the tenant is the token's context or the context header. The header is checked by
  `ChatClientContextPolicyInterface` on every request, and the person's web context is never written.
- **FR-CAPI-005**: access rules are those of the web UI (`ChatVoter::decide()`). Unknown, foreign and out-of-context
  uuids answer 404.
- **FR-CAPI-006**: bodies are `tokens/1` (Q-BD-16). References are filtered per reader, and link previews come from the
  host's provider per reader.
- **FR-CAPI-007**: budgets per IP and per token, on top of the person's own budgets. A spent budget answers 429 with
  `Retry-After`.
- **FR-CAPI-008**: refusals dispatch `ChatClientApiDeniedEvent` with ids and codes only.
- **FR-CAPI-011** (audit ZBW):
  - A bound token without a context policy is refused.
  - `allows()` is asked for bound tokens too.
  - A context policy requires `realtime_events: ids`.
  - `ids` mode carries no display name or status text.
  - 404s are audited.
- **FR-CAPI-010**: thread reads (root + a page of replies), the reply count and last reply time per message, and reaction
  summaries (`emoji`, `count`, `mine`) per message. Writing them comes in 2.5.0 (owner, 2026-11-25).
- **FR-CAPI-012** (2.4.1, Beacon Desktop): `POST logout` revokes the presented token only, through the optional host
  port `ChatClientTokenRevokerInterface`. Always 204 without body for any token (valid, revoked, expired, unknown: no
  oracle); 401 only without a Bearer header. Per-IP and per-token budgets apply; no scope, context or reachability
  check. A revoker failure propagates. `ChatClientLoggedOutEvent` (ids only) after the revocation. Without a revoker:
  204, client-side only, `me.features.serverLogout: false`.
- **FR-CAPI-013** (2.4.1, audit ZBWv): `unreadTotalCapped` only when more than 200 conversations exist (I2); the dock
  coalesces ids-only `status` list reloads (I3); docs name the person's own status on the hub (I1).
- **FR-CAPI-009**: the profile option `realtime_events: ids` sends ids-only message events. The dock fetches the message.

## Success criteria

- **SC-001**: 100 % line coverage of the new units.
- **SC-002**: the worker test runs 500 rounds on one kernel. Two users in two contexts and a token revoked mid-run are
  served correctly, and memory stays flat.
- **SC-003**: PHPStan, PER-CS, Igor and CI are green.
