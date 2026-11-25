# Client API v1

Since **2.4.0**. A versioned, stateless JSON API for native clients (Beacon Desktop, phase BD-2). It is read-only, except
for the reader's own read marks. The machine-readable reference is [`openapi/chat-client-v1.yaml`](openapi/chat-client-v1.yaml);
a test keeps it in step with the routes.

This API is not the dock's JSON API. The dock's API (`<chat prefix>/api/...`) is internal UI JSON: it uses the session and
CSRF, it is unversioned, and native clients must not use it.

## Table of contents

- [Turning it on](#turning-it-on)
- [Authentication](#authentication)
- [Tenant (context)](#tenant-context)
- [Routes](#routes)
- [Message bodies](#message-bodies)
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
- Without a policy and without a bound context, a request that names a context is refused (`403 context_forbidden`).
  Nothing could check it.
- The API never writes the person's web context (a "current organization" stored on the server).
- The checked context is a request attribute (`ChatClientCall::ATTRIBUTE`, `_nowo_chat_client`). Host ports that need
  it can read it from the current request. It is never stored in a service.

## Routes

All paths are under `<prefix>/chat/v1`. All responses are sent with `Cache-Control: no-store, private`.

| Method and path | Answer |
|---|---|
| `GET me` | `{apiVersion, user, scopes, context, contexts, features, limits}` |
| `GET conversations?archived=&cursor=&limit=` | `{conversations, nextCursor, unreadTotal}` |
| `GET conversations/{uuid}` | `{conversation}`, with `membersCount` and `permissions` |
| `GET conversations/{uuid}/messages?before=&after=&limit=` | `{messages, hasMore, readUpTo}` (oldest first) |
| `GET conversations/{uuid}/messages/{messageUuid}` | `{message}` |
| `GET conversations/{uuid}/messages/{messageUuid}/thread?before=&after=` | `{root, replies, hasMore, replyCount, readUpTo, following}`. Needs `message_menu.threads.enabled` (404 otherwise); a reply's uuid opens its root's thread |
| `POST conversations/{uuid}/read` `{messageId}` | `{ok, readUpTo}` |
| `GET realtime` | `{enabled, hubUrl, token, topic, expiresAt, events}` |

How the routes behave:

- **Conversation list.** It is sorted by newest activity first. `limit` is 1–100, 50 by default. `nextCursor` is opaque:
  keep following it until it is `null`. A page may hold fewer rows than `limit`, or none at all, when the rows it scanned
  were not visible. `unreadTotal` comes on the first page only and leaves out muted conversations. It is counted over the
  200 most recent conversations; later pages answer `null`.
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
- Each message has `reactions`, a list of `{emoji, count, mine}` (`mine`: the reader reacted). A thread root has
  `thread: {count, lastReplyAt}` and a reply has `threadRootUuid`. Timeline pages hold no thread replies: read them
  through `…/thread`.
- `previews` lists the reader's link previews: at most 3 per message and 30 per response. The host's
  `ChatLinkPreviewProviderInterface` is asked 10 URLs at a time, and anything it answers that was not asked is dropped.
- `lastMessage.preview` in the conversation list is plain text for the reader. A reference the reader may not open reads
  "Content not visible".

## Realtime

`GET realtime` returns the hub URL and a 15-minute subscriber token for the caller's **own** private topic only
(`/nowo-chat/users/<publicId>`).

- The token comes from the host's `ChatRealtimeTransportInterface`. In Beacon that is `BeaconChatRealtime` over its
  Mercure hub. ChatKit signs nothing itself, unless its own `MercureChatRealtime` is the transport, as it is for the web
  UI today.
- `enabled: false` means the client polls.
- Treat events as hints and re-fetch through the API.
- The profile option `realtime_events: ids` (Q-BD-13) makes `message` and `message_updated` events carry only
  `{conversationUuid, messageUuid, messageId, deleted}`, plus `threadRootUuid` for a thread reply. A reaction change is a
  `message_updated` hint, and a thread change is also a `thread` event (`rootUuid`, `replyCount`, `lastReplyAt`). No body, reference or name ever reaches the hub. The dock
  fetches such messages through its own API. The default `full` keeps the 2.3 behaviour.

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

Every refusal from the second step onwards dispatches `Nowo\ChatKitBundle\Event\ChatClientApiDeniedEvent` for the host's
audit log. The event carries the `reason` (the code; `account_inactive` for an unreachable person), the `tokenId`, the
`userPublicId` and the client's `/24` or `/64` `ipPrefix`. It never carries the token, the raw header or a full IP.

## Worker mode and concurrency

The API keeps no request, user, token or tenant data in services (FrankenPHP worker mode, kernel reset false):

- the principal is resolved per request;
- the call lives in the request attributes;
- reference cards are memoised for the request only.

`ChatClientApiWorkerTest` serves two people in two contexts for 500 rounds on one kernel. A reference grant flips every
round, a token is revoked half-way, and the test checks that memory stays flat. Read marks use the same locked,
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

Listen to `ChatClientApiDeniedEvent` to audit refusals, the same way the TimeTrack client API refusals are audited
(`client_api: chat`).
