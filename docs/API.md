# Client API v1

Since **2.4.0**. A versioned, stateless JSON API for native clients (Beacon Desktop, phase BD-2). It is read-only, except
for the reader's own read marks and, since **2.4.1**, logout (the host revokes the client's own token). The machine-readable reference is [`openapi/chat-client-v1.yaml`](openapi/chat-client-v1.yaml);
a test keeps it in step with the routes.

This API is not the dock's JSON API. The dock's API (`<chat prefix>/api/...`) is internal UI JSON: it uses the session and
CSRF, it is unversioned, and native clients must not use it.

## Table of contents

- [Turning it on](#turning-it-on)
- [Authentication](#authentication)
- [Tenant (context)](#tenant-context)
- [Routes](#routes)
- [Message bodies](#message-bodies)
- [Logout](#logout)
- [Realtime](#realtime)
- [Limits](#limits)
- [Errors](#errors)
- [Worker mode and concurrency](#worker-mode-and-concurrency)
- [Implementing the ports (Beacon)](#implementing-the-ports-beacon)

## Turning it on

The API is **off** until the host names a principal resolver (deny by default). While it is off, the route import adds
no route.

```yaml
# config/packages/nowo_chat_kit.yaml
nowo_chat_kit:
    profiles:
        default:
            client_api:
                principal_resolver: App\Chat\ClientTokens          # ChatClientPrincipalResolverInterface
                context_policy: App\Chat\ClientContexts             # optional, ChatClientContextPolicyInterface
                token_revoker: App\Chat\ClientTokenRevoker          # optional (2.4.1), ChatClientTokenRevokerInterface
                prefix: /api                                        # the API answers at /api/chat/v1
                context_header: X-Beacon-Context
```

```yaml
# config/routes/nowo_chat_kit_client_api.yaml
nowo_chat_kit_client_api:
    resource: .
    type: nowo_chat_kit_client_api
```

Give the path a **stateless** firewall of its own. The API never reads the session, but a session firewall in front of
it would still start one. Use the container parameter so the pattern follows `client_api.prefix`:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        chat_client_api:
            pattern: ^%nowo_chat_kit.client_api.v1_path_prefix%/
            stateless: true
            security: false   # ChatKit authenticates the Bearer token itself, through your resolver
```

Do not enable credentialed CORS on this path. Native clients do not need CORS.

## Authentication

- Clients send `Authorization: Bearer <token>`, and nothing else. Cookies, sessions and CSRF tokens are ignored, so a
  signed-in browser session gets `401`.
- ChatKit issues and stores no token. The host's `ChatClientPrincipalResolverInterface::resolve()` is asked on
  **every** request. It returns a `ChatClientPrincipal` (the chat user, the token's scopes, a stable non-secret token id
  and, optionally, the context the token is bound to), or `null` for any token that is not a valid chat token now.
  `null` covers unknown, expired and revoked tokens, and tokens of another client family.
- Scope `chat:read` covers every v1 route, read marks included (Q-BD-8). `chat:write` is reserved for later versions.
- A person the access policy no longer considers reachable (`ChatAccessPolicyInterface::isReachable()`) gets `401`.

## Tenant (context)

The client names its tenant **explicitly on every request**, in the context header (`client_api.context_header`,
default `X-Chat-Context`; Beacon uses `X-Beacon-Context`). A key is 1–128 characters of `[A-Za-z0-9._:-]`, for example
`personal` or `org:<uuid>`.

- With a `context_policy`, the header is required, unless the token is bound to a context. `allows()` re-checks the
  person's membership on every request. `includes()` decides which conversations belong to the context: the others are
  left out of lists, and asking for one directly answers `404`.
- With a token bound to a context (`ChatClientPrincipal::$contextKey`), the header may be left out. If it is sent, it
  must name the same context.
- Membership is re-checked on every request for bound tokens too: `allows()` must still answer `true` for the bound
  context (audit ZBW-L3).
- **Only the policy filters conversations by context** (`includes()`). A token bound to a context while no
  `context_policy` is configured is therefore refused (`403 context_forbidden`, event reason `context_unverifiable`).
  ChatKit fails closed rather than serve every context to a token approved for one (audit ZBW-L1).
- Without a policy and without a bound context, a request that names a context is refused (`403 context_forbidden`).
  Nothing could check it. Without a policy, unbound tokens and no header, the API serves the person's whole chat:
  this is the host-without-tenants case.
- A `context_policy` requires `realtime_events: ids`, otherwise the container build fails (see [Realtime](#realtime)).
- The API never writes the person's web context (a "current organization" stored on the server).
- The checked context is a request attribute (`ChatClientCall::ATTRIBUTE`, `_nowo_chat_client`). Host ports that need
  it can read it from the current request. It is never stored in a service.

## Routes

All paths are under `<prefix>/chat/v1`. All responses are sent with `Cache-Control: no-store, private`.

| Method and path | Answer |
|---|---|
| `GET me` | `{apiVersion, user, scopes, context, contexts, features, limits}` |
| `GET conversations?archived=&cursor=&limit=` | `{conversations, nextCursor, unreadTotal, unreadTotalCapped}` |
| `GET conversations/{uuid}` | `{conversation}`, with `membersCount` and `permissions` |
| `GET conversations/{uuid}/messages?before=&after=&limit=` | `{messages, hasMore, readUpTo}` (oldest first) |
| `GET conversations/{uuid}/messages/{messageUuid}` | `{message}` |
| `GET conversations/{uuid}/messages/{messageUuid}/thread?before=&after=` | `{root, replies, hasMore, replyCount, readUpTo, following}`. Needs `message_menu.threads.enabled` (404 otherwise); a reply's uuid opens its root's thread |
| `POST conversations/{uuid}/read` `{messageId}` | `{ok, readUpTo}` |
| `GET realtime` | `{enabled, hubUrl, token, topic, expiresAt, events}` |
| `POST logout` (2.4.1) | `204`, no body. See [Logout](#logout) |

How the routes behave:

- **Conversation list.** It is sorted by newest activity first. `limit` is 1–100, 50 by default. `nextCursor` is opaque:
  keep following it until it is `null`. A page may hold fewer rows than `limit`, or none at all, when the rows it scanned
  were not visible. `unreadTotal` comes on the first page only and leaves out muted conversations. It is counted over the
  200 most recent conversations. `unreadTotalCapped: true` means more than 200 exist, so show it as "200+" (exactly 200
  answer `false`, 2.4.1). Later pages answer
  `null` for both.
- **Message pages.** Without a cursor you get the newest page. `before=<id>` returns older messages and `after=<id>` is a
  catch-up page. Never send both. `limit` is 1–100, 30 by default.
- **Read marks.** The read cursor is capped at the conversation's newest message and never moves back. Two devices never
  lose an update, because the cursor row is locked. Read marks count against the person's action budget.
- **Unknown, foreign or out-of-context uuids** all answer `404`, never `403`.

## Message bodies

Message bodies are never HTML (Q-BD-16). Each body is `{format: "tokens/1", blocks: [...]}`, built from the stored
Markdown by the dock renderer's own rules. Render every string as a text node.

- Blocks:
  - `paragraph` and `quote` carry `tokens`;
  - `list` carries `ordered` and `items` (one token list per item);
  - `code` carries `text`.
- Tokens:
  - `text` (`text`, and `marks` with `bold`, `italic` or `strike`);
  - `break`;
  - `code` (`text`);
  - `mention` (`userUuid`, `name`);
  - `link` (`url`, `text`, `card`, plus `provider` on link cards). The URL is always `http(s)` or `mailto`;
  - `reference`.
- **References are filtered per reader (R2).**
  - A card the reader may open is `visible: true`, with `refType`, `id`, `typeLabel`, `icon`, `title`, `subtitle`,
    `badge`, `tone` and `url`. `url` is `null` when it is not a safe link.
  - Anything else is `{type: "reference", visible: false, text: "Content not visible"}`, with no type, id or title.
  - References inside code are never resolved, and their titles are never shown.
- Deleted messages have `body: null`. Bot and system lines are one translated paragraph.
- `mentionAll` is `channel` or `here` when the message carries a group mention, and is absent otherwise.
- Each message has `reactions`, a list of `{emoji, count, mine}` (`mine`: the reader reacted). A thread root has
  `thread: {count, lastReplyAt}` and a reply has `threadRootUuid`. Timeline pages hold no thread replies: read them
  through `…/thread`.
- `previews` lists the reader's link previews: at most 3 per message and 30 per response. The host's
  `ChatLinkPreviewProviderInterface` is asked 10 URLs at a time, and anything it answers that was not asked is dropped.
- `lastMessage.preview` in the conversation list is plain text for the reader. A reference the reader may not open reads
  "Content not visible".

## Logout

Since 2.4.1, `POST <prefix>/chat/v1/logout` signs the client out. Only `POST` is routed; any other method answers `405`.

- **It revokes the presented token only**, through the host's `ChatClientTokenRevokerInterface::revoke($bearer,
  $request)` (`client_api.token_revoker`). Other tokens, devices and web sessions of the person are never touched. The
  resolver must accept the token first; a token the resolver refuses (another client family included) never reaches
  the revoker.
- **Always `204` with no body**, also when the token was already revoked, has expired or is unknown. These cases are
  never told apart (no oracle), so a client may retry a logout freely, for example after a timeout. The only other
  answers are `401 invalid_token` when there is no usable Bearer token (header absent, blank, or a token longer than
  4096 bytes: the shape of the header, never the token; audit ZCB-I3), and `429 rate_limited`.
- **The per-IP budget and a per-token logout budget apply.** Unknown tokens spend the per-IP budget only. A still-valid
  token can get `429` when its own logout budget is spent, which an unknown token never gets; this tells nothing that
  `GET me` would not (audit ZCB-I1). The per-token logout budget (`per_token_per_minute`) has a key of its own,
  separate from the token's budget on the other routes, so read traffic with the same token (a copied token, a client
  in a reload loop) can never keep its sign-out at `429` (audit ZCB-L1). On `429` the token is not revoked yet: retry
  after `Retry-After`. The person's own read and action budgets are not spent.
- **A token the resolver refuses is not revoked.** The answer is the same `204`. Resolvers should refuse only for
  reasons of the token itself (unknown, expired, revoked, another family), or the host revokes a person's client tokens
  when it disables the account; a token refused for an account, IP or context reason would otherwise survive a logout
  (audit ZCB-I2).
- **No scope, context or account check.** A token without `chat:read`, a bound token whose membership is gone or the
  token of an account that can no longer use the chat may always be given up. The context header is ignored.
- **A failed revocation is never a success.** If the revoker throws (database down, lock conflict) the error
  propagates (`5xx`, or `409` when the host maps it), no event is dispatched, and the client retries.
- After the revoker returned, `Nowo\ChatKitBundle\Event\ChatClientLoggedOutEvent` is dispatched for the host's audit log,
  with the `tokenId`, the `userPublicId`, `revoked` and the `/24` or `/64` `ipPrefix`. It never carries the token. A
  logout with a token the resolver does not accept dispatches nothing. Two concurrent logouts of one token can both
  revoke (idempotently) and dispatch the event twice: deduplicate on `tokenId` if needed (audit ZCB-I4).
- **Without a token revoker** the route still answers `204`, but nothing is revoked: the client only forgets its token,
  which stays valid until it expires or is revoked in the host's UI. `me.features.serverLogout` is `false` then (and the
  event has `revoked: false`), so a client can tell the person to revoke the device in the web UI. Hosts that issue
  long-lived tokens should configure a revoker. This fail-open default is deliberate (audit ZCB-I5: the build does not
  fail without a revoker).

## Realtime

`GET realtime` returns the hub URL and a 15-minute subscriber token for the caller's **own** private topic only
(`/nowo-chat/users/<publicId>`).

- The token comes from the host's `ChatRealtimeTransportInterface`. In Beacon that is `BeaconChatRealtime` over its
  Mercure hub. ChatKit signs nothing itself, unless its own `MercureChatRealtime` is the transport, as it is for the web
  UI today.
- `enabled: false` means the client polls.
- Treat events as hints and re-fetch through the API.
- **The stream is per person, not per context.** The topic carries events of every conversation the person is in,
  whatever context the client shows. Clients ignore events of conversations they did not get from the API in the
  current context, and re-fetch through the API, which applies the context.
- The profile option `realtime_events: ids` (Q-BD-13) keeps message content off the hub. **Hosts that use contexts must
  set it**: a `context_policy` with `full` fails the container build (audit ZBW-L2). The web dock works with both
  settings. In `ids` mode the hub carries:
  - `message` / `message_updated`: `{conversationUuid, messageUuid, messageId, deleted}`, plus `threadRootUuid` for a
    thread reply. A reaction change is a `message_updated` hint.
  - `thread`: `{conversationUuid, rootUuid, replyCount, lastReplyAt}`.
  - `read`: `{conversationUuid, userUuid, messageId}`; `typing`: `{conversationUuid, userUuid}` (no display name).
  - `status`: `{userUuid, status}` (no status text or emoji).
  - The person's own `my_status` and `prefs`, which go to their own topic only.

  No message body, reference, author, display name or status text of **another** person reaches the hub (audit
  ZBW-L4). The person's own status (`my_status`: status, text, emoji, expiry) and preferences (`prefs`) still reach the
  hub, on their own topic only, so the hub operator can see them (audit ZBWv-I1). The default `full` keeps the 2.3
  payloads.

## Limits

These budgets come on top of the person's own budgets, which are shared with the web UI:

| Budget | Default | Key |
|---|---|---|
| per IP (IPv6 per /64), checked before the token | 300/min | `client_api.limits.per_ip_per_minute` |
| per token (by the principal's token id) | 60/min | `client_api.limits.per_token_per_minute` |
| the person's reads | 600/min | `limits.reads_per_minute` |
| the person's actions (read marks) | 240/min | `limits.actions_per_minute` |

When a budget is spent, the API answers `429 rate_limited` with `Retry-After`. Limiters live in the framework's
rate-limiter cache pool. Share it across workers (for example in Redis).

- **60 per token per minute is the budget the audit accepted.**
  - Clients poll the list (every 30 s while open, 120 s in the background).
  - They fetch messages only for the open conversation, with `after=`.
  - They batch catch-ups after a realtime hint or a wake-up instead of one call per event.
  - They honour `Retry-After`.
  - A host may raise the budget (Beacon: up to 120) with `client_api.limits.per_token_per_minute`.
- **The per-IP budget reads `Request::getClientIp()`.** Behind a reverse proxy, configure `framework.trusted_proxies`
  and `trusted_headers` for the API path. Without them every client shares the proxy's bucket. With an over-broad
  trusted range a client could rotate `X-Forwarded-For` (the per-token budget still applies). Audit ZBW-I5.

## Errors

Errors have the shape `{ok: false, error: <code>}`.

| Status | Codes |
|---|---|
| 400 | `invalid_context`, `context_required`, `invalid_cursor`, `invalid_limit`, `invalid_json` |
| 401 | `invalid_token` (with `WWW-Authenticate`) |
| 403 | `insufficient_scope` (with `WWW-Authenticate`), `context_forbidden` |
| 404 | `not_found` |
| 409 | `unavailable` / `conflict` with `code` `CONFLICT_001` / `CONFLICT_002` (lock conflicts, see [CONCURRENCY.md](CONCURRENCY.md)) |
| 422 | `invalid_message_id` |
| 429 | `rate_limited` (with `Retry-After`) |

Every refusal from the second step onwards, 404s included (reason `not_found`, audit ZBW-I2), dispatches `Nowo\ChatKitBundle\Event\ChatClientApiDeniedEvent` for the host's
audit log. The event carries the `reason` (the code; `account_inactive` for an unreachable person), the `tokenId`, the
`userPublicId` and the client's `/24` or `/64` `ipPrefix`. The reason `context_unverifiable` marks a bound token
refused for lack of a context policy. It never carries the token, the raw header or a full IP.

## Worker mode and concurrency

The API keeps no request, user, token or tenant data in services (FrankenPHP worker mode, kernel reset false):

- the principal is resolved per request, and logout asks the host's revoker per request;
- the call lives in the request attributes;
- reference cards are memoised for the request only.

`ChatClientApiWorkerTest` serves two people in two contexts for 500 rounds on one kernel. A reference grant flips every
round, a token is signed out with `POST logout` half-way, and the test checks that memory stays flat. Read marks use the same locked,
monotonic write as the web UI.

## Implementing the ports (Beacon)

```php
final readonly class ClientTokens implements ChatClientPrincipalResolverInterface
{
    public function resolve(string $bearer, ChatClientRequest $request): ?ChatClientPrincipal
    {
        // TimeTrack's client-token lookup (hash, constant time), family `chat` only, credential state, user checker.
        $token = $this->tokens->findActive($bearer, family: 'chat');
        if ($token === null) {
            return null;   // also for a `time` token
        }

        // Bind `org:<uuid>` here only if the approval chose a tenant: it then needs the context policy below.
        return new ChatClientPrincipal($token->chatUser(), $token->scopes(), $token->publicId());
    }
}

final readonly class ClientContexts implements ChatClientContextPolicyInterface
{
    public function contextsFor(ChatClientPrincipal $p): array { /* personal + each organization the user belongs to */ }
    public function allows(ChatClientPrincipal $p, string $key): bool { /* membership now */ }
    public function includes(ChatClientPrincipal $p, string $key, ChatClientConversationRef $c): bool { /* project channel of that organization; direct + support everywhere */ }
}
```

```php
// 2.4.1: POST logout. Revoke this token only, idempotently, in its own transaction; let failures propagate.
final readonly class ClientTokenRevoker implements ChatClientTokenRevokerInterface
{
    public function revoke(string $bearer, ChatClientRequest $request): void
    {
        $this->authenticator->revoke('chat', $bearer);   // TimeTrack ClientFamilyTokenAuthenticator; no-op when unknown
    }
}
```

Configure `realtime_events: ids` together with the context policy.

Beacon checklist for logout (audit ZCB-I5): set `client_api.token_revoker` to the revoker above; assert
`me.features.serverLogout === true` in the integration test (and that a `POST logout` makes the next request 401);
Beacon Desktop shows the "revoke this device in the web" hint when it is `false`. Confirm that the chat resolver
refuses only for reasons of the token, or that disabling an account revokes its client tokens (audit ZCB-I2).

Listen to `ChatClientApiDeniedEvent` to audit refusals, the same way the TimeTrack client API refusals are audited
(`client_api: chat`), and to `ChatClientLoggedOutEvent` to audit sign-outs.
