# Configuration

## Table of contents

- [Root key](#root-key)
- [Profiles](#profiles)
  - [Two profiles](#two-profiles)
  - [How the profile is selected](#how-the-profile-is-selected)
- [Reference](#reference)
- [Retention from the application (1.31)](#retention-from-the-application-131)
- [Retention from a runtime setting (1.32)](#retention-from-a-runtime-setting-132)
- [Rate limits](#rate-limits)
- [Client API (2.4)](#client-api-24)
- [Private entity manager](#private-entity-manager)
- [Container parameters](#container-parameters)
- [Timeouts](#timeouts)
- [CSRF token ids](#csrf-token-ids)
- [FormKit profile](#formkit-profile)

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
| `profiles.<name>.user_class` | string | — (required with Doctrine, unless `doctrine.user_reference: id`) | Your user entity (implements `ChatUserInterface`). |
| `profiles.<name>.retention_days` | int ≥ 30 | `365` | Messages older than this are purged by `nowo:chat:purge`. `%env()%` allowed. Fixed when the container is built; since 1.31 a `RetentionPolicyProviderInterface` service overrides it at run time (see below). |
| `profiles.<name>.runtime.retention_days` | string or map | not set | 1.32: read `retention_days` from a runtime setting on every purge (needs `nowo-tech/runtime-setting-contract`). See [Retention from a runtime setting](#retention-from-a-runtime-setting-132). |
| `profiles.<name>.realtime` | bool | `true` | Use the realtime transport when one is available. |
| `profiles.<name>.realtime_events` | `full` \| `ids` | `full` | 2.4 (Q-BD-13): what hub events carry. `ids`: new and changed messages as ids only (`conversationUuid`, `messageUuid`, `messageId`, `deleted`, plus `threadRootUuid`); `read` / `typing` without the display name; `status` without its text. Every client, the dock included, fetches through the API. `full`: the 2.3 payloads. **Required with `client_api.context_policy`** (the build fails otherwise). |
| `profiles.<name>.client_api.principal_resolver` | ?string | `null` | 2.4: service id of your `ChatClientPrincipalResolverInterface`. `null`: the [client API](API.md) is off (no route). |
| `profiles.<name>.client_api.context_policy` | ?string | `null` | 2.4: service id of your `ChatClientContextPolicyInterface` (tenant per request). Needs `principal_resolver`. |
| `profiles.<name>.client_api.token_revoker` | ?string | `null` | 2.4.1: service id of your `ChatClientTokenRevokerInterface`: `POST <prefix>/chat/v1/logout` revokes the presented token (only it). `null`: logout still answers 204 but only the client forgets the token (`me.features.serverLogout: false`). Needs `principal_resolver`. |
| `profiles.<name>.client_api.writes` | bool | `false` | 2.5: the write routes (send, thread reply, reactions, edit and delete of one's own message) for tokens holding `chat:read` and `chat:write`. `false`: they answer 404 and `me.features.send` is `false`. Needs `principal_resolver`. |
| `profiles.<name>.client_api.prefix` | string | `/api` | 2.4: the API answers at `<prefix>/chat/v1`. Empty or a lowercase path without a trailing slash. |
| `profiles.<name>.client_api.context_header` | string | `X-Chat-Context` | 2.4: header naming the tenant (Beacon: `X-Beacon-Context`). |
| `profiles.<name>.client_api.limits.per_ip_per_minute` | int ≥ 1 | `300` | 2.4: client API requests per IP (IPv6: per /64). |
| `profiles.<name>.client_api.limits.per_token_per_minute` | int ≥ 1 | `60` | 2.4: client API requests per token. |
| `profiles.<name>.client_api.limits.writes_per_token_per_minute` | int ≥ 1 | `30` | 2.5: client API writes per token (on top of `per_token_per_minute` and the person's action and send budgets). |
| `profiles.<name>.push` | bool | `false` | Queue Web Push for new messages (needs a `ChatPushSenderInterface`). |
| `profiles.<name>.push_url` | string | `/?chat={uuid}` | URL a notification opens; `{uuid}` is the conversation. The dock opens `?chat=<uuid>` itself. |
| `profiles.<name>.push_icon` | ?string | `null` | Notification icon URL. |
| `profiles.<name>.digest.enabled` | bool | `false` | Email the conversations still unread (needs Messenger and a `ChatDigestSenderInterface`). |
| `profiles.<name>.digest.delay_minutes` | int ≥ 1 | `15` | How long a message stays unread before it counts. |
| `profiles.<name>.digest.interval_minutes` | int ≥ 1 | `60` | At most one email per user in this window. |
| `profiles.<name>.push_preview` | bool | `false` | Put the first 140 characters of the message in the Web Push body (lock screens). Off: a generic "New message". |
| `profiles.<name>.doctrine.table_name_prefix` | string | `nowo_chat_` | Replaces the `nowo_chat_` part of every bundle table, index and unique-constraint name (non-empty; lowercase letters, digits, `_`). `chat_` gives `chat_conversation`, `chat_message`, `idx_chat_message_created`, `uniq_chat_message_uuid`, …; the Doctrine-generated `IDX_*` / `FK_*` names follow the new table names. See [Table names](#table-names). |
| `profiles.<name>.doctrine.table_prefix` | string | `''` | Prepended to every bundle table, index and unique-constraint name **after** `table_name_prefix` is applied (lowercase letters, digits, `_`). `app_` gives `app_nowo_chat_conversation`, `app_idx_nowo_chat_message_created`, … Generate a migration after changing it. |
| `profiles.<name>.doctrine.user_reference` | `relation` \| `id` \| `string` | `relation` | 1.24: how the chat tables point at users. `relation` = Doctrine relations to `user_class` with foreign keys (`resolve_target_entities`). `id` = each user column holds the user's id as a string (`VARCHAR(64)`, no foreign key, indexed), users are loaded through `ChatDirectoryInterface::findByIds()` after the rows, `user_class` is not needed and need not be an entity. Global like the table names. Switching needs a migration ([User references](#user-references-124)). |
| `profiles.<name>.doctrine.entity_manager` | string | `default` | 1.27: the Doctrine entity manager that maps the chat entities: the source of the bundle's private entity manager (connection, mapping, caches, events). |
| `profiles.<name>.doctrine.private_entity_manager` | ?bool | `null` | **Deprecated, no effect** (2.0): the bundle always reads and writes its rows through its own private entity manager ([Private entity manager](#private-entity-manager)). `true` is accepted until 3.0 (with a deprecation; remove it); `false` fails the container build. |
| `profiles.<name>.web_ui.layout_template` | ?string | `null` | Layout the full-page chat extends (REQ-UI-003); `null` = `nowo_ui_kit.layouts.admin`. It must render the UiKit canonical blocks. |
| `profiles.<name>.web_ui.templates.page` | string | `@NowoChatKitBundle/page.html.twig` | Template of the full-page chat (a host template extending `@!NowoChatKitBundle/page.html.twig` and overriding one block). |
| `profiles.<name>.web_ui.assets` | bool | `true` | 1.21: `false` = the full-page chat leaves out the `nowo-chat.css` and `nowo-chat.js` tags (your bundler ships them). The dock on your other pages takes its own `nowo_chat_assets: false` ([USAGE](USAGE.md#front-end-iife-or-your-bundler)). |
| `profiles.<name>.web_ui.channels_url` | ?string | `null` | 1.24: your page to browse or create channels; when set, the dock's people picker shows a **Browse or create channels** link to it and the dock configuration carries `channelsUrl`. A path starting with one `/`, or an absolute `http(s)` URL (no spaces, backslashes or control characters; anything else is a configuration error). |
| `profiles.<name>.web_ui.page.open_recent` | bool | `false` | 1.25: `true` = `/chat/` (no conversation in the address) opens the most recent conversation beside the list, on wide screens only (a phone shows the list first) and never under **Closed**. Needs JavaScript. The conversation is shown, not read: it is marked read (and its people get the read receipt) only once the viewer interacts with it. With nothing to open, the thread column shows the "No conversations yet" empty state instead of "Pick a conversation". Off by default: the page keeps its "pick a conversation" start, and the list stays its own view. |
| `profiles.<name>.web_ui.dock.sidebar_sections` | bool | `false` | 1.25: `true` = the floating dock lists conversations in the grouped sidebar of the full-page chat (**Recent / Unread**, the sections of your `ChatSidebarSectionProviderInterface` services, **Direct messages and groups**). The full-page chat is grouped as soon as a section provider exists, or when this option is on. `false` = the 1.24 list in the dock ([USAGE](USAGE.md#sidebar-sections-125)). |
| `profiles.<name>.web_ui.message_context_menu` | ?bool | `null` | 1.33: the message context menu (UiKit 2.54): right-click, Shift+F10, the ContextMenu key or a long press on a message opens the items of its **More actions** kebab (Reply, Edit, Copy link, Pin / Unpin, Delete — only those the viewer may use). `null` = follow UiKit's `nowo_ui_kit.theme.context_menus` (your layout's UiKit `scripts` partial then loads the runtime); `true` = always on: the dock loads UiKit's `js/nowo-ui-context-menu.js` itself (UiKit's `nowo_ui_kit` asset package, same origin, CSP nonce of the page's scripts) unless the runtime already runs or the page has a `<script>` for it; `false` = always off (messages keep the browser's menu, even under the theme flag). The kebab is there in every mode. |
| `profiles.<name>.web_ui.dock.dialog_runtime` | bool | `false` | 1.26: `true` = the dock loads UiKit's remote-dialog runtime (`js/nowo-ui-dialog.js`, UiKit's `nowo_ui_kit` asset package) itself, so the sidebar actions (`dialogUrl`) open as dialogs on every page. Skipped when the runtime already runs or the page has a `<script>` for it (never two copies); the script gets the CSP nonce of the page's own scripts. Needs `symfony/asset` and UiKit's asset package (otherwise nothing is added), served from the same origin (a CDN `base_url` is not loaded). Do not combine it with a layout that appends its own `nowo-ui-dialog.js` after the dock has rendered (two copies would bind the openers twice). `false` = your layout loads it (UiKit document layout or its `scripts` partial). |
| `profiles.<name>.web_ui.page.full_height` | bool | `false` | 1.21: `true` = the full-page chat fills what is left of the host content area under the page header (class `nowo-chat-page--fill`) instead of the viewport-based `--nowo-chat-page-height`. The content area needs a bounded height ([USAGE](USAGE.md#full-page-chat)). |
| `profiles.<name>.cache_pool` | string | `cache.app` | PSR-6 pool for presence and recipient lists. Share it between workers / replicas. |
| `profiles.<name>.reactions` | list | `👍 ❤️ 😂 😮 😢 🎉` | Emojis offered as reactions (anything else is refused). |
| `profiles.<name>.icon_set` | `ux_icon` \| `svg_inline` | `ux_icon` | Dock icons from Symfony UX Icons, or the built-in SVG only. |
| `profiles.<name>.icons` | map | Tabler set | UX Icons name per dock icon (`chat`, `compose`, `chevronUp`, `chevronDown`, `tuck`, `close`, `send`, `back`, `arrowDown`, `search`, `hash`, `lifebuoy`, `retry`, `buzz`, `smile`, `check`, `edit`, `trash`, `link`, `external`; 1.24: `bell`, `bellOff` = `tabler:bell` / `tabler:bell-off`, the mute button; 1.25: `lock`, `plus` = `tabler:lock` / `tabler:plus`, private channels and add actions of the sidebar). Any other key adds a name the sidebar rows and actions may use as `icon`. |
| `profiles.<name>.presence_ttl` | int ≥ 30 | `90` | Seconds a heartbeat keeps someone online. |
| `profiles.<name>.auto_reply_interval_hours` | int ≥ 1 | `4` | At most one automatic reply per conversation and person in this many hours (1.11). |
| `profiles.<name>.max_pins` | int ≥ 1 | `20` | Pinned messages per conversation (1.10). |
| `profiles.<name>.auto_away_minutes` | int ≥ 0 | `10` | Minutes without keyboard or pointer input (in every tab) before an available user shows as away. `0`: never. |
| `profiles.<name>.dock.overlay` | `hide` \| `inert` \| `none` | `hide` | 1.17: what the floating dock does while the page shows a modal overlay (`nowo:overlay-open` / `-close` events, `html[data-nowo-overlay]`): `hide` = invisible and inert, `inert` = painted but inert, `none` = 1.16 behaviour. Page mode is never affected ([USAGE](USAGE.md#stacking-with-drawers-and-modals)). |
| `profiles.<name>.dock.watch_aria_modal` | bool | `false` | 1.17: also react to a modal `<dialog>` or a shown `[aria-modal="true"]` element outside the dock (one `MutationObserver`), for overlays that do not emit the events. |
| `profiles.<name>.status_presets.quick` | list | lunch, meeting, home, sick, holiday | One-click suggestions of the status menu (1.12): `emoji`, `label` (translation key of the `NowoChatKitBundle` domain, or a text), `clear_after` (`never`, `30m`, `1h`, `2h`, `4h`, `today`, `week`; default `never`). A list you set replaces the default one. |
| `profiles.<name>.status_presets.more` | list | *Work* and *Personal* groups | Groups of the **More statuses** drop-down (1.12): `label` and `statuses` (like `quick`). `[]` hides the drop-down. Defaults: `Configuration::DEFAULT_STATUS_PRESETS`. |
| `profiles.<name>.support.ticket_prefix` | string | `SUP-` | Prefix of the readable ticket number (`SUP-000123`). |
| `profiles.<name>.support.ticket_digits` | int 1–12 | `6` | Digits of the ticket number (zero-padded). |
| `profiles.<name>.support.resume_after_days` | int ≥ 1 | `7` | Opening support when the last ticket has had no message for this many days (or is resolved) asks "continue it or open a new one?". |
| `profiles.<name>.support.close_after_days` | int ≥ 0 | `30` | A resolved ticket turns read-only after this many days (`0`: never; a **closed** ticket is read-only at once). |
| `profiles.<name>.support.emojis` | bool | `true` | Decorative emojis (`aria-hidden`) before the ticket status, the topic label and the support assistant's lines; `false` shows text only. |
| `profiles.<name>.support.translation_domain` | string | `NowoChatKitBundle` | Domain of the topic labels and replies. |
| `profiles.<name>.support.topics` | list | incident, billing, how_to, account, other | Topics offered when a ticket opens: `key` (a-z, 0-9, `_`, `-`), `label` / `reply` (translation keys; default `support.topic.<key>` / `support.topic_reply.<key>`; `reply: ''` posts none), `icon` (for your templates), `emoji` (decorative, before the label; null = the built-in one of incident 🐞, billing 💳, how_to ❓, account 🔐, other 💬; `""` = none), `assignee` (public id of the agent new tickets of the topic go to). |
| `profiles.<name>.links.enabled` | bool | `true` | 1.19: pinned links, link cards, the **Attach link** action and the Links panel. Off: the links API answers 404, nothing is indexed, the dock shows none of it. |
| `profiles.<name>.links.max_pinned` | int 1–200 | `20` | 1.19: pinned links per conversation (`422 too_many_links` beyond). |
| `profiles.<name>.links.allow_http` | bool | `false` | 1.19: also accept `http:` links (pinned links and link cards). `https:` only by default; no other scheme ever. |
| `profiles.<name>.links.channel_managers` | `members` \| `admins` | `members` | 1.19: who manages the pinned links of a channel: every member, or only whom `ChatLinkPolicyInterface::canManageLinksInChannel()` allows (else `ChatPinPolicyInterface::canPinInChannel()`, else nobody). Both people of a direct conversation always do; in tickets, the agents. |
| `profiles.<name>.links.providers` | map | teams, meet, zoom, jitsi, webex | 1.19: known providers, `key => {label, hosts, icon, fallback_icon}`: `hosts` are lowercase hostnames (a subdomain matches too; only the hostname is looked at), `icon` the UX Icons name of the dock icon `provider_<key>` (null: the built-in `fallback_icon`, default `video`). Keys: `a-z`, `0-9`, `_` (not `other`). A map you set replaces the default one (`Configuration::DEFAULT_LINK_PROVIDERS`). |
| `profiles.<name>.search.enabled` | bool | `false` | 1.37: message search — the page `/chat/search`, `GET /chat/api/search` and a dock header link; off, both routes answer 404. Build the full-text index first ([UPGRADING](UPGRADING.md#to-1370), [USAGE](USAGE.md#message-search-137)). With `search.fulltext` it also maps the MySQL / MariaDB FULLTEXT index for `doctrine:migrations:diff`; with either off, an existing index stays mapped (never dropped by a diff). |
| `profiles.<name>.search.dock_entry` | bool | `true` | 1.37: the search link in the dock header (with `search.enabled`). |
| `profiles.<name>.search.conversation_entry` | bool | `true` | 1.40: a search button in each conversation header (with `search.enabled`): a modal over the conversation (this conversation by default, or all of the viewer's), backed by `GET /chat/search/results`; a link to `/chat/search?conversation=<uuid>` without JavaScript. |
| `profiles.<name>.search.fulltext` | bool | `true` | 1.37: use the database full-text index (MySQL / MariaDB FULLTEXT, PostgreSQL GIN on `to_tsvector`); `false` = `LIKE` only (no index needed, slow on big tables). |
| `profiles.<name>.search.postgresql_config` | string | `simple` | 1.37: PostgreSQL text search configuration of the index and the query (lower-case identifier). `simple` = no stemming, no stop words, any language. Changing it needs the index built again. |
| `profiles.<name>.search.mysql_min_token_length` | int 1–84 | `3` | 1.37: the server's `innodb_ft_min_token_size`; shorter words are matched with `LIKE`. |
| `profiles.<name>.search.min_query_length` | int 1–50 | `2` | 1.37: shortest search text (characters). |
| `profiles.<name>.search.max_query_length` | int 10–500 | `200` | 1.37: longest search text (characters); longer text answers 400 `query_too_long`. |
| `profiles.<name>.search.page_size` | int 1–50 | `20` | 1.37: results per page. |
| `profiles.<name>.search_kit.enabled` | bool | `false` | 1.37: register the SearchKit groups (**Messages** and, 1.38, **Conversations** in the Ctrl/⌘+K palette, results page and recents) when `nowo-tech/search-kit-bundle` ^1.10 is installed and its recents are revalidated (`nowo_search_kit.recent.revalidate: true`, or `recent.enabled: false`; otherwise they are not registered) ([USAGE](USAGE.md#searchkit-palette-137)). The Messages group answers only with `search.enabled`. |
| `profiles.<name>.search_kit.priority` | int | `30` | 1.37: tag priority of the Messages group (higher groups first). |
| `profiles.<name>.search_kit.messages.enabled` | bool | `true` | 1.38: register the Messages group (key `messages`). |
| `profiles.<name>.search_kit.messages.max_candidates` | int | `100` | 1.38: messages read and checked per search (1–1000): SearchKit's candidate window, which bounds the per-row checks, the visible total and the deepest results page. |
| `profiles.<name>.search_kit.channels.enabled` | bool | `true` | 1.38: register the Conversations group (key `chat_channels`): direct conversations, channels and support tickets the viewer can open, found by their name. |
| `profiles.<name>.search_kit.channels.priority` | int | `35` | 1.38: tag priority of the Conversations group. |
| `profiles.<name>.search_kit.channels.max_candidates` | int | `100` | 1.38: conversations read and checked per search (1–1000). |
| `profiles.<name>.search_kit.channels.direct_scan` | int | `200` | 1.38: the viewer's newest direct conversations in which the other person's name is matched (1–5000; the names come from the directory, not from ChatKit's tables). Older direct conversations are not found; when the scan is full the group's total is marked not exact. |
| `profiles.<name>.message_menu.quick_reactions.emojis` | list | `👍 ❤️ 😂 😮 😢 🙏` | 1.35: the row of emoji at the top of the message menu (`[]` hides it). Each must be one of `reactions`, or of the picker catalog (`ChatEmojiCatalog`) with `picker` on; anything else fails the container build. |
| `profiles.<name>.message_menu.quick_reactions.picker` | bool | `true` | 1.35: **More…** opens the emoji picker; the reaction endpoint then accepts `reactions` plus the picker catalog, nothing else. |
| `profiles.<name>.message_menu.copy_text` | bool | `true` | 1.35: **Copy text** (client side: the plain text the viewer sees). |
| `profiles.<name>.message_menu.quote.enabled` / `.max_length` | bool / int 20–4000 | `true` / `1000` | 1.35: **Quote** — the message as a Markdown quote in the composer (mentions as plain names), cut at `max_length` characters. |
| `profiles.<name>.message_menu.mark_unread` | bool | `true` | 1.35: **Mark unread from here** (not on one's own messages). The receipts other people see never go back. |
| `profiles.<name>.message_menu.save.enabled` | bool | `false` | 1.35: **Save for later** and the Saved list (`GET /chat/saved`, `GET /chat/api/saved`). Stores data: off by default. |
| `profiles.<name>.message_menu.save.max_per_user` | int ≥ 0 | `500` | 1.35: saved messages per user (`422 too_many_saved` beyond; `0` = unlimited). A soft cap: two saves racing at the limit may both pass. |
| `profiles.<name>.message_menu.save.dock_entry` | bool | `true` | 1.35: a **Saved** link in the dock header (when save is enabled). |
| `profiles.<name>.message_menu.message_info.enabled` | bool | `false` | 1.35: **Message info** — who read a message and when. Stores read times: off by default. |
| `profiles.<name>.message_menu.message_info.read_times` | bool | `true` | 1.35: store and show read times (only of people who share them: the reciprocal **Share read times** preference). `false`: who read it, never when. |
| `profiles.<name>.message_menu.message_info.per_person_max_members` | int 2–500 | `20` | 1.35: up to this many people besides the author, the info lists them; above it, counts only, and no read time is stored. |
| `profiles.<name>.message_menu.message_info.own_messages_only` | bool | `true` | 1.35: read info on the viewer's own messages only; support agents (moderators) see it on every message outside direct conversations. |
| `profiles.<name>.message_menu.forward.enabled` | bool | `false` | 1.36: **Forward** — copy a text message to other conversations or to a new direct conversation (all targets or none). |
| `profiles.<name>.message_menu.forward.max_targets` | int 1–20 | `5` | 1.36: conversations one forward may go to. Each target counts against the send rate limit. |
| `profiles.<name>.message_menu.forward.from_support` | bool | `false` | 1.36: messages of support tickets may be forwarded out. Forwarding **into** a ticket is for agents only, whatever this says. |
| `profiles.<name>.message_menu.threads.enabled` | bool | `false` | 1.36: **Reply in thread** — one-level threads under a message. Replies stay out of the main timeline and its unread badge. Stores thread read state: off by default. |
| `profiles.<name>.message_menu.edit_history.enabled` | bool | `false` | 1.36: keep the previous texts of edited messages; the author and moderators read them (`GET …/revisions`), everyone sees the edit times in message info. Stores data: off by default. |
| `profiles.<name>.message_menu.edit_history.max_revisions` | int 1–200 | `20` | 1.36: previous texts kept per message; older ones are dropped in the same write. |
| `profiles.<name>.message_menu.order` | list | `reply, thread, quote, forward, copy_text, copy_link, save, mark_unread, pin, info, edit, delete` | 1.35: order of the menu items; a subset hides the others. Separators go between groups (reply/thread/quote/forward · copy_text…pin · info/edit · delete). 1.36 added `thread` and `forward`. |
| `profiles.<name>.limits.*` | int | see below | Rate limits. |

## Retention from the application (1.31)

`retention_days` is read when the container is built. To change it without a deploy (for example an administration
setting kept in the database), alias `Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface` to your service:

```yaml
services:
    Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface: '@App\Chat\InstanceSettingsRetention'
```

The service returns the days (for example from `InstanceSettings`, see [USAGE](USAGE.md#retentionpolicyproviderinterface-optional))
or null. `nowo:chat:purge` asks it on every run, never caches the answer, and falls back to `retention_days` on null.
The minimum of 30 stays: a smaller value is raised to 30 with a warning in the log. A value of 0 or less is ignored
(warning; `retention_days` applies). Since 1.31 the maximum is 36500 days: a larger value, from the provider or from
`retention_days`, is lowered to 36500 with a warning. The provider is global: it applies to every profile.

## Retention from a runtime setting (1.32)

With [`nowo-tech/runtime-setting-contract`](https://github.com/nowo-tech/runtime-setting-contract) ^1.0 (an optional
dependency) the retention can live in `.env` or in the database and change without a deploy or a restart. The contract's
resolver must exist: register `NowoRuntimeSettingBundle` and bind its source and writer (InstanceSettingsBundle ≥ 1.20,
RuntimeEnvBundle ≥ 1.6 or your own), or, in a DDD host, register a `RuntimeSettingResolver` service yourself.

```yaml
nowo_chat_kit:
    profiles:
        default:
            retention_days: '%env(int:CHAT_RETENTION_DAYS)%'   # the configured (env) value
            runtime:
                retention_days:
                    source: db_then_env      # env | db | db_then_env (default)
                    key: chat.retention_days # namespace.key or an environment variable name (default chat.retention_days)
                    on_error: fail_closed    # the only accepted value
```

A string is the short form: `runtime: { retention_days: chat.retention_days }` means `source: db_then_env` with that key.

| `source` | Retention used by `nowo:chat:purge` |
| --- | --- |
| `env` | The configured `retention_days` (through the resolver; the commands refuse to write it). |
| `db` | The stored value; unset: the purge **fails** and deletes nothing (no bundle default: a default shorter than your `retention_days` would delete messages you keep). The configured value is ignored. |
| `db_then_env` | The stored value; unset: the configured `retention_days`. |

- **Bounds.** The setting is an `int` bounded to 30..36500 days for every source (`IntRange`, no clamping):
  `nowo:runtime-setting:set chat.retention_days 5` is refused and nothing is written.
- **Fail closed.** An invalid value read from the source (a hand-edited row, a value outside the bounds, not an integer),
  an invalid configured fallback (`%env(int:…)%` coerced to 0) or a source that cannot be read makes the purge fail:
  error log, the reason on the console, exit code 1, nothing deleted. There is no fallback to the configured value, to
  a default or to "keep forever"; `on_error: fallback` is refused when the container is built.
- **Live.** The value is read on every purge run; the resolver keeps it for one request, command or message only, so a
  value set with `nowo:runtime-setting:set` reaches a long-running worker (FrankenPHP, kernel reset off) within the
  contract's `version_ttl`, without a restart.
- **Build errors.** The container build fails when the node is set and the contract is not installed, when no
  `RuntimeSettingResolver` service exists, or when `RetentionPolicyProviderInterface` is also aliased to an application
  provider (keep one source). Only the default profile's node is used, like `retention_days`.
- Without the node nothing is registered and nothing changes.

**Recommended: `db_then_env`**, so that an unset value (or `nowo:runtime-setting:unset`) means "the configured
`retention_days`", as before. Use `db` only when the value must come from the source.

**Moving from your own definition.** If your application already registers a runtime-setting definition for the chat
retention (for example `ops.chat_retention_days`), remove it and set ChatKit's `key` to that id
(`runtime: { retention_days: { key: ops.chat_retention_days, source: db_then_env } }`). Otherwise the contract's registry
fails on a duplicate id, or, with the default key, ChatKit reads `chat.retention_days` and your stored value is ignored.
Remove your `RetentionPolicyProviderInterface` alias as well (the build refuses both).

## Rate limits

Prepended as `framework.rate_limiter` limiters (sliding window), keyed by the user's public id:

| Key | Limiter | Default |
| --- | --- | --- |
| `limits.send_per_minute` | `nowo_chat_send` | 30 |
| `limits.actions_per_minute` | `nowo_chat_action` (opens, reads, edits, reactions, presence) | 240 |
| `limits.typing_per_minute` | `nowo_chat_typing` (over the limit: dropped silently) | 30 |
| `limits.reads_per_minute` | `nowo_chat_read` (list, messages, contacts, realtime token) | 600 |
| `limits.references_per_minute` | `nowo_chat_reference` (`/` command menu and `#` search) | 120 |
| `limits.buzz_interval_seconds` | `nowo_chat_buzz` (per conversation) | 15 |
| `limits.buzz_per_minute` | `nowo_chat_buzz_global` | 10 |
| `limits.searches_per_minute` | `nowo_chat_search` (1.37, at least 3: message searches of the page and the API, key `nowo_chat_search_page_<public id>`; the SearchKit palette has its own bucket of the same size, key `nowo_chat_search_palette_<public id>`, one token per call since 1.38; 1.38: the SearchKit Conversations group has its own bucket too, key `nowo_chat_search_conversations_<public id>`, one token per call) | 30 |
| `limits.mention_all_per_hour` | `nowo_chat_mention_all` (1.24: `@channel` / `@here` per writer, when the channel provider allows them; over it the message is sent without the group mention) | 6 |

## Client API (2.4)

The versioned, stateless client API for native clients. See [API.md](API.md) for the routes, the ports and the
`security.yaml` firewall.

```yaml
nowo_chat_kit:
    profiles:
        default:
            realtime_events: ids                     # required with context_policy: ids-only hub events (Q-BD-13)
            client_api:
                principal_resolver: App\Chat\ClientTokens
                context_policy: App\Chat\ClientContexts
                token_revoker: App\Chat\ClientTokenRevoker   # 2.4.1: POST logout revokes the token
                writes: true                         # 2.5: send, thread reply, reactions, edit/delete own (chat:write)
                prefix: /api                         # → /api/chat/v1
                context_header: X-Beacon-Context
                limits: { per_ip_per_minute: 300, per_token_per_minute: 60, writes_per_token_per_minute: 30 }
```

It adds three limiters, `nowo_chat_client_ip`, `nowo_chat_client_token` and (2.5) `nowo_chat_client_write`. The client
API also uses the person's own `nowo_chat_read` (reads), `nowo_chat_action` (read marks and every write) and (2.5)
`nowo_chat_send` (messages and thread replies) budgets, shared with the web UI.

## Table names

The bundle maps its tables as `nowo_chat_<name>`. Two options change that, applied in this order:

1. `doctrine.table_name_prefix` replaces the `nowo_chat_` part of each table, index and unique-constraint name.
2. `doctrine.table_prefix` is prepended to the result.

| `table_name_prefix` | `table_prefix` | Message table | Created-at index | UUID unique |
|---|---|---|---|---|
| `nowo_chat_` (default) | `''` (default) | `nowo_chat_message` | `idx_nowo_chat_message_created` | `uniq_nowo_chat_message_uuid` |
| `chat_` | `''` | `chat_message` | `idx_chat_message_created` | `uniq_chat_message_uuid` |
| `nowo_chat_` | `app_` | `app_nowo_chat_message` | `app_idx_nowo_chat_message_created` | `app_uniq_nowo_chat_message_uuid` |
| `chat_` | `app_` | `app_chat_message` | `app_idx_chat_message_created` | `app_uniq_chat_message_uuid` |

The tables are `conversation`, `message`, `message_mention`, `participant`, `quick_reply`, `reaction`, `ticket_counter` (1.30) and
`user_settings`. Doctrine derives the `IDX_*` / `FK_*` names of the foreign keys from the table name, so they change
with it. Changing either option on an existing database needs a migration; [UPGRADING](UPGRADING.md#to-1220) has the
rename statements.

```yaml
nowo_chat_kit:
    profiles:
        default:
            doctrine:
                table_name_prefix: chat_
```

Rules (REQ-ORM-002):

- Both options match `/^[a-z][a-z0-9_]*\z/` (`table_prefix` may be empty). Together they may not make any table,
  index, unique or PostgreSQL sequence name longer than 63 characters, i.e. at most 38 characters for
  `table_prefix` + `table_name_prefix`. Both are checked when the container is built. `%env()%` is **not supported** for either option (the table names are part of the schema, and every published table-name parameter must come from a validated literal): the container build fails.
- The table names are **global**: only the default profile's `doctrine` options apply. Another profile may repeat
  them; a different value fails the container build.

Container parameters with the resolved physical names, for host SQL (GDPR erasers, reports):

| Parameter | Default value | With `table_name_prefix: chat_` |
|---|---|---|
| `nowo_chat_kit.doctrine.table.conversation` | `nowo_chat_conversation` | `chat_conversation` |
| `nowo_chat_kit.doctrine.table.message` | `nowo_chat_message` | `chat_message` |
| `nowo_chat_kit.doctrine.table.message_mention` | `nowo_chat_message_mention` | `chat_message_mention` |
| `nowo_chat_kit.doctrine.table.participant` | `nowo_chat_participant` | `chat_participant` |
| `nowo_chat_kit.doctrine.table.quick_reply` | `nowo_chat_quick_reply` | `chat_quick_reply` |
| `nowo_chat_kit.doctrine.table.reaction` | `nowo_chat_reaction` | `chat_reaction` |
| `nowo_chat_kit.doctrine.table.ticket_counter` (1.30) | `nowo_chat_ticket_counter` | `chat_ticket_counter` |
| `nowo_chat_kit.doctrine.table.user_settings` | `nowo_chat_user_settings` | `chat_user_settings` |
| `nowo_chat_kit.doctrine.tables` | map of the keys above to the names | |

Inside the bundle every query takes the names from the Doctrine metadata, never from literals.

## User references (1.24)

```yaml
nowo_chat_kit:
    profiles:
        default:
            doctrine:
                user_reference: id   # default: relation
```

| | `relation` (default) | `id` |
|---|---|---|
| User columns (`author_id`, `user_id`, `support_owner_id`, `assignee_id`, `resolved_by_id`, `deleted_by_id`, `pinned_by_id`, `created_by_id`) | integer, foreign key to your user table | `VARCHAR(64)` holding `ChatUserInterface::getId()` as text, no foreign key |
| `resolve_target_entities` | `ChatUserInterface` → `user_class` | none (`user_class` optional) |
| Your chat user | a Doctrine entity | any object implementing `ChatUserInterface` (a DTO, a read model) |
| Loading users | joined with the rows | one `ChatDirectoryInterface::findByIds()` call per batch of rows (memoized per request) |
| Deleting a user | foreign keys cascade / set null | nothing happens: call `ChatPersonalData::erase()` from your account deletion |
| A row whose user is gone | removed by the database | nullable relations read null ("Deleted user"); participant, reaction, mention, settings and quick-reply rows get an `UnresolvedChatUser` (never reachable, a hashed public id) until erased |

In id mode the indexes the foreign keys gave are mapped explicitly (`idx_nowo_chat_msg_author`,
`idx_nowo_chat_msg_deleted_by`, `idx_nowo_chat_msg_pinned_by`, `idx_nowo_chat_conv_resolved_by`,
`idx_nowo_chat_conv_link_by`, `idx_nowo_chat_reaction_user`; renamed by `table_name_prefix` / `table_prefix`), and every
entity exposes `get<Relation>Reference()` (e.g. `ChatMessage::getAuthorReference()`) to read the stored id without
loading the user. [UPGRADING](UPGRADING.md#to-1240) has the migration.

1.27: **string references** (`doctrine.user_reference: string`). Every chat user implements
`ChatUserReferenceInterface` and is stored by its `getChatUserReference()` (1–64 characters, refused with whitespace at
either end or control / format characters), loaded through `ChatUserReferenceDirectoryInterface::findByReferences()`
(every answer re-checked byte for byte), and compared exactly everywhere; direct pairs keep both references in
`direct_user_low` / `direct_user_high`. On MySQL / MariaDB every user reference column must have the binary NO PAD
collation: the bundle checks it once per request and refuses to work until the `ALTER` of
[UPGRADING](UPGRADING.md#to-1280) has run. Integer-id users are refused in this mode (and reference users in `id`
mode). The `relation` and `id` modes are unchanged.

## Private entity manager

```yaml
nowo_chat_kit:
    profiles:
        default:
            doctrine:
                entity_manager: default        # the manager that maps the chat entities
```

Opt-in from 1.27 (`private_entity_manager: true`), the only mode since 2.0. Up to 1.26 (and in 1.x without the option)
every chat write flushed your default entity manager, so it also wrote whatever your application had left pending
there, and a failed chat write closed your manager. Now:

- every ChatKit service reads and writes through `ChatKitEntityManager`, a private manager created on the connection,
  mapping, caches, proxies and event manager of `entity_manager`. The tables do not move and the Doctrine listeners
  (the bundle's and yours) still run. Your services keep your manager;
- a chat write never flushes, clears or closes your manager. A failed chat write closes only the private manager,
  created again on the next call;
- the connection is shared: inside a transaction you opened, the chat rows are part of it, and the bundle's own
  transactions and bulk statements become savepoints (`nowo_chat_kit_*`); when rolling back to such a savepoint fails,
  the original exception is rethrown;
- the private manager is dropped at every request, Messenger message and console command boundary (after the memos;
  `ChatRequestBoundaryListener`) and at `kernel.reset`. Changes still pending then are discarded and logged as a
  warning by entity class and count (never a value);
- with doctrine/dbal 4, a rollback that undid rows the private manager wrote replaces it before its next use (DBAL
  middleware on the source connection only); with DBAL 3 it is replaced at the next boundary;
- the SQL filters enabled on your manager are mirrored on the private one on every call;
- in `relation` mode, your user objects on chat rows (the security user, a user your code loaded) are replaced on the
  row by the private manager's reference to the same id before each flush; your instance is never written. Users read
  back from chat rows are the private manager's instances: compare users by id, never with `===`. 2.0: the id is taken
  from your manager's unit of work (an uninitialised proxy is never loaded for it); a user you persisted but did not
  flush yet, or never persisted, fails with a `LogicException` ("… is not saved yet … Flush your own entity manager
  first") before anything is written, and so does one whose row ChatKit cannot see (with a single-table-inheritance
  `user_class`: deleted, filtered or of another subclass);
- chat rows you load through **your** manager and hand to the chat services (a route value resolver, your own query)
  are loaded again by id in the private manager (`ChatNotFoundException` when the row is gone), never inserted again.
  Your copy is not updated by the chat write;
- the reverse is not saved: a chat row loaded through ChatKit's repository services (which use the private manager)
  that you change and then flush with **your** manager is not written (the private manager drops it at the next
  boundary, with a warning in the log). Change chat rows through the ChatKit services (`ChatServiceInterface`,
  `ChatMessagingInterface`, …);
- your own entities may point at chat rows: use `$yourManager->getReference(ChatConversation::class, $id)` (or a row
  your manager loaded) and flush your manager.

`private_entity_manager: false` fails the container build since 2.0; `true` is accepted as a deprecated no-op until
3.0. The 1.39 host-flush probe ("Your pending changes to App\X were flushed by NowoChatKitBundle; 2.0 no longer does.")
is gone with the mode it reported on; its entries on 1.x are your migration list (see UPGRADING).

## Container parameters

`nowo_chat_kit.default_profile`, `nowo_chat_kit.profiles` and, for the default profile, `nowo_chat_kit.<key>` for each
key above (`user_class`, `retention_days`, `realtime`, `push`, `push_url`, `push_icon`, `push_preview`, `cache_pool`, `web_ui`, `web_ui.layout_template`, `web_ui.templates.page`, `web_ui.assets`, `web_ui.page.full_height`, `web_ui.page.open_recent` and `web_ui.dock.sidebar_sections` (1.25), `web_ui.dock.dialog_runtime` (1.26), `web_ui.message_context_menu` (1.33), `doctrine.table_prefix`, `doctrine.table_name_prefix`, `doctrine.tables`, `doctrine.table.<key>`,
`reactions`, `icon_set`, `icons`, `presence_ttl`, `auto_away_minutes`, `status_presets`, `max_pins`, `auto_reply_interval_hours`, `limits`, `digest`, `support`, `dock`, and `support.<key>` for each support key).
Since 2.4: `nowo_chat_kit.realtime_events`, `nowo_chat_kit.client_api.enabled` (a principal resolver is configured), `nowo_chat_kit.client_api.prefix`, `nowo_chat_kit.client_api.v1_path_prefix` (`<prefix>/chat/v1`, for `security.yaml` patterns) and `nowo_chat_kit.client_api.context_header`. Since 2.5: `nowo_chat_kit.client_api.writes` (writes on, with the API on).
Since 1.32, `nowo_chat_kit.runtime.retention_days` holds the `runtime.retention_days` setting id (null when not set).

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

## CSRF token ids

The JSON API checks the `X-CSRF-TOKEN` header against the token id **`nowo_chat`**; the full-page message form uses
**`nowo_chat_page`**. Both must stay **session** tokens (Symfony's default).

> **Warning:** do **not** add `nowo_chat` (or `nowo_chat_page`) to `framework.csrf_protection.stateless_token_ids`.
> `SameOriginCsrfTokenManager` keeps the double-submit state of stateless ids in the session, and signing in
> regenerates the session, so every chat request after sign-in is refused (the log reads "double-submit info was used
> in a previous request but is now missing"). Keep the chat ids out of that list; the 1.26 token refresh
> (`GET {prefix}/api/csrf`) plus one retry of the refused write is the supported recovery when a session loses its token.

```yaml
# config/packages/framework.yaml
framework:
    csrf_protection:
        # Your own stateless ids only; never nowo_chat / nowo_chat_page.
        stateless_token_ids: ['submit', 'authenticate', 'logout']
```

See [SECURITY — Host responsibilities](SECURITY.md#host-responsibilities-residual-risks).

## FormKit profile

Every chat form type (`ChatPageMessageType`, `ChatDockSearchType`, `ChatDockComposerType`, `ChatDockEditType`,
`ChatDockStatusType`, `ChatPreferencesType`) is a FormKit Type (`#[FormKitConfig('chat_kit')]`, REQ-TWIG-005 rule 11).
Its field defaults come from the FormKit profile **`nowo_form_kit.profiles.chat_kit`**, which the bundle prepends unless
you define it:

```yaml
# config/packages/nowo_form_kit.yaml — define the profile to override the bundle default (nothing is prepended then)
nowo_form_kit:
    profiles:
        chat_kit:
            alias: chat_kit
            translation_domain: NowoChatKitBundle
            auto_help: false
            auto_placeholder: false
            defaults:
                attr: {}
                row_attr: {}
            field_types: {}   # e.g. textarea: { attr: { class: my-input } }
```

- No framework classes by default (REQ-UI-003): the UiKit form theme paints the fields. Options a type sets itself (the dock `nowo-chat-*` classes, `data-part` hooks, explicit labels) win over the profile.
- `ChatDockStatusType` labels arrive translated in the dock locale, so its fields keep `translation_domain: false`.
- The profile does not change your `nowo_form_kit.default_profile`.

