# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [Contracts](#contracts)
- [Conversations and permissions](#conversations-and-permissions)
- [Customising the markup](#customising-the-markup)
- [Theme tokens](#theme-tokens)
- [Translations](#translations)
- [Front end: IIFE or your bundler](#front-end-iife-or-your-bundler)
- [User settings](#user-settings)
- [Web Push](#web-push)
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
  --nowo-chat-bottom-offset: 0px;   /* e.g. above a fixed footer */
  --nowo-chat-z-index: 60;
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

## User settings

Per-user settings live in the bundle (`ChatUserSettings`: sound, push, buzzes, presence). Read / save them with
`ChatUserSettingsRepository::forUser($user)` (unsaved defaults until changed) from your account page. Hiding presence
is reciprocal; call `ChatPresence::forget($user)` to hide it at once.

## Web Push

With `push: true`, each new message queues `DeliverChatPushMessage` (route it to an async transport; handled inline
without Messenger). At delivery time the bundle keeps participants who enabled push, have not read it, have not muted
the conversation and do not have the app open in any visible tab (buzzes only to people who accept them), then calls
your `ChatPushSenderInterface`. One notification per conversation (`tag`), replaced by the next.

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
| POST | `/api/conversations/{uuid}/read` `{messageId}` · `/typing` · `/buzz` · `/resolve` | |
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
