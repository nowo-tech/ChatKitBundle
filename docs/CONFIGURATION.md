# Configuration

## Table of contents

- [Root key](#root-key)
- [Profiles](#profiles)
- [Reference](#reference)
- [Rate limits](#rate-limits)
- [Container parameters](#container-parameters)

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

`default_profile` **must** be a key of `profiles` (REQ-CFG-002).

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
| `profiles.<name>.cache_pool` | string | `cache.app` | PSR-6 pool for presence and recipient lists. Share it between workers / replicas. |
| `profiles.<name>.reactions` | list | `👍 ❤️ 😂 😮 😢 🎉` | Emojis offered as reactions (anything else is refused). |
| `profiles.<name>.icon_set` | `ux_icon` \| `svg_inline` | `ux_icon` | Dock icons from Symfony UX Icons, or the built-in SVG only. |
| `profiles.<name>.icons` | map | Tabler set | UX Icons name per dock icon (`chat`, `compose`, `chevronUp`, `chevronDown`, `close`, `send`, `back`, `arrowDown`, `search`, `hash`, `lifebuoy`, `retry`, `buzz`, `smile`, `check`, `edit`, `trash`). |
| `profiles.<name>.presence_ttl` | int ≥ 30 | `90` | Seconds a heartbeat keeps someone online. |
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
key above (`user_class`, `retention_days`, `realtime`, `push`, `push_url`, `push_icon`, `cache_pool`, `reactions`,
`icon_set`, `icons`, `presence_ttl`, `limits`).
