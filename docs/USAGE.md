# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [Contracts](#contracts)
  - [`ChatDirectoryInterface` (required)](#chatdirectoryinterface-required)
  - [`ChatAccessPolicyInterface` (required)](#chataccesspolicyinterface-required)
  - [`ChatChannelProviderInterface` (optional)](#chatchannelproviderinterface-optional)
  - [`ChatRealtimeInterface` (optional)](#chatrealtimeinterface-optional)
  - [`ChatPushSenderInterface` (optional)](#chatpushsenderinterface-optional)
  - [`ChatLinkPreviewProviderInterface` (optional)](#chatlinkpreviewproviderinterface-optional)
  - [`ChatDigestSenderInterface` (optional)](#chatdigestsenderinterface-optional)
- [Conversations and permissions](#conversations-and-permissions)
- [Opening conversations from your pages](#opening-conversations-from-your-pages)
- [Support inbox](#support-inbox)
- [Full-page chat](#full-page-chat)
- [Events](#events)
- [Link previews](#link-previews)
- [Email digest](#email-digest)
- [Customising the markup](#customising-the-markup)
  - [Overridable templates](#overridable-templates)
  - [Forms](#forms)
- [Theme tokens](#theme-tokens)
  - [Bottom bars](#bottom-bars)
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
public function isSupportAgent(ChatUserInterface $user): bool;       // answers support, resolves, moderates
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
| Channel | everyone `canUse()` allows | One per key; agents moderate. |
| Support | the owner and every support agent | Agents resolve; the owner's next message reopens it. |

Messages: Markdown rendered and sanitised on the server (escape first, closed tag set, http(s) links only). The author
edits or deletes within 15 minutes (tombstone kept); agents delete in channels and support threads. Reactions: the
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
rate limit as the API). Every dock window has an "Open in a page" link to it. The page extends `page_layout` (default: a
minimal standalone layout); set it to your own layout, which must define the `title` and `body` blocks:

```yaml
nowo_chat_kit:
    profiles:
        default:
            page_layout: base.html.twig
```

Override `@NowoChatKitBundle/page.html.twig` (blocks `nowo_chat_page_list`, `nowo_chat_page_thread`) to change the
markup. With the route prefix `/chat`, the list lives at `/chat/` (Symfony redirects `/chat` there).

## Events

| Event | When | Typical use |
| --- | --- | --- |
| `ChatMessageSentEvent` | a message or buzz was stored and published | counters, bots, audit |
| `ChatMessageDeletedEvent` | a message was deleted; `moderated` when an agent removed someone else's | audit moderation |
| `ChatSupportStatusChangedEvent` | a support thread was resolved (`by` = agent) or reopened (`by` = owner) | inbox counters, notifications |

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
| `page.html.twig` | Full-page chat (list + thread + composer form) | `title`, `body`, `nowo_chat_page_list`, `nowo_chat_page_thread` |
| `page_layout.html.twig` | Default standalone layout of the full page (or set `page_layout`) | `title`, `body` |

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

The one documented exception is `_templates.html.twig`: its composer and search fields are inert `<template>` markup
that the TypeScript clones and sends as JSON to the API (CSRF in the `X-CSRF-TOKEN` header). They are never submitted
as an HTML form, so they are not Symfony Form views; `make check-no-raw-html-form` allowlists only this file.

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
| `--nowo-chat-shadow` / `--nowo-chat-shadow-soft` | elevation | windows, menus / reaction bar |
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
| GET / POST | `/api/conversations/{uuid}/messages` (`?before=` / `?after=`) | page / send `{body}` |
| PATCH / DELETE | `/api/conversations/{uuid}/messages/{messageUuid}` | edit `{body}` / delete |
| POST | `/api/conversations/{uuid}/messages/{messageUuid}/reactions` `{emoji}` | toggle a reaction |
| POST | `/api/conversations/{uuid}/read` `{messageId}` · `/typing` · `/buzz` · `/resolve` · `/reopen` | |
| GET | `/api/support/inbox?status=open\|resolved\|mine\|all&q=` | agents only: support threads + open count |
| GET | `/api/link-previews?url[]=…` | previews from your provider, per viewer |
| GET | `/api/realtime` | hub URL + subscriber token for the caller's topic |
| POST | `/api/presence` `{visible}` | heartbeat; online direct contacts |

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
