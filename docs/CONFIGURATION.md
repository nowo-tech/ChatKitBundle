# Configuration

## Table of contents

- [Root key](#root-key)
- [Profiles](#profiles)
  - [Two profiles](#two-profiles)
  - [How the profile is selected](#how-the-profile-is-selected)
- [Reference](#reference)
- [Rate limits](#rate-limits)
- [Container parameters](#container-parameters)
- [Timeouts](#timeouts)

## Root key

All options live under `nowo_chat_kit` (`Nowo\ChatKitBundle\DependencyInjection\Configuration::ALIAS`).

## Profiles

The bundle follows the Nowo canonical multi-config shape (REQ-CFG-001): `default_profile` names the profile in use and
`profiles` holds complete, named settings blocks. Omitting `profiles` gives one profile with every default.

```yaml
nowo_chat_kit:
    default_profile: default
    profiles:
        default:
            user_class: App\Entity\User
            retention_days: '%env(int:CHAT_RETENTION_DAYS)%'
            push: true
            push_url: '/dashboard?chat={uuid}'
            push_icon: '/icons/icon-192.png'
            cache_pool: cache.chat          # Redis in production (presence across workers)
            icon_set: ux_icon
            icons:
                buzz: 'lucide:zap'          # merged over the Tabler defaults
            reactions: ['👍', '❤️', '😂', '🎉']
```

`default_profile` **must** be a key of `profiles` (REQ-CFG-002): an unknown name fails container compilation
(`nowo_chat_kit.default_profile must reference a key defined under nowo_chat_kit.profiles.`). The profile in use also
needs a `user_class` when DoctrineBundle is installed.

### Two profiles

```yaml
nowo_chat_kit:
    default_profile: production
    profiles:
        production:
            user_class: App\Entity\User
            realtime: true
            push: true
            cache_pool: cache.chat          # Redis: presence shared by every worker
            limits:
                send_per_minute: 20
        staging:
            user_class: App\Entity\User
            realtime: false                 # polling only (no hub on staging)
            push: false
            cache_pool: cache.app
```

### How the profile is selected

- **Only `default_profile` is active.** The extension reads that one profile at compile time: it drives the Doctrine
  `resolve_target_entities` mapping (`user_class`), the rate limiters, the `nowo_chat_kit.<key>` container parameters,
  the services and the dock.
- **Other profiles are validated but not used.** Every entry under `profiles` goes through the same configuration tree
  (types, minimums, enums), so a typo fails the build, but nothing reads it at runtime.
- **No per-request, per-entity or per-form selection.** There is no request attribute, form option or Twig argument
  that switches profile; a chat has one user entity and one set of tables. Switch profiles per environment instead,
  e.g. `default_profile: '%env(CHAT_PROFILE)%'` or a `when@staging:` block that sets `default_profile: staging`.
- There are no legacy synonym keys (`default_config` / `configs`); a configuration without `profiles` gets one profile
  named after `default_profile` (default `default`) with every default.

## Reference

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | `default` | Profile in use. |
| `profiles.<name>.enabled` | bool | `true` | Render the dock. |
| `profiles.<name>.user_class` | string | — (required with Doctrine) | Your user entity (implements `ChatUserInterface`). |
| `profiles.<name>.retention_days` | int ≥ 30 | `365` | Messages older than this are purged by `nowo:chat:purge`. `%env()%` allowed. |
| `profiles.<name>.realtime` | bool | `true` | Use the realtime transport when one is available. |
| `profiles.<name>.push` | bool | `false` | Queue Web Push for new messages (needs a `ChatPushSenderInterface`). |
| `profiles.<name>.push_url` | string | `/?chat={uuid}` | URL a notification opens; `{uuid}` is the conversation. The dock opens `?chat=<uuid>` itself. |
| `profiles.<name>.push_icon` | ?string | `null` | Notification icon URL. |
| `profiles.<name>.digest.enabled` | bool | `false` | Email the conversations still unread (needs Messenger and a `ChatDigestSenderInterface`). |
| `profiles.<name>.digest.delay_minutes` | int ≥ 1 | `15` | How long a message stays unread before it counts. |
| `profiles.<name>.digest.interval_minutes` | int ≥ 1 | `60` | At most one email per user in this window. |
| `profiles.<name>.push_preview` | bool | `false` | Put the first 140 characters of the message in the Web Push body (lock screens). Off: a generic "New message". |
| `profiles.<name>.doctrine.table_prefix` | string | `''` | Prepended to every bundle table, index and unique constraint (lowercase letters, digits, `_`). `app_` gives `app_nowo_chat_conversation`, `app_nowo_chat_message`, … Generate a migration after changing it. |
| `profiles.<name>.web_ui.layout_template` | ?string | `null` | Layout the full-page chat extends (REQ-UI-003); `null` = `nowo_ui_kit.layouts.admin`. It must render the UiKit canonical blocks. |
| `profiles.<name>.web_ui.templates.page` | string | `@NowoChatKitBundle/page.html.twig` | Template of the full-page chat (a host template extending `@!NowoChatKitBundle/page.html.twig` and overriding one block). |
| `profiles.<name>.page_layout` | ?string | `null` | **Deprecated** (use `web_ui.layout_template`): a 1.x layout with the `title`, `stylesheets`, `javascripts` and `body` blocks; while it is the only layout set, the page keeps its 1.x template. |
| `profiles.<name>.cache_pool` | string | `cache.app` | PSR-6 pool for presence and recipient lists. Share it between workers / replicas. |
| `profiles.<name>.reactions` | list | `👍 ❤️ 😂 😮 😢 🎉` | Emojis offered as reactions (anything else is refused). |
| `profiles.<name>.icon_set` | `ux_icon` \| `svg_inline` | `ux_icon` | Dock icons from Symfony UX Icons, or the built-in SVG only. |
| `profiles.<name>.icons` | map | Tabler set | UX Icons name per dock icon (`chat`, `compose`, `chevronUp`, `chevronDown`, `tuck`, `close`, `send`, `back`, `arrowDown`, `search`, `hash`, `lifebuoy`, `retry`, `buzz`, `smile`, `check`, `edit`, `trash`, `link`, `external`). |
| `profiles.<name>.presence_ttl` | int ≥ 30 | `90` | Seconds a heartbeat keeps someone online. |
| `profiles.<name>.support.ticket_prefix` | string | `SUP-` | Prefix of the readable ticket number (`SUP-000123`). |
| `profiles.<name>.support.ticket_digits` | int 1–12 | `6` | Digits of the ticket number (zero-padded). |
| `profiles.<name>.support.resume_after_days` | int ≥ 1 | `7` | Opening support when the last ticket has had no message for this many days (or is resolved) asks "continue it or open a new one?". |
| `profiles.<name>.support.close_after_days` | int ≥ 0 | `30` | A resolved ticket turns read-only after this many days (`0`: never; a **closed** ticket is read-only at once). |
| `profiles.<name>.support.translation_domain` | string | `NowoChatKitBundle` | Domain of the topic labels and replies. |
| `profiles.<name>.support.topics` | list | incident, billing, how_to, account, other | Topics offered when a ticket opens: `key` (a-z, 0-9, `_`, `-`), `label` / `reply` (translation keys; default `support.topic.<key>` / `support.topic_reply.<key>`; `reply: ''` posts none), `icon` (for your templates), `assignee` (public id of the agent new tickets of the topic go to). |
| `profiles.<name>.limits.*` | int | see below | Rate limits. |

## Rate limits

Prepended as `framework.rate_limiter` limiters (sliding window), keyed by the user's public id:

| Key | Limiter | Default |
| --- | --- | --- |
| `limits.send_per_minute` | `nowo_chat_send` | 30 |
| `limits.actions_per_minute` | `nowo_chat_action` (opens, reads, edits, reactions, presence) | 240 |
| `limits.typing_per_minute` | `nowo_chat_typing` (over the limit: dropped silently) | 30 |
| `limits.reads_per_minute` | `nowo_chat_read` (list, messages, contacts, realtime token) | 600 |
| `limits.buzz_interval_seconds` | `nowo_chat_buzz` (per conversation) | 15 |
| `limits.buzz_per_minute` | `nowo_chat_buzz_global` | 10 |

## Container parameters

`nowo_chat_kit.default_profile`, `nowo_chat_kit.profiles` and, for the default profile, `nowo_chat_kit.<key>` for each
key above (`user_class`, `retention_days`, `realtime`, `push`, `push_url`, `push_icon`, `push_preview`, `cache_pool`, `page_layout`, `web_ui`, `web_ui.layout_template`, `web_ui.templates.page`, `doctrine.table_prefix`,
`reactions`, `icon_set`, `icons`, `presence_ttl`, `limits`, `digest`, `support`, and `support.<key>` for each support key).

## Timeouts

The bundle itself opens no subprocess and no outbound HTTP connection, and has no timeout option of its own. The only
blocking call in a web request is the **Mercure publish**, made through the application's `HubInterface` and its Symfony
HttpClient; Web Push and email run in Messenger workers through the host's senders. Configure the deadlines so the
innermost one fires first (REQ-RUNTIME-001):

| Layer (innermost first) | Setting | Demo value |
| --- | --- | --- |
| Mercure hub HTTP client | `framework.http_client.default_options.timeout` (or a scoped client for the hub) | 5 s |
| PHP | `max_execution_time` | 30 s |
| FrankenPHP / Caddy | `servers { timeouts { write … } }`; `frankenphp { max_wait_time … }` | write 60 s; max_wait_time 30 s |

```yaml
# config/packages/framework.yaml
framework:
    http_client:
        default_options:
            timeout: 5     # below max_execution_time
```

Rule: **HTTP client < PHP `max_execution_time` < FrankenPHP / Caddy write timeout** (and `max_wait_time` when workers
are used). Raise them together. A failed or timed-out publish is logged and never fails the user's request: clients
catch up by polling. Set HTTP / SMTP timeouts in your `ChatPushSenderInterface` / `ChatDigestSenderInterface`
implementations (Messenger workers). See also [USAGE — Timeouts](USAGE.md#timeouts) and
[DEMO-FRANKENPHP — Timeouts](DEMO-FRANKENPHP.md#timeouts).
