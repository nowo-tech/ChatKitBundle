# Client API v1

Since **2.4.0**. A versioned, stateless JSON API for native clients (Beacon Desktop, phase BD-2). In 2.4 it is read-only,
except for the reader's own read marks and, since **2.4.1**, logout (the host revokes the client's own token). Since
**2.5.0** (Beacon Desktop BD-3) it also writes: send, thread replies, reactions, and edit and delete of one's own message
(scope `chat:write`, off until `client_api.writes`; see [Writes](#writes-25)). The machine-readable reference is [`openapi/chat-client-v1.yaml`](openapi/chat-client-v1.yaml);
a test keeps it in step with the routes.

This API is not the dock's JSON API. The dock's API (`<chat prefix>/api/...`) is internal UI JSON: it uses the session and
CSRF, it is unversioned, and native clients must not use it.

## Table of contents

- [Turning it on](#turning-it-on)
- [Authentication](#authentication)
- [Tenant (context)](#tenant-context)
- [Routes](#routes)
- [Message bodies](#message-bodies)
- [Writes (2.5)](#writes-25)
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
                writes: true                                        # optional (2.5): the write routes, for chat:write tokens
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
- Scope `chat:read` covers every read route, read marks included (Q-BD-8).
- Scope `chat:write` (2.5) opens the [write routes](#writes-25), together with `chat:read`, and only while the host turned
  them on (`client_api.writes: true`). ChatKit grants no scope: a token holds `chat:write` only when the host's resolver
  says so, so tokens approved for reading stay read-only.
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
| `POST conversations/{uuid}/messages` `{text}` (2.5) | `201 {message, replayed}` (`200` for a replay). See [Writes](#writes-25) |
| `POST conversations/{uuid}/messages/{messageUuid}/thread` `{text}` (2.5) | `201 {message, root, replayed}` (`200` for a replay) |
| `PUT` / `DELETE conversations/{uuid}/messages/{messageUuid}/reactions/{emoji}` (2.5) | `200 {reactions, replayed}` |
| `PATCH conversations/{uuid}/messages/{messageUuid}` `{text}` (2.5) | `200 {message, replayed}` |
| `DELETE conversations/{uuid}/messages/{messageUuid}` (2.5) | `200 {message, replayed}` (the tombstone) |

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

## Writes (2.5)

Since 2.5.0 (Beacon Desktop BD-3) a client can write. **Off by default**: the routes answer `404` until the host sets
`client_api.writes: true`, and then only for tokens holding both `chat:read` and `chat:write`. `me.features` tells a
client what this server offers (`send`, `threadReply`, `reactions`, `editOwn`, `deleteOwn`; `threadReply` also needs
`message_menu.threads.enabled`), so Desktop feature-detects instead of guessing.

| Write | Route | Rule (same as the web UI) |
|---|---|---|
| Send | `POST conversations/{uuid}/messages` `{text}` | `ChatVoter::SEND`: read-only channels (`ChatChannel::$readOnly`, `ChatChannelWritePolicyInterface`), blocked direct messages (`canWriteDirect()`), closed tickets → `403 read_only` |
| Thread reply | `POST conversations/{uuid}/messages/{messageUuid}/thread` `{text}` | SEND, then `ChatVoter::THREAD` (a shown text message of the main timeline) → `422 not_threadable`. A reply's uuid replies in its root's thread. `404` while threads are off |
| Reaction | `PUT` / `DELETE …/messages/{messageUuid}/reactions/{emoji}` | SEND, then `ChatVoter::REACT` (no line, buzz or tombstone) → `422 not_reactable`. The emoji is URL-encoded and must be in `me.reactions.accepted` → `422 invalid_reaction` |
| Edit own | `PATCH …/messages/{messageUuid}` `{text}` | SEND, then `ChatVoter::EDIT`: own message, within `limits.editWindowSeconds` (15 min), not a buzz or tombstone → `403 edit_forbidden` |
| Delete own | `DELETE …/messages/{messageUuid}` | `ChatVoter::DELETE`: own message within the window → `403 delete_forbidden`. One's own message that is already deleted answers its tombstone again |

- **The web send, not a copy of it.** Every write goes through `ChatService` (the dock's own path): Markdown normalised,
  `@[Name](uuid)` mentions resolved against the conversation's members (with the `@channel` / `@here` policy and budget),
  `#[Title](type:id)` references validated for the author, link cards checked, support status moved, the author's read
  cursor and everyone's unread counters, closed lists reopened, realtime (`ids` events), Web Push, the digest, the search
  index, the links panel, and the events (`ChatMessageSentEvent`, `ChatMentionEvent`, `ChatThreadReplyEvent`,
  `ChatMessageEditedEvent`, `ChatMessageDeletedEvent`) — exactly as when the person writes in the dock.
- **Lookups first.** The conversation must be visible to the token's person **in the request's context** (the context
  policy's `includes()`), and the message must belong to it; anything else is `404` (audited), never `403`.
- **Answers** are the same `Message` rows as `GET …/messages/{messageUuid}` (tokens, never HTML). Reactions answer the
  message's `{emoji, count, mine}` after the change, never who reacted.
- **Audit.** After each write (and each replay) `Nowo\ChatKitBundle\Event\ChatClientApiWriteEvent` is dispatched with the
  `action` (`send`, `thread_reply`, `reaction_add`, `reaction_remove`, `edit`, `delete`), `tokenId`, `userPublicId`,
  `conversationUuid`, `messageUuid`, `replayed`, `contextKey` and the `/24`–`/64` `ipPrefix`: never the token, the text or
  the emoji.

### Request body

A write body is a JSON object (at most 32 KB) with **one field, `text`** (a string; Markdown as in the dock; at most
`limits.textMaxLength` = 4000 characters after trimming). Any other field answers `422 unsupported_field` and nothing is
written: a field is never silently dropped.

**Forward compatibility with 2.6 (spec 259).** 2.6 adds typed card references (secret cards, later attachments) as a new
optional field next to `text` (for example `cards: [{type, id}]`), advertised by `me.features.cards: true`. Because 2.5
refuses unknown fields, a 2.6 client talking to a 2.5 server gets a clear `422 unsupported_field` instead of a message
sent without its card. Clients send the new field only when `me.features.cards` is `true`; `text` keeps its name and
meaning, and a body with `text` only stays valid in every later v1 version.

### Idempotency-Key

Every write needs an `Idempotency-Key` header: a client-generated id of **this write** (a UUID v4/v7 fits; 1–80
characters of `[A-Za-z0-9_.:-]`). Missing: `400 idempotency_key_required`; malformed: `400 idempotency_key_invalid`.

- **Deduplicated per token and conversation** (the stored key is a hash of the token id, the conversation uuid and the
  key), for 24 hours (`nowo:chat:purge` removes older keys). Another device of the same person, or another conversation,
  never collides.
- **The same key and the same request** (route, message, text or emoji) answer what the first one wrote, with
  `replayed: true` (send and thread reply: `200` instead of `201`) and **nothing written again**. Two attempts racing each
  other write once: the key is claimed in the write's own transaction; the second waits and replays.
- **The same key with another request** answers `422 idempotency_mismatch`, nothing written.
- **When to reuse a key.** Retry with the same key after a timeout, a lost connection, a `5xx`, `409` or `429`. Never
  reuse a key for a new write. A `4xx` other than `409`/`429` wrote nothing: fix the request and use a new key.
- **The `personal` retry.** A client that retries once in the `personal` context after `403 context_forbidden` (its
  organization refused) may keep the key: the refusal happens before anything is written. The conversation must then be
  in `personal` (direct messages and support are), otherwise `404`.
- Reactions and deletes are idempotent by themselves as well: setting a reaction one has, removing one one does not have,
  or deleting an already deleted own message changes nothing and publishes nothing.

### Write budgets

Writes spend the per-IP and per-token budgets, a **per-token write budget** of their own
(`client_api.limits.writes_per_token_per_minute`, default 30) and the person's budgets shared with the web UI: the action
budget for every write, plus the **send budget** (`limits.send_per_minute`, same key as the dock) for messages and thread
replies. A spent budget answers `429 rate_limited` with `Retry-After`; nothing was written, so retry with the same key.

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
| the person's actions (read marks; 2.5: every write) | 240/min | `limits.actions_per_minute` |
| per token, writes only (2.5) | 30/min | `client_api.limits.writes_per_token_per_minute` |
| the person's sends (2.5: messages and thread replies, shared with the dock) | 30/min | `limits.send_per_minute` |

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
| 400 | `invalid_context`, `context_required`, `invalid_cursor`, `invalid_limit`, `invalid_json`; 2.5 writes: `idempotency_key_required`, `idempotency_key_invalid` |
| 401 | `invalid_token` (with `WWW-Authenticate`) |
| 403 | `insufficient_scope` (with `WWW-Authenticate`; writes name `chat:read chat:write`), `context_forbidden`; 2.5 writes: `read_only`, `edit_forbidden`, `delete_forbidden` |
| 404 | `not_found` (2.5: also every write route while `client_api.writes` is off) |
| 405 | a method the route does not take (for example `POST …/reactions/{emoji}`) |
| 409 | `unavailable` / `conflict` with `code` `CONFLICT_001` / `CONFLICT_002` (lock conflicts, see [CONCURRENCY.md](CONCURRENCY.md)); a write answered `409` wrote nothing: retry with the same key |
| 422 | `invalid_message_id`; 2.5 writes: `unsupported_field`, `invalid_text`, `empty_text`, `text_too_long`, `invalid_link`, `invalid_reaction`, `not_threadable`, `not_reactable`, `idempotency_mismatch` |
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

2.5 writes keep nothing either: the key, the text and the call are locals of the request, and every write is
`ChatService`'s own unit of work (the conversation's row lock first, the `Idempotency-Key` claimed as its first
statement). `ChatClientApiWritesTest` writes 120 times on one kernel (two people, two tokens); `ConcurrentWritersTest`
races two worker processes on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (CI): duplicate sends under one key (one message
per key), concurrent reactions of one person (never two rows) and two senders (read cursors and unread counters never
set back). See [CONCURRENCY.md](CONCURRENCY.md).

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

**2.5 writes (BD-3).** Set `client_api.writes: true`, and let the chat family's device approval grant `chat:write`
(next to `chat:read`) only when the person approves writing; existing tokens keep their scopes and stay read-only until
re-approved. No schema change in ChatKit (the keys use the existing `nowo_chat_idempotency` table). Listen to
`ChatClientApiWriteEvent` for the audit log (`client_api: chat`, action + token id). Beacon Desktop checks
`me.features.send` (and `threadReply`, `reactions`, `editOwn`, `deleteOwn`) before showing the composer and actions, and
`me.features.cards` before sending 2.6 cards.

Beacon checklist for logout (audit ZCB-I5): set `client_api.token_revoker` to the revoker above; assert
`me.features.serverLogout === true` in the integration test (and that a `POST logout` makes the next request 401);
Beacon Desktop shows the "revoke this device in the web" hint when it is `false`. Confirm that the chat resolver
refuses only for reasons of the token, or that disabling an account revokes its client tokens (audit ZCB-I2).

Listen to `ChatClientApiDeniedEvent` to audit refusals, the same way the TimeTrack client API refusals are audited
(`client_api: chat`), and to `ChatClientLoggedOutEvent` to audit sign-outs.
