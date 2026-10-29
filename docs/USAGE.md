# Usage

## Table of contents

- [How it fits together](#how-it-fits-together)
- [Contracts](#contracts)
- [Conversations and permissions](#conversations-and-permissions)
- [Opening conversations from your pages](#opening-conversations-from-your-pages)
- [Support inbox](#support-inbox)
- [Support tickets](#support-tickets)
- [Full-page chat](#full-page-chat)
- [Events](#events)
- [Mentions](#mentions)
- [References](#references)
- [Link previews](#link-previews)
- [Email digest](#email-digest)
- [Customising the markup](#customising-the-markup)
- [Theme tokens](#theme-tokens)
- [Translations](#translations)
- [Front end: IIFE or your bundler](#front-end-iife-or-your-bundler)
- [User settings](#user-settings)
- [Chat status](#chat-status)
- [Closing conversations](#closing-conversations)
- [Pinned messages](#pinned-messages)
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

### `ChatReferenceProviderInterface` (optional, one per kind of item)

```php
public function key(): string;                                    // 'task' — the token type and the /task command
public function label(): string;                                  // 'Task', translated
public function icon(): string;                                   // built-in: issue, task, project, monitor, report, ticket, page, link
public function isAvailable(ChatReferenceScope $scope): bool;     // offer /task at all?
public function search(ChatReferenceScope $scope, string $query, int $limit): array; // list<ChatReference>
public function resolve(ChatReferenceScope $scope, array $ids): array;               // id => ChatReference
```

See [References](#references).

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

Support agents (`ChatAccessPolicyInterface::isSupportAgent()`) see every support ticket in the dock. For an inbox page of
your own, `GET /api/support/inbox?status=open|waiting|resolved|closed|mine|unassigned|all&topic=&q=` returns the
tickets (each with its `ticket` block, `owner` and `ownerTickets`, the requester's ticket count), the `open` count and
`topics` (open + waiting tickets per topic); `ChatSupportInbox` gives the same data in PHP (`list()`, `countOpen()`,
`countByTopic()`, `otherTickets()`). `mine` means assigned to the agent. Agents change the status, the topic and the
assignee (`ChatSupportTickets` or the API below); `ChatService::resolveSupport()` / `reopenSupport()` still work.

## Support tickets

Since 1.8 a support conversation is a **ticket**: a readable number (`SUP-000123`, sequential per application), a
topic, a status (`open`, `waiting` for the user, `resolved`, `closed`), created / last activity / resolved / closed
times, the requester and an optional assignee. One user may hold several tickets.

- **Opening support** (`data-nowo-chat-open="support"`, `POST /api/support`) continues the latest ticket while it is
  open or waiting and has had a message in the last `support.resume_after_days` (7). Otherwise it opens the user's
  **intake draft**, where the support assistant greets them with the topics as buttons. When the latest ticket can
  still be continued (resolved, or idle), the assistant asks first: *Continue SUP-000123 (topic, last message date)
  or open a new one?* — Continue moves anything typed meanwhile into that ticket and reopens it; New shows the topics.
- **Choosing a topic** gives the draft the next number, assigns the topic's default `assignee` (when they answer
  support), posts *Ticket SUP-000123 opened: Billing.* and the topic's automatic reply (`support.topic_reply.<key>`,
  e.g. *Tell us the invoice number…*). Text typed before choosing stays the first message (agents see such a draft
  in the inbox with *No topic*).
- **Replies move the status**: an agent's reply turns an open ticket to `waiting`; the requester's reply turns it back
  to `open` and reopens a resolved one. These automatic switches dispatch no event.
- **Earlier tickets**: the assistant lists the requester's tickets (number, topic, status, last activity); each opens
  in the dock. A resolved ticket shows **Reopen** to its requester. A **closed** ticket, or one resolved more than
  `support.close_after_days` (30) ago, is read-only for everyone (agents may still change its status).
- Every status change, assignment, topic change and continuation posts a support-assistant line (`kind: bot`, no
  author, translated for each reader — a realtime line carries `refsPending` and each dock fetches it again in its
  own locale) in the ticket.
- **Emojis** (`support.emojis`, default `true`): a decorative emoji before the ticket status (open 🟢, waiting ⏳,
  resolved ✅, closed 🔒), before the topic label (status line, topic buttons, earlier tickets; ticket blocks carry
  `topicEmoji` / `statusEmoji`, topic options and inbox counts `emoji`) and before each assistant line (🎫 opened,
  🏷️ topic changed, 🔄 continued, 🔁 reopened, ⏳ waiting, ✅ resolved, 🔒 closed, 🙋 assigned, 👤 unassigned, 👋 the
  greeting, 🔄 "continue or new?"; a topic's first reply uses the topic's emoji). They are `<span class="nowo-chat-deco"
  aria-hidden="true">` elements outside the translations, so screen readers, previews, Web Push and the digest read only
  the text. Built-in topics: incident 🐞, billing 💳, how_to ❓, account 🔐, other 💬; set `emoji` per topic (`""`
  for none) or `support.emojis: false` for text only.

Topics come from `support.topics` (see CONFIGURATION). To manage them as data, alias
`ChatSupportTopicProviderInterface` to your service returning `ChatSupportTopic` objects; a topic with a null `domain`
keeps its `label` and `reply` as plain text:

```php
use Nowo\ChatKitBundle\Contract\ChatSupportTopicProviderInterface;
use Nowo\ChatKitBundle\Model\ChatSupportTopic;

final class SupportTopics implements ChatSupportTopicProviderInterface
{
    public function topics(): array
    {
        return [
            new ChatSupportTopic('incident', 'support.topic.incident', 'support.topic_reply.incident', 'tabler:bug', null, 'messages', '🐞'),
            new ChatSupportTopic('sales', 'Sales', 'Which plan are you interested in?'), // plain text
        ];
    }
}
```

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
| `SupportTicketCreatedEvent` | a requester chose a topic and a ticket opened (`ticket`, `requester`) | notify the support agents |
| `SupportTicketReopenedEvent` | a ticket went back to open: Continue, Reopen, the requester's reply to a resolved ticket, an agent (`by`) | notify the agents / the assignee |
| `SupportTicketStatusChangedEvent` | a deliberate status change (`previous`, `by`); not the automatic open ↔ waiting switch | notify the requester |
| `SupportTicketAssignedEvent` | a ticket was assigned or unassigned (`assignee` null), by an agent or by the topic's default assignee | notify the assignee |
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

## References

`/` at the start of a message opens the command menu — one command per registered provider the conversation may use
(`/task`, `/issue` …); after a command the text typed searches its items (`/task deploy`). `#` anywhere searches every
offered type. Picking an item writes `#Title`; the message carries the token `#[Title](type:id)`. Commands only insert
references in this version; they never act (no `/assign`, `/resolve`).

Implement `ChatReferenceProviderInterface` for each kind of item (autoconfigured; tag
`nowo_chat_kit.reference_provider`). The contract is generic — issues, tasks, projects, monitors, reports, tickets, a
wiki page — and **permissions are the provider's job, deny by default**:

- `ChatReferenceScope::$audience` lists everyone who must be able to open the item: the writer, plus the other person of
  a direct conversation. Filter **inside your query** by the permission of each of them (never fetch then filter after
  a `LIMIT`, which leaks counts and empty pages).
- `ChatReferenceScope::channelKey()` is set in a channel: offer and accept only that channel's items (e.g. a project
  channel keeps its project).
- `isAvailable()` decides whether the command is listed: no `/monitor` for someone who may open no monitor in scope. An
  empty search answer never says why.
- Return nothing for a user or a type without an explicit permission mapping. Do not rely on the security token:
  `resolve()` also runs in Messenger handlers (Web Push, digest) for the recipient.

On send and edit, `ChatReferences::validate()` keeps a token only when `resolve()` returns the item for the writer's
scope (its title becomes the item's current title); anything else — forged, foreign, unknown, unreadable, or past 10 per
message — is stored as the plain text `#Title`. The stored HTML holds an empty slot per token; each reader's payload
fills it through `resolve()` with `ChatReferenceScope::reader($viewer)`: a card (`.nowo-chat-ref`, icon, type, title,
subtitle, badge with a tone, link — root-relative or http(s) only) or the neutral chip
`.nowo-chat-ref--restricted` with *Content not visible* (`chat.reference_restricted`), never a title, project, status or
id. `ChatReferences::plainText()` gives the same per-reader text for previews, push bodies and digests; use it (and the
same string) in your own notifications. A page of messages resolves its references in one `resolve()` per type,
cached for the request.

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

Per-user settings live in the bundle (`ChatUserSettings`), so your user table needs no columns. Read / save them with
`ChatUserSettingsRepository::forUser($user)` (unsaved defaults until changed) and `save($settings)`. Hiding presence is
reciprocal; call `ChatPresence::forget($user)` to hide it at once.

| Preference (1.10 key) | Default | Effect in the dock |
| --- | --- | --- |
| `soundMessage` · `soundMention` · `soundBuzz` · `soundSupport` | `pop` · `chime` · `buzz` · `ding` | sound of a new message, a message that @mentions the user, a buzz, a support reply: `none`, `pop`, `chime`, `ding`, `bell`, `blip`, `knock`, `buzz` (`getSound()` / `setSound()` stay the new-message sound) |
| `volume` | `80` | 0 – 100 |
| `soundBackgroundOnly` | `false` | sounds only while the tab is hidden or unfocused |
| `buzzEnabled` | `true` | off: a buzz is only a line in the thread |
| `buzzOpenWindow` | `true` | off: a buzz rings (and shakes a window already open) without opening it |
| `openOnMessage` | `false` | an incoming message opens its window (off: only the unread badge) |
| `sendKey` | `enter` | `enter` (Shift+Enter: new line) or `ctrl_enter` (Ctrl/⌘+Enter sends, Enter: new line); the composer hint follows |
| `pushPreview` | `true` | off: Web Push says only "New message" even when the application enables `push_preview` |
| `presenceVisible` | `true` | reciprocal online dot |
| `emailDigest` | `true` | unread-messages email |
| `pushEnabled` | `false` | Web Push (your page also needs the browser subscription; not in the JSON allow-list) |

Three ways to offer them:

- **One control at a time** (an "interface preferences" drawer): `POST /api/settings` `{key, value}` with the
  `nowo_chat` CSRF header — keys from `ChatUserSettings::PREFERENCE_KEYS`, values of their type (bool, int 0 – 100,
  sound or send-key name); `422 invalid_setting` otherwise. From TypeScript, `saveChatPreference(url, csrf, key, value)`
  (exported by the entry point) posts it and dispatches `nowo-chat:prefs`, so the dock of the page applies it at once;
  the user's other tabs follow through the realtime `prefs` event. `playSound(name, volume)` previews a sound.
- **A whole form**: `Nowo\ChatKitBundle\Form\ChatPreferencesType` (bound to `ChatUserSettings`; each sound select has
  `data-nowo-chat-sound-select="<event>"` for a ▶ preview button). Persist the entity after a valid submit.
- **Your own form type** reading and writing the same getters / setters (`getSoundFor()` / `setSoundFor()`,
  `getVolume()`, `isOpenOnMessage()` …).

## Chat status

Each user picks a status **from the chat itself** — the avatar button at the top of the dock's list opens the status menu
(an ARIA menu: ↑ ↓ Home End, Enter / Space, Escape), and a button with `data-nowo-chat-status-open` anywhere on the page
(the full-page chat has one at the top of its list) opens the same menu:

| Status | Others see | Effect for the user |
| --- | --- | --- |
| `available` | green dot | — (shown **away** while idle: no keyboard or pointer input in any tab for `auto_away_minutes`, default 10) |
| `busy` | red dot | — |
| `away` | amber dot | — |
| `dnd` | red dot with a bar | no sounds, no buzz opening / shaking, no opening on a message, no Web Push — the badge only |
| `invisible` | offline (no dot, no custom text) | the user keeps seeing everyone |

Plus an optional custom status — an emoji (at most 16 characters, no letters or digits) and a text (at most 80) — and
when it all clears: never, 30 minutes, 1 hour, 4 hours, end of today, end of this week (`clearAfter`: `never`, `30m`,
`1h`, `4h`, `today`, `week`; then back to available, without text). `POST /api/status`
`{status, text?, emoji?, clearAfter?}` stores it (`422 invalid_status` otherwise) and publishes it live: the user's
direct contacts get a `status` event (what others see), the user's other tabs a `my_status` event.

Where it shows: the dot on the avatar of a direct conversation (list, window header, people picker), the label and the
custom status in the window subtitle and after the name in the list, the dot and text in the @mention list
(`/members` carries `status`, `statusText`, `statusEmoji`), and the full-page chat header. Conversation summaries carry
`peerStatus` (`available`, `busy`, `away`, `dnd`, `offline`), `peerStatusText`, `peerStatusEmoji`; the heartbeat
answers `statuses` by public id and the user's own status (`me`).

Privacy: an invisible user, or one who hides presence, is `offline` to everyone; an invisible user's custom text is never
sent; a viewer who hides their own presence sees nobody's dot (reciprocal) but still the custom text; the status only
reaches people who already see the user in the chat (direct contacts, members of a conversation the viewer can read).
Storage: four columns on `nowo_chat_user_settings` (see [UPGRADING](UPGRADING.md#to-1100)).

## Closing conversations

Each person tidies their own list (1.10): the **Close** button on a row of the dock (on hover, focus or touch; the
`Delete` key on a row does the same) takes the conversation out of their list with an **Undo** notice; the **Closed**
filter lists them, each with **Reopen**; the archive button next to the filters closes every conversation without
unread messages ("Close all read"). The full-page chat has a *Closed* link and a Close / Reopen button per row
(`data-nowo-chat-archive` / `data-nowo-chat-unarchive="<uuid>"`, handled by the dock's script).

Nothing is deleted and nobody else is affected (`nowo_chat_participant.archived_at`). A closed conversation is back in
the list as soon as a new message arrives — unless the person muted it, and except a resolved or closed support
ticket, which stays closed. API: `POST` / `DELETE /api/conversations/{uuid}/archive`, `POST
/api/conversations/archive-read`, `GET /api/conversations?archived=1`; the person's other tabs get a realtime `list`
event.

## Pinned messages

Messages can be pinned to the top of a conversation (1.10) from the message's actions (Pin / Unpin; keyboard
reachable like the other actions). The pinned bar above the thread shows the latest pin (a click scrolls to the
message, loading older pages if needed, and highlights it) and **All pinned (n)** lists every pin with Unpin. A line
"X pinned a message" appears in the thread (translated for each reader, no sound or push); every reader's bar updates
live (realtime `pins`); deleting a pinned message unpins it. Pins are shared by the conversation, at most `max_pins`
(default 20; `422 too_many_pins` beyond).

Who may pin: both people of a direct conversation; support agents in tickets; in channels only whom your access policy
allows by also implementing `ChatPinPolicyInterface::canPinInChannel()` — nobody otherwise (deny by default). Others
get no Pin action and the API answers `403 pin_forbidden` (`404` when they cannot read the conversation). API:
`GET /api/conversations/{uuid}/pins` (`{pins, canPin}`), `POST` / `DELETE /api/conversations/{uuid}/messages/{messageUuid}/pin`.
Summaries carry `canPin`, messages `pinned` (and a pin line `pinnedMessage`). The personal data export lists the pins a
person made.

## Web Push

With `push: true`, each new message queues `DeliverChatPushMessage` (route it to an async transport; handled inline
without Messenger). At delivery time the bundle keeps participants who enabled push, have not read it, have not muted
the conversation, are not in "do not disturb" and do not have the app open in any visible tab (buzzes only to people
who accept them), then calls your `ChatPushSenderInterface`. The message text goes in only with `push_preview: true` and
the recipient's own `pushPreview` preference on. One notification per conversation (`tag`), replaced by the next.

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
| POST | `/api/direct` `{userUuid}` · `/api/channel` `{channelKey}` · `/api/support` `{context?}` | open (support: the active ticket or the intake draft, whose summary carries `intake`) |
| GET | `/api/conversations/{uuid}` | one conversation |
| GET | `/api/conversations/{uuid}/members?q=` | members the caller may @mention |
| GET | `/api/conversations/{uuid}/references` (`?type=key\|*&q=`) | without `type`: the `/` commands offered; with it: the items everyone in the conversation may open (rate limited) |
| GET | `/api/conversations/{uuid}/messages/{messageUuid}` | one message as the caller sees it (its reference cards) |
| GET / POST | `/api/conversations/{uuid}/messages` (`?before=` / `?after=`) | page / send `{body}` |
| PATCH / DELETE | `/api/conversations/{uuid}/messages/{messageUuid}` | edit `{body}` / delete |
| POST | `/api/conversations/{uuid}/messages/{messageUuid}/reactions` `{emoji}` | toggle a reaction |
| POST | `/api/conversations/{uuid}/read` `{messageId}` · `/typing` · `/buzz` · `/resolve` · `/reopen` | |
| GET | `/api/support/inbox?status=open\|waiting\|resolved\|closed\|mine\|unassigned\|all&topic=&q=` | agents only: tickets, open count, counts per topic |
| GET | `/api/support/tickets` (`?owner=<public id>`, agents) | the caller's (or a requester's) tickets |
| POST | `/api/support/{uuid}/topic` `{topic}` | requester: the draft becomes a ticket (`{conversation, messages}`); agent: change the topic |
| POST | `/api/support/{uuid}/resume` `{draft?}` | requester: continue the ticket (the draft's messages move into it) |
| POST | `/api/support/{uuid}/status` `{status}` | agents: any status; requester: `open` (Reopen) only |
| POST | `/api/support/{uuid}/assign` `{assignee}` | agents: an agent's public id, or null |
| GET | `/api/link-previews?url[]=…` | previews from your provider, per viewer |
| GET | `/api/realtime` | hub URL + subscriber token for the caller's topic |
| POST | `/api/presence` `{visible, idle?}` | heartbeat; online direct contacts, their `statuses`, the caller's own status (`me`) |
| POST | `/api/status` `{status, text?, emoji?, clearAfter?}` | set the caller's chat status (1.10) |
| POST | `/api/settings` `{key, value}` | save one chat preference (1.10); answers every preference |
| POST / DELETE | `/api/conversations/{uuid}/archive` · POST `/api/conversations/archive-read` | close / reopen in the caller's list, close all read (1.10) |
| GET | `/api/conversations/{uuid}/pins` · POST / DELETE `/api/conversations/{uuid}/messages/{messageUuid}/pin` | pinned messages, pin / unpin (1.10) |

### Pagination and limits

Every list is bounded server-side (constants, not configuration); nothing returns an unbounded collection:

| Endpoint | Default / cap | Paging |
| --- | --- | --- |
| `GET /api/conversations` | 50 most recent conversations (DMs, channels, support merged by last message) | none (the list is a recent-activity view) |
| `GET /api/conversations/{uuid}/messages` | 30 messages per page | `?before={messageId}` (older page) / `?after={messageId}` (catch-up), response `hasMore` |
| `GET /api/contacts?q=` | 30 contacts from `ChatDirectoryInterface::contactsFor()` | narrow with `q` |
| `GET /api/conversations/{uuid}/members?q=` | 20 members (cached recipient ids, one user query) | narrow with `q` |
| `GET /api/support/inbox` | 50 threads (`ChatSupportInbox::list()` default) | narrow with `status` / `q` |
| `GET /api/conversations/{uuid}/references` | 8 items (`ChatReferences::SEARCH_LIMIT`), query capped at 80 characters | narrow with `q` |
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
