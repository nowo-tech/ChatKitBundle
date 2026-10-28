# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [Contracts](#contracts)
- [Conversations and permissions](#conversations-and-permissions)
- [Opening conversations from your pages](#opening-conversations-from-your-pages)
- [Support inbox](#support-inbox)
- [Full-page chat](#full-page-chat)
- [Events](#events)
- [Mentions](#mentions)
- [Link previews](#link-previews)
- [Email digest](#email-digest)
- [Customising the markup](#customising-the-markup)
- [Theme tokens](#theme-tokens)
- [Translations](#translations)
- [Front end: IIFE or your bundler](#front-end-iife-or-your-bundler)
- [User settings](#user-settings)
- [Web Push](#web-push)
- [Messenger: retries and failures](#messenger-retries-and-failures)
- [Timeouts](#timeouts)
- [Privacy](#privacy)
- [JSON API](#json-api)
- [Naming conventions](#naming-conventions)

## How it fits together

Your application stays the source of truth for **people and permissions** (contracts below). The bundle owns the
**conversations** (Doctrine entities), the **JSON API**, **realtime fan-out**, **presence**, **Web Push selection**,
the **Twig markup** and the **TypeScript dock** that works on it.

## Contracts

### `ChatDirectoryInterface` (required)

```php
public function findByPublicId(string $publicId): ?ChatUserInterface;
public function findByIds(array $ids): array;                       // list<ChatUserInterface>
public function contactsFor(ChatUserInterface $actor, ?string $query, int $limit): array; // list<ChatContact>
public function supportAgents(): array;                             // who answers the support inbox
```

`ChatContact($user, $source, $via)` groups the people picker: `source` is free (`group`, `project`, `search` …) and
picks the heading `chat.people_<source>` (fallback `chat.people_other`); `via` fills `chat.via_<source>`
("Group · Backend"), else the email is shown. The bundle filters contacts with the access policy again.

### `ChatAccessPolicyInterface` (required)

```php
public function isReachable(ChatUserInterface $user): bool;          // active account?
public function isSupportAgent(ChatUserInterface $user): bool;       // answers support, resolves
public function canMessage(ChatUserInterface $actor, ChatUserInterface $other): bool;
```

Evaluated on every request (memoize per request and implement `ResetInterface` for FrankenPHP workers).

### `ChatChannelProviderInterface` (optional)

```php
public function channelsFor(ChatUserInterface $user): array;        // list<ChatChannel>
public function find(string $key): ?ChatChannel;
public function contextChannel(ChatUserInterface $user, Request $request): ?ChatChannel; // the current page's channel
public function canUse(ChatUserInterface $user, ChatChannel $channel): bool;
public function members(ChatChannel $channel): array;               // realtime recipients
```

`ChatChannel($key, $label)`: a stable key up to 120 characters, e.g. `project:<uuid>`. The channel of the page being
viewed is offered first in the people picker.

### `ChatRealtimeInterface` (optional)

`MercureChatRealtime` is used when `symfony/mercure-bundle` has a hub; otherwise `NullChatRealtime` (polling). Implement
it to reuse your own hub configuration (dynamic URLs, same-origin hub URL…).

### `ChatPushSenderInterface` (optional)

```php
public function send(array $recipients, callable $payloadFor): void; // $payloadFor($user) → title, body, tag, url, icon…
```

The bundle has already chosen the recipients; deliver to their push subscriptions.

### `ChatLinkPreviewProviderInterface` (optional)

```php
public function previews(ChatUserInterface $viewer, array $urls): array; // url => ChatLinkPreview($title, $subtitle, $badge)
```

See [Link previews](#link-previews).

### `ChatDigestSenderInterface` (optional)

```php
public function send(ChatUserInterface $user, array $items): void; // list<ChatDigestItem($title, $unread, $preview, $url)>
```

See [Email digest](#email-digest).

## Conversations and permissions

| Kind | Who | Notes |
| --- | --- | --- |
| Direct | two people the policy allows | Stays readable for both when the policy stops allowing it; writable again only if allowed (or replying to a support agent). |
| Channel | everyone `canUse()` allows | One per key. |
| Support | the owner and every support agent | Agents resolve; the owner's next message reopens it. |

Messages: Markdown rendered and sanitised on the server (escape first, closed tag set, http(s) links only). The author
edits or deletes within 15 minutes (tombstone kept); nobody, agents included, deletes someone else's message. Reactions: the
configured emojis. Buzz: direct conversations only, rate limited.

Unknown or foreign UUIDs answer 404 (never 403); every response is `Cache-Control: no-store`.

## Opening conversations from your pages

Any element with `data-nowo-chat-open` opens a conversation in the dock when clicked:

```twig
<a href="#" data-nowo-chat-open="support"
   data-nowo-chat-context-url="{{ app.request.uri }}" data-nowo-chat-context-label="Invoice #42">Report a problem</a>
<a href="#" data-nowo-chat-open="user:{{ colleague.chatPublicId }}">Message</a>
<a href="#" data-nowo-chat-open="channel:project:{{ project.uuid }}">Project chat</a>
<a href="{{ path('nowo_chat_page_conversation', {uuid: thread.uuid}) }}" data-nowo-chat-open="conversation:{{ thread.uuid }}">Open</a>
```

The dock handles the click; without JavaScript the `href` still works (e.g. the full-page chat).

`support` opens (or creates) the user's support thread; the optional context (`url`, `label`) is stored on the thread and
shown to the agents as a "Reported from" banner.

## Support inbox

Support agents (`ChatAccessPolicyInterface::isSupportAgent()`) see every support thread in the dock. For an inbox page of
your own, `GET /api/support/inbox?status=open|resolved|mine|all&q=` returns the threads (each with its `owner`) and the
`open` count; `ChatSupportInbox` gives the same data in PHP (e.g. a menu counter: `countOpen()`). Agents resolve and
reopen threads (`ChatService::resolveSupport()` / `reopenSupport()`, or the API); a new message from the owner reopens
them too.

## Full-page chat

`/chat/` (route `nowo_chat_page`) and `/chat/{uuid}` (`nowo_chat_page_conversation`) render the list and one thread on
the server: a plain form posts the message and redirects back (works without JavaScript, same permissions, CSRF and
rate limit as the API). Every dock window has an "Open in a page" link to it. The page follows the UiKit page contract
(REQ-UI-003, [TEMPLATES.md](TEMPLATES.md)): it extends the UiKit admin layout (`nowo_ui_kit.layouts.admin`, or
`web_ui.layout_template` per profile), puts its title in the UiKit page header and stacks `css/nowo-chat.css` /
`js/nowo-chat.js` on `nowo_ui_styles` / `nowo_ui_scripts` with `parent()`, so the chat is styled even when your layout
does not include the dock (when it does, the dock does not load them a second time). Include the dock in that layout
(e.g. in `nowo_ui_modals`):

```yaml
nowo_ui_kit:
    layouts:
        admin: 'layouts/admin.html.twig'   # renders the UiKit canonical blocks
nowo_chat_kit:
    profiles:
        default:
            web_ui:
                layout_template: null      # null = nowo_ui_kit.layouts.admin
```

The 1.x `page_layout` option (a layout with `title`, `stylesheets`, `javascripts` and `body` blocks) is deprecated:
while it is the only layout set, the page keeps its 1.x template (`page_legacy.html.twig`).

Opening `/chat/{uuid}` is a plain GET that changes nothing. With JavaScript, `nowo-chat.js` marks the conversation read
through the CSRF-protected `POST /api/conversations/{uuid}/read` (the page carries the URL, the newest message id and the
header token in `data-nowo-chat-read-*` attributes); without JavaScript, sending a message from the page marks the
conversation read up to that message.

To change one part, point `web_ui.templates.page` at a template that extends `@!NowoChatKitBundle/page.html.twig`
and overrides `nowo_chat_page_list` or `nowo_chat_page_thread`. Theme the page and the dock once with UiKit: the
`--nowo-chat-*` tokens default to the `--nowo-ui-*` tokens. With the route prefix `/chat`, the list lives at `/chat/` (Symfony redirects `/chat` there).

## Events

| Event | When | Typical use |
| --- | --- | --- |
| `ChatMessageSentEvent` | a message or buzz was stored and published | counters, bots, audit |
| `ChatMessageDeletedEvent` | a message was deleted (by its author; `moderated` is true only on tombstones from before 1.4) | audit |
| `ChatSupportStatusChangedEvent` | a support thread was resolved (`by` = agent) or reopened (`by` = owner) | inbox counters, notifications |
| `ChatMentionEvent` | a stored message mentions members (`users`: only the people an edit adds; `edited`) | notify the mentioned people |

## Mentions

Typing `@` in the composer or the inline editor opens the conversation's members (`GET
/api/conversations/{uuid}/members?q=`: the people who may read it now, not the caller, at most 20). Picking one writes
`@Name` in the box and sends the token `@[Name](publicId)`. On send and edit `ChatMentions` keeps a token only for a
member who may read the conversation (never the author; at most 20), rewrites its name to the current display name and
stores a `ChatMessageMention` row; any other token becomes plain `@name` text. The message renders the token as a chip
(`.nowo-chat-mention[data-mention]`), and a message that mentions the viewer gets `nowo-chat-msg--mentioned`.

Notify the people in your own channels (toast, push, e-mail) from the event, after checking they can still read the
conversation:

```php
#[AsEventListener]
public function __invoke(ChatMentionEvent $event): void
{
    foreach ($event->users as $user) {
        // $event->message, $event->conversation; one event per message and person (edits carry only new people)
    }
}
```

For an inbox of "messages that mention me", `ChatMessageMentionRepository::findForUser($user, $channelKeys,
$isSupportAgent, $limit, $unreadOnly, $since, $channelsOnly)` and `countForUser()` return only mentions in direct
threads, the channels you pass (`ChatChannelProviderInterface::channelsFor()` keys) and the support threads the user may
read; `read_at` is set when the user reads the conversation past the message (or you call `markRead()` and flush).
`countUnreadByConversation()` gives an "@" count per conversation.

## Link previews

Messages that contain links to your application (absolute or root-relative URLs) can show a small card. The dock asks
`GET /api/link-previews?url[]=…` (at most 10 URLs per request, batched and cached per page) and your
`ChatLinkPreviewProviderInterface` answers **for the viewer**: leave out any URL the viewer may not see, so a preview
never leaks a title. The default provider returns nothing (no cards).

## Email digest

```yaml
nowo_chat_kit:
    profiles:
        default:
            digest: { enabled: true, delay_minutes: 15, interval_minutes: 60 }
```

Each new message schedules (through Messenger, with a delay) one check per recipient. When it runs, conversations still
unread are collected and passed to your `ChatDigestSenderInterface` — unless the user has the application open, opted out
(`ChatUserSettings::setEmailDigest(false)`) or got a digest less than `interval_minutes` ago. You send the email with your
own mailer and templates. Route `SendChatDigestMessage` to an async transport.

## Customising the markup

`@NowoChatKitBundle/dock.html.twig` renders the root and includes `@NowoChatKitBundle/_templates.html.twig`: every
piece of the UI is a `<template data-nowo-chat-template="…">` the script clones and fills through `data-part` hooks
(text always via `textContent`).

Change part of it — e.g. add a class to the message bubble — by extending the original:

```twig
{# templates/bundles/NowoChatKitBundle/_templates.html.twig #}
{% extends '@!NowoChatKitBundle/_templates.html.twig' %}

{% block nowo_chat_template_messages %}
    {# copy the block, keep the data-part / data-icon attributes, change the rest #}
{% endblock %}
```

Blocks: `nowo_chat_template_dock`, `_row`, `_list_state`, `_picker`, `_overflow_item`, `_window`, `_messages`,
`nowo_chat_icons`. In `dock.html.twig`: `nowo_chat_assets` (the `<link>` / `<script>`; empty it when your bundler
ships them) and `nowo_chat_root`.

Rules: keep every `data-part` (the script throws a clear error naming a missing one), `data-filter` on the filter chips
and `data-icon` on icon slots. An empty `data-icon` span gets the icon; put your own SVG inside it to replace one icon.

### Overridable templates

Any file below can be overridden by creating `templates/bundles/NowoChatKitBundle/<subpath>` in the application; the
application's copy always wins.

| `<subpath>` | What it renders | Blocks |
| --- | --- | --- |
| `dock.html.twig` | Dock root (config attribute) and assets | `nowo_chat_assets`, `nowo_chat_root` |
| `_templates.html.twig` | Every `<template>` the dock clones | `nowo_chat_template_dock`, `_row`, `_list_state`, `_picker`, `_overflow_item`, `_window`, `_messages`, `nowo_chat_icons` |
| `page.html.twig` | Full-page chat (list + thread + composer form), UiKit layout | `nowo_ui_document_title`, `nowo_ui_styles`, `nowo_ui_scripts`, `nowo_chat_page_script` (the `nowo-chat.js` tag), `nowo_ui_page_header`, `nowo_ui_content`, `nowo_chat_page_list`, `nowo_chat_page_thread` |
| `page_legacy.html.twig` | Deprecated 1.x page (only with the deprecated `page_layout`) | `title`, `stylesheets`, `javascripts`, `body`, `nowo_chat_page_list`, `nowo_chat_page_thread` |
| `page_layout.html.twig` | Deprecated 1.x default layout of `page_legacy.html.twig` | `title`, `stylesheets`, `javascripts`, `body` |

**Procedure.** Create the file under `templates/bundles/NowoChatKitBundle/`, start it with
`{% extends '@!NowoChatKitBundle/<subpath>' %}` and override only the blocks you need, then
`php bin/console cache:clear`.

**Freeze vs upgrade.** Extending and overriding single blocks keeps you on the bundle's markup for everything else, so
upgrades bring fixes automatically. Copying a whole file freezes it: you own it from then on and must re-apply
upstream changes yourself (check `UPGRADING.md` on every upgrade). Prefer the smallest override.

### Forms

The full-page composer is a Symfony Form (`ChatPageMessageType`, CSRF token id `nowo_chat_page`) rendered with
`form_start` / the `{% for child in form %}{% if not child.rendered %}{{ form_row(child) }}…` loop / `form_end`; overrides
of `page.html.twig` must keep that pattern and never reintroduce hand-written form tags or inputs.

The dock markup in `_templates.html.twig` follows the same rule: its search boxes, window composer and inline message
editor are Symfony Form views from `nowo_chat_dock_forms()` (`ChatDockSearchType` — children `query` and `people`,
`ChatDockComposerType` and `ChatDockEditType` — child `body`), rendered with `form_start` / `form_widget` / the child
loop / `form_end`. The types carry the attributes the script relies on (`class`, `data-part`, `rows`, `autocomplete`);
the template adds the placeholder and `aria-label` translated in the dock locale. The script clones these `<template>`
copies, gives each cloned control a unique `id`, cancels the submit and sends JSON to the API with the CSRF token in the
`X-CSRF-TOKEN` header. The types keep Symfony form CSRF on with the API token id (`nowo_chat`), so the hidden `_token`
carries the same token the API checks (the dock is rendered for signed-in users, inside a session). When you
override those blocks, keep the Form helpers and the `data-part` hooks; `make check-no-raw-html-form` has no exception.

## Theme tokens

`css/nowo-chat.css` defines the tokens on `:where(:root)` (light, dark under `prefers-color-scheme`, or forced with
`<html data-theme="dark|light">`). Override them after it, on `:root` or `.nowo-chat`:

```css
.nowo-chat {
  --nowo-chat-accent: var(--brand-600);
  --nowo-chat-accent-strong: var(--brand-700);
  --nowo-chat-on-accent: #fff;
  --nowo-chat-text: var(--ink);
  --nowo-chat-surface: var(--surface);
  --nowo-chat-surface-muted: var(--surface-2);
  --nowo-chat-border: var(--line);
  --nowo-chat-danger: var(--red-600);
  --nowo-chat-warn: var(--amber-500);
  --nowo-chat-mist: var(--blue-100);
  --nowo-chat-font-display: var(--font-heading);
  --nowo-chat-bottom-offset: 0px;   /* extra offset; footers are better marked with data-nowo-chat-avoid */
  --nowo-chat-z-index: 60;
}
```

Shapes and sizes are tokens too (defaults shown):

| Token | Default | Used by |
| --- | --- | --- |
| `--nowo-chat-radius-window` | `12px` | panel, windows (top corners), popovers, full page |
| `--nowo-chat-radius-bubble` | `16px` | message bubbles |
| `--nowo-chat-radius-input` | `18px` | composer field |
| `--nowo-chat-radius-control` | `8px` | search, rows, notices, link previews, code blocks (inline tags use half) |
| `--nowo-chat-radius-pill` | `999px` | chips, reactions, badges, day separators, mobile launcher |
| `--nowo-chat-radius-button` | `50%` | icon buttons, send, emoji |
| `--nowo-chat-radius-avatar` / `--nowo-chat-radius-avatar-square` | `50%` / `10px` | people / channels and support |
| `--nowo-chat-shadow-dock` | upward elevation | panel and windows (clipped at the bottom edge so they come out of it) |
| `--nowo-chat-shadow` / `--nowo-chat-shadow-soft` | elevation | menus, jump button / reaction bar |
| `--nowo-chat-window-width` / `--nowo-chat-panel-width` | `336px` / `312px` | conversation windows / list panel |
| `--nowo-chat-font-size` | `0.875rem` | base text size of the dock |

```css
/* Square, flat look */
.nowo-chat, .nowo-chat-page {
  --nowo-chat-radius-window: 4px;
  --nowo-chat-radius-bubble: 4px;
  --nowo-chat-radius-input: 4px;
  --nowo-chat-radius-avatar: 6px;
  --nowo-chat-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}
```

### Bottom bars

The dock sits flush with the bottom of the viewport. Mark anything the application pins or scrolls into that area
(typically its footer) with `data-nowo-chat-avoid`: the dock rests just above its visible part (recomputed on scroll and
resize). The Symfony web debug toolbar is detected automatically at its real height (collapsed, open, two rows).

```twig
<footer class="site-footer" data-nowo-chat-avoid>…</footer>
```

### Tucking the dock away

The list header has a **Hide to the side** button (`data-part="tuck"`): the list and every open window leave the screen
and a single tab on the right edge (`.nowo-chat-edge`, chat icon and unread count) brings them back exactly as they were.
The choice is part of the shared dock layout (kept across pages, mirrored to the other browser tabs); opening a
conversation from a link, the list or a push notification shows the dock again. Move the tab with
`--nowo-chat-edge-offset` (distance above the dock baseline, default `24px`); restyle it through `.nowo-chat-edge` or the
`nowo_chat_template_edge` block.

### Stacking with drawers and modals

The dock is fixed at `z-index: var(--nowo-chat-z-index, 60)`. Native `<dialog>` modals (top layer) always cover it. A
drawer or overlay that lives inside a lower stacking context (for example a main column with its own `z-index`) is
painted under the dock: lower the dock while it is open, e.g.

```css
body:has(.my-drawer.is-open) .nowo-chat {
  z-index: 1;
}
```

## Translations

Domain `NowoChatKitBundle` (en, es, fr, de, it, pt, nl). Override any key in your own
`translations/NowoChatKitBundle.<locale>.yaml` — e.g. call the support inbox "Administrators" or channels "Projects":

```yaml
chat:
  support_title: 'Administrators'
  filter_channels: 'Projects'
  channel: 'Project channel'
```

The dock is translated in the request's locale (markup and script strings alike).

## Front end: IIFE or your bundler

- **IIFE** (default): `dock.html.twig` loads `js/nowo-chat.js`, which exposes `window.NowoChat` (`mount`, `unmount`,
  `mountAll`) and starts on `DOMContentLoaded` / `turbo:load`.
- **Your bundler**: empty the `nowo_chat_assets` block and import the TypeScript source:

```ts
import { mount, unmount } from '../vendor/nowo-tech/chat-kit-bundle/src/Resources/assets/src/index';
// e.g. in a Stimulus controller: connect() { mount(this.element) }  disconnect() { unmount(this.element) }
```

- **Host-mounted dock next to the IIFE** (1.5.2): the chat page (`page.html.twig`) always loads `nowo-chat.js`, whose
  automatic start mounts every `[data-nowo-chat-config]` root on the page — a dock your layout mounts itself would be
  mounted twice. Mark it manual and the automatic start (first paint, `turbo:load`, `turbo:before-render` cleanup)
  skips it; `mount()` / `NowoChat.mount(root)` still mount it when you call them:

```twig
{{ include('@NowoChatKitBundle/dock.html.twig', {nowo_chat_manual: true}) }}
{# or, on your own root: <div data-nowo-chat-config="…" data-nowo-chat-manual …> #}
```

  To drop the bundle script from the chat page as well (your bundler ships it), empty `nowo_chat_page_script` in the
  `web_ui.templates.page` override: `{% block nowo_chat_page_script %}{% endblock %}`.

- **Bundler hosts** (1.6): `nowo_chat_assets: false` leaves the bundle `<link>` / `<script>` out of the dock (you ship
  `nowo-chat.css` and the script), and `nowo_chat_root_attr` adds attributes to the dock root — `class` is appended, the
  dock's own attributes (`data-nowo-chat-config`, `data-testid`, …) cannot be replaced, `false` / `null` values are
  skipped. A Stimulus controller that mounts the dock needs no template override:

```twig
{{ include('@NowoChatKitBundle/dock.html.twig', {
    nowo_chat_manual: true,
    nowo_chat_assets: false,
    nowo_chat_root_attr: {'data-controller': 'chat-dock'},
}) }}
```

## User settings

Per-user settings live in the bundle (`ChatUserSettings`: sound, push, buzzes, presence, email digest). Read / save them with
`ChatUserSettingsRepository::forUser($user)` (unsaved defaults until changed) from your account page. Hiding presence
is reciprocal; call `ChatPresence::forget($user)` to hide it at once.

## Web Push

With `push: true`, each new message queues `DeliverChatPushMessage` (route it to an async transport; handled inline
without Messenger). At delivery time the bundle keeps participants who enabled push, have not read it, have not muted
the conversation and do not have the app open in any visible tab (buzzes only to people who accept them), then calls
your `ChatPushSenderInterface`. One notification per conversation (`tag`), replaced by the next.

## Messenger: retries and failures

`DeliverChatPushMessage` and `SendChatDigestMessage` carry ids only; their handlers are registered with `sign: true` and
re-check everything at delivery time (reachability, settings, mute, read state, open tabs). Without Messenger they run
inline.

- **Transport:** route both to an asynchronous transport (`SendChatDigestMessage` needs one: it uses a `DelayStamp`).
- **Retries:** the transport's retry strategy applies (Symfony default: 3 retries with backoff). An exception thrown by
  your `ChatPushSenderInterface` / `ChatDigestSenderInterface` triggers a retry; catch and log the errors you do not want
  retried (e.g. an expired push subscription).
- **Failure transport:** configure `framework.messenger.failure_transport` to keep messages that exhausted their retries.
- **Idempotency:** a redelivered digest message is a no-op inside `digest.interval_minutes` (a "sent" marker in the
  cache pool); a redelivered push message sends the notification again with the same `tag`, which replaces the first one
  on the device.

## Timeouts

The bundle makes no outbound HTTP call or subprocess itself:

- **Mercure** publishing goes through the application's `HubInterface` (Symfony HttpClient). Give that client explicit
  timeouts (e.g. `framework.http_client.default_options.timeout: 5`) **below** PHP's `max_execution_time` and the
  FrankenPHP / proxy request timeout (innermost first: HttpClient < PHP < web server). A failed publish is logged and
  never fails the user's request (clients catch up by polling).
- **Web Push and email** run in Messenger workers through your senders: set the HTTP / SMTP timeouts there.

## Privacy

- `nowo:chat:purge` — retention.
- `ChatPersonalData::export($user)` — own messages and settings (not other people's messages).
- `ChatPersonalData::erase($user)` — authored messages become tombstones without author or text; reactions, read cursors,
  settings and the user's own support thread are deleted.

Wire them into your GDPR export / erasure flow.

## JSON API

Under your route prefix (e.g. `/chat`), `X-CSRF-TOKEN` header (token id `nowo_chat`) on every write:

| Method | Path | |
| --- | --- | --- |
| GET | `/api/conversations` | list with unread counts and presence |
| GET | `/api/contacts?q=` | people picker |
| POST | `/api/direct` `{userUuid}` · `/api/channel` `{channelKey}` · `/api/support` | open |
| GET | `/api/conversations/{uuid}` | one conversation |
| GET | `/api/conversations/{uuid}/members?q=` | members the caller may @mention |
| GET / POST | `/api/conversations/{uuid}/messages` (`?before=` / `?after=`) | page / send `{body}` |
| PATCH / DELETE | `/api/conversations/{uuid}/messages/{messageUuid}` | edit `{body}` / delete |
| POST | `/api/conversations/{uuid}/messages/{messageUuid}/reactions` `{emoji}` | toggle a reaction |
| POST | `/api/conversations/{uuid}/read` `{messageId}` · `/typing` · `/buzz` · `/resolve` · `/reopen` | |
| GET | `/api/support/inbox?status=open\|resolved\|mine\|all&q=` | agents only: support threads + open count |
| GET | `/api/link-previews?url[]=…` | previews from your provider, per viewer |
| GET | `/api/realtime` | hub URL + subscriber token for the caller's topic |
| POST | `/api/presence` `{visible}` | heartbeat; online direct contacts |

### Pagination and limits

Every list is bounded server-side (constants, not configuration); nothing returns an unbounded collection:

| Endpoint | Default / cap | Paging |
| --- | --- | --- |
| `GET /api/conversations` | 50 most recent conversations (DMs, channels, support merged by last message) | none (the list is a recent-activity view) |
| `GET /api/conversations/{uuid}/messages` | 30 messages per page | `?before={messageId}` (older page) / `?after={messageId}` (catch-up), response `hasMore` |
| `GET /api/contacts?q=` | 30 contacts from `ChatDirectoryInterface::contactsFor()` | narrow with `q` |
| `GET /api/conversations/{uuid}/members?q=` | 20 members (cached recipient ids, one user query) | narrow with `q` |
| `GET /api/support/inbox` | 50 threads (`ChatSupportInbox::list()` default) | narrow with `status` / `q` |
| `GET /api/link-previews?url[]=…` | 10 URLs per request (500 characters each) | the dock batches |
| Full page `/chat/`, `/chat/{uuid}` | 50 conversations, last 50 messages | none (server-rendered view) |

The conversation list loads unread counts, last messages and direct peers in batched queries, and a message page loads
authors and reactions in batch (no per-row queries).

## Naming conventions

| Item | Value |
| --- | --- |
| Composer package | `nowo-tech/chat-kit-bundle` |
| PHP namespace | `Nowo\ChatKitBundle` |
| Bundle class | `NowoChatKitBundle` |
| Configuration root / asset package | `nowo_chat_kit` |
| Twig namespace | `@NowoChatKitBundle` (REQ-TWIG-002) |
| Translation domain | `NowoChatKitBundle` (REQ-I18N-003) |
| Route names | `nowo_chat_api_*` |
| CSS classes / tokens | `.nowo-chat-*` / `--nowo-chat-*` |
| Tables | `nowo_chat_*` |
