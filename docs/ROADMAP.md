# Roadmap

Feature roadmap of the Chat Kit Bundle after **1.34.1** (2026-11-18), written on 2026-11-18 from a review of the code
(entities, controllers, realtime, TypeScript dock, configuration, events, privacy, support) against what a team or
business chat is expected to offer. It is a plan, not a commitment: every item becomes a Spec Kit feature
(`specs/NNN-*`) with its own spec, tasks and tests before it is built, and versions may move.

## Table of contents

- [Ground rules](#ground-rules)
- [Where we are (1.34.1, 1.35 merged, 1.36 planned)](#where-we-are-1341-135-merged-136-planned)
- [Capability matrix](#capability-matrix)
- [Next](#next)
- [Later](#later)
- [Considering](#considering)
- [Non-goals](#non-goals)
- [Decided](#decided)
- [Requested for CrmKit (Beacon spec 254, 2026-11-23)](#requested-for-crmkit-beacon-spec-254-2026-11-23)

## Ground rules

1. **Backward compatible, opt-in.** Every new capability is a new option per profile with a neutral default. Features
   that store new data are off by default; existing profiles behave exactly as today until a host opts in.
2. **Server-checked.** Each action is checked again by its endpoint (voter, access policy, host veto); the dock only
   hides what the viewer may not do.
3. **Host owns identity, storage and remote calls.** People, permissions, files, outbound fetches and delivery
   channels (mail, push) stay behind host ports, as `ChatDirectoryInterface`, `ChatLinkPreviewProviderInterface` and
   `ChatPushSenderInterface` do today.
4. **House bars apply to every item:** concurrency (REQ-RUNTIME-003 write units, 409 + Retry-After), FrankenPHP worker
   mode with kernel reset false, standard Symfony and DDD hosts (uuid façade, every user-reference mode), GDPR export
   and erasure for every new table, UiKit tokens, strict CSP, WCAG 2.1 AA, seven locales.

## Where we are (1.34.1, 1.35 merged, 1.36 planned)

- **Conversations:** direct (1:1, access policy), application channels from `ChatChannelSourceInterface` (title,
  subtitle, icon, read-only), support tickets; archive / close in one's own list, archive all read, deep links,
  floating dock and full-page chat (no JavaScript needed), grouped sidebar with host sections.
- **Messages:** Markdown subset (bold, italic, strikethrough, code and fenced code, links, quotes, lists) with a
  formatting toolbar; edit within 15 minutes; delete (own, or moderated by support agents); reply as a one-line
  excerpt; nudge ("buzz"); system and assistant lines; optimistic send with retry; cursor pagination.
- **Reactions, mentions, references:** a configured reaction list; `@user`, `@channel` and `@here` (host policy) with
  mention rows and events; `/` and `#` references to host objects, resolved per reader.
- **Links:** links pinned to a conversation (chips under the header), an index of links shared in messages with search
  and provider filter, link previews through a host provider.
- **Pins, read state, typing, presence:** pinned messages bar, read cursor with double ticks in direct conversations and
  "seen by" in channels, typing indicator, presence (PSR-6), statuses (available, busy, away, do not disturb,
  invisible) with custom text, emoji, presets and expiry, auto-away, automatic replies, personal quick replies.
- **Notifications:** Mercure realtime on private per-user topics, Web Push (host sender, preview opt-in), email digest
  of unread conversations, per-conversation mute with duration, sounds per event, do not disturb.
- **Support:** ticket numbers, topics, assistant in the dock, open / waiting / resolved / closed, assignment, agent
  inbox with filters, continue or start a new ticket, ticket events.
- **Data and privacy:** retention purge (config or runtime setting), personal-data export and erasure (including former
  accounts and mentions), author badges for service accounts, table prefixes, private entity manager.
- **1.35 (merged, release pending):** richer message menu (quick reactions row and full emoji picker, copy text, multi-line quote,
  mark unread from a message, save for later with a Saved page, message info with read times and a reciprocal per-user
  opt-out).
- **1.36 (planned):** forward to other conversations, one-level threads with follower unread, edit history.
- **1.37 (merged, release pending):** message search — a database full-text search (MySQL / MariaDB FULLTEXT, PostgreSQL
  `tsvector`) filtered in SQL to the messages each viewer may read, with filters (person, conversation, date, has a
  link), a no-JavaScript page and a JSON endpoint, plus an optional SearchKit provider for the Ctrl/⌘+K palette (spec
  `specs/002-message-search/`).

## Capability matrix

Status: **Yes** shipped · **1.35** merged, release pending · **1.36** planned · **1.37** merged, release pending (message search) · **Partial** some of it · **No** missing.

| Area | Capability | Status |
|---|---|---|
| Conversations | Direct conversations | Yes |
| Conversations | Host-defined channels, read-only channels | Yes |
| Conversations | User-created group conversations | No |
| Conversations | Channel directory (browse, join, leave), public and private channels | Partial (host link only) |
| Membership | Roles inside a conversation (owner, admin, member), add and remove members | No |
| Messages | Formatting, edit, delete, reply | Yes |
| Messages | Quote, copy text | 1.35 |
| Messages | Forward, threads, edit history | 1.36 |
| Messages | Scheduled send | No |
| Messages | Reminders on a message | No |
| Reactions | Configured list | Yes |
| Reactions | Full emoji picker | 1.35 |
| Reactions | Custom emoji | No |
| Mentions | `@user`, `@channel`, `@here` | Yes |
| Mentions | Mentions of user groups | No |
| Mentions | Mentions and activity view | No |
| Media | File and image attachments | 2.7 (files, scan, signed downloads); previews 2.8 |
| Media | Voice notes, short recorded clips | No |
| Media | Link previews | Partial (host provider) |
| Read state | Read receipts, typing | Yes |
| Read state | Read times, per-user opt-out | 1.35 |
| Presence | Presence, status, auto-away, do not disturb | Yes |
| Presence | Scheduled quiet hours | No |
| Notifications | Realtime, Web Push, email digest, mute | Yes |
| Notifications | Per-conversation level (all, mentions only, none), keyword alerts | No |
| Search | People, links, support inbox | Yes |
| Search | Full-text message search, palette provider | 1.37 |
| Organisation | Pins, archive, pinned links | Yes |
| Organisation | Saved messages, mark unread | 1.35 |
| Organisation | Unread-only view, jump to first unread | Partial |
| Organisation | Drafts kept across devices | Partial (per browser) |
| Interaction | Polls | No |
| Interaction | Voice and video calls, screen share | No |
| Integrations | Domain events (in-process) | Yes |
| Integrations | Incoming and outgoing webhooks | No |
| Integrations | Bot / app accounts through a token API | Partial (badges only) |
| Integrations | Slash commands that run an action | Partial (insert references only) |
| Integrations | No-code automations | No |
| Moderation | Moderated delete in support | Yes |
| Moderation | Report a message, block a person, channel moderators | No |
| Compliance | Retention purge, personal-data export and erasure | Yes |
| Compliance | Conversation export, per-conversation retention, legal hold | No |
| Security | End-to-end encryption | No |
| Devices | Multi-tab and multi-device sync, phone layout | Yes |
| Devices | Offline send queue, installable app | No |
| Support | Tickets, topics, assignment, inbox | Yes |
| Support | Internal notes, shared replies, satisfaction rating, response-time targets | No |
| Tenancy | Tenant isolation | Partial (host access policy) |
| Tenancy | Guest and external accounts, channels shared between organisations | No |
| Accessibility | Keyboard paths, live regions, reduced motion | Yes |
| Accessibility | Keyboard shortcut help | No |

## Next

Highest value for a team or business chat, building on what exists.

### Attachments and media

- **File and image attachments.** Done in 2.7 (spec 259 block B): StorageKit, limits, a mandatory fail-closed scan,
  per-reader signed downloads. Next (2.8): previews (image thumbnails and lightbox, PDF thumbnail and viewer, Markdown /
  text / code cards), link unfurls and file names in push.
- **Attachment gallery per conversation.** A "Files" tab next to the link index; people find a shared file without
  scrolling.

### Search

- **Full-text message search.** Shipped in 1.37 (merged, release pending): a built-in database full-text search,
  filtered per viewer to the conversations they may read, with filters (person, conversation, date, has a link); the
  "has a file" filter waits for attachments.
- **Search palette provider.** Shipped in 1.37 for messages (SearchKit "Messages" group, `search_kit.enabled`);
  conversations as a palette group are still to come.
- **Search inside one conversation** with jump-to-result in the timeline.

### Conversations and membership

- **User-created group conversations.** A new `group` conversation kind, off by default: several people, a name, add
  and remove members, leave; colleagues need ad hoc groups without a host-defined channel.
- **Group roles.** Admin and member: admins rename, manage members, pin and moderate; lets people run their own groups
  without application code.
- **Channel directory.** Browse and join public channels, private channels by invitation, leave; today the dock only
  links to a host page.

### Notifications

- **Notification level per conversation.** All messages, mentions only or nothing, next to mute; cuts noise in busy
  channels without missing what matters.
- **Mentions and activity view.** One list of mentions, thread replies and reactions to one's own messages, using the
  mention rows already stored; nothing addressed to the viewer gets lost.
- **Scheduled quiet hours.** Do not disturb on a weekly schedule in the user's time zone; respects working time without
  manual toggling.

### Messages

- **Scheduled send.** Write now, deliver at a chosen time, editable and cancellable until then; respects colleagues'
  time zones.
- **Reminders on a message.** "Remind me in 1 hour / tomorrow" through the push and digest channels; turns chat into a
  lightweight to-do.

### Integrations

- **Incoming webhooks.** Post into a channel from an external system with a per-channel secret; brings alerts and
  build results into the conversation.
- **Outgoing webhooks.** Signed delivery of message and ticket events to a host URL, with retries; lets other systems
  react without writing PHP listeners.
- **Meeting link integration port.** A host port that creates a meeting in the system the host chooses and posts its
  link into the conversation; calls without running real-time media in the bundle.
- **Actionable slash commands.** Host-registered commands that run an action (create, assign, resolve) after a
  permission check; today `/` only inserts references.

### Moderation

- **Report a message** to moderators with a reason, and a moderation queue; required for larger or mixed audiences.
- **Block a person** in direct conversations; basic personal safety.
- **Channel moderators** who may delete others' messages in that channel, with an audit trail.

## Later

- **Voice notes.** Record and play short audio in the composer (needs attachments); faster than typing on a phone.
- **Short recorded clips** of audio, video or the screen (needs attachments); asynchronous explanations instead of a
  meeting.
- **Polls.** Single or multiple choice, anonymous or named, closing time; quick team decisions in the conversation.
- **Custom emoji** per installation, managed by admins; team culture and shorthand.
- **Mentions of user groups** from a host directory port (for example a department); reach the right people without
  listing names.
- **Unread-only view and jump to first unread**; catching up after time away.
- **Drafts across devices** stored on the server; continue a message on another device.
- **Bot accounts and a token API** for posting, reacting and reading as an application user, shown with the existing
  author badges; the base for assistants and integrations.
- **No-code automations.** Simple triggers (new member, keyword, form) with actions (post a message, open a ticket);
  routine work without code.
- **Conversation export** for admins (transcript with attachments, JSON and printable), and **per-conversation
  retention** that overrides the global value; compliance and record keeping.
- **Support: internal notes** visible to agents only, **shared replies** for the agent team, a **satisfaction rating**
  when a ticket closes, and **response-time targets** with overdue markers; a helpdesk needs them.
- **Broadcast to many direct recipients** (each gets an individual message), complementing read-only channels; for
  announcements that should allow private answers.
- **Offline send queue** and an installable web app shell; reliable sending on poor mobile connections.
- **Keyboard shortcut help** (a dialog listing the shortcuts) and jump-to-conversation by name; faster for heavy users.
- **Link previews out of the box** through an optional host-side fetcher with an allow-list, size and time limits;
  fewer hosts need to write a provider.

## Considering

Items with heavy privacy, security or infrastructure implications. Each needs an owner decision before a spec.

- **Voice and video calls, screen share.** Real-time media needs a call set-up server, TURN relays, bandwidth and
  recording rules. Decided: if it is ever built, it is a separate package; the bundle itself only gets the meeting link
  integration port (Next).
- **Drop-in audio rooms per channel.** Same media infrastructure as calls, plus presence of who is in the room.
- **End-to-end encryption.** Hides content from the server, which conflicts with search, link previews, references,
  moderation, retention purge, support agents and personal-data export; it also needs key management per device.
- **Phone-number identity and contact discovery.** Identity belongs to the host; phone-number matching raises
  data-protection questions (uploading address books) and is out of scope for an embedded business chat.
- **Timed message expiry** (messages removed after a period, per conversation). Good for privacy, but it conflicts with
  retention rules and legal hold and needs a clear audit story.
- **Single-view media.** Weak protection (screenshots) and conflicts with export and moderation.
- **Guest and external accounts, channels shared between organisations.** Cross-tenant access widens the security
  model (directory, access policy, retention owner, erasure). Decided: this waits for a tenancy contract.
- **Legal hold.** Suspends purge and erasure for selected people or conversations; legal and GDPR implications.
- **Message translation.** Sends message text to a translation service; needs a host port and consent.
- **Animated images and stickers from external catalogues.** Third-party requests and content moderation.

## Non-goals

- Running a media server, TURN relay or SFU inside the bundle.
- Public, anonymous chat rooms (every participant is a known host user).
- Replacing the host's identity, user directory or file storage.

## Decided

Owner decisions of 2026-11-18 on the questions this review raised.

1. **Attachments** go through StorageKit, with a mandatory host scan hook before anyone can see a file.
2. **Message search** is a built-in database full-text search filtered per viewer, plus a SearchKit provider for the
   Ctrl/⌘+K palette.
3. **User-created groups** are a new `group` conversation kind with admin and member roles, off by default.
4. **Calls:** only an integration port that posts the meeting link. Real-time media stays under Considering, as a
   separate package.
5. **Guests and cross-organisation channels** wait for a tenancy contract and stay under Considering.

## Requested for CrmKit (Beacon spec 254, 2026-11-23)

Beacon spec 254 "CRM HubSpot parity" (owner decision D-20261123-11) and the owner's reuse rule of 2026-11-23 ("if a
nowo bundle already does, or nearly does, a CRM capability, use it or extend it") send these pieces here instead of
building CRM-only copies in CrmKitBundle. Each piece is opt-in, generic (no CRM dependency) and gets its own contract
revision before it is built. Versions are planned next minors and move if this bundle releases something else first.
Plan and reuse matrix: [CrmKitBundle docs/ROADMAP.md](https://github.com/nowo-tech/CrmKitBundle/blob/main/docs/ROADMAP.md#reuse-matrix).

| Version | Piece | Used by |
| --- | --- | --- |
| later | **Customer channel option**: conversations of signed-in customers (CrmKit portal contacts, not platform users) that open or continue a CRM ticket; no anonymous website chat. Waits for CrmKit phase F (portal) and a tenancy contract, since this roadmap keeps guest / external accounts under *Considering* (Q-254-8: deferred) | CrmKit D-3 |

Not reused for CrmKit tickets: the support inbox (platform users ↔ operator, no company, contact or deal, no SLA, no
e-mail channel).
