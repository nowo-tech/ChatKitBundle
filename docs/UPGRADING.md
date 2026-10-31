# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
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
