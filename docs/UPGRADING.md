# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [Unreleased](#unreleased)
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
