# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.27.1](#to-1271)
- [To 1.27.1](#to-1271-1)
- [To 1.27.0](#to-1270)
  - [Framework-free port twins (REQ-ARCH-001 rule 3)](#framework-free-port-twins-req-arch-001-rule-3)
  - [Private entity manager (REQ-ARCH-001 rule 6, opt-in)](#private-entity-manager-req-arch-001-rule-6-opt-in)
  - [String user references (REQ-ARCH-001 rule 2, opt-in `doctrine.user_reference: string`)](#string-user-references-req-arch-001-rule-2-opt-in-doctrineuser_reference-string)
  - [Calling the chat by id](#calling-the-chat-by-id)
- [To 1.26.4](#to-1264)
  - [Exact channel keys (house rule LQ)](#exact-channel-keys-house-rule-lq)
- [To 1.26.3](#to-1263)
- [To 1.26.2](#to-1262)
- [To 1.26.1](#to-1261)
- [To 1.26.0](#to-1260)
- [To 1.25.1](#to-1251)
- [To 1.25.0](#to-1250)
  - [Target 1.25.0: sidebar sections](#target-1250-sidebar-sections)
- [To 1.24.0](#to-1240)
  - [Chat room channels (Symfony Beacon spec 184)](#chat-room-channels-symfony-beacon-spec-184)
  - [DDD / hexagonal ports](#ddd--hexagonal-ports)
  - [Optional: `doctrine.user_reference: id`](#optional-doctrineuser_reference-id)
- [To 1.23.0](#to-1230)
- [To 1.22.1](#to-1221)
- [To 1.22.0](#to-1220)
- [To 1.21.0](#to-1210)
- [To 1.20.0](#to-1200)
- [To 1.19.0](#to-1190)
- [To 1.18.0](#to-1180)
- [To 1.17.0](#to-1170)
- [To 1.16.1](#to-1161)
- [To 1.16.0 (profile pictures)](#to-1160-profile-pictures)
- [To 1.15.3](#to-1153)
- [To 1.15.2](#to-1152)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.13.2](#to-1132)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.3](#to-1113)
- [To 1.11.2](#to-1112)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.1](#to-1101)
- [To 1.10.0](#to-1100)
- [To 1.9.2](#to-192)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.5](#to-155)
- [To 1.5.4](#to-154)
- [To 1.5.2](#to-152)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
  - [Template contract (1.5)](#template-contract-15)
- [1.4.1 (2026-10-18)](#141-2026-10-18)
- [1.4.0 (2026-10-16)](#140-2026-10-16)
- [1.3.1 (2026-10-16)](#131-2026-10-16)
- [1.3.0 (2026-10-16)](#130-2026-10-16)
- [1.2.0 (2026-10-16)](#120-2026-10-16)
- [1.1.1 (2026-10-16)](#111-2026-10-16)
- [1.1.0 (2026-10-16)](#110-2026-10-16)
- [1.0.0 (2026-10-16)](#100-2026-10-16)

## Unreleased

## To 1.27.1

From **1.27.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.27.1

From **1.27.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

No configuration, schema or contract change. Four notes:

1. **Rebuilt browser assets.** `src/Resources/public/js/nowo-chat.js` carries the dock fix, so a host that copies or
   bundles the asset itself (`bin/console assets:install`, an importmap, its own asset build) must pick up the new
   file. Hosts whose page scroller is an inner element — a fixed header above a scrolling main column — and that mark
   a static footer with `data-nowo-chat-avoid` now get the dock resting on top of that footer instead of covering it.
   Nothing to configure.
2. **`doctrine.user_reference: string` on MySQL 8 / MariaDB: check the collation of your user columns.**
   `utf8mb4_bin` is a binary but **PAD SPACE** collation there (`'bob '` equals `'bob'`), so it no longer counts as
   exact and the schema guard refuses it, naming the columns and the collations it does accept. Use
   `utf8mb4_0900_bin` on MySQL 8 and later, `utf8mb4_nopad_bin` on MariaDB; `utf8mb4_bin` stays accepted on MySQL 5.7,
   which has no NO PAD collation and where the reference format rule (no leading or trailing whitespace) keeps the
   comparison exact. If you ran the `ALTER TABLE` of "String user references" with `utf8mb4_bin` on MySQL 8 or
   MariaDB, run it again with the right collation before deploying. PostgreSQL and SQLite are unaffected.
3. **`doctrine.user_reference: string`: the schema check now runs once per request**, not once per process, because
   `ChatRequestBoundaryListener` clears it at every request, command and message boundary. That is one
   `information_schema` read per request that touches the chat (and none in a request that does not), and a database
   switched between two requests of the same worker is checked again instead of trusting the earlier pass. The pass is
   held per `Connection` object: it does not distinguish the same database name on a different server, nor a
   `USE other_db` issued on that connection inside one request.
4. **`doctrine.user_reference: id` / `string` with integer ids of 19 digits.** `ChatUserReferences::isCanonicalId()`
   now accepts up to 19 digits bounded by `ChatUserReferences::MAX_ID` (`9223372036854775807`), so snowflake-style
   bigint ids (>= 10^18) are resolved through `ChatDirectoryInterface::findByIds()` again instead of being dropped.
   Note the type change that follows: for such a key `ChatUserReferences::idOrKey()` returns an **`int`** where 1.27.0
   returned a `string`, and that value reaches the Mercure payloads, `SendChatDigestMessage` and `ChatMentions`. A
   host that compares those values loosely is unaffected; one that compares them with `===` against a string, or
   serialises them and expects a quoted JSON value, must accept the integer form. Anything above `PHP_INT_MAX` stays
   a string reference.

## To 1.27.0

From **1.26.4**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target 1.27.0, from **1.26.4**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Nothing to change: with the same configuration and the same services the bundle behaves as 1.26.4, and no migration is
needed in any of the existing modes (`relation`, `id`). A migration is needed only when you opt in to the new
`doctrine.user_reference: string` (two columns, the binary collation; see "String user references" below). The opt-in
private entity manager needs no schema change.

### Framework-free port twins (REQ-ARCH-001 rule 3)

`ChatChannelProviderInterface`, `ChatRealtimeInterface` and `CurrentChatUserResolverInterface` are deprecated (removed
in 2.0) and keep working unchanged. To move a service, implement the twin and alias either id to it:

| Deprecated | Implement instead | Change |
|---|---|---|
| `ChatChannelProviderInterface` | `ChatChannelSourceInterface` | `contextChannel(ChatUserInterface $user, ChatRequestContext $page)`: read `$page->path`, `$page->route`, `$page->routeParam('slug')`, `$page->query` instead of the `Request` |
| `ChatRealtimeInterface` | `ChatRealtimeTransportInterface` | `browserHubUrl(ChatRequestContext $request)`: `$request->origin()` is `Request::getSchemeAndHttpHost()` |
| `CurrentChatUserResolverInterface` | `ChatParticipantResolverInterface` | `resolve(?ChatSecurityUser $user)`: map `$user->identifier` (or `$user->id`) to the participant; the security user object is not passed |

```yaml
# config/services.yaml — either line works
Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface: '@App\Chat\ProjectChannels'
Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface: '@App\Chat\ProjectChannels'
```

Keep the write and mention policies (`ChatChannelWritePolicyInterface`, `ChatChannelMentionPolicyInterface`) on the
same class. A class implementing both the old interface and the twin is asked through the old one until 2.0. A port
service that implements neither now fails the container build (it failed at the first call before).

### Private entity manager (REQ-ARCH-001 rule 6, opt-in)

```yaml
nowo_chat_kit:
    profiles:
        default:
            doctrine:
                private_entity_manager: true
                # entity_manager: default   # the manager that maps the chat entities
```

Recommended. Up to 1.26 every chat write flushed your default entity manager (your pending changes included). With the
option, the chat writes through its own manager on the same connection; your manager is never flushed, cleared or
closed by the bundle. No schema change. Check after enabling it:

- code that relied on a chat call flushing **your** pending changes must call `flush()` itself;
- users read from chat rows are the private manager's instances: compare users by id (`getId()`), never with `===`;
- chat entities you load through **your** manager are not the ones the bundle writes: change chat rows through the
  ChatKit services only;
- inside your own transaction the chat rows are part of it (shared connection); the bundle's own writes run in
  savepoints.

Setting it to `false` explicitly is deprecated (it becomes the only mode in 2.0); leaving it unset keeps 1.26 behaviour.

### String user references (REQ-ARCH-001 rule 2, opt-in `doctrine.user_reference: string`)

Nothing changes for integer ids, in `relation` or `id` mode (no new column, no collation change). To key chat users by a
string (UUID, ULID, IdP subject) instead of an integer surrogate:

1. Set `doctrine.user_reference: string` (the `id` mode with string references).
2. Every chat user class implements `ChatUserReferenceInterface::getChatUserReference(): string` (1–64 characters, no
   whitespace at either end, no control or format characters; `getId()` may return null). Users of both kinds never mix
   (audit OJ-L1: a reference `"42"` must not meet the integer id 42): in `string` mode a user without the interface is
   unreachable and refused when a chat row is written, in `id` mode a user with it is refused the same way, and a
   configured `user_class` of the wrong kind fails the container build.
3. Your `ChatDirectoryInterface` also implements `ChatUserReferenceDirectoryInterface::findByReferences(list<string>)`,
   returning users whose reference is **exactly** one of those asked (no case, accent, padding or Unicode-normalisation
   folding; the bundle drops any other answer).
4. Migrate: `nowo_chat_conversation` gains two nullable columns, `direct_user_low` and `direct_user_high`
   (`VARCHAR(64)`), and on MySQL / MariaDB every user reference column gets the binary NO PAD collation. **This is
   enforced (audit OJ-M1):** on MySQL / MariaDB the bundle checks once per request (`information_schema`) that the
   columns exist and compare byte for byte, and until then every chat query or write by user throws a `LogicException`
   naming the columns (fail closed: a folding column would let look-alikes read each other's rows, and a unique-key
   clash between them would close the entity manager). Existing `id`-mode stored values (integer ids) must be rewritten
   to your references by your own migration when you switch.

**Before the `ALTER` (MySQL / MariaDB): look for references that the old collation folds together.** Integer ids cannot
collide; run these only if string references were already written (e.g. by a custom import). `TRIM`, `DISTINCT`,
`LOWER` and `COLLATE …_ai_ci` fold too, so compare the bytes:

```sql
-- Participant rows: references equal under the column's collation but different bytes.
SELECT a.user_id, HEX(a.user_id), b.user_id, HEX(b.user_id)
  FROM nowo_chat_participant a JOIN nowo_chat_participant b
    ON a.user_id = b.user_id AND CAST(a.user_id AS BINARY) <> CAST(b.user_id AS BINARY)
 GROUP BY a.user_id, b.user_id;
-- The same check for the other user columns: nowo_chat_message.author_id / deleted_by_id / pinned_by_id,
-- nowo_chat_reaction.user_id, nowo_chat_message_mention.user_id, nowo_chat_user_settings.user_id,
-- nowo_chat_quick_reply.user_id, nowo_chat_conversation.support_owner_id / assignee_id / resolved_by_id,
-- nowo_chat_conversation_link.created_by_id.
```

Then (adapt the table names to `doctrine.table_name_prefix` / `table_prefix`). The collation to use is
`utf8mb4_0900_bin` on MySQL 8 and later and `utf8mb4_nopad_bin` on MariaDB. **`utf8mb4_bin` is accepted on MySQL 5.7
only** (1.27.1): it is a binary but PAD SPACE collation, so on MySQL 8 and on MariaDB `'bob '` still equals `'bob'`
and the guard refuses it; 5.7 has no NO PAD collation at all, and there the reference format rule — no leading or
trailing whitespace — is what keeps the comparison exact:

```sql
ALTER TABLE nowo_chat_conversation
    ADD direct_user_low VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
    ADD direct_user_high VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
    MODIFY support_owner_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
    MODIFY assignee_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
    MODIFY resolved_by_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
ALTER TABLE nowo_chat_participant MODIFY user_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE nowo_chat_message
    MODIFY author_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
    MODIFY deleted_by_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL,
    MODIFY pinned_by_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
ALTER TABLE nowo_chat_reaction MODIFY user_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE nowo_chat_message_mention MODIFY user_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE nowo_chat_user_settings MODIFY user_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE nowo_chat_quick_reply MODIFY user_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL;
ALTER TABLE nowo_chat_conversation_link MODIFY created_by_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
```

`doctrine:migrations:diff` generates the same in `string` mode (the collation comes from the bundle's schema listener).
Until the `ALTER` the chat refuses to work (see step 4). On top of the collation, every lookup by user is re-checked
byte for byte in PHP (participant rows, settings, quick replies, mentions and their counts, reactions, support threads,
direct peers, push recipients, the erasure), lists read on until a page holds only exact rows, and the unread counts
compare the user columns as binary in SQL.

Also: `ChatAccess::usersByIds()` now returns users keyed by `ChatUserReferences::key()` (the integer id stays an
integer array key), `ChatConversation::directUserKeys()` / `peerKeyOf()` work for both kinds of pairs, and
`ChatPersonalData::eraseFormerAccount()` takes the string reference of a gone account.

### Calling the chat by id

New `ChatMessagingInterface` (autowire it): the same operations as `ChatServiceInterface` by public uuid, answering
with `ChatConversationView` / `ChatMessageView` / `ChatParticipantView`. `edit()`, `delete()`, `toggleReaction()` and
`pin()` take the conversation uuid and the message uuid; `edit()` takes the actor first. Every method with an actor
authorizes it like the JSON API does; an unknown uuid and a forbidden one both throw `ChatNotFoundException` (`$what`,
`$uuid`). Use `can($actor, $conversationUuid, ChatMessagingInterface::SEND)` (VIEW, SEND, MODERATE, RESOLVE, PIN, EDIT,
DELETE, REACT) to authorize by uuid. `ChatServiceInterface` is not deprecated (it still leaves authorization to you).

## To 1.26.4

From **1.26.3**:

```bash
composer update nowo-tech/chat-kit-bundle
```

### Exact channel keys (house rule LQ)

**Who is affected:** hosts on MySQL or MariaDB. PostgreSQL and SQLite need nothing.

1. **Channel keys must follow the `ChatChannelKey` rule**: 1–120 bytes of UTF-8, no whitespace at either end and no
   control or format characters (zero-width spaces and joiners, bidirectional overrides, the BOM). `project:<uuid>`
   and any printable key pass. A key that breaks the rule is now an unknown channel: the API answers 404 and
   `ChatService::openChannel()` throws a `LogicException`. Keys are compared byte for byte, so `project:Acme` and
   `project:acme` are two channels; make `ChatChannelProviderInterface::find()` return the canonical key.
2. **Give `channel_key` the binary collation.** New schemas get it from `ChannelKeyCollationListener`;
   `doctrine:migrations:diff` (or `doctrine:schema:update --dump-sql`) shows the change for an existing table. By hand
   (default table name; use yours when `doctrine.table_prefix` / `table_name_prefix` are set):

   ```sql
   -- MySQL 8.0 / 8.4
   ALTER TABLE nowo_chat_conversation MODIFY channel_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin DEFAULT NULL;
   -- MariaDB
   ALTER TABLE nowo_chat_conversation MODIFY channel_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_nopad_bin DEFAULT NULL;
   -- MySQL 5.7
   ALTER TABLE nowo_chat_conversation MODIFY channel_key VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL;
   ```

   The change only makes comparisons stricter, so it never fails on existing rows: any two keys the old unique key
   accepted are also distinct under the binary collation. Until it runs, the PHP re-check already keeps every channel to its
   exact key, and a look-alike of a channel that is already open fails with a unique-key error instead of opening it.
3. **Check existing rows before or after the `ALTER`.** A channel whose provider key differed only in case, accents or
   trailing spaces from an older one was attached to the older conversation; that conversation keeps its stored key
   and stays with the channel that created it. Find rows whose stored key the provider no longer returns exactly, and
   rows with padded keys (they no longer open and are no longer listed):

   ```sql
   SELECT id, uuid, HEX(channel_key) FROM nowo_chat_conversation
   WHERE channel_key IS NOT NULL
     AND (CAST(channel_key AS BINARY) <> CAST(TRIM(channel_key) AS BINARY) OR channel_key REGEXP '[[:cntrl:]]');
   ```

   The comparison is done on bytes because a PAD SPACE collation (MariaDB's default `utf8mb4_uca1400_ai_ci`,
   `utf8mb4_unicode_ci`, `utf8mb4_general_ci`) calls `team:ops ` and `team:ops` equal, so a plain
   `channel_key <> TRIM(channel_key)` misses every key with trailing spaces there.

   Participant rows that members of the other channel received in it grant nothing (`ChatAccess::canView()` checks
   the provider for the stored key); remove them if you want their unread counters gone:
   `DELETE FROM nowo_chat_participant WHERE conversation_id = ? AND user_id IN (...)`. For a padded key, either fix
   the provider key and update the row to the exact key, or leave the row: its channel is simply no longer reachable.

## To 1.26.3

From **1.26.2**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.26.2

From **1.26.1**:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console assets:install   # nowo-chat.js changed
```

No PHP, schema, configuration, template or API change. Client-side fixes only:

- **After a realtime reconnection**, each open window (and the full-page thread) sends one more `GET …/messages`
  request (plus one `?before=` request per older page it shows, up to 10 requests in all) to refresh edits, deletions
  and reactions made while the connection was down. Rate limits sized for 1.26.1 keep working: it happens on a
  reconnection only, at most once every 30 s per window, never on the 60 s tick. If your end-to-end tests reopened a window after a reconnection to see an edit or a deletion, that
  workaround can go.
- **Cache paint:** the server's newest page replaces the cached lines unless its first message is already cached.
  Nothing to change.

## To 1.26.1

From **1.26.0**:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console assets:install   # nowo-chat.js changed
```

No schema, configuration or template change. Everything is backward compatible:

- **Open windows catch up after a dropped realtime connection.** Nothing to configure. If your end-to-end tests worked
  around the stale window (reopening it after a reconnection), the workaround can go.
- **`lastMessageId`** is a new key of the conversation summary JSON (after `lastMessageAt`; set on list rows, `null`
  without a message or where `lastMessage` is `null`). Clients that compare the summary keys exactly must allow it.
- **Bundlers that import the TypeScript:** `ChatRealtime`'s constructor takes an optional fourth argument `onResumed`;
  `ThreadView` gains `resync()` and `syncedId()`; `ChatApiError` gains `retryAfter` (optional fourth constructor
  argument). Rebuild as usual.
- **CSRF token ids (docs).** If `framework.csrf_protection.stateless_token_ids` lists `nowo_chat` (or `nowo_chat_page`),
  remove it: with a stateless id every chat request after sign-in is refused. See
  [CONFIGURATION — CSRF token ids](CONFIGURATION.md#csrf-token-ids).

## To 1.26.0

From **1.25.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

From **1.25.1**:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console assets:install   # nowo-chat.css and nowo-chat.js changed
```

No schema change. Everything is backward compatible:

- **Sidebar rows.** The `sidebar-item` template (block `nowo_chat_template_sidebar`) gained the `status`, `preview` and
  `close` hooks. A host that overrides it may add them (see `_templates.html.twig`); an override without them keeps the
  1.25 look (no custom status, preview or Close button). Bundlers that import the TypeScript rebuild as usual.
- **Phone layout.** `.nowo-chat-pagemode.has-sidebar { grid-template-columns: … }` now sits inside
  `@media (min-width: 769px)`. A host workaround that forced one column on phones (e.g.
  `@media (max-width: 768px) { .nowo-chat-pagemode.has-sidebar { grid-template-columns: minmax(0, 1fr); } }`) can go.
- **Dialog runtime (opt-in).** To have the sidebar actions open as dialogs on every page without adding UiKit's
  `js/nowo-ui-dialog.js` to your layout, set `web_ui.dock.dialog_runtime: true`. Pages that already load it are
  untouched (the dock never loads a second copy).
- **CSRF refresh.** The new route `nowo_chat_api_csrf` (`GET {prefix}/api/csrf`) sits under the bundle's route
  import like the rest of the API. If your `security.yaml` lists the chat API paths one by one, or your application
  allows chat routes by name (e.g. an access catalogue of `nowo_chat_api_*` names), add it with the same access as
  `/api/conversations`; without it the refresh fails closed into the 1.22.1 reload notice. It returns the session's CSRF
  token: never enable credentialed CORS (`Access-Control-Allow-Credentials`) on the chat prefix. The 1.22.1
  `chat.session_stale` notice now shows only when the token cannot be refreshed. The settings screen's
  `saveChatPreference()` and the full-page `markPageRead()` still post with the token they were given (one-shot, no
  refresh).

## To 1.25.1

From **1.25.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

No schema change, no migration, no configuration change.

- **Section providers:** `ChatSidebarSection` throws `InvalidArgumentException` when an item is not a
  `ChatSidebarItem` or an action is not a `ChatSidebarAction`. A provider yielding something that is not a
  `ChatSidebarSection` is refused too. Either way, the bundle logs the error and leaves that provider's sections out;
  the other sections still show. If your logs show `Chat sidebar provider … failed`, fix the provider: it was already
  breaking the whole sidebar endpoint in 1.25.0.
- **`web_ui.page.open_recent`:** focus alone no longer marks the opened conversation read. A pointer, touch, wheel or
  key inside it, or a Tab into it, still does. A host script that moves focus into the chat thread on load no longer
  sends the read receipt for the viewer. Nothing to change.

## To 1.25.0

From **1.24.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

### Target 1.25.0: sidebar sections

From **1.24.x**: `composer update nowo-tech/chat-kit-bundle`. Additive; no schema change, no migration.

- **Requirement:** `nowo-tech/ui-kit-bundle` **^2.33.1** (was ^2.2). The sidebar's action dialogs are UiKit remote form
  dialogs (`remote_form_dialog` partial, multi-step answers and the shared fragment filter of 2.33.1). A host on an
  older UiKit gets a Composer conflict and must upgrade UiKit too; read UiKit's UPGRADING from your version, in
  particular **2.28** (every dialog announces `nowo:overlay-open` / `-close`, and drawers make the shell `inert`).
- **Behaviour unchanged by default.** Without a `ChatSidebarSectionProviderInterface` service and with
  `web_ui.dock.sidebar_sections` and `web_ui.page.open_recent` off (their defaults), the dock configuration keys and the
  behaviour are the 1.24 ones: no `sidebar` / `openRecent` keys, no dialogs, the flat list with its filters. The markup
  is not byte-identical: `_templates.html.twig` always prints the three new inert `<template>`s (block
  `nowo_chat_template_sidebar`), and the `i18n` strings of the dock configuration carry the 8 new `chat.sidebar_*`
  keys.
- **Deliberate layout change once a section provider exists:** the full-page chat (page mode) shows the grouped,
  collapsible sidebar instead of the flat list under **All** without a search: **Recent** (toggle **Unread**), your
  sections, **Direct messages and groups** (every listed conversation none of your sections shows), compact Slack-style
  rows (icon or avatar, name, muted, `@`, unread count) instead of the two-line rows with preview and time, and a wider
  list column (`minmax(280px, 380px)` instead of `minmax(260px, 340px)`). The other filters (Unread, Projects, Support,
  Closed) and the search still list flat, exactly as before. The floating dock keeps the 1.24 list unless you set
  `web_ui.dock.sidebar_sections: true`.
- **Templates:** `_templates.html.twig` gains the block `nowo_chat_template_sidebar` (templates `sidebar-section`,
  `sidebar-item`, `sidebar-action`) and `dock.html.twig` the block `nowo_chat_sidebar_dialogs` (the two dialogs). A
  host that copied `_templates.html.twig` whole keeps the flat list (the script needs `sidebar-section`); one that
  extends it and overrides blocks gets them.
- **Icons:** `nowo_chat_kit.icons` gains `lock` (`tabler:lock`) and `plus` (`tabler:plus`). With `icon_set: ux_icon`
  and a local icon set, add them (or the built-in SVG is used).
- **Browser storage:** folded sections and the Recent / Unread choice are kept per viewer in `localStorage`
  (`nowo-chat-sidebar:<public id>`), cleared with the other chat keys on logout; unavailable storage only means they
  are not remembered.
- **Beacon:** register your Channels section as a provider (see [USAGE](USAGE.md#sidebar-sections-125)); the
  temporary `/dashboard/chat/channels` tabs (`chat/layout.html.twig`) can then go. "Browse channels" and "Create
  channel" are two `ChatSidebarAction`s with a `dialogUrl` served by your `RemoteDialogResponder` controllers.

## To 1.24.0

From **1.23.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target **1.24.0** (from **1.23.x**): `composer update nowo-tech/chat-kit-bundle`.

### Chat room channels (Symfony Beacon spec 184)

No schema change: system lines use the existing nullable `author_id` and the `kind` column, the mute uses the existing
`muted_until` column. Nothing changes until you use the new options, except:

- **New route** `nowo_chat_api_mute` (`POST` / `DELETE /api/conversations/{uuid}/mute`), loaded by the bundle's
  `routes.php` import like the others; the dock configuration's `endpoints` gains `mute`.
- **A bell button in each conversation's header** (`data-part="mute"`, class `nowo-chat-window__mute`). A host that
  copied `nowo_chat_template_window` keeps working without it (the script skips a missing bell); add the button from the
  bundle's block to offer muting. With `icon_set: ux_icon` the icons are `tabler:bell` / `tabler:bell-off`: make sure
  your UX Icons setup can serve them (or map `icons.bell` / `icons.bellOff`).
- **JSON**: list rows and `GET /api/conversations/{uuid}` gain `mutedUntil` (null unless the person muted it); a message
  gains `mentionAll` only when it has one. Clients that compare key lists exactly must allow them.
- **Muted conversations** (rows whose `muted_until` lies in the future, e.g. set by your own code before 1.24) no
  longer count in `unreadTotal` / the launcher badge and ring no sound.
- **New limiter** `nowo_chat_mention_all` (`limits.mention_all_per_hour`), prepended like the others.

Optional, to adopt spec 184:

- Replace a `ChatVoter` decorator that blocks writes in archived rooms with `new ChatChannel(..., readOnly: true)` or
  `ChatChannelWritePolicyInterface` on your channel provider (both enforced server side by the bundle).
- Replace system lines posted as `ChatMessage::bot()` (shown as the support assistant) with
  `ChatService::postSystemLine()`.
- Implement `ChatChannelMentionPolicyInterface` on your channel provider to offer `@channel` / `@here`.
- Set `web_ui.channels_url` for the **Browse or create channels** link; put your own buttons in the
  `nowo_chat_dock_header_actions` / `nowo_chat_window_header_actions` blocks.
- Templates: a copy of `nowo_chat_template_dock` / `nowo_chat_template_window` / the picker misses the new blocks;
  re-copy them from the bundle if you want the hooks.

### DDD / hexagonal ports

Additive; nothing to change unless you adopt them:

- **`ChatServiceInterface`** (alias of `ChatService`): the bundle's controllers and `ChatSupportTickets` now depend on
  the interface. Type-hint it in your code; decorate it to audit or project chat activity. A decorator of the concrete
  `ChatService` service id keeps working but no longer reaches the bundle's controllers — decorate the interface.
- **`CurrentChatUserResolverInterface`** (default `SecurityChatUserResolver`: the security user when it implements
  `ChatUserInterface`, as before). Alias it to your own to map a security user that is a DTO / wrapper to its chat
  participant; the voter, the dock and every chat controller use it.
- **Event DTOs**: every event keeps its entity properties and gains read-only views — `messageView()`
  (`ChatMessageView`), `conversationView()` / `ticketView()` (`ChatConversationView`), `linkView()` (`ChatLinkView`)
  and public-id accessors (`byPublicId()`, `mentionedPublicIds()`, `requesterPublicId()`, `assigneePublicId()`).
- New private `*Ref` properties on the entities (unmapped in the default mode; no schema change) and the getters
  `get<Relation>Reference()`.

### Optional: `doctrine.user_reference: id`

The chat tables stop pointing at your user table: no `resolve_target_entities`, no foreign keys; each user column holds
the user id as text, and users come from your `ChatDirectoryInterface`. Steps:

1. Set `nowo_chat_kit.profiles.<default>.doctrine.user_reference: id` (`user_class` may go).
2. Generate the migration: `bin/console doctrine:migrations:diff`. It drops the foreign keys from the chat tables to
   your user table, changes the eight user columns (`author_id`, `deleted_by_id`, `pinned_by_id` on messages;
   `support_owner_id`, `resolved_by_id`, `assignee_id` on conversations; `created_by_id` on pinned links; `user_id` on
   participants, reactions, mentions, quick replies and user settings) to `VARCHAR(64)` and adds the six indexes listed
   in [CONFIGURATION](CONFIGURATION.md#user-references-124). The values stay the same ids, now as text. Review it:
   on PostgreSQL the type change may need `USING <column>::varchar`; on MySQL the foreign keys must be dropped before
   the columns change (the generated order does that).
3. Call `ChatPersonalData::erase($user)` from your account deletion, **before** the account goes (nothing cascades any
   more). It is mandatory, not only for privacy: without foreign keys, a user id reused later (a manual import, an
   auto-increment reset) would inherit the old account's direct threads, tickets, settings and quick replies. For an
   account that is already gone, call `ChatPersonalData::eraseFormerAccount($userId, $publicId, $displayName)`: the
   id removes its rows, the former chat public id scrubs `@[Name](publicId)` tokens in other people's messages, the
   former display name scrubs bot and system lines. Run it server side only (a cleanup job, a console command), never
   from request input; it refuses an id the directory still returns, and it does not exist in `relation` mode. An
   object that only returns the id (such as `UnresolvedChatUser`)
   leaves the mention tokens and the names behind.
   User ids must be integers (`ChatUserInterface::getId(): ?int`); UUID-keyed users are not supported yet.
4. Code that queried the chat entities with DQL on `author`, `user`, `supportOwner`, … must use the reference fields
   (`authorRef`, `userRef`, …) in this mode, or the bundle's repositories.

Back to `relation`: the reverse migration (integer columns, foreign keys) only works if every stored id still exists
in your user table; erase or delete the orphaned rows first.

## To 1.23.0

From **1.22.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

From **1.22.x**: `composer update nowo-tech/chat-kit-bundle`. No schema, route, configuration or template change.
Phone layout changes (below 768px, and row layout on touch screens); desktop is unchanged:

- **Folded phone launcher (MUX-02).** One round 48px button (`--nowo-chat-fab-size`, never under 44px); the user's own
  status button no longer sits beside it (it is in the open sheet's header). It was ~96 × 59px. CSS that targeted the
  folded `.nowo-chat-me` or relied on the 40px icon / 8px launcher padding should be checked.
- **Reserve the dock's space (MUX-02).** While the floating dock is shown, `<body>` has the class
  `nowo-chat-dock-shown` and `<html>` the token `--nowo-chat-dock-reserve` (measured height of the folded dock + 8px).
  Nothing changes until you use it: add `padding-bottom: var(--nowo-chat-dock-reserve, 0px)` to the element that
  scrolls, or put `data-nowo-chat-dock-reserve` on it. Remove any fixed bottom padding you added for the dock.
- **Open phone sheet above the host (MUX-91).** While the list or a conversation is open on a phone the root has
  `is-sheet-open` and `z-index: var(--nowo-chat-sheet-z-index, 1000)`, so a fixed host header no longer covers its title
  and close button. Hosts with a fixed header above 1000 should lower it or raise the token.
- **Reconnecting notice (MUX-08).** On phones, with the dock folded, the notice is a pill at the top of the screen
  (`--nowo-chat-status-top`) instead of under the launcher; the root gets `is-reconnecting` while the live connection is
  down and the launcher shows a warning ring. It keeps `role="status"`.
- **List rows (MUX-25).** On phones and touch screens (`(max-width: 768px), (hover: none)`) each row `<li>` is a two-column
  grid: the Close / Reopen button has its own column instead of covering the time and badge; tag and time are 12px.
  Host CSS that positioned `.nowo-chat-row__archive` on phones should be removed.
- A demo e2e or visual test that measured the folded phone launcher should expect 48px.

## To 1.22.1

From **1.22.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

No configuration or schema change. A host that overrides the bundle's strings may translate the new key `chat.session_stale` (domain `NowoChatKitBundle`): the notice the dock keeps after the server refused its CSRF token (403 `invalid_csrf`), asking for a reload. After that refusal the dock sends no heartbeat and no write until the page is reloaded.

## To 1.22.0

From **1.21.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

The close now waits for its fade: the `hidden` state, `dialog.close()` or removal comes up to 150 ms later than before, and a test should wait for the closed state or set the durations to `0ms`. With no transition (`0ms`, or a page without the bundle's CSS) it all happens at once, as before. Durations come from UiKit's `--nowo-ui-motion-*` tokens with fallbacks here, so no UiKit release is needed.

## To 1.21.0

From **1.20.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

From **1.20.x**: `composer update nowo-tech/chat-kit-bundle`. No schema or route change. A host that sets nothing gets
the same pages. A new channel's row is now written through DBAL in a transaction, so a Doctrine listener on
`ChatConversation` no longer fires for it. Optional, to delete host code:

- A `web_ui.templates.page` override that only emptied `nowo_ui_styles` / `nowo_chat_page_script`: remove it and set
  `web_ui.assets: false`. Keep passing `nowo_chat_assets: false` to the dock on your other pages.
- A route or `kernel.controller` listener that limited `nowo_chat_api_open_channel`: move it to
  `ChatChannelOpeningEvent` and call `deny($reason)`. Anything that read the old 403 body now gets `error: channel_denied`.
  For an exact limit, lock your plan row with `SELECT … FOR UPDATE` before counting.
- Deep imports of `src/prefs`, `src/sounds` or `src/types`: import `src/public`.
- Host CSS that stretched `.nowo-chat-page`: remove it and set `web_ui.page.full_height: true`, then check the page in
  your shell, since it needs a bounded content area.

## To 1.20.0

From **1.19.x**: `composer update nowo-tech/chat-kit-bundle`. No schema, route or configuration change. **Visual change
(dialog rule v2):**

- **Link dialog** (pinned links, attach a link): medium width `var(--nowo-dialog-width-md, min(40rem, 100vw − 2rem))`
  (was 440px; on phones the screen width minus 2rem, was minus 16px), height = content, capped at
  `min(var(--nowo-dialog-height-md, 36rem), 100dvh − 2rem)`. Declare UiKit's `--nowo-dialog-width-md|wide` /
  `--nowo-dialog-height-md|wide` on `:root` to size it with your other dialogs. CSS that set the width or `max-height`
  of `.nowo-chat-linkdialog` should be removed (the sizes live on `.nowo-chat-dialog` / `.nowo-chat-dialog--md`).
- **Status menu template** (`nowo_chat_template_status_menu`): the footer has a Cancel button
  (`<button type="button" class="nowo-chat-status-form__cancel" data-part="status-cancel">`) between Clear and Save. An
  overridden block without it keeps working (the button is looked up only when present); copy it to follow the
  anatomy. Header and footer are tinted from `--nowo-chat-mist`.

## To 1.19.0

From **1.18.x**: `composer update nowo-tech/chat-kit-bundle`, then **create the two new tables** and
index the existing messages.

1. Schema: generate a migration (`bin/console doctrine:migrations:diff`) or apply the SQL below. Names shown with the
   default `doctrine.table_name_prefix` / `table_prefix`; with custom prefixes, generate the migration instead. Replace
   `app_user` with your user table (`user_class`).

   MySQL / MariaDB:

   ```sql
   CREATE TABLE nowo_chat_conversation_link (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, host VARCHAR(255) NOT NULL, title VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, uuid VARCHAR(36) NOT NULL, conversation_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_nowo_chat_conv_link_conv (conversation_id), UNIQUE INDEX uniq_nowo_chat_conv_link_uuid (uuid), INDEX IDX_C437798CB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
   CREATE TABLE nowo_chat_message_link (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, host VARCHAR(255) NOT NULL, title VARCHAR(120) DEFAULT NULL, kind VARCHAR(8) NOT NULL, provider VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, message_id INT NOT NULL, INDEX idx_nowo_chat_message_link_msg (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
   ALTER TABLE nowo_chat_conversation_link ADD CONSTRAINT FK_C437798C9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE;
   ALTER TABLE nowo_chat_conversation_link ADD CONSTRAINT FK_C437798CB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL;
   ALTER TABLE nowo_chat_message_link ADD CONSTRAINT FK_C28F783E537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE;
   ```

   PostgreSQL:

   ```sql
   CREATE TABLE nowo_chat_conversation_link (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, url VARCHAR(2048) NOT NULL, host VARCHAR(255) NOT NULL, title VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, uuid VARCHAR(36) NOT NULL, conversation_id INT NOT NULL, created_by_id INT DEFAULT NULL, PRIMARY KEY (id));
   CREATE INDEX idx_nowo_chat_conv_link_conv ON nowo_chat_conversation_link (conversation_id);
   CREATE UNIQUE INDEX uniq_nowo_chat_conv_link_uuid ON nowo_chat_conversation_link (uuid);
   CREATE INDEX IDX_C437798CB03A8386 ON nowo_chat_conversation_link (created_by_id);
   CREATE TABLE nowo_chat_message_link (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, url VARCHAR(2048) NOT NULL, host VARCHAR(255) NOT NULL, title VARCHAR(120) DEFAULT NULL, kind VARCHAR(8) NOT NULL, provider VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, message_id INT NOT NULL, PRIMARY KEY (id));
   CREATE INDEX idx_nowo_chat_message_link_msg ON nowo_chat_message_link (message_id);
   ALTER TABLE nowo_chat_conversation_link ADD CONSTRAINT FK_C437798C9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE NOT DEFERRABLE;
   ALTER TABLE nowo_chat_conversation_link ADD CONSTRAINT FK_C437798CB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE;
   ALTER TABLE nowo_chat_message_link ADD CONSTRAINT FK_C28F783E537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE NOT DEFERRABLE;
   ```

   SQLite:

   ```sql
   CREATE TABLE nowo_chat_conversation_link (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, host VARCHAR(255) NOT NULL, title VARCHAR(120) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, uuid VARCHAR(36) NOT NULL, conversation_id INTEGER NOT NULL, created_by_id INTEGER DEFAULT NULL, CONSTRAINT FK_C437798C9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C437798CB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE);
   CREATE INDEX idx_nowo_chat_conv_link_conv ON nowo_chat_conversation_link (conversation_id);
   CREATE UNIQUE INDEX uniq_nowo_chat_conv_link_uuid ON nowo_chat_conversation_link (uuid);
   CREATE INDEX IDX_C437798CB03A8386 ON nowo_chat_conversation_link (created_by_id);
   CREATE TABLE nowo_chat_message_link (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(2048) NOT NULL, host VARCHAR(255) NOT NULL, title VARCHAR(120) DEFAULT NULL, kind VARCHAR(8) NOT NULL, provider VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, message_id INTEGER NOT NULL, CONSTRAINT FK_C28F783E537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
   CREATE INDEX idx_nowo_chat_message_link_msg ON nowo_chat_message_link (message_id);
   ```

   The new table keys `conversation_link` and `message_link` are published in `nowo_chat_kit.doctrine.tables` (and
   `nowo_chat_kit.doctrine.table.<key>`) for host SQL.

2. Index the links of the existing messages once (safe to run again; `--after-id` resumes, `--batch` sets the batch
   size): `bin/console nowo:chat:links:backfill`. Run it again after you change `links.providers` (the provider of each
   indexed link is stored when it is indexed).

3. Nothing else is required: the feature is on by default (`links.enabled`). New: `links.max_pinned` (20),
   `links.allow_http` (false), `links.channel_managers` (`members`), `links.providers`; the dock icons `links`, `video`
   and `provider_<key>` (UX Icons, built-in fallbacks). A host that overrides `_templates.html.twig` needs no change: the
   links bar, the header **Links** button and the composer **Attach link** button are added by the script.

4. Privacy and retention: `ChatPersonalData::erase()` **deletes the pinned links the user created** in every
   conversation (dispatching `ChatLinkRemovedEvent` with `by` = the erased user, and the `links` broadcast) — they may
   carry personal data such as a 1:1 room or a meeting password. `nowo:chat:purge` now also deletes pinned links whose
   last change (else their creation) is older than `retention_days` (no event; open bars refresh on their next load).
   Markdown links and autolinks gain `noreferrer` in their `rel` (the stored HTML of older messages keeps the old value
   until it is edited).

5. Behaviour to know: `!link[Title](https://…)` in a message body is now a link card (a token whose link is not
   accepted makes the send / edit fail with `422 invalid_link`); the message HTML gains `a.nowo-chat-linkcard`, which
   your own copies of message HTML (search index, e-mail) can keep or reduce to the title (`ChatMarkdownRenderer::plainText()`
   does).

## To 1.18.0

From **1.17.x**: `composer update nowo-tech/chat-kit-bundle`. No schema change, no route change, no configuration change.

- **Status menu template** (`nowo_chat_template_status_menu`, UiKit 2.29 dialog anatomy): the Clear and Save buttons
  moved out of the form into `<footer class="nowo-chat-status-menu__footer">` (Save keeps `type="submit"` with
  `form="nowo-chat-status-form"`; the form gets that id), the options and the form sit in
  `.nowo-chat-status-menu__body`, the title is an `<h2>` in `.nowo-chat-status-menu__header` with a close button
  (`data-part="status-close"`, optional). A host that overrides the block keeps working as before (the close button is
  looked up only when present); to follow the anatomy, copy the new block. CSS that styled the menu's padding or scroll
  on `.nowo-chat-status-menu` itself now applies to `.nowo-chat-status-menu__body`.
- The automatic reply's save button is `.nowo-chat-status-form__secondary` (was `.nowo-chat-status-form__save`); the
  delete confirmation renders Cancel before Delete. Selectors on `data-part` are unchanged.
- **Erasure:** `ChatPersonalData::erase()` now runs in one transaction (nested in yours, if any), flushes the entity
  manager when it rewrote a mention (pending changes of yours are written with it) and dispatches
  `ChatMentionsErasedEvent` (before the `message_updated` broadcast, which now only logs a failure). It returns the
  `ChatMentionErasure` instead of `void`. Listen to the event to re-index the rewritten messages and to report
  `skippedMessageIds` (text that could not be scanned; fix or delete those messages, then call `eraseMentions()` again);
  do not log its `publicId`. Call it on a fresh (or flushed and cleared) entity manager, and clear it after a call that
  threw. To scrub accounts erased before 1.15.2, call `eraseMentions($user)`.
- **Host drawers:** replace hand-written `nowo:overlay-*` events with `window.NowoChat.acquireOverlay(drawer)` /
  `handle.release()` (or UiKit's `window.NowoUiOverlay.acquire()`), so a UiKit dialog opened over the drawer does not
  bring the dock back early ([USAGE](USAGE.md#stacking-with-drawers-and-modals)).

## To 1.17.0

From **1.16.x**:

```bash
composer update nowo-tech/chat-kit-bundle
```

- No schema change, no route change, no PHP signature change.
- **New default behaviour, only with overlays that announce themselves:** while a modal overlay is open the floating
  dock hides (`dock.overlay: hide`). It reacts to the `nowo:overlay-open` / `nowo:overlay-close` events and to
  `html[data-nowo-overlay]`, which UiKit 2.28 (dialogs, overlay side panels, `window.NowoUiOverlay`) and any host script
  can emit. Nothing emitted them before, so a page without such overlays behaves exactly as 1.16.
- Hosts that hid the dock with their own CSS (e.g. `html:has(.my-drawer.is-open) .nowo-chat { visibility: hidden; }` or
  a lower `--nowo-chat-z-index`) may keep it or drop it once their drawers emit the events (or call
  `NowoUiOverlay.acquire()`); both give the same result.
- To keep 1.16 behaviour: `dock: { overlay: none }` in the profile. To keep the dock painted but out of reach:
  `overlay: inert`. For overlays that emit nothing: `watch_aria_modal: true`.

```yaml
nowo_chat_kit:
    profiles:
        default:
            dock:
                overlay: hide            # hide | inert | none
                watch_aria_modal: false
```

## To 1.16.1

From **1.16.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.16.0 (profile pictures)

From **1.15.3**:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console assets:install public
```

- Nothing changes until you alias `Nowo\ChatKitBundle\Contract\ChatAvatarUrlResolverInterface` (see
  [USAGE.md](USAGE.md#profile-pictures)). With the default resolver every payload gains `null` fields (`avatarUrl`,
  `authorAvatarUrl`, `me.avatarUrl`) and nothing else changes.
- Return stable, access-checked URLs (`https:` or a same-origin `/path`). A pre-signed URL travels in Mercure events
  and stays in the page, so it is not supported. Allow the picture origins in your `img-src` CSP.
- Rejected values (`http:`, `data:`, relative paths without a leading `/`, protocol-relative `//cdn…` URLs from an asset
  package, IPv6-literal hosts, quotes, more than 2048 bytes …) silently fall back to the initials.
- A resolver must not run a query per user: `urlsFor()` gets every person of a response at once.
- Overridden templates need no change. The pictures are added by `nowo-chat.js` inside the existing avatar slots.
  Custom CSS on `.nowo-chat-avatar` should keep `position: relative` (the picture is absolutely positioned).

## To 1.15.3

From **1.15.2**:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console assets:install public
```

- No schema change and no configuration change. Re-publish the assets (`nowo-chat.css`) if you copy them instead of
  symlinking them.
- **Launcher on phones:** hosts that worked around the missing accessible name of the folded launcher (an override
  that keeps `.nowo-chat-launcher__label` in the accessibility tree, or an `aria-label` on the button) can drop it.
  If you override the template, keep the `.nowo-chat-launcher__label` text and add `aria-hidden="true"` to
  `.nowo-chat-launcher__icon`.
- **Moderated removals:** `ChatMessage::getMeta()` can now hold `moderated: true` on a deleted message of any kind (it
  was only filled for bot and pin lines). Code that treats a non-null `meta` as "bot message" should check
  `isBot()` instead. Removals made before this release keep their state only while the moderator's `deletedBy`
  pointer exists; `ChatPersonalData::erase()` stores the flag for them before nulling it, but deleting the moderator's
  user row directly (`onDelete: SET NULL`) does not. Run `erase()` for a moderator before deleting the row.
- The conversation payload's `lastMessage` has a new `moderated` boolean (additive).

## To 1.15.2

From **1.15.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

- **symfony/mercure 0.8+ (0.9 included):** subscriber tokens work again. The bundle's JS client still uses the Legacy
  hub protocol (`?topic=` / `?authorization=`). With the V1 protocol, the default since symfony/mercure 0.8, the token
  factory requires `iss`, `aud`, `sub` and `client_id`; if they are not configured, `/realtime` answers 500. Keep the
  hub on the Legacy protocol, or configure those claims.

## To 1.15.0

From **1.14.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

New option `profiles.<name>.doctrine.table_name_prefix` (default `nowo_chat_`): it **replaces** the `nowo_chat_` part
of every table, index and unique-constraint name. With the default nothing changes: every table, index, unique and
foreign-key name stays byte-identical, so no migration is needed. The existing `doctrine.table_prefix` keeps its
meaning and is prepended to the result (`table_name_prefix: chat_` + `table_prefix: app_` gives `app_chat_message`,
`app_idx_chat_message_created`).

**Validation (both options):** a prefix must match `/^[a-z][a-z0-9_]*\z/` (lowercase letter first, then lowercase
letters, digits and `_`; `table_prefix` may stay empty), and `table_prefix` + `table_name_prefix` may not make any
table, index, unique or PostgreSQL sequence name longer than 63 characters (at most 38 characters together). The
container build fails otherwise, and also on a `%env()%` value (not supported: the names are part of the schema). `doctrine` options are
global: a non-default profile that sets a different `doctrine.*` value now fails the container build instead of being
silently ignored (repeating the default profile's value is allowed).

**Switching an existing database to another `table_name_prefix`** renames the tables, the named indexes and unique
constraints, and the Doctrine-generated `IDX_*` / `FK_*` names (Doctrine derives them from the table name). Run the
statements below in a migration (they were checked against MySQL 8.0 and PostgreSQL 17: the renamed schema matches a
fresh schema created with the new prefix). They switch from `nowo_chat_` to `chat_` with no `table_prefix`; `user` is
your user table. For another prefix, replace `chat_` and take the new `IDX_*` / `FK_*` names from
`php bin/console doctrine:schema:update --dump-sql` on an empty database.

Before you run it:

- **Check that no table of the host already uses one of the new names** (`chat_message`, …): `RENAME TABLE` fails on a
  clash, and a later `doctrine:schema:update` would otherwise mix both.
- **Take a backup and plan downtime.** On MySQL / MariaDB DDL is **not transactional**: every statement commits on its
  own, so a failure half-way leaves a partly renamed schema. Put the application in maintenance mode and stop the
  Messenger workers (digest, push) for the window; keep `FOREIGN_KEY_CHECKS=1`. In a Doctrine migration declare
  `isTransactional(): false` on MySQL. Deploy the `table_name_prefix` change **together with** the migration: the
  old code reads the old names, the new code the new ones.
- On **PostgreSQL** DDL is transactional: run the whole block in one transaction (a Doctrine migration does that by
  default), so it applies completely or not at all.

MySQL 8 / MariaDB ≥ 10.5.2 (foreign keys cannot be renamed, so they are dropped and re-created):

```sql
RENAME TABLE
    nowo_chat_conversation TO chat_conversation,
    nowo_chat_message TO chat_message,
    nowo_chat_message_mention TO chat_message_mention,
    nowo_chat_participant TO chat_participant,
    nowo_chat_quick_reply TO chat_quick_reply,
    nowo_chat_reaction TO chat_reaction,
    nowo_chat_user_settings TO chat_user_settings;
ALTER TABLE chat_conversation
    DROP FOREIGN KEY FK_D7385CFF59EC7D60,
    DROP FOREIGN KEY FK_D7385CFF6713A32B,
    DROP FOREIGN KEY FK_D7385CFFCA8AEAC6;
ALTER TABLE chat_message
    DROP FOREIGN KEY FK_A40D82CC59662AC1,
    DROP FOREIGN KEY FK_A40D82CC9AC0396,
    DROP FOREIGN KEY FK_A40D82CCC76F1F52,
    DROP FOREIGN KEY FK_A40D82CCF675F31B;
ALTER TABLE chat_message_mention
    DROP FOREIGN KEY FK_9DF33B9B537A1329,
    DROP FOREIGN KEY FK_9DF33B9BA76ED395;
ALTER TABLE chat_participant
    DROP FOREIGN KEY FK_9FE7FEB9AC0396,
    DROP FOREIGN KEY FK_9FE7FEBA76ED395;
ALTER TABLE chat_quick_reply
    DROP FOREIGN KEY FK_7B6D659EA76ED395;
ALTER TABLE chat_reaction
    DROP FOREIGN KEY FK_F6AD5573537A1329,
    DROP FOREIGN KEY FK_F6AD5573A76ED395;
ALTER TABLE chat_user_settings
    DROP FOREIGN KEY FK_F14147EEA76ED395;
ALTER TABLE chat_conversation
    RENAME INDEX IDX_D7385CFF6713A32B TO IDX_74654F686713A32B,
    RENAME INDEX IDX_D7385CFFCA8AEAC6 TO IDX_74654F68CA8AEAC6,
    RENAME INDEX idx_nowo_chat_conversation_activity TO idx_chat_conversation_activity,
    RENAME INDEX idx_nowo_chat_conversation_assignee TO idx_chat_conversation_assignee,
    RENAME INDEX idx_nowo_chat_conversation_support TO idx_chat_conversation_support,
    RENAME INDEX uniq_nowo_chat_conversation_channel TO uniq_chat_conversation_channel,
    RENAME INDEX uniq_nowo_chat_conversation_direct TO uniq_chat_conversation_direct,
    RENAME INDEX uniq_nowo_chat_conversation_ticket TO uniq_chat_conversation_ticket,
    RENAME INDEX uniq_nowo_chat_conversation_uuid TO uniq_chat_conversation_uuid;
ALTER TABLE chat_message
    RENAME INDEX IDX_A40D82CC59662AC1 TO IDX_FAB3FC1659662AC1,
    RENAME INDEX IDX_A40D82CC9AC0396 TO IDX_FAB3FC169AC0396,
    RENAME INDEX IDX_A40D82CCC76F1F52 TO IDX_FAB3FC16C76F1F52,
    RENAME INDEX IDX_A40D82CCF675F31B TO IDX_FAB3FC16F675F31B,
    RENAME INDEX idx_nowo_chat_message_created TO idx_chat_message_created,
    RENAME INDEX idx_nowo_chat_message_unread TO idx_chat_message_unread,
    RENAME INDEX uniq_nowo_chat_message_uuid TO uniq_chat_message_uuid;
ALTER TABLE chat_message_mention
    RENAME INDEX IDX_9DF33B9B537A1329 TO IDX_119AF996537A1329,
    RENAME INDEX IDX_9DF33B9BA76ED395 TO IDX_119AF996A76ED395,
    RENAME INDEX idx_nowo_chat_mention_user TO idx_chat_mention_user,
    RENAME INDEX uniq_nowo_chat_mention TO uniq_chat_mention;
ALTER TABLE chat_participant
    RENAME INDEX IDX_9FE7FEB9AC0396 TO IDX_E8ED9C899AC0396,
    RENAME INDEX idx_nowo_chat_participant_user TO idx_chat_participant_user,
    RENAME INDEX uniq_nowo_chat_participant TO uniq_chat_participant;
ALTER TABLE chat_quick_reply
    RENAME INDEX idx_nowo_chat_quick_reply_user TO idx_chat_quick_reply_user;
ALTER TABLE chat_reaction
    RENAME INDEX IDX_F6AD5573537A1329 TO IDX_90F5D0C7537A1329,
    RENAME INDEX IDX_F6AD5573A76ED395 TO IDX_90F5D0C7A76ED395,
    RENAME INDEX uniq_nowo_chat_reaction TO uniq_chat_reaction;
ALTER TABLE chat_user_settings
    RENAME INDEX uniq_nowo_chat_user_settings_user TO uniq_chat_user_settings_user;
ALTER TABLE chat_conversation
    ADD CONSTRAINT FK_74654F6859EC7D60 FOREIGN KEY (assignee_id) REFERENCES user (id) ON DELETE SET NULL,
    ADD CONSTRAINT FK_74654F686713A32B FOREIGN KEY (resolved_by_id) REFERENCES user (id) ON DELETE SET NULL,
    ADD CONSTRAINT FK_74654F68CA8AEAC6 FOREIGN KEY (support_owner_id) REFERENCES user (id) ON DELETE CASCADE;
ALTER TABLE chat_message
    ADD CONSTRAINT FK_FAB3FC1659662AC1 FOREIGN KEY (pinned_by_id) REFERENCES user (id) ON DELETE SET NULL,
    ADD CONSTRAINT FK_FAB3FC169AC0396 FOREIGN KEY (conversation_id) REFERENCES chat_conversation (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_FAB3FC16C76F1F52 FOREIGN KEY (deleted_by_id) REFERENCES user (id) ON DELETE SET NULL,
    ADD CONSTRAINT FK_FAB3FC16F675F31B FOREIGN KEY (author_id) REFERENCES user (id) ON DELETE SET NULL;
ALTER TABLE chat_message_mention
    ADD CONSTRAINT FK_119AF996537A1329 FOREIGN KEY (message_id) REFERENCES chat_message (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_119AF996A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
ALTER TABLE chat_participant
    ADD CONSTRAINT FK_E8ED9C899AC0396 FOREIGN KEY (conversation_id) REFERENCES chat_conversation (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_E8ED9C89A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
ALTER TABLE chat_quick_reply
    ADD CONSTRAINT FK_9A7E86FCA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
ALTER TABLE chat_reaction
    ADD CONSTRAINT FK_90F5D0C7537A1329 FOREIGN KEY (message_id) REFERENCES chat_message (id) ON DELETE CASCADE,
    ADD CONSTRAINT FK_90F5D0C7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
ALTER TABLE chat_user_settings
    ADD CONSTRAINT FK_9F891C1AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
```

PostgreSQL (the identity sequences and primary keys are renamed too so the names match a fresh install):

```sql
ALTER TABLE nowo_chat_conversation RENAME TO chat_conversation;
ALTER SEQUENCE nowo_chat_conversation_id_seq RENAME TO chat_conversation_id_seq;
ALTER INDEX nowo_chat_conversation_pkey RENAME TO chat_conversation_pkey;
ALTER TABLE nowo_chat_message RENAME TO chat_message;
ALTER SEQUENCE nowo_chat_message_id_seq RENAME TO chat_message_id_seq;
ALTER INDEX nowo_chat_message_pkey RENAME TO chat_message_pkey;
ALTER TABLE nowo_chat_message_mention RENAME TO chat_message_mention;
ALTER SEQUENCE nowo_chat_message_mention_id_seq RENAME TO chat_message_mention_id_seq;
ALTER INDEX nowo_chat_message_mention_pkey RENAME TO chat_message_mention_pkey;
ALTER TABLE nowo_chat_participant RENAME TO chat_participant;
ALTER SEQUENCE nowo_chat_participant_id_seq RENAME TO chat_participant_id_seq;
ALTER INDEX nowo_chat_participant_pkey RENAME TO chat_participant_pkey;
ALTER TABLE nowo_chat_quick_reply RENAME TO chat_quick_reply;
ALTER SEQUENCE nowo_chat_quick_reply_id_seq RENAME TO chat_quick_reply_id_seq;
ALTER INDEX nowo_chat_quick_reply_pkey RENAME TO chat_quick_reply_pkey;
ALTER TABLE nowo_chat_reaction RENAME TO chat_reaction;
ALTER SEQUENCE nowo_chat_reaction_id_seq RENAME TO chat_reaction_id_seq;
ALTER INDEX nowo_chat_reaction_pkey RENAME TO chat_reaction_pkey;
ALTER TABLE nowo_chat_user_settings RENAME TO chat_user_settings;
ALTER SEQUENCE nowo_chat_user_settings_id_seq RENAME TO chat_user_settings_id_seq;
ALTER INDEX nowo_chat_user_settings_pkey RENAME TO chat_user_settings_pkey;
ALTER INDEX IDX_D7385CFF6713A32B RENAME TO IDX_74654F686713A32B;
ALTER INDEX IDX_D7385CFFCA8AEAC6 RENAME TO IDX_74654F68CA8AEAC6;
ALTER INDEX idx_nowo_chat_conversation_activity RENAME TO idx_chat_conversation_activity;
ALTER INDEX idx_nowo_chat_conversation_assignee RENAME TO idx_chat_conversation_assignee;
ALTER INDEX idx_nowo_chat_conversation_support RENAME TO idx_chat_conversation_support;
ALTER INDEX uniq_nowo_chat_conversation_channel RENAME TO uniq_chat_conversation_channel;
ALTER INDEX uniq_nowo_chat_conversation_direct RENAME TO uniq_chat_conversation_direct;
ALTER INDEX uniq_nowo_chat_conversation_ticket RENAME TO uniq_chat_conversation_ticket;
ALTER INDEX uniq_nowo_chat_conversation_uuid RENAME TO uniq_chat_conversation_uuid;
ALTER INDEX IDX_A40D82CC59662AC1 RENAME TO IDX_FAB3FC1659662AC1;
ALTER INDEX IDX_A40D82CC9AC0396 RENAME TO IDX_FAB3FC169AC0396;
ALTER INDEX IDX_A40D82CCC76F1F52 RENAME TO IDX_FAB3FC16C76F1F52;
ALTER INDEX IDX_A40D82CCF675F31B RENAME TO IDX_FAB3FC16F675F31B;
ALTER INDEX idx_nowo_chat_message_created RENAME TO idx_chat_message_created;
ALTER INDEX idx_nowo_chat_message_unread RENAME TO idx_chat_message_unread;
ALTER INDEX uniq_nowo_chat_message_uuid RENAME TO uniq_chat_message_uuid;
ALTER INDEX IDX_9DF33B9B537A1329 RENAME TO IDX_119AF996537A1329;
ALTER INDEX IDX_9DF33B9BA76ED395 RENAME TO IDX_119AF996A76ED395;
ALTER INDEX idx_nowo_chat_mention_user RENAME TO idx_chat_mention_user;
ALTER INDEX uniq_nowo_chat_mention RENAME TO uniq_chat_mention;
ALTER INDEX IDX_9FE7FEB9AC0396 RENAME TO IDX_E8ED9C899AC0396;
ALTER INDEX idx_nowo_chat_participant_user RENAME TO idx_chat_participant_user;
ALTER INDEX uniq_nowo_chat_participant RENAME TO uniq_chat_participant;
ALTER INDEX idx_nowo_chat_quick_reply_user RENAME TO idx_chat_quick_reply_user;
ALTER INDEX IDX_F6AD5573537A1329 RENAME TO IDX_90F5D0C7537A1329;
ALTER INDEX IDX_F6AD5573A76ED395 RENAME TO IDX_90F5D0C7A76ED395;
ALTER INDEX uniq_nowo_chat_reaction RENAME TO uniq_chat_reaction;
ALTER INDEX uniq_nowo_chat_user_settings_user RENAME TO uniq_chat_user_settings_user;
ALTER TABLE chat_conversation RENAME CONSTRAINT FK_D7385CFF59EC7D60 TO FK_74654F6859EC7D60;
ALTER TABLE chat_conversation RENAME CONSTRAINT FK_D7385CFF6713A32B TO FK_74654F686713A32B;
ALTER TABLE chat_conversation RENAME CONSTRAINT FK_D7385CFFCA8AEAC6 TO FK_74654F68CA8AEAC6;
ALTER TABLE chat_message RENAME CONSTRAINT FK_A40D82CC59662AC1 TO FK_FAB3FC1659662AC1;
ALTER TABLE chat_message RENAME CONSTRAINT FK_A40D82CC9AC0396 TO FK_FAB3FC169AC0396;
ALTER TABLE chat_message RENAME CONSTRAINT FK_A40D82CCC76F1F52 TO FK_FAB3FC16C76F1F52;
ALTER TABLE chat_message RENAME CONSTRAINT FK_A40D82CCF675F31B TO FK_FAB3FC16F675F31B;
ALTER TABLE chat_message_mention RENAME CONSTRAINT FK_9DF33B9B537A1329 TO FK_119AF996537A1329;
ALTER TABLE chat_message_mention RENAME CONSTRAINT FK_9DF33B9BA76ED395 TO FK_119AF996A76ED395;
ALTER TABLE chat_participant RENAME CONSTRAINT FK_9FE7FEB9AC0396 TO FK_E8ED9C899AC0396;
ALTER TABLE chat_participant RENAME CONSTRAINT FK_9FE7FEBA76ED395 TO FK_E8ED9C89A76ED395;
ALTER TABLE chat_quick_reply RENAME CONSTRAINT FK_7B6D659EA76ED395 TO FK_9A7E86FCA76ED395;
ALTER TABLE chat_reaction RENAME CONSTRAINT FK_F6AD5573537A1329 TO FK_90F5D0C7537A1329;
ALTER TABLE chat_reaction RENAME CONSTRAINT FK_F6AD5573A76ED395 TO FK_90F5D0C7A76ED395;
ALTER TABLE chat_user_settings RENAME CONSTRAINT FK_F14147EEA76ED395 TO FK_9F891C1AA76ED395;
```

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

`nowo-tech/form-kit-bundle` is now required at `^2.17` (was `^2.16`); update both packages together:

```bash
composer update nowo-tech/chat-kit-bundle nowo-tech/form-kit-bundle
```

No configuration or template change: the rendered forms are the same. `FormKitFieldTrait` (an internal helper of the bundle form types) is removed; it was never part of the public API.

## To 1.14.0

From **1.13.2**:

```bash
composer update nowo-tech/chat-kit-bundle
```

- **New required dependency `nowo-tech/form-kit-bundle` (`^2.16`, REQ-TWIG-005 rule 11):** register `Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true]` in `config/bundles.php` (Symfony Flex does it on `composer update`). The chat form types get their field options from FormKit; without the FormKit bundle registered they fail with an uninitialized `FormOptionsMerger`.
- **Form theme through the FormKit profile:** field classes and translation domain of the chat forms come from `nowo_form_kit.profiles.chat_kit` (prepended by the bundle; see [CONFIGURATION](CONFIGURATION.md#formkit-profile)). The rendered markup is unchanged; to add field classes or change the domain, define the `chat_kit` profile in `config/packages/nowo_form_kit.yaml` instead of overriding the types.

## To 1.13.2

From **1.13.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

The chat routes are only registered by the documented import of `@NowoChatKitBundle/Resources/config/routes.php`
(with your prefix, e.g. `/chat`). On Symfony >= 7.3 a host without that import got them through
`routing.controllers`, unprefixed; add the import if you relied on it.

## To 1.13.0

From **1.12.0**: `composer update nowo-tech/chat-kit-bundle`, then `php bin/console assets:install` (new script and
stylesheet). No configuration or schema change.

The full-page chat now runs in **page mode**: the dock's list panel and one conversation, inline in the page, with every
option of the dock. What a host has to check:

- **The dock must be on the chat page.** Page mode is the dock script mounted on the dock root (`dock.html.twig`, which
  carries the configuration and the `<template>` markup). Include the dock in the layout the page renders in, as for
  every signed-in page; without it the page stays the server-rendered one (it keeps working, without the dock options).
- **IIFE hosts** (the bundle's `nowo-chat.js`): nothing to do; the automatic start finds the page and mounts in page mode.
- **Hosts that mount the dock themselves** (`data-nowo-chat-manual`, a Stimulus controller importing the TypeScript
  source): `mount(root)` starts page mode by itself when the document holds `[data-nowo-chat-page-mode]`, so an existing
  `connect() { mount(this.element) }` / `disconnect() { unmount(this.element) }` keeps working. To be explicit, call
  `mountPage(root, page)` or `mount(root, { page })` (page = `findChatPage()` or your own element); pass
  `{ page: null }` to keep the floating dock on that page. `markPageRead()` can stay: page mode marks the conversation
  read itself, and the page's read attributes are consumed once.
- **Overrides of `page.html.twig`** (`web_ui.templates.page` extending `@!NowoChatKitBundle/page.html.twig`): keep the
  element that carries `data-nowo-chat-page-mode` (and its `data-nowo-chat-current` / `data-nowo-chat-archived`), i.e.
  do not redefine `nowo_ui_content` without `{{ parent() }}`. Overrides of `nowo_chat_page_list` / `nowo_chat_page_thread`
  only change the page without JavaScript now (page mode hides them); restyle the page mode through the dock templates
  (`_templates.html.twig`) and the `--nowo-chat-*` tokens instead. A wrapper around `parent()` (e.g. one that attached a
  Markdown toolbar to `.nowo-chat-page__composer textarea`) is harmless but only affects the no-JS form; the dock
  composer has its own formatting toolbar.
- **Styles**: the page-mode height is `--nowo-chat-page-height` (default `max(28rem, 100dvh - 14rem)`); set it on
  `.nowo-chat-pagemode` to fit your layout's header and footer.
- **End-to-end tests** that clicked `.nowo-chat-page__row` or read `.nowo-chat-page__header` / the composer form with
  JavaScript on: use the page-mode markup (`[data-testid="nowo-chat-page-mode"]`, `.nowo-chat-pagemode .nowo-chat-row`,
  `.nowo-chat-window[role="region"]`), or run them with JavaScript disabled to test the fallback. The floating dock is
  hidden on the chat page (`.nowo-chat-dock` is not visible there).

## To 1.12.0

From **1.11.3**: `composer update nowo-tech/chat-kit-bundle`, then `php bin/console assets:install` (new script and
stylesheet). No schema change.

- The status menu has a **More statuses** drop-down and the suggestions come from the new `status_presets` option (see
  [CONFIGURATION](CONFIGURATION.md)). To change them, set `quick` and / or `more` in your profile (a list you set
  replaces the default one; `more: []` hides the drop-down); labels are translation keys of the `NowoChatKitBundle`
  domain (add your own keys to that domain) or plain texts.
- If you redefined `nowo_chat_template_status_menu`, render the suggestion buttons from
  `nowo_chat_status_form.vars.status_presets` (`emoji`, `text`, `clear_after`) and add the drop-down
  (`{{ form_label(nowo_chat_status_form.moreStatus) }}{{ form_widget(nowo_chat_status_form.moreStatus) }}` when
  `nowo_chat_status_form.moreStatus is defined`); otherwise the unrendered field ends up at the end of the form.
- `clearAfter` accepts `2h` (2 hours) too; a host that validates the API payload itself should allow it.
- Tests that counted the children of `ChatDockStatusType` get `moreStatus` first.

## To 1.11.3

From **1.11.2**: `composer update nowo-tech/chat-kit-bundle`, then `php bin/console assets:install`. No configuration or
schema change.

## To 1.11.2

From **1.11.1** — no schema change. Run `assets:install`. The `/` `#` reference list no longer has the
`.nowo-chat-mention-list` class: style or select it with `.nowo-chat-ref-list`. Tests that read `aria-expanded` on the
composer box should check that the list is visible (or `aria-controls` is set) instead.

## To 1.11.1

From **1.11.0** — no schema change. Run `assets:install` (or rebuild your bundle of the TS sources). Tests that found
the status button by its old name ("Your status: …") should match "<initials> — Your status: …".

## To 1.11.0

From **1.10.1** — **schema change**. MySQL / MariaDB (prefix the names with a `doctrine.table_prefix`; take the `IDX_` /
`FK_` names from `doctrine:migrations:diff` if you validate the schema; `user` is your user table):

```sql
ALTER TABLE nowo_chat_user_settings
  ADD auto_reply_enabled TINYINT(1) DEFAULT 0 NOT NULL,
  ADD auto_reply_text LONGTEXT DEFAULT NULL,
  ADD auto_reply_when VARCHAR(16) DEFAULT 'status' NOT NULL,
  ADD auto_reply_from DATETIME DEFAULT NULL,
  ADD auto_reply_until DATETIME DEFAULT NULL,
  ADD auto_reply_mentions TINYINT(1) DEFAULT 0 NOT NULL;
ALTER TABLE nowo_chat_participant ADD last_auto_reply_at DATETIME DEFAULT NULL;
CREATE TABLE nowo_chat_quick_reply (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(80) NOT NULL, body LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_nowo_chat_quick_reply_user (user_id), PRIMARY KEY (id));
ALTER TABLE nowo_chat_quick_reply ADD CONSTRAINT FK_nowo_chat_quick_reply_user FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE;
```

Then `assets:install` (or rebuild your bundle of the TS sources). The window template has a composer tools row
(`data-part="composer-tools"`, `format-toggle`, `quick-replies`, `format-toolbar`) and the status menu an *Automatic
reply* `<details>`; templates redefined from 1.10 keep working without them. With `icon_set: ux_icon`, import
`tabler:typography`, `message-bolt`, `bold`, `italic`, `strikethrough`, `code`, `quote`, `list`, `help`, `clock-play`.
Message actions are now absolutely positioned over the message (restyle `.nowo-chat-msg__actions` if you themed them).

## To 1.10.1

From **1.10.0** — no schema change. Run `php bin/console assets:install public` (or rebuild your bundle of the TS
sources). Cards alone in a paragraph get `nowo-chat-ref--block` (restyle that class if you themed the cards); inline
chips hide their type and subtitle (both stay in the `title` attribute). The status menu template block gained the
suggestions (`data-preset-*`) and the emoji button (`data-part="status-emoji-pick"`, `data-part="status-emoji-panel"`);
a block redefined from 1.10.0 keeps working without them.

## To 1.10.0

From **1.9.2** — **schema change** (chat preferences and chat status on `nowo_chat_user_settings`). After
`composer update nowo-tech/chat-kit-bundle`:

```bash
php bin/console assets:install public          # nowo-chat.js / nowo-chat.css (or rebuild your bundle of the TS sources)
php bin/console doctrine:migrations:diff       # or write the migration by hand from the SQL below
php bin/console doctrine:migrations:migrate
```

MySQL / MariaDB (with a `doctrine.table_prefix`, prefix the table name):

```sql
ALTER TABLE nowo_chat_user_settings
  ADD sound_mention VARCHAR(16) DEFAULT 'chime' NOT NULL,
  ADD sound_buzz VARCHAR(16) DEFAULT 'buzz' NOT NULL,
  ADD sound_support VARCHAR(16) DEFAULT 'ding' NOT NULL,
  ADD volume SMALLINT DEFAULT 80 NOT NULL,
  ADD sound_background_only TINYINT(1) DEFAULT 0 NOT NULL,
  ADD buzz_open_window TINYINT(1) DEFAULT 1 NOT NULL,
  ADD open_on_message TINYINT(1) DEFAULT 0 NOT NULL,
  ADD send_key VARCHAR(16) DEFAULT 'enter' NOT NULL,
  ADD push_preview TINYINT(1) DEFAULT 1 NOT NULL,
  ADD status VARCHAR(16) DEFAULT 'available' NOT NULL,
  ADD status_text VARCHAR(80) DEFAULT NULL,
  ADD status_emoji VARCHAR(32) DEFAULT NULL,
  ADD status_expires_at DATETIME DEFAULT NULL;
-- The 1.x single sound was the sound of every message: keep it for @mentions and support replies too.
UPDATE nowo_chat_user_settings SET sound_mention = sound, sound_support = sound;
-- Closing conversations in one's own list, pinned messages.
ALTER TABLE nowo_chat_participant ADD archived_at DATETIME DEFAULT NULL;
ALTER TABLE nowo_chat_message ADD pinned_at DATETIME DEFAULT NULL, ADD pinned_by_id INT DEFAULT NULL;
ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_nowo_chat_message_pinned_by FOREIGN KEY (pinned_by_id) REFERENCES user (id) ON DELETE SET NULL;
CREATE INDEX IDX_nowo_chat_message_pinned_by ON nowo_chat_message (pinned_by_id);
```

PostgreSQL: `BOOLEAN DEFAULT false / true` for the four flags and `TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL` for
`status_expires_at`. Without a row a user has the defaults (nothing to back-fill).

Then:

- **Templates**: if you override `_templates.html.twig` by extending it, nothing to do — the new block
  `nowo_chat_template_status_menu` and the `me` button in `nowo_chat_template_dock` come with it. If you redefined
  `nowo_chat_template_dock`, add the button (`data-part="me"` with `data-part="me-avatar"` and `data-part="me-dot"`
  inside, `hidden`) before the launcher to offer the status menu; without it the dock works as before.
  `nowo_chat_dock_forms()` returns a fourth view, `status`.
- **Full-page chat**: `page.html.twig` has a new block `nowo_chat_page_status` (the user's status button) inside
  `nowo_chat_page_list`, and the header of a direct conversation shows the other person's status.
- **Your settings screen** may offer the new preferences (see [USAGE: User settings](USAGE.md#user-settings)): the
  form type `ChatPreferencesType`, `POST /api/settings` / `saveChatPreference()` for one control at a time, or your own
  fields on the same getters and setters. `getSound()` / `setSound()` keep meaning the new-message sound.
- **Presence answers**: `ChatPresence::statusOf()` entries carry `status`, `text`, `emoji`; `online` is false for an
  invisible user. `POST /api/presence` answers `{online, statuses, me}` (was `{online}`); `ChatApi.presence()` resolves
  to that object (was the `online` list). `playSound()` / `playOnce()` take an optional volume.
- **Auto-away**: `auto_away_minutes` (default 10; `0` turns it off).
- **Closing conversations**: the row template (`nowo_chat_template_row`) has a `data-part="row-archive"` button and
  the filters a `data-filter="archived"` chip and a `data-part="archive-read"` button; a host template without them
  keeps working without those actions. `ChatApi.conversations()` takes an optional `archived` flag.
- **Pinned messages**: the message template has a `data-part="pin"` action. To let people pin in channels, implement
  `ChatPinPolicyInterface` on your access policy (nobody pins in channels otherwise). New `max_pins` (default 20). With
  `icon_set: ux_icon`, import `tabler:archive`, `tabler:arrow-back-up` and `tabler:pin` in your icon set (or the dock
  keeps its built-in SVG).
## To 1.9.2

From **1.9.1** — no schema change. Run `php bin/console assets:install public` (or rebuild your bundle of the TS
sources). If your stylesheet styled the support emojis through `.nowo-chat-emoji`, use `.nowo-chat-deco` (the former is
the emoji picker). `ThreadCache` takes an optional second argument, the locale.

## To 1.9.1

From **1.9.0** — no schema change. Run `php bin/console assets:install public` (or rebuild your bundle of the TS
sources). Support lines show decorative emojis by default: set `support.emojis: false` to keep text only, or an `emoji`
per topic (`""` for none). `ChatSupportTopic` takes an optional last argument `$emoji`. If your stylesheet restyled
`.nowo-chat-ref__type` / `__subtitle` with `opacity`, the card now sets colours instead (`--nowo-chat-ref-fg`,
`--nowo-chat-ref-fg-soft`).

## To 1.9.0

From **1.8.0** — no schema change. Run `php bin/console assets:install public` (or rebuild your bundle of the TS
sources). To offer `/` commands and `#` references, implement `ChatReferenceProviderInterface` (autoconfigured) for each
kind of item — see [USAGE: References](USAGE.md#references). Without a provider nothing changes. New optional rate limit
`limits.references_per_minute` (default 120). Two providers with the same key, or an invalid key, now fail loudly.

## To 1.8.0

From **1.7.0** — **schema change** (support tickets). After `composer update nowo-tech/chat-kit-bundle`:

```bash
php bin/console assets:install public          # nowo-chat.js / nowo-chat.css (or rebuild your bundle of the TS sources)
php bin/console doctrine:migrations:diff       # or write the migration by hand from the SQL below
php bin/console doctrine:migrations:migrate
```

MySQL / MariaDB (with a `doctrine.table_prefix`, prefix the table, index and constraint names; `user` is your user
table; take the generated `IDX_` / `FK_` names from `doctrine:migrations:diff` if you validate the schema):

```sql
ALTER TABLE nowo_chat_conversation
  ADD ticket_number INT DEFAULT NULL,
  ADD ticket_topic VARCHAR(64) DEFAULT NULL,
  ADD assignee_id INT DEFAULT NULL,
  ADD closed_at DATETIME DEFAULT NULL;
-- The new index first: the support_owner_id foreign key needs an index once the unique key is gone.
CREATE INDEX idx_nowo_chat_conversation_support ON nowo_chat_conversation (support_owner_id, last_message_at);
DROP INDEX uniq_nowo_chat_conversation_support ON nowo_chat_conversation;
CREATE UNIQUE INDEX uniq_nowo_chat_conversation_ticket ON nowo_chat_conversation (ticket_number);
CREATE INDEX idx_nowo_chat_conversation_assignee ON nowo_chat_conversation (assignee_id);
ALTER TABLE nowo_chat_conversation
  ADD CONSTRAINT FK_nowo_chat_conversation_assignee FOREIGN KEY (assignee_id) REFERENCES `user` (id) ON DELETE SET NULL;
ALTER TABLE nowo_chat_message ADD meta JSON DEFAULT NULL;
-- Existing support threads that hold a message become tickets SUP-000001, SUP-000002 … (oldest first, no topic).
SET @n := 0;
UPDATE nowo_chat_conversation c
   SET c.ticket_number = (@n := @n + 1)
 WHERE c.kind = 'support' AND EXISTS (SELECT 1 FROM nowo_chat_message m WHERE m.conversation_id = c.id)
 ORDER BY c.id;
```

Behaviour changes to check:

- `ChatSupportInbox::FILTERS` gains `waiting`, `closed` and `unassigned`; **`mine` now means assigned to the agent**
  (it meant "threads the agent wrote in"). A host form listing the filters gets the new choices (translate them).
- `ChatConversationRepository::findSupportFor()` returns the user's **latest** support conversation; open support with
  `ChatSupportTickets::open()` (the API and the dock already do).
- A ticket resolved more than `support.close_after_days` (30) ago, or closed, is read-only: `ChatVoter::SEND` is
  denied. Set `close_after_days: 0` to keep resolved tickets writable.
- An agent's reply turns an open ticket to `waiting`; count both `open` and `waiting` if your menu counter means
  "active tickets" (`countOpen()` still counts `open` only).
- New strings (`chat.support_*`, `support.topic.*`, `support.topic_reply.*`, `support.bot.*`): override them in your
  translations, or point `support.translation_domain` and the topics' `label` / `reply` at your own keys.

Optional:

- Notify people: listen to `SupportTicketCreatedEvent` / `SupportTicketReopenedEvent` (agents),
  `SupportTicketStatusChangedEvent` (the requester) and `SupportTicketAssignedEvent` (the assignee).
- Manage topics as data: alias `ChatSupportTopicProviderInterface` (USAGE, *Support tickets*).

## To 1.7.0

From **1.6.0** — **schema change** (new table `nowo_chat_message_mention`). After `composer update nowo-tech/chat-kit-bundle`:

```bash
php bin/console assets:install public          # nowo-chat.js / nowo-chat.css (or rebuild your bundle of the TS sources)
php bin/console doctrine:migrations:diff       # or write the migration by hand from the SQL below
php bin/console doctrine:migrations:migrate
```

MySQL / MariaDB (with a `doctrine.table_prefix`, prefix the table, index and constraint names; `user` is your user
table):

```sql
CREATE TABLE nowo_chat_message_mention (
  id INT AUTO_INCREMENT NOT NULL,
  message_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  read_at DATETIME DEFAULT NULL,
  UNIQUE INDEX uniq_nowo_chat_mention (message_id, user_id),
  INDEX idx_nowo_chat_mention_user (user_id, read_at, created_at),
  PRIMARY KEY (id)
);
ALTER TABLE nowo_chat_message_mention
  ADD CONSTRAINT FK_nowo_chat_mention_message FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE,
  ADD CONSTRAINT FK_nowo_chat_mention_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE;
```

Optional:

- Notify mentioned people: listen to `ChatMentionEvent` (see USAGE, *Mentions*). The bundle itself only highlights
  the message in the conversation.
- Theme: the mention chip and the highlighted message use `--nowo-chat-mention*` / `--nowo-chat-mentioned-*`, derived
  from `--nowo-chat-accent` and `--nowo-chat-warn`; override them to match your palette.
- Hosts that construct `ChatService` by hand can pass `ChatMentions` and `ChatMessageMentionRepository` (new optional
  last arguments); without them tokens are stored as written and no mention rows are kept.

## To 1.6.0

From **1.5.5**: `composer update nowo-tech/chat-kit-bundle`. Optional: an override of `dock.html.twig` that only empties
`nowo_chat_assets` and adds attributes to the root can go — pass `nowo_chat_assets: false` and `nowo_chat_root_attr` to
the include instead (see USAGE, *Bundler hosts*).

## To 1.5.5

From **1.5.4** — no configuration, template or schema change; the published `nowo-chat.js` and `nowo-chat.css` change:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console assets:install public
```

Hosts that import the TypeScript sources (`src/Resources/assets/src`) pick the thread fix up on their next front-end build. Hosts that
override the folded phone launcher badge (`.nowo-chat.is-mobile .nowo-chat-panel:not(.is-open) .nowo-chat-badge`) can delete
the override.

## To 1.5.4

From **1.5.3**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.5.2

From **1.5.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

No action needed. If your layout mounts its own dock (own bundler or Stimulus controller calling `mount()`), mark
its root `data-nowo-chat-manual` (`{{ include('@NowoChatKitBundle/dock.html.twig', {nowo_chat_manual: true}) }}`) so
the `nowo-chat.js` loaded by the chat page no longer mounts a second dock; optionally empty the new
`nowo_chat_page_script` block in your `web_ui.templates.page` override. See
[USAGE — Front end](USAGE.md#front-end-iife-or-your-bundler).

## To 1.5.1

From **1.5.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.5.0

From **1.4.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

### Template contract (1.5)

The full-page chat follows the **UiKit 2.x page contract** (REQ-UI-003) and the bundle requires
**`nowo-tech/ui-kit-bundle` ^2.2** (`composer update nowo-tech/chat-kit-bundle -W`; register
`Nowo\UiKitBundle\NowoUiKitBundle` in `config/bundles.php` if Flex did not).

| Template | Contract | Blocks |
| --- | --- | --- |
| `page.html.twig` | unique page, extends `layout ?? nowo_ui_layout.admin`; context `header` (`PageHeader`), `bundle` + the 1.x variables | canonical `nowo_ui_*` blocks; `nowo_chat_page_list`, `nowo_chat_page_thread` |
| `dock.html.twig`, `_templates.html.twig` | widget (unchanged blocks), `--nowo-chat-*` tokens | unchanged |

**What hosts may notice**

1. **Layout.** Without configuration the chat page now renders in `nowo_ui_kit.layouts.admin` (default
   `@NowoUiKit/layout/admin.html.twig`, which loads `nowo-ui.css` but no CSS framework). Point
   `nowo_ui_kit.layouts.admin` (or `profiles.<name>.web_ui.layout_template`) at a layout that renders the UiKit
   canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`,
   `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`; UiKit
   `docs/PAGE_CONTRACT.md#example-host-integration` has a one-file bridge) and include the dock in it (e.g. in
   `nowo_ui_modals`).
2. **`page_layout` keeps working until 2.0.** A host that set `page_layout` to its own 1.x layout (`title`,
   `stylesheets`, `javascripts`, `body` blocks) and not `web_ui.layout_template` still gets the 1.x page
   (`page_legacy.html.twig`) in that layout, with a deprecation. Migrate by pointing `web_ui.layout_template` (or
   `nowo_ui_kit.layouts.admin`) at a layout with the canonical blocks and removing `page_layout`. The 1.x default value
   `@NowoChatKitBundle/page_layout.html.twig` now means "the UiKit layout".
3. **Page markup.** The "Messages" heading moved from the list (`.nowo-chat-page__heading`) to the UiKit page header
   (`[data-nowo-ui-region="header"] h1`). The root `[data-testid="chat-page"]`, the list / thread / composer classes
   and the blocks `nowo_chat_page_list` / `nowo_chat_page_thread` are unchanged; elements also carry theme slot
   classes (`section`, `empty`, `muted`, `flash_error`, `btn_link`, `btn_primary`, `form`; with
   `css_framework: bootstrap5` e.g. `btn btn-primary` on the send button).
4. **Colours.** The `--nowo-chat-*` defaults read the UiKit tokens first: with `nowo-ui.css` (or
   `nowo_ui_kit.theme.tokens`) on the page, the dock and the page take the host's primary / surface / text / border /
   radius. Hosts that set `--nowo-chat-*` themselves keep their values.
5. **Overrides.** A `templates/bundles/NowoChatKitBundle/page.html.twig` that extends `@!NowoChatKitBundle/page.html.twig`
   and overrides `title` / `stylesheets` / `javascripts` / `body` must move to the `nowo_ui_*` blocks (or keep the 1.x
   page with `page_layout`, deprecated). Overrides of `nowo_chat_page_list` / `nowo_chat_page_thread` keep working;
   prefer wiring them through `profiles.<name>.web_ui.templates.page`.

**Deprecated configuration** (still read in 1.x, removed in 2.0)

| Key | Replacement |
| --- | --- |
| `profiles.<name>.page_layout` | `profiles.<name>.web_ui.layout_template` (a layout with the UiKit canonical blocks; null = `nowo_ui_kit.layouts.admin`) |
| Twig global `nowo_chat_kit_page_layout` | `nowo_ui_layout.admin` (UiKit) |

## 1.4.1 (2026-10-18)

No changes required. If you redefine `_templates.html.twig` blocks and pass an already translated `placeholder` (or
`title`) to `form_widget`, add `translation_domain: false` as the bundle template now does.

## 1.4.0 (2026-10-16)

- **Deleting is author-only now:** support agents can no longer delete someone else's message (channels and support
  threads); `DELETE /api/conversations/{uuid}/messages/{messageUuid}` answers 404 for them, like any message the caller
  may not delete. If your application relied on agent moderation, remove the offending content another way (for
  example an admin tool over `ChatService::delete()`); `ChatMessageDeletedEvent::$moderated` stays for old tombstones.
- **Overridden `_templates.html.twig`:** the `edit-form` template has a new `.nowo-chat-edit__hint` span (copy it if you
  override the block; optional). Overridden translations: add `chat.just_now`, `chat.edit_hint` and
  `chat.deleted_message_mine` (`chat.deleted_message` now reads "This message was deleted").
- With `icon_set: ux_icon`, make sure `tabler:ban` is available (local icons: `bin/console ux:icons:import tabler:ban`).

## 1.3.1 (2026-10-16)

No breaking change (Spec Kit baseline and docs only).

## 1.3.0 (2026-10-16)

- **Custom `page_layout`:** the full-page chat now adds its CSS and JS through the layout's `stylesheets` and
  `javascripts` blocks. If you set `page_layout` to your own template, make sure it defines `{% block stylesheets %}` and
  `{% block javascripts %}` (inside `<head>` / before `</body>`) besides `title` and `body`.
- **Overridden `_templates.html.twig`:** the search boxes, composer and inline editor are now Form views from
  `nowo_chat_dock_forms()`. If you copied the whole file, re-copy those blocks (`form_start` / `form_widget` /
  `form_end`) instead of raw `<form>` / `<input>` / `<textarea>`; the `data-part` hooks are unchanged.
- **Read state:** `GET /chat/{uuid}` no longer marks the conversation read; the bundle page script does it with a
  CSRF-protected POST. If you render the page with your own script instead of `nowo-chat.js`, call
  `POST /api/conversations/{uuid}/read` with the `X-CSRF-TOKEN` header.
- `symfony/asset` is now required (Composer installs it; full-stack applications already have it).

## 1.2.0 (2026-10-16)

No breaking change. With `icon_set: ux_icon`, import the new icon: `php bin/console ux:icons:import
tabler:arrow-bar-to-right`. If you override `_templates.html.twig` without extending the bundle's, add the `tuck` button
and the `edge` template (see the `nowo_chat_template_edge` block).

## 1.1.1 (2026-10-16)

No breaking change (CSS fix only).

## 1.1.0 (2026-10-16)

No breaking change. If you set `--nowo-chat-bottom-offset` only to clear a footer, mark the footer with
`data-nowo-chat-avoid` instead (the dock then follows it when it scrolls into view); the Symfony toolbar is now
measured, so overrides of the old `body:has(.sf-toolbar-opened) .nowo-chat` rule can go.

## 1.0.0 (2026-10-16)

First release — nothing to upgrade from. Follow [INSTALLATION.md](INSTALLATION.md). The configuration shape
(`default_profile` + `profiles`), the contracts in `Nowo\ChatKitBundle\Contract`, the template `data-part` hooks and the
`--nowo-chat-*` tokens are the public API and stay stable for 1.x.
