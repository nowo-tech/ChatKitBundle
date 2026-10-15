# Configuration

## Table of contents

- [Root key](#root-key)
- [Profiles](#profiles)
- [Reference](#reference)
- [Container parameters](#container-parameters)
- [Planned options](#planned-options)

## Root key

All options live under `nowo_chat_kit` (`Nowo\ChatKitBundle\DependencyInjection\Configuration::ALIAS`).

## Profiles

The bundle follows the Nowo canonical multi-config shape (REQ-CFG-001): `default_profile` names the profile used when nothing else is requested, and `profiles` holds complete, named settings blocks.

```yaml
nowo_chat_kit:
    default_profile: default
    profiles:
        default:            # end-user dock
            enabled: true
        support:            # support agents' inbox (planned)
            enabled: true
```

Rules:

- `profiles` must contain at least one entry.
- `default_profile` **must** be a key of `profiles`; otherwise container compilation fails with `InvalidConfigurationException` (REQ-CFG-002).
- Profile names are public API strings; keep them stable once used in templates or code.

## Reference

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `default_profile` | string | `default` | Profile used when no explicit profile is requested. |
| `profiles` | map | `{ default: { enabled: true } }` | Named chat profiles. |
| `profiles.<name>.enabled` | bool | `true` | Render the floating chat dock for this profile. |

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_chat_kit.default_profile` | Resolved `default_profile` |
| `nowo_chat_kit.profiles` | Normalized `profiles` map |

## Planned options

The following per-profile options are planned and **not** accepted yet:

- `realtime` — Mercure hub / topic prefix, presence TTL, typing debounce
- `push` — Web Push VAPID keys (via env vars, never committed), "only when the app is not open"
- `sounds` — enabled sounds and default volume; `buzz` cooldown
- `features` — direct messages, channels, support inbox toggles
- `templates` — Twig template overrides per UI fragment
- `theme` — default values for `--nowo-chat-*` CSS tokens
