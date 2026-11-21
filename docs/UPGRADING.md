# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.40.2](#to-1402)
- [To 1.40.1](#to-1401)
- [To 1.40.1](#to-1401-1)
- [To 1.40.0](#to-1400)
- [To 1.39.1](#to-1391)
- [Preparing for 2.0](#preparing-for-20)
- [To 1.39.0](#to-1390)
- [To 1.38.1](#to-1381)
- [To 1.38.0](#to-1380)
- [To 1.37.0](#to-1370)
- [To 1.36.1](#to-1361)
- [To 1.36.0](#to-1360)
- [To 1.35.0](#to-1350)
- [To 1.34.0](#to-1340)
- [To 1.33.0](#to-1330)
- [To 1.32.0](#to-1320)
- [To 1.31.1](#to-1311)
- [To 1.31.0](#to-1310)
- [To 1.30.0](#to-1300)
- [To 1.29.0](#to-1290)
- [To 1.28.0](#to-1280)
- [To 1.27.1](#to-1271)
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

```bash
composer require nowo-tech/chat-kit-bundle:^2.0 -W
```

2.0 contains only changes that 1.x announced through deprecation notices: an application that runs the last 1.x minor
without deprecation notices from this bundle upgrades without code changes. After 2.0.0 is released, the 1.x line
receives security fixes for 6 months: fixes rated Moderate and above are backported to the last 1.x minor; Low ones are
fixed in 2.x only.

### Before you upgrade

Run your application (or its test suite) on the last 1.x (1.40) and clear every deprecation from
`nowo-tech/chat-kit-bundle` in your log (the profiler, `var/log/*.deprecations.log`, the container's build log):

- > Not setting "nowo_chat_kit.profiles.default.doctrine.private_entity_manager" is deprecated: chat writes then flush
  > the application's entity manager, including its pending changes. In 2.0 the private entity manager is the only
  > mode (as if true). [...]

  and

  > "nowo_chat_kit ... doctrine.private_entity_manager: false" is deprecated: chat writes then flush the application's
  > entity manager, including its pending changes; the private entity manager becomes the only mode in 2.0. Set it to
  > true (see UPGRADING.md).

  Set `private_entity_manager: true` on 1.x first (it is accepted in 2.x too) and check the list under "Private manager
  only" below.
- > Your pending changes to App\Entity\Order were flushed by NowoChatKitBundle; 2.0 no longer does.

  Every entry is your migration list: a place where your code changed or persisted that class and relied on a chat
  write to save it. Add your own `flush()` after that change. The entries stop once `private_entity_manager: true` is
  on (see [To 1.39.0](#to-1390)).
- > App\Chat\ProjectChannels implements the deprecated Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface,
  > which is removed in 2.0. Implement Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface instead [...]

  (the same for `ChatRealtimeInterface` and `CurrentChatUserResolverInterface`): move the service to the twin, see
  "Port interfaces" below.
- > The "nowo_chat_kit.profiles.default.page_layout" option is deprecated and removed in 2.0 [...]

  Use `web_ui.layout_template` (a layout rendering the UiKit canonical blocks; `null` = `nowo_ui_kit.layouts.admin`).
- `ChatMessageSearchProvider::MAX_PAGE` has no notice (a constant): search your code for it.

### Default flip: the private entity manager is the only mode

| | 1.x default (option unset) | 2.0 |
|---|---|---|
| Entity manager of the chat rows | your default manager (`doctrine.entity_manager`) | ChatKit's private manager, created on the connection, mapping and events of `doctrine.entity_manager` |
| A chat write flushes your pending changes | yes (with the 1.39 probe notice) | never |
| A failed chat write closes your manager | yes (replaced at the next request boundary) | never (only the private manager, replaced on the next call) |
| `private_entity_manager: true` | opt-in | accepted, no effect, deprecated (removed in 3.0) |
| `private_entity_manager: false` | deprecated | container build error naming the profile |

There is no option to keep the 1.x behaviour. No schema change; the tables stay where they are.

### Removed

| Removed | Deprecated since | Replacement |
|---|---|---|
| `profiles.<name>.doctrine.private_entity_manager: false` (and the unset 1.x behaviour) | 1.27 (`false`), 1.39 (unset) | nothing to set: the private manager is the only mode (`true` stays valid until 3.0, with a deprecation) |
| The host-flush probe (`Doctrine\ChatHostFlushProbe`, internal) and its notices | 1.39 (deprecation layer) | your own `flush()` |
| `ChatKitTransactionRunner::beforeHostFlush()` (now a no-op) and the `$hostFlushProbe` argument of `ChatKitTransactionRunner` / `ChatMentions` (ignored) | 2.0 (deprecated, removed in 3.0) | nothing |
| `Contract\ChatChannelProviderInterface` | 1.27 | `Contract\ChatChannelSourceInterface` |
| `Contract\ChatRealtimeInterface` | 1.27 | `Contract\ChatRealtimeTransportInterface` |
| `Contract\CurrentChatUserResolverInterface` | 1.27 | `Contract\ChatParticipantResolverInterface` |
| `profiles.<name>.page_layout` (build error) | 1.5 | `profiles.<name>.web_ui.layout_template` |
| `page_legacy.html.twig`, `page_layout.html.twig` | 1.5 | `page.html.twig` (override one block through `web_ui.templates.page`) |
| Twig global `nowo_chat_kit_page_layout` | 1.5 | `web_ui.layout_template` |
| `Configuration::LEGACY_PAGE_LAYOUT`, `ChatPageController::LEGACY_TEMPLATE` | 1.5 | none |
| The `$layout` / `$pageLayout` arguments of `ChatPageController`, `ChatSearchController`, `ChatDockExtension` (ignored) | 2.0 (deprecated, removed in 3.0) | none |
| `SearchKit\ChatMessageSearchProvider::MAX_PAGE` | 1.38 | `search_kit.messages.max_candidates` |
| `Doctrine\ChannelKeyCollationListener::postGenerateSchemaTable()` | 1.40.1 | none (the listener works on `postGenerateSchema`) |

### Port interfaces

The three ports typed with Symfony classes keep only their 1.27 twins:

| Removed | Implement and alias instead | Change |
|---|---|---|
| `ChatChannelProviderInterface` | `ChatChannelSourceInterface` | `contextChannel(ChatUserInterface $user, ChatRequestContext $page)`: read `$page->path`, `$page->route`, `$page->routeParam('slug')`, `$page->query` instead of the `Request` |
| `ChatRealtimeInterface` | `ChatRealtimeTransportInterface` | `browserHubUrl(ChatRequestContext $request)`: `$request->origin()` is `Request::getSchemeAndHttpHost()` |
| `CurrentChatUserResolverInterface` | `ChatParticipantResolverInterface` | `resolve(?ChatSecurityUser $user)`: map `$user->identifier` (or `$user->id`) to the participant; the security user object is not passed |

```yaml
# config/services.yaml
Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface: '@App\Chat\ProjectChannels'
```

Keep the write and mention policies (`ChatChannelWritePolicyInterface`, `ChatChannelMentionPolicyInterface`) on the
same class. The container build fails, naming the service and the interface to implement, when a service still uses a
removed interface: an alias or a service under the removed id (`#[AsAlias]` included), a decorator of the removed id
(`#[AsDecorator]`, `decorates:`, also with `onInvalid: ignore`), or a class that implements it (autodiscovered, or behind
a twin's alias). Symfony would otherwise drop such a service silently, and the chat would fall back to its default (no
channels, no realtime transport of yours, no participant mapping). **A decorator of a removed id has no replacement
hook of that id:** decorate your own twin service instead (or the twin's id, which you alias). The bundle's defaults
(`NullChannelProvider`, `MercureChatRealtime`, `NullChatRealtime`, `SecurityChatUserResolver`) are no longer instances
of the removed interfaces; decorate your twin rather than them.

A host service that injects a removed interface to call it (for example a nullable parameter with a `null` default,
which silently receives `null` once the interface is gone) must switch to the new port interface, or it quietly stops
calling it.

### Configuration, templates and the Twig global

- `profiles.<name>.page_layout` fails the container build. Move the layout to `profiles.<name>.web_ui.layout_template`
  (a layout rendering the UiKit canonical blocks); the 1.x template `page_legacy.html.twig` and its default document
  `page_layout.html.twig` are gone, and so is the Twig global `nowo_chat_kit_page_layout`. A host override of
  `templates/bundles/NowoChatKitBundle/page_legacy.html.twig` is no longer rendered: port it to a one-block override of
  `page.html.twig` ([TEMPLATES](TEMPLATES.md)).

### Private manager only

The 1.27 "Check after enabling it" list, now unconditional:

- **Your pending changes are no longer flushed by ChatKit.** A change of yours that was only stored because a ChatKit
  write flushed your manager is no longer stored: typically in a listener of a ChatKit event, a port of yours (a channel
  source, a directory, a policy that writes), or code that changes an entity and then calls a ChatKit service. Flush it
  yourself. The 1.39 probe entries are the list.
- **The reverse is not saved either.** Host code that loads a chat row through a ChatKit repository service (it uses
  the private manager), changes it and flushes its own manager saves nothing: the private manager drops the change at
  the next boundary with only a warning in the log. Change chat rows through the ChatKit services
  (`ChatServiceInterface`, `ChatMessagingInterface`, …). That includes `ChatServiceInterface::participant()`: the row it
  returns is persisted in ChatKit's private manager, so flushing your own manager does not save it.
- **Chat rows you load through your own manager** (a route value resolver, your own query) are loaded again by id when
  you hand them to ChatKit, never inserted again; a row deleted meanwhile throws `ChatNotFoundException`. Your copy is
  not updated by the chat write.
- **Users read from chat rows are the private manager's instances**: compare users by id (`getId()`), never with
  `===`. Events that take a user from your call (`ChatMessageDeletedEvent::$by`, …) still hand you your own instance.
- **Your user on a chat row** (the security user, a user your code loaded, an uninitialised proxy) is written as the
  private manager's reference to the id your manager knows; your instance is never written. A user you persisted but
  did not flush yet, or never persisted, fails with a `LogicException` ("… is not saved yet … Flush your own entity
  manager first") before anything is written: flush your user first. With a single-table-inheritance `user_class`, a
  user whose row ChatKit cannot see (deleted, filtered or of another subclass) fails clearly too.
- **Listeners: use the id getters, then `getReference()` with a private manager.** A listener that takes a user or a chat
  row from an event (`$event->by`, `$event->message`, `$event->conversation`, …) and stores it in an entity of yours
  fails with "A new entity was found through the relationship", because those objects belong to ChatKit's private
  manager. Every event now has scalar getters: read `byReference()` / `userReference()` / `actorReference()` /
  `requesterReference()` / `assigneeReference()` / `mentionedReferences()` (the host user id as a string) and
  `messageId()`, `conversationId()`, `ticketId()`, `linkId()` (or the `*Uuid()` twins), then
  `$entityManager->getReference(User::class, (int) $event->byReference())` on your own manager and flush it. The
  entity and user properties of the events are deprecated since 2.0 (docblock only) and removed in 3.0 (table:
  [USAGE: Events](USAGE.md#events)).
- **Your entities pointing at chat rows** (a ManyToOne to `ChatConversation`, …): use
  `$entityManager->getReference(ChatConversation::class, $id)` (or a row your manager loaded) and flush your manager.
- **Ports of yours that wrote through the shared manager** must commit their own change: a custom
  `ChatDirectoryInterface`, policy or listener that only changed a managed entity of yours and relied on the chat's
  flush now has to flush (or run its own unit of work).
- Inside your own transaction the chat rows are part of it (shared connection); the bundle's own writes run in
  savepoints.

`doctrine.entity_manager` keeps its meaning: the manager that maps the chat entities, the source of the private one.

### Unchanged

Retention and the purge, GDPR export and erasure, search visibility (page, API, SearchKit palette), Web Push, the
Mercure topics, the realtime resync, the conflict answers (409 + `Retry-After`, `CONFLICT_001` / `CONFLICT_002`), the
tables and their names.

## To 1.40.2

From **1.40.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.40.1

From **1.40.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.40.1

From **1.40.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

From 1.26.4 to 1.40.0, on MySQL and MariaDB with `doctrine/orm` 3.7 or 3.8 and `doctrine/dbal` 4.5 or later, the schema
the bundle generated (`doctrine:schema:create`, `doctrine:schema:update`, `doctrine:migrations:diff`) missed the foreign
keys, and their indexes, of the tables whose key columns get the exact-match collation. A migration generated then may
have dropped them. Without them, deleting a user or a conversation leaves chat rows (personal data) behind that no purge
reaches. PostgreSQL and SQLite were not affected, nor was `doctrine.user_reference: id`.

1.40.1 generates the keys again, so your next `doctrine:migrations:diff` adds them, and `doctrine:schema:validate`
reports the mapping and the database out of sync until they are there. They cannot be added while orphaned rows exist.
The statements below use the default table names (`doctrine.table_name_prefix: nowo_chat_`, no `table_prefix`); with a
prefix the table, index and key names differ, take them from `doctrine:migrations:diff` or `doctrine:schema:update
--dump-sql`. They are the same on MySQL 8.4 and MariaDB 11.4. Skip the statements whose index or key already exists
(`SHOW CREATE TABLE …`). Afterwards `doctrine:schema:validate` reports the database in sync.

**`doctrine.user_reference: relation`** (the default), from 1.26.4: the user foreign keys of `nowo_chat_conversation`.
`app_user` stands for your user table (the table of `user_class`). Find the orphans:

```sql
SELECT c.id, c.support_owner_id, c.resolved_by_id, c.assignee_id
FROM nowo_chat_conversation c
LEFT JOIN app_user so ON so.id = c.support_owner_id
LEFT JOIN app_user rb ON rb.id = c.resolved_by_id
LEFT JOIN app_user a ON a.id = c.assignee_id
WHERE (c.support_owner_id IS NOT NULL AND so.id IS NULL)
   OR (c.resolved_by_id IS NOT NULL AND rb.id IS NULL)
   OR (c.assignee_id IS NOT NULL AND a.id IS NULL);
```

Then do what the keys would have done: delete the support conversations of deleted owners (ON DELETE CASCADE; their
messages, participants and the rest go with them through their own keys) and unset the deleted resolvers and assignees
(ON DELETE SET NULL); then add the indexes and the keys:

```sql
DELETE c FROM nowo_chat_conversation c LEFT JOIN app_user u ON u.id = c.support_owner_id WHERE c.support_owner_id IS NOT NULL AND u.id IS NULL;
UPDATE nowo_chat_conversation c LEFT JOIN app_user u ON u.id = c.resolved_by_id SET c.resolved_by_id = NULL WHERE c.resolved_by_id IS NOT NULL AND u.id IS NULL;
UPDATE nowo_chat_conversation c LEFT JOIN app_user u ON u.id = c.assignee_id SET c.assignee_id = NULL WHERE c.assignee_id IS NOT NULL AND u.id IS NULL;
CREATE INDEX IDX_D7385CFFCA8AEAC6 ON nowo_chat_conversation (support_owner_id);
CREATE INDEX IDX_D7385CFF6713A32B ON nowo_chat_conversation (resolved_by_id);
ALTER TABLE nowo_chat_conversation ADD CONSTRAINT FK_D7385CFFCA8AEAC6 FOREIGN KEY (support_owner_id) REFERENCES app_user (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_conversation ADD CONSTRAINT FK_D7385CFF6713A32B FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON DELETE SET NULL;
ALTER TABLE nowo_chat_conversation ADD CONSTRAINT FK_D7385CFF59EC7D60 FOREIGN KEY (assignee_id) REFERENCES app_user (id) ON DELETE SET NULL;
```

**`doctrine.user_reference: string`**, from 1.27.0: the foreign keys of the message, mention, reaction, saved message,
thread read, participant and conversation link tables (`nowo_chat_conversation` has no other foreign key in that mode).
Find the orphans, for example:

```sql
SELECT 'nowo_chat_message' AS t, COUNT(*) FROM nowo_chat_message x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL
UNION ALL SELECT 'nowo_chat_participant', COUNT(*) FROM nowo_chat_participant x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL
UNION ALL SELECT 'nowo_chat_conversation_link', COUNT(*) FROM nowo_chat_conversation_link x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL
UNION ALL SELECT 'nowo_chat_message (thread)', COUNT(*) FROM nowo_chat_message x LEFT JOIN nowo_chat_message r ON r.id = x.thread_root_id WHERE x.thread_root_id IS NOT NULL AND r.id IS NULL
UNION ALL SELECT 'nowo_chat_message_mention', COUNT(*) FROM nowo_chat_message_mention x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL
UNION ALL SELECT 'nowo_chat_reaction', COUNT(*) FROM nowo_chat_reaction x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL
UNION ALL SELECT 'nowo_chat_saved_message', COUNT(*) FROM nowo_chat_saved_message x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL
UNION ALL SELECT 'nowo_chat_thread_read', COUNT(*) FROM nowo_chat_thread_read x LEFT JOIN nowo_chat_message m ON m.id = x.root_message_id WHERE m.id IS NULL;
```

Delete them (ON DELETE CASCADE) and unset the forwarded-from references to deleted rows (ON DELETE SET NULL), in this
order (messages first, so the rows of the messages they take are orphaned next):

```sql
DELETE x FROM nowo_chat_participant x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL;
DELETE x FROM nowo_chat_conversation_link x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL;
DELETE x FROM nowo_chat_message x LEFT JOIN nowo_chat_conversation c ON c.id = x.conversation_id WHERE c.id IS NULL;
DELETE x FROM nowo_chat_message x LEFT JOIN nowo_chat_message r ON r.id = x.thread_root_id WHERE x.thread_root_id IS NOT NULL AND r.id IS NULL;
UPDATE nowo_chat_message x LEFT JOIN nowo_chat_message f ON f.id = x.forwarded_from_message_id SET x.forwarded_from_message_id = NULL WHERE x.forwarded_from_message_id IS NOT NULL AND f.id IS NULL;
UPDATE nowo_chat_message x LEFT JOIN nowo_chat_conversation c ON c.id = x.forwarded_from_conversation_id SET x.forwarded_from_conversation_id = NULL WHERE x.forwarded_from_conversation_id IS NOT NULL AND c.id IS NULL;
DELETE x FROM nowo_chat_message_mention x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL;
DELETE x FROM nowo_chat_reaction x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL;
DELETE x FROM nowo_chat_saved_message x LEFT JOIN nowo_chat_message m ON m.id = x.message_id WHERE m.id IS NULL;
DELETE x FROM nowo_chat_thread_read x LEFT JOIN nowo_chat_message m ON m.id = x.root_message_id WHERE m.id IS NULL;
```

Then add the indexes and the keys:

```sql
CREATE INDEX IDX_A40D82CC9AC0396 ON nowo_chat_message (conversation_id);
CREATE INDEX IDX_A40D82CCC8BC80CF ON nowo_chat_message (thread_root_id);
CREATE INDEX IDX_A40D82CC4645CC3E ON nowo_chat_message (forwarded_from_message_id);
CREATE INDEX IDX_A40D82CCBDD9A572 ON nowo_chat_message (forwarded_from_conversation_id);
CREATE INDEX IDX_9DF33B9B537A1329 ON nowo_chat_message_mention (message_id);
CREATE INDEX IDX_9FE7FEB9AC0396 ON nowo_chat_participant (conversation_id);
CREATE INDEX IDX_F6AD5573537A1329 ON nowo_chat_reaction (message_id);
CREATE INDEX IDX_C835FCC3537A1329 ON nowo_chat_saved_message (message_id);
CREATE INDEX IDX_C2874913F639B458 ON nowo_chat_thread_read (root_message_id);
ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CC9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CCC8BC80CF FOREIGN KEY (thread_root_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CC4645CC3E FOREIGN KEY (forwarded_from_message_id) REFERENCES nowo_chat_message (id) ON DELETE SET NULL;
ALTER TABLE nowo_chat_message ADD CONSTRAINT FK_A40D82CCBDD9A572 FOREIGN KEY (forwarded_from_conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE SET NULL;
ALTER TABLE nowo_chat_message_mention ADD CONSTRAINT FK_9DF33B9B537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_participant ADD CONSTRAINT FK_9FE7FEB9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_reaction ADD CONSTRAINT FK_F6AD5573537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_saved_message ADD CONSTRAINT FK_C835FCC3537A1329 FOREIGN KEY (message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_thread_read ADD CONSTRAINT FK_C2874913F639B458 FOREIGN KEY (root_message_id) REFERENCES nowo_chat_message (id) ON DELETE CASCADE;
ALTER TABLE nowo_chat_conversation_link ADD CONSTRAINT FK_C437798C9AC0396 FOREIGN KEY (conversation_id) REFERENCES nowo_chat_conversation (id) ON DELETE CASCADE;
```

If you call the collation listener yourself: it now listens to `postGenerateSchema`;
`ChannelKeyCollationListener::postGenerateSchemaTable()` is a deprecated no-op and its registry argument is unused.

## To 1.40.0

From **1.39.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

**1.40.0** — the conversation header gets a message search button that opens a modal, whenever `search.enabled` is on
(`search.conversation_entry`, default **true**). To keep the 1.39 header, turn it off:

```yaml
nowo_chat_kit:
    profiles:
        default:
            search:
                conversation_entry: false
```

- Hosts that override `_templates.html.twig`: the `window` template gets an `<a data-part="message-search">` before
  the resolve button, and a new `search-dialog` template (block `nowo_chat_template_search_dialog`) renders after the
  others. An override of the `window` block without the button simply has no search button; an override without the
  `search-dialog` template makes the button open `/chat/search` instead.
- Hosts that bundle the dock's TypeScript themselves: rebuild (new module `search-dialog.ts`).
- The modal uses UiKit's `confirm_dialog` anatomy and dialog CSS (UiKit ≥ 2.29; the bundle already requires ^2.58.1).
- Nothing changes in who can search what: the modal's fragment endpoint calls the same search as the page.

## To 1.39.1

From **1.39.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Message search page (`/chat/search`): nothing to do unless you restyled or overrode it.

- The page's `<section>` class is now `nowo-chat-search-page` (it was `nowo-chat-search`, the dock's search-field
  class). Move host CSS that targeted the page through `.nowo-chat-search` to `.nowo-chat-search-page`; the
  `.nowo-chat-search__*` element classes are unchanged.
- Overrides of `nowo_chat_search_form` and `nowo_chat_search_results` keep working (same names and places). A form
  block override that renders every field still works; to keep the new layout, start from the bundle's block, which
  renders the text and the button first, then `nowo_chat_search_filters`.
- New translation keys under `chat_search.*`: `back`, `filters`, `results`, `start_title`, `start`, `empty_title`,
  `empty_hint`.

## Preparing for 2.0

ChatKit 2.0 contains only the deprecations announced in 1.x; every one of them triggers a runtime deprecation in
1.39.0, so a 1.39 application with an empty deprecation log for `nowo-tech/chat-kit-bundle` and no
"flushed by NowoChatKitBundle" entry upgrades without changes. What 2.0 removes:

| 1.x | 2.0 | Notice in 1.39 |
|---|---|---|
| `doctrine.private_entity_manager` unset or `false` (chat writes flush your entity manager) | the private entity manager is the only mode; the option goes | at the container build (unset: 1.39, `false`: 1.27) |
| your pending changes flushed by a chat write | no longer written by ChatKit: flush them yourself | host-flush probe, one per entity class and request |
| `ChatChannelProviderInterface`, `ChatRealtimeInterface`, `CurrentChatUserResolverInterface` | `ChatChannelSourceInterface`, `ChatRealtimeTransportInterface`, `ChatParticipantResolverInterface` | at the container build (or when a factory service is created) |
| `profiles.<name>.page_layout`, `page_legacy.html.twig`, Twig global `nowo_chat_kit_page_layout` | `profiles.<name>.web_ui.layout_template` (or `nowo_ui_kit.layouts.admin`) | when the key is set (since 1.5) |
| `ChatMessageSearchProvider::MAX_PAGE` (unused since 1.38) | `search_kit.messages.max_candidates` | none (a constant) |

**1.x security support.** After 2.0.0 is tagged, the 1.x line gets security fixes for 6 months: Moderate and higher
are backported to the last 1.x minor; Low ones are fixed in 2.x only, with the advice to upgrade. Advisories name both
lines.

## To 1.39.0

From **1.38.x**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Requires `nowo-tech/transaction-doctrine` ^1.9 (Composer updates it). Nothing breaks: with the same configuration the
bundle behaves as 1.38, and no migration is needed. What is new is what you see in your deprecation log (the profiler,
`var/log/*.deprecations.log`, or the container's build log):

**Unset `private_entity_manager`.** Without `doctrine.private_entity_manager` you now get, once per container build:

> Not setting "nowo_chat_kit.profiles.default.doctrine.private_entity_manager" is deprecated: chat writes then flush the
> application's entity manager, including its pending changes. In 2.0 the private entity manager is the only mode (as
> if true). [...]

Opt in now; it needs no schema change and the tables stay where they are (see "Private entity manager" under
[To 1.27.0](#private-entity-manager-req-arch-001-rule-6-opt-in) for what to check):

```yaml
nowo_chat_kit:
    profiles:
        default:
            doctrine:
                private_entity_manager: true
```

**The host-flush probe: your migration list.** Until you opt in, a chat write (sending, editing, deleting, reacting,
pinning, links, auto-replies, support status, settings, GDPR erasure...) flushes your entity manager. Right before each
of those flushes ChatKit now lists the entity classes **outside ChatKit** with pending changes in your unit of work
and triggers, once per class and request (or message, or command):

> Your pending changes to App\Entity\Order were flushed by NowoChatKitBundle; 2.0 no longer does.

Each entry is a place where your code changed or persisted an `App\Entity\Order` and relied on the chat write to save
it. In 2.0 (and in 1.39 with `private_entity_manager: true`) ChatKit writes only its own rows, so that change would
be lost. To migrate:

1. Run the application (or its test suite) on 1.39 for a while and collect the "flushed by NowoChatKitBundle" entries:
   that is the exact list of classes.
2. For each class, find the code that changes it before a chat call and add your own `$entityManager->flush()` (or
   your usual unit of work) after the change.
3. Turn `private_entity_manager: true` on and check that no new entries appear (with the private manager the probe is
   off: the entries stop because ChatKit no longer flushes your manager).

The probe only reads: it never flushes, clears, detaches or resets anything, and it costs nothing for ChatKit's own
classes. It is reset at every request, message and command boundary, also in FrankenPHP worker mode with kernel reset
off.

**Deprecated port interfaces.** An application service on `ChatChannelProviderInterface`, `ChatRealtimeInterface` or
`CurrentChatUserResolverInterface` (deprecated since 1.27) now triggers, once per container build:

> App\Chat\ProjectChannels implements the deprecated Nowo\ChatKitBundle\Contract\ChatChannelProviderInterface, which is
> removed in 2.0. Implement Nowo\ChatKitBundle\Contract\ChatChannelSourceInterface instead [...]

Move it to the twin as described in [Framework-free port twins](#framework-free-port-twins-req-arch-001-rule-3).

## To 1.38.1

From **1.38.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.38.0

From **1.37.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

From **1.37.0**: nothing to change unless you use the SearchKit palette (`search_kit.enabled: true`).

**SearchKit ^1.10.** The chat groups now run on SearchKit's provider base (SearchKit 1.9.0) and its batch display hook `prepareItems()` (1.10.0):

```bash
composer require nowo-tech/search-kit-bundle:^1.10
```

ChatKit declares a conflict with SearchKit below 1.10, so Composer will not install this version next to an older
SearchKit (the palette groups would otherwise silently disappear). SearchKit 1.10 needs PHP 8.3, like ChatKit.

**New group: Conversations (`chat_channels`).** With `search_kit.enabled: true` a second group is registered by
default: the direct conversations, channels and support tickets the viewer can open, found by their name (the other
person, `#label`, `SUP-000123 · Topic`). Turn it off with `search_kit.channels.enabled: false` (or SearchKit's
`nowo_search_kit.groups.chat_channels.enabled: false`). Like the Messages group it is registered only while SearchKit's
recents are revalidated or off. See [USAGE.md](USAGE.md#searchkit-palette-137).

```yaml
nowo_chat_kit:
    profiles:
        default:
            search_kit:
                enabled: true
                messages:
                    enabled: true        # 1.38: the Messages group (key `messages`)
                    max_candidates: 100  # messages read and checked per search
                channels:
                    enabled: true        # 1.38: the Conversations group (key `chat_channels`)
                    priority: 35
                    max_candidates: 100
                    direct_scan: 200     # newest direct conversations matched by the other person's name
```

**Messages group: what changes for viewers.** The rows a viewer may see are the same (same permission filter, same
per-row checks, same excerpts). What follows SearchKit's shared flow now:

- The group shows the **visible total** within its candidate window (it showed none), and SearchKit's own "see all"
  label (`search.see_all`) instead of ChatKit's `chat_search.see_all` (the link is unchanged: `/chat/search?q=…`).
- The palette and SearchKit's results page go as deep as `search_kit.messages.max_candidates` (100) messages instead of
  3 pages; older matches are on ChatKit's own search page (`/chat/search`, "See all").
- One limiter token per palette call, whatever the results page (it cost one per page replayed).
- Host listeners of `SearchKitEvents::ITEM_VISIBILITY` now receive the group's candidates (subject type
  `chat_message`, id = the message id) and can deny them; a deny wins.

**For integrators calling the provider directly.** `ChatMessageSearchProvider` is now a thin wrapper of SearchKit's
`EntitySearchProvider` (constructor: a `ChatMessageSearchDefinition` and SearchKit's optional services);
`ChatMessageSearchProvider::MAX_PAGE` is deprecated and unused. The service ids (the class names) and the group key
(`messages`) are unchanged.

## To 1.37.0

From **1.36.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

**Target 1.37.0 (message search, opt-in).** From **1.36.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

**Schema (every host).** One nullable column on `nowo_chat_message`: `search_text`, the viewer-neutral plain text
message search matches (audit VA2-1: `#reference` titles, link URLs and mention ids are not in it). From 1.37 every
send, edit, deletion and GDPR erasure writes it in the same unit of work; rows written before stay NULL until the
backfill fills them, and a NULL row is never found. Generate and run the migration:

```bash
php bin/console doctrine:migrations:diff   # then review and run the migration
```

It is equivalent to (default names):

```sql
-- MySQL 8.4 / MariaDB 11.4
ALTER TABLE nowo_chat_message ADD search_text LONGTEXT DEFAULT NULL;
-- PostgreSQL 16
ALTER TABLE nowo_chat_message ADD search_text TEXT DEFAULT NULL;
```

Adding a nullable column without a default value is a metadata change on PostgreSQL 16, and `ALGORITHM=INSTANT` on
MySQL 8.4 / MariaDB 11.4 **as long as the table has no FULLTEXT index**. With one (a pre-release 1.37 build), MySQL
rebuilds the table (`ALGORITHM=COPY`, writes blocked for the whole copy) and MariaDB takes `LOCK=SHARED`: drop that
index first (`nowo:chat:search-index --drop --force` on such a build, or plan a window). Every server still needs a
short exclusive lock on the table for the change, which waits behind long transactions and blocks the writes queued
behind it: run the migration with a lock wait limit (`SET SESSION lock_wait_timeout = 5;` on MySQL / MariaDB,
`SET lock_timeout = '5s';` on PostgreSQL) and retry if it gives up. Nothing else changes until you set
`search.enabled: true`: no route is added and no index is built.

**1. Fill the search text of the existing messages.**

```bash
php bin/console nowo:chat:search-backfill            # --batch=500 (at most 2000), --after-id=<id> to resume
```

It walks the rows with a NULL search text by id; each batch is one short transaction (DBAL only: it never flushes,
clears or resets an entity manager) that reads its rows with a row lock (`SELECT … FOR UPDATE`) and writes a row only
while its search text is still NULL, so a send or an edit made meanwhile wins. **Run it once every node runs 1.37**,
and **run it again after the last old node has stopped**: a node still on 1.36 writes messages without a search text
(never found until filled). Safe while the chat is in use and safe to run again. A lock conflict stops it with `CONFLICT_001`
and exit code 75: the batches before it are kept, and the message says which `--after-id` resumes it
(REQ-RUNTIME-003 rule 6). Expect roughly the time of reading and updating every message once; on a busy table use a
smaller `--batch`.

**2. Build the index.** `php bin/console nowo:chat:search-index` prints the index state and the statement for your
database (it follows `doctrine.table_prefix` / `doctrine.table_name_prefix`); `--force` runs it, `--drop` removes the
index. Build it after the backfill (a FULLTEXT index on MySQL / MariaDB is cheaper to build once than to maintain row
by row during the backfill).

- **MySQL 8.4 / MariaDB 11.4.** With `search.enabled` and `search.fulltext` on, the index is part of the mapping, so
  `doctrine:migrations:diff` adds it; with either off it stays mapped while it exists, so a diff never drops it:

  ```sql
  CREATE FULLTEXT INDEX idx_nowo_chat_message_search_fts ON nowo_chat_message (search_text);
  ```

- **PostgreSQL 16.** Doctrine cannot describe an expression index, so a diff neither creates nor drops it. Put the
  statement in a migration that is **not transactional** (`public function isTransactional(): bool { return false; }`;
  `CONCURRENTLY` refuses to run inside a transaction), or run the command:

  ```sql
  CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_nowo_chat_message_search_fts ON nowo_chat_message
    USING GIN (to_tsvector('simple'::regconfig, search_text));
  ```

- **Other platforms** (SQLite …) have no index: the search uses `LIKE` (fine for small tables only).

**Build time and locks on big tables.** Plan it like any index build on the largest chat table:

- **MySQL / MariaDB (InnoDB).** The first FULLTEXT index of a table rebuilds the whole table (InnoDB adds its hidden
  `FTS_DOC_ID` column): count on roughly the time of a full table copy plus tokenising every message, and on extra disk
  space of about the table's size while it runs. Writes to `nowo_chat_message` are blocked for the duration (sending a
  message waits; `ALGORITHM=INPLACE, LOCK=NONE` is not available for a first FULLTEXT index). Run it in a maintenance
  window, or build it with an online schema-change tool. Measure on a copy first: a
  table of ten million short messages typically takes tens of minutes. `innodb_ft_min_token_size` (default 3) and the
  server's stop-word list decide which words the index knows; shorter words are matched with `LIKE` (set
  `search.mysql_min_token_length` to the server's value).
- **PostgreSQL.** `CREATE INDEX CONCURRENTLY` keeps the table writable; it scans the table twice and waits for
  transactions that were writing to it when it started, so it takes longer than a plain build (several minutes per ten
  million rows is common; a GIN build is CPU-bound — raise `maintenance_work_mem` for the session to speed it up). If it
  fails or is cancelled, PostgreSQL keeps an **INVALID** index: the command reports `invalid`, and `--force` drops it
  and builds it again.
- **A lock conflict** (a lock wait timeout, a deadlock, `lock_timeout`) stops the command with `CONFLICT_001` and exit
  code 75 (REQ-RUNTIME-003 rule 6): no usable index was built; on PostgreSQL an INVALID index may remain until
  `--force` runs again. That run drops the INVALID index before building, so if it fails as well no index is left
  (PostgreSQL search still answers, through slower plans; MySQL / MariaDB search needs the index: keep `search.enabled` off until it is built). Run it again when the table is quieter.
- `search.postgresql_config` (default `simple`: no stemming, no stop words, any language) is part of the index
  expression: changing it means dropping and building the index again.

**Pre-release builds on PostgreSQL.** A 1.37 development build created `idx_nowo_chat_message_body_fts` (on `body`),
which no schema diff sees. `nowo:chat:search-index --force` (or `--drop --force`) drops it (`CONCURRENTLY`) when it is
there. On MySQL / MariaDB it is no longer mapped, so the next diff drops it.

**3. Turn it on.**

```yaml
nowo_chat_kit:
    profiles:
        default:
            search:
                enabled: true          # page /chat/search, GET /chat/api/search, dock header link
            # Optional: a "Messages" group in the SearchKit palette (composer require nowo-tech/search-kit-bundle:^1.8)
            search_kit:
                enabled: true
```

**SearchKit palette.** `search_kit.enabled` needs SearchKit's recents revalidated:

```yaml
nowo_search_kit:
    recent:
        revalidate: true   # or enabled: false
```

Without it the provider is not registered (otherwise message excerpts picked in the palette would stay in the
browser's localStorage after a delete, an erasure or a lost access). Palette searches share
`limits.searches_per_minute` with the page and the API.

**Turning search off later (MySQL / MariaDB).** The FULLTEXT index stays in the mapped schema while it exists, so a diff
made with search off keeps it. Drop it on purpose with `nowo:chat:search-index --drop --force`.

**Accepted loss.** `#reference` titles are not searchable (not even for readers who may open the item): the search text
holds a neutral `#…` in their place, so a message can never be found through a title a viewer cannot see.

**Words the index can use.** On MySQL / MariaDB / PostgreSQL a search made only of short or punctuated words answers no
rows (no full table scan).

The search obeys the purge's retention window (`retention_days` / `RetentionPolicyProviderInterface`): messages older
than it are never found, even before `nowo:chat:purge` deletes them. A new rate limiter `nowo_chat_search`
(`limits.searches_per_minute`, default 30) is added. See [USAGE.md](USAGE.md#message-search-137) and
[CONFIGURATION.md](CONFIGURATION.md).

## To 1.36.1

From **1.36.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

## To 1.36.0

From **1.35.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

**1.36 (forward, threads, edit history).** From **1.35.0**:

```bash
composer update nowo-tech/chat-kit-bundle
php bin/console doctrine:migrations:diff   # then review and run the migration
```

**Schema.** Three new tables (`nowo_chat_message_revision`, `nowo_chat_thread_read`, `nowo_chat_idempotency`) and six
columns on `nowo_chat_message`, all with defaults: `thread_root_id`, `thread_reply_count` (0), `thread_last_reply_at`,
`forwarded_from_message_id`, `forwarded_from_conversation_id` and `forwarded` (false). The unread index
`idx_nowo_chat_message_unread` gains `thread_root_id` and is rebuilt (Q-B14). On large tables, run that step in a
maintenance window, or online: `ALGORITHM=INPLACE, LOCK=NONE` on MySQL / MariaDB, `CREATE INDEX CONCURRENTLY` (then
`DROP INDEX CONCURRENTLY` of the old one) on PostgreSQL. On MySQL / MariaDB (default names, `relation` mode) the
migration is equivalent to:

```sql
ALTER TABLE nowo_chat_message ADD thread_root_id INT DEFAULT NULL, ADD thread_reply_count INT DEFAULT 0 NOT NULL,
  ADD thread_last_reply_at DATETIME DEFAULT NULL, ADD forwarded_from_message_id INT DEFAULT NULL,
  ADD forwarded_from_conversation_id INT DEFAULT NULL, ADD forwarded TINYINT(1) DEFAULT 0 NOT NULL;
DROP INDEX idx_nowo_chat_message_unread ON nowo_chat_message;
CREATE INDEX idx_nowo_chat_message_unread ON nowo_chat_message (conversation_id, thread_root_id, id, author_id, deleted_at);
CREATE TABLE nowo_chat_message_revision (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, edited_at DATETIME NOT NULL,
  message_id INT NOT NULL, INDEX idx_nowo_chat_revision_message (message_id, id), PRIMARY KEY (id));
CREATE TABLE nowo_chat_thread_read (id INT AUTO_INCREMENT NOT NULL, last_read_reply_id INT DEFAULT NULL,
  following TINYINT(1) DEFAULT 1 NOT NULL, updated_at DATETIME NOT NULL, root_message_id INT NOT NULL, user_id INT NOT NULL,
  UNIQUE INDEX uniq_nowo_chat_thread_read (root_message_id, user_id), PRIMARY KEY (id));
CREATE TABLE nowo_chat_idempotency (id INT AUTO_INCREMENT NOT NULL, result JSON DEFAULT NULL, created_at DATETIME NOT NULL,
  idempotency_key VARCHAR(80) NOT NULL, request_hash VARCHAR(64) NOT NULL, user_id INT NOT NULL,
  INDEX idx_nowo_chat_idempotency_at (created_at), UNIQUE INDEX uniq_nowo_chat_idempotency (user_id, idempotency_key), PRIMARY KEY (id));
-- plus the foreign keys Doctrine generates: thread_root_id and the revision / thread tables ON DELETE CASCADE,
-- the forward columns ON DELETE SET NULL
```

**Nothing changes until you enable the items:**

```yaml
nowo_chat_kit:
    profiles:
        default:
            message_menu:
                forward: { enabled: true }        # max_targets: 5, from_support: false
                threads: { enabled: true }
                edit_history: { enabled: true }   # max_revisions: 20
```

- A host whose `message_menu.order` lists the items keeps its order: add `thread` and `forward` to show them.
- A host that overrides `nowo_chat_message_menu` renders the new items with `data-part="menu-thread"` and
  `data-part="menu-forward"`.
- Implementations of `ChatMessageActionsInterface` (introduced in 1.35) add `forward()`, `thread()`,
  `replyInThread()` and `revisions()`.
- `ChatService::edit()` with the same text as stored is no longer an edit (no "edited" mark, no event).
- Messages of the main timeline, the unread counts, the list previews and `findLatest()` leave thread replies out.
  Queries of your own on `nowo_chat_message` should add `thread_root_id IS NULL` where they mean the main timeline.
- **Privacy:** previous texts and thread read state are personal data. Document them in your privacy notice;
  `ChatPersonalData` exports and erases them.

## To 1.35.0

From **1.34.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

From **1.34.1**:

```bash
composer update nowo-tech/chat-kit-bundle nowo-tech/ui-kit-bundle nowo-tech/transaction-doctrine   # ui-kit ^2.58.1, transaction-doctrine ^1.6
php bin/console doctrine:migrations:diff   # then review and run the migration
```

**Schema (additive).** The richer message menu adds:

- two tables: `nowo_chat_saved_message` and `nowo_chat_read_mark`;
- three columns with defaults, so there is no data migration: `nowo_chat_participant.receipt_message_id` (nullable;
  null means "same as `last_read_message_id`"), `nowo_chat_user_settings.share_read_times` (default true) and
  `nowo_chat_user_settings.read_times_shared_since` (nullable).

The names follow `doctrine.table_name_prefix` / `table_prefix`, and the user columns follow `doctrine.user_reference`,
as every other chat table. On MySQL / MariaDB (default names, `relation` mode) the migration is equivalent to:

```sql
ALTER TABLE nowo_chat_participant ADD receipt_message_id INT DEFAULT NULL;
ALTER TABLE nowo_chat_user_settings ADD share_read_times TINYINT(1) DEFAULT 1 NOT NULL, ADD read_times_shared_since DATETIME DEFAULT NULL;
CREATE TABLE nowo_chat_saved_message (id INT AUTO_INCREMENT NOT NULL, saved_at DATETIME NOT NULL, message_id INT NOT NULL,
  user_id INT NOT NULL, INDEX idx_nowo_chat_saved_message_list (user_id, saved_at, id),
  UNIQUE INDEX uniq_nowo_chat_saved_message (user_id, message_id), PRIMARY KEY (id));
CREATE TABLE nowo_chat_read_mark (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, up_to_message_id INT NOT NULL, read_at DATETIME NOT NULL,
  participant_id INT NOT NULL, INDEX idx_nowo_chat_read_mark_upto (participant_id, up_to_message_id),
  INDEX idx_nowo_chat_read_mark_at (read_at), PRIMARY KEY (id));
-- plus the foreign keys (ON DELETE CASCADE) Doctrine generates
```

**What appears without configuration.** The message menu now shows the quick reactions row (with **More…**), Quote,
Copy text and Mark unread from here, with separators between groups. Save for later and Message info stay off until
you enable them:

```yaml
nowo_chat_kit:
    profiles:
        default:
            message_menu:
                save: { enabled: true }          # Saved list: GET /chat/saved, dock entry
                message_info: { enabled: true }  # who read it (and when: reciprocal preference)
```

- With `quick_reactions.picker` (default on), the reaction endpoint also accepts the picker's emoji. Set it to `false`
  to keep only `reactions`. A `quick_reactions.emojis` entry outside the accepted set fails the container build.
- **Conflict answers** carry `code` (`CONFLICT_001` | `CONFLICT_002`) in the JSON body and the `X-Conflict-Code`
  header. Retry on `CONFLICT_001` (with `Retry-After`), read again on `CONFLICT_002`.
- **Privacy:** read times are personal data. Message info stores them only with `message_info.read_times` (default
  true once `message_info` is enabled), and only for people who keep **Share read times** on. Document the purpose in
  your privacy notice. Your GDPR export and erasure through `ChatPersonalData` cover the new rows.
- A host that overrides the `nowo_chat_message_menu` block keeps its markup. To get the new items, render them with
  their `data-part="menu-<name>"` (see [USAGE](USAGE.md#message-menu-135)).

## To 1.34.0

From **1.33.0**:

```bash
composer update nowo-tech/chat-kit-bundle nowo-tech/transaction-doctrine
```

**409 (was 503).** A transient storage failure on a ChatKit route (deadlock, lock wait timeout, `TransientStorageFailure`)
now answers **409 Conflict + `Retry-After`**, the status of the shared `ConflictResponsePolicy` of
nowo-tech/transaction-doctrine 1.3 (required `^1.3`). The API body (`{"ok": false, "error": "unavailable"}`) and the
unique-key 409 (`"conflict"`) are unchanged. `conflict_response.status: 503` keeps the old status:

```yaml
# config/packages/nowo_transaction_doctrine.yaml
nowo_transaction_doctrine:
    conflict_response:
        status: 503   # 409 (default) or 503
        retry_after: 1
```

- **Hosts that never register `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` move to 409 too** (the
  listener falls back to `new ConflictResponsePolicy()`: 409, 1 s). The only way back to 503 is to register that bundle
  in `config/bundles.php` and set `conflict_response.status: 503`.
- Clients that retry on 503 + `Retry-After` must also retry on 409 + `Retry-After`; tell `error: "unavailable"` (retry the
  same request) from `error: "conflict"` (another request won).
- A write that already committed part of its work (`ConcurrencyConflict` with `committed: true`) is answered with the same
  status but **without** `Retry-After` and with `message` asking to reload: never retry it blindly.
- Chat pages now throw `HttpException(409|503)` with the storage error as previous, instead of
  `ServiceUnavailableHttpException`; match on the status, not the class, in a custom error page.
- New `ChatConflictResponseFormatter` (tag `nowo_transaction_doctrine.conflict_response_formatter`): if you list ChatKit
  routes in `nowo_transaction_doctrine.controller_routes`, the responder's answer for `nowo_chat_api_*` routes uses
  ChatKit's JSON shape.

## To 1.33.0

From **1.32.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Requires `nowo-tech/ui-kit-bundle` **^2.54** (`composer update nowo-tech/ui-kit-bundle nowo-tech/chat-kit-bundle`). No BC break
in behaviour, but the markup changes: hosts with snapshot (golden-file) tests of the dock or message markup, or of the dock
configuration (`endpoints.messageLink`), will see a diff and must update them.

- Every message now has a **More actions** kebab (UiKit kebab, Reply / Edit / Copy link / Pin / Delete) and is focusable
  (`tabindex="0"`, `data-nowo-ui-context-menu-scope`). A host that overrides the `message` template (block
  `nowo_chat_template_messages`) keeps its markup and gets no kebab; to add it, copy the block `nowo_chat_message_menu`
  (keep its `data-part` attributes). To restyle it use the `.nowo-chat-msg__menu` rules.
- The **context menu** is off unless you opt in: set UiKit's `nowo_ui_kit.theme.context_menus: true` (your layout's
  UiKit `scripts` partial loads the runtime) or `nowo_chat_kit.profiles.<name>.web_ui.message_context_menu: true` (the
  dock loads UiKit's runtime itself). `false` keeps the browser's menu on messages even under the theme flag.
- New endpoint `POST /api/conversations/{uuid}/messages/{messageUuid}/link` → `{path}` (in the bundle's routes; nothing
  to import). It answers a root-relative path; the dock prefixes the page's own origin, so the `Host` header is never
  used. Same-origin check: the browser's `Sec-Fetch-Site` decides when it is sent (`same-origin` only); without it the
  `Origin` header is compared with the scheme and host Symfony sees. Behind a reverse proxy that terminates TLS, set
  `framework.trusted_proxies` (and the forwarded headers) so that scheme and host are the browser's, and
  `framework.trusted_hosts` to your domains; otherwise a browser that sends `Origin` without `Sec-Fetch-Site` gets
  `403 cross_origin` on Copy link. A client sending neither header still needs the CSRF header.
- If your application stores or renders its own message HTML, note that the presenter now strips
  `data-nowo-ui-context-menu*` and `data-nowo-ui-card` from it.

## To 1.32.0

From **1.31.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target 1.32.0, from **1.31.x**. Nothing to change: without `runtime.retention_days` the bundle behaves exactly as in
1.31.1.

Optional: read the purge retention from a runtime setting (`.env` or database, changed live):

```bash
composer require nowo-tech/runtime-setting-contract:^1.0
```

```yaml
# config/bundles.php: Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle::class => ['all' => true]
# Source and writer: InstanceSettingsBundle >= 1.20 (runtime_setting.enabled: true binds them), RuntimeEnvBundle >= 1.6,
# or your own (nowo_runtime_setting: { source: <service id>, writer: <service id> }).
nowo_chat_kit:
    profiles:
        default:
            retention_days: '%env(int:CHAT_RETENTION_DAYS)%'   # stays the env fallback
            runtime:
                retention_days: chat.retention_days           # = { source: db_then_env, key: chat.retention_days }
```

- If you aliased `RetentionPolicyProviderInterface` to your own provider (1.31), remove the alias: the build refuses both
  (two sources for one value).
- If your application already registers its own runtime-setting definition for the chat retention (for example id
  `ops.chat_retention_days`), remove that definition and set ChatKit's `key` to the same id:
  `runtime: { retention_days: { key: ops.chat_retention_days, source: db_then_env } }`. Keeping both fails with a
  duplicate-definition error at runtime; keeping yours with ChatKit's default key makes ChatKit read
  `chat.retention_days` and ignore your stored value.
- Prefer `source: db_then_env` (the short form's default): unset means the configured `retention_days`. With
  `source: db` an unset value makes the purge fail and delete nothing (there is no bundle default).
- Values are bounded to 30..36500 days for every source: `nowo:runtime-setting:set` refuses anything else, and a value
  outside the bounds found in the source (a hand-edited row) makes the purge fail without deleting anything. Unlike the
  1.31 provider, a value below 30 is refused, not raised to 30.
- `on_error: fallback` is not accepted for this setting: the purge deletes data, so it fails closed.

## To 1.31.1

From **1.31.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target 1.31.1. Hosts whose `retention_days` resolves to 0 or a negative number (for example `%env(int:CHAT_RETENTION_DAYS)%`
with a value such as `'1e100'`, which the `int:` processor turns into 0, or `'-5'`) will now see `nowo:chat:purge` log an
error ("Chat purge aborted") and exit with a failure instead of purging with a 30-day window. A non-numeric or empty
variable (`'abc'`, `''`) was never coerced: the `int:` processor throws when the command is built, so the command already
failed closed, without that log line. Fix the variable. Valid values (also 1 to 29, still raised to 30) and a
`RetentionPolicyProviderInterface` answering a usable value are unaffected.

## To 1.31.0

From **1.30.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Nothing to change: without a provider `retention_days` applies exactly as before.

Optional: read the retention from your own storage. Implement `Nowo\ChatKitBundle\Contract\RetentionPolicyProviderInterface`
and alias it (see [USAGE](USAGE.md#retentionpolicyproviderinterface-optional)). `nowo:chat:purge` asks it on every run;
null falls back to `retention_days`; the minimum of 30 days stays. The provider is not cached by the bundle: do not
memoize the answer in the service either, or a worker keeps the old value.

## To 1.30.0

From **1.29.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target 1.30.0 (Beacon spec 239, load readiness).

- **New requires:** `nowo-tech/transaction-contract` ^1.0 and `nowo-tech/transaction-doctrine` ^1.1 (doctrine/dbal
  `^3.8.2 || ^4`, as before).
- **DBAL 3 only:** turn savepoints on (`doctrine.dbal.use_savepoints: true`; DBAL 4 always uses them). ChatKit's units
  of work nest inside your transactions and its race handling rolls back to savepoints; without them a refused or lost
  insert inside your transaction marks it rollback-only.
- **Schema:** one new table, `nowo_chat_ticket_counter` (with your `doctrine.table_name_prefix` / `table_prefix`).
  Generate a migration (`doctrine:migrations:diff`) or update the schema. Seeding it in the migration is optional:

  ```sql
  INSERT INTO nowo_chat_ticket_counter (counter_key, last_number)
  SELECT 'nowo_chat_conversation', COALESCE(MAX(ticket_number), 0) FROM nowo_chat_conversation;
  ```

  (both table names with your prefixes). Without it, the first ticket opened after the upgrade starts the counter after
  the highest existing number, without locking the conversation table.
- **Transaction runner:** ChatKit runs its writes on its own runner (`nowo_chat_kit.transaction_runner`) and never on a
  runner your application binds to `Nowo\TransactionContract\TransactionRunnerInterface` (DoctrineDeadlockRetryBundle's
  adapter, your own): such a runner drives your application's entity manager, so it would flush and commit your pending
  work with a chat write, and reset your manager after a chat failure. ChatKit defines no `TransactionRunnerInterface`
  service for your application. Transient failures are not retried by ChatKit: they answer 503 + `Retry-After`.
- **Ticket numbers:** still sequential and gap-free (a failed opening hands no number out); new guarantee: a number is
  never given again, even after the ticket was purged or erased. Numbers written outside ChatKit are skipped (the
  counter catches up).
- **Failures:** a deadlock, lock wait timeout or serialization failure on a ChatKit route answers 503 with
  `Retry-After` (was 500); a lost unique key race that cannot be resolved by reading the winner's row answers 409. The
  dock shows its usual error for a failed request (the person sends again); custom clients of the JSON API should retry
  a 503 after `Retry-After`.
- **Your entity manager after a failed ChatKit write** (`doctrine.private_entity_manager` off, ChatKit shares it): it is
  never reset or cleared by ChatKit, and your entities stay managed. Your pending changes are flushed on their own before
  the chat unit of work, so a chat failure never rolls them back. Refusals (look-alike users, limits, validation) come
  before any write, or roll the chat rows back. Only the ChatKit rows the failed unit added or changed are detached;
  ChatKit loads them again on its next call. A deadlock or lock wait inside the chat flush closes the manager (the ORM
  does, as in 1.29): your next flush in that request fails loudly, and ChatKit replaces the closed manager at the next
  request, message or command boundary, so a long-running worker never serves a later request with it. With the private
  entity manager on, your manager is never involved at all.
- **Workers:** for Messenger consumers and custom loops, see USAGE "High load"; DoctrineDeadlockRetryBundle's
  `worker_guard` is the recommended companion for your own writes.

## To 1.29.0

From **1.28.0**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target 1.29.0, from **1.28.0**: additive, no migration, no configuration change.

```bash
composer update nowo-tech/chat-kit-bundle
```

- **Nothing changes until you alias the new port.** Without a `ChatAuthorBadgeProviderInterface` alias the page, the
  dock markup, the message API and the realtime payloads are byte for byte those of 1.28.0 (golden tests).
- **Author badges (optional).** Implement `Nowo\ChatKitBundle\Contract\ChatAuthorBadgeProviderInterface` and alias it
  ([USAGE — Author badges](USAGE.md#author-badges)). Answer by `ChatAuthorRef::$reference` exactly as given, in one
  query for the whole batch; keep no state between requests.
- **JSON consumers.** Messages may carry a new `authorBadges` array (`{label, variant, title}`); it is absent when the
  author has none. Render `label` / `title` as text only.
- **Template overrides.** If you override `page.html.twig` / `page_legacy.html.twig` (`nowo_chat_page_thread`) or the
  dock's `nowo_chat_template_messages` block, your copies keep working without badges; to show them, include
  `@NowoChatKitBundle/_author_badges.html.twig` after the author's name (page) or keep the
  `nowo_chat_template_author_badge` block (dock; without it the script draws a plain `span`). Restyle with the
  `.nowo-chat-author-badge` classes.
- **Assets.** Rebuild or re-install `nowo-chat.js` / `nowo-chat.css` (`assets:install`) if you bundle them yourself.

## To 1.28.0

From **1.27.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Target 1.28.0, from **1.27.1**:

```bash
composer update nowo-tech/chat-kit-bundle
```

Only `doctrine.user_reference: string`, and only on a database whose user columns fold although the schema check
passed (a column altered, or a migration rolled back, after the check of the request, or a connection switched to
another database inside the request; since 1.27.1 the check runs once per request and connection).

A read by user that comes back with a look-alike's row still ignores it and degrades — unsaved defaults from
`ChatUserSettingsRepository::forUser()`, null from the `findOneFor()` of the participant and reaction repositories —
and now logs a PSR-3 **warning** naming the column and a keyed hash of the asked user's reference (never the
reference itself; see SECURITY.md "Logging"), once per column and user key and request. New: the write into that same
column that would follow such a read throws `ChatUserLookAlikeException` (a `LogicException`) before `persist()`
(`ChatService::participant()` and the methods that go through it, `ChatService::toggleReaction()`,
`ChatUserSettingsRepository::save()`), because that insert would hit the look-alike's unique key inside the flush and
close the host entity manager. `ChatAutoReplier` catches it and skips the automatic reply, so sending a message never
fails for this. Writes to other tables are not affected.

Two writes that have no read before them are protected too: `ChatService::openDirect()` between two references that a
folding column could take for one (`bob` / `Bob`) throws `ChatUserLookAlikeException` and writes nothing when the
participant column folds (inside one transaction, so nothing is left behind), and `ChatMentions::sync()` writes the
second of two such mention rows only after a read shows the column keeps them apart: on a folding column the message
keeps one row and the second person is notified once. On exact columns nothing visible changes: both participant
rows and both mention rows are written, and everyone is notified once.

`ChatPersonalData::export()` now filters every list byte for byte, so on a folding column the export never contains a
look-alike's messages, pins, quick replies or links (it did in 1.27.0 and 1.27.1). Nothing to change in the host.

If those warnings appear, run the `ALTER TABLE` of ["String user references"](#to-1270) again: the chat is reading
and writing on columns that treat `bob`, `Bob` and `bob ` as one person.

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
