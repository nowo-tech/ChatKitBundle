# Upgrading

This guide lists the changes integrators must apply when upgrading between versions.

## Table of contents

- [1.4.0 (2026-10-16)](#140-2026-10-16)
- [1.3.1 (2026-10-16)](#131-2026-10-16)
- [1.3.0 (2026-10-16)](#130-2026-10-16)
- [1.2.0 (2026-10-16)](#120-2026-10-16)
- [1.1.1 (2026-10-16)](#111-2026-10-16)
- [1.1.0 (2026-10-16)](#110-2026-10-16)
- [1.0.0 (2026-10-16)](#100-2026-10-16)

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
