# Templates

ChatKitBundle follows the UiKit 2.x **page contract** (REQ-UI-003, `nowo-tech/ui-kit-bundle` ^2.2). It ships one page
(the full-page chat, a unique page on the UiKit admin layout) and one **widget** (the floating dock, included by the
host layout). References: UiKit `docs/PAGE_CONTRACT.md`, `docs/THEME_SLOTS.md`.

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Widget: the dock](#widget-the-dock)
- [Per-page overrides](#per-page-overrides)
- [Layout](#layout)
- [Tokens](#tokens)
- [Allowed exceptions](#allowed-exceptions)

## Pages

No page fits a scaffold: the chat (conversation list + thread + composer) is listed under
[Unique pages](#unique-pages).

## Unique pages

- `page.html.twig` — full-page chat (`/chat/`, `/chat/{uuid}`; the dock's "open in a page", the small-screen view and
  the no-JS fallback). Extends `layout ?? nowo_ui_layout.admin` (`layout` = `web_ui.layout_template`), sets
  `nowo_ui_bundle` (`NowoChatKitBundle`) and fills `nowo_ui_document_title` (conversation title or `chat.page_title`),
  `nowo_ui_styles` / `nowo_ui_scripts` (chat stylesheet and script through the `nowo_chat_kit` asset package,
  stacked with `parent()`), `nowo_ui_page_header` (UiKit `page_header` partial; `header` =
  `Nowo\ChatKitBundle\View\ChatPage::header()`, title "Messages" (`chat.page_title`)) and `nowo_ui_content`. Content blocks:
  `nowo_chat_page_list` (conversation list) and `nowo_chat_page_thread` (thread header, messages, composer form).
  Theme slots: `section` (+ attributes, root `[data-testid="chat-page"]`), `empty`, `muted`, `flash_error` (send
  errors), `btn_link` (back to the list, phones), `btn_primary` (send), `form` (composer). The chat surfaces
  (`nowo-chat-page__*`, bubbles) are painted by `nowo-chat.css` from the `--nowo-chat-*` tokens.

Context: `layout`, `header` (`PageHeader`), `bundle`, `conversations` (summaries: `uuid`, `title`, `subtitle`,
`unread`, `lastMessage.preview`, …), `current` (summary or null), `messages` (presented messages), `read` (mark-read
call: `url`, `messageId`, `csrf`, or null), `can_send`, `composer` (`FormView` of `ChatPageMessageType`).

## Widget: the dock

`dock.html.twig` (`{{ include('@NowoChatKitBundle/dock.html.twig') }}` in the signed-in layout) and its markup in
`_templates.html.twig` (`<template>` elements cloned by `nowo-chat.js`). Blocks: `nowo_chat_assets`, `nowo_chat_root`
in the dock; every part of `_templates.html.twig` is a block (override one with
`{% extends '@!NowoChatKitBundle/_templates.html.twig' %}`). The dock keeps its own `nowo-chat-*` classes (no
framework classes): it is themed through the `--nowo-chat-*` tokens, which default to the UiKit tokens (below). Its
root carries `data-nowo-ui-bundle="NowoChatKitBundle"`, so per-bundle tokens (`theme.bundles.NowoChatKitBundle.tokens`)
apply to it. `{{ include('@NowoChatKitBundle/dock.html.twig', {nowo_chat_manual: true}) }}` marks the root
`data-nowo-chat-manual`: the automatic start of `nowo-chat.js` skips it, for a dock the host mounts itself (see
[USAGE — Front end](USAGE.md#front-end-iife-or-your-bundler)).

## Per-page overrides

`nowo_chat_kit.profiles.<profile>.web_ui.templates.page`: a host template that
`{% extends '@!NowoChatKitBundle/page.html.twig' %}` and redefines `nowo_chat_page_list`, `nowo_chat_page_thread` or
`nowo_chat_page_script` (the `nowo-chat.js` tag; empty it when the host loads the chat script itself) (or a file under `templates/bundles/NowoChatKitBundle/` doing the same). Do not copy the whole page.

## Layout

`web_ui.layout_template` — default `null` = `nowo_ui_kit.layouts.admin` (the host layout or bridge that renders the
UiKit canonical blocks). The deprecated `page_layout` (a 1.x layout with `title` / `stylesheets` / `javascripts` /
`body` blocks) keeps working until 2.0: when it is set and `web_ui.layout_template` is not, the page renders the 1.x
template `page_legacy.html.twig` (deprecated) in that layout.

## Tokens

`nowo-chat.css` reads `--nowo-chat-*` tokens; their defaults read the UiKit tokens first (`--nowo-ui-primary`,
`--nowo-ui-btn-primary-hover-bg`, `--nowo-ui-primary-contrast`, `--nowo-ui-text`, `--nowo-ui-surface`,
`--nowo-ui-surface-muted`, `--nowo-ui-border`, `--nowo-ui-danger`, `--nowo-ui-font-display`, `--nowo-ui-radius`) and
fall back to the 1.x light / dark palette. A host that sets its own `--nowo-chat-*` values keeps them (the defaults
sit in `:where(:root)`, zero specificity).

## Allowed exceptions

None: the contract test (`tests/Integration/PageContractTest.php`) runs with the default allow-list.
