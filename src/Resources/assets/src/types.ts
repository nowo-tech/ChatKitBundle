/** Shapes of the chat JSON API (ChatPresenter) and of the dock configuration (`nowo_chat_dock_config()`). */

export type ConversationKind = 'direct' | 'channel' | 'support';

/** Emoji → public ids of the people who reacted with it. */
export type Reactions = Record<string, string[]>;

export interface ConversationSummary {
  uuid: string;
  kind: ConversationKind;
  title: string;
  subtitle: string;
  initials: string;
  /** 1.16: picture of the person the bubble shows (direct peer, requester for an agent); null: the initials. */
  avatarUrl?: string | null;
  peerUuid: string | null;
  channelKey: string | null;
  supportStatus: SupportStatus | null;
  /** Support only: where the problem was reported from. */
  supportContext?: { url?: string; label?: string } | null;
  /** Support only: the ticket (a draft has a null `number`); null for other kinds. */
  ticket?: SupportTicket | null;
  /** The requester's own draft only: the topic question or "continue or new?", and their earlier tickets. */
  intake?: SupportIntake;
  canSend: boolean;
  peerOnline: boolean;
  /** 1.10: the viewer closed it (listed under the "Closed" filter). */
  archived?: boolean;
  /** Direct only (1.10): what the viewer sees of the other person's status (absent before 1.10 = offline). */
  peerStatus?: VisibleStatus;
  /** Direct only (1.10): the other person's custom status text and emoji. */
  peerStatusText?: string | null;
  peerStatusEmoji?: string | null;
  unread: number;
  lastMessageAt: string | null;
  lastMessage: { preview: string; deleted: boolean; moderated?: boolean; buzz?: boolean; pin?: boolean; mine: boolean; authorName: string | null } | null;
  /** 1.10: the viewer may pin and unpin messages here. */
  canPin?: boolean;
}

export interface ChatMessage {
  id: number;
  uuid: string;
  authorUuid: string | null;
  authorName: string;
  authorInitials: string;
  /** 1.16: the author's picture; null: the initials. */
  authorAvatarUrl?: string | null;
  mine: boolean;
  /** `pin` (1.10): "X pinned a message" line (`pinnedMessage` = its uuid). */
  kind: 'text' | 'buzz' | 'bot' | 'pin' | 'auto';
  /** 1.10: pinned to the top of the conversation. */
  pinned?: boolean;
  pinnedMessage?: string | null;
  html: string;
  createdAt: string;
  edited: boolean;
  deleted: boolean;
  moderated: boolean;
  reactions?: Reactions;
  /** Members the message @mentions (public id and the name written in the message). */
  mentions?: MentionRef[];
  /** The viewer is one of them (false in viewer-neutral realtime payloads: use `mentions`). */
  mentionsMe?: boolean;
  /** #references the viewer may open (the editor turns their cards back into tokens). */
  references?: Reference[];
  /** Viewer-neutral realtime payload: its reference cards are fetched for the viewer (`GET` the message). */
  refsPending?: boolean;
}

/** A #reference the viewer may open: an issue, a task, a project … (ChatReference). */
export interface Reference {
  type: string;
  id: string;
  title: string;
  url: string;
  subtitle: string;
  badge: string;
  tone: string;
}

/** A `/` command: one type of item the conversation may refer to. */
export interface ReferenceType {
  key: string;
  label: string;
  icon: string;
}

/** One @mention of a message: public id and display name. */
export interface MentionRef {
  uuid: string;
  name: string;
}

/** A conversation member offered by the @mention list. */
export interface Member extends MentionRef {
  initials: string;
  /** 1.16: the member's picture; null: the initials. */
  avatarUrl?: string | null;
  /** 1.10: the member's status as the viewer sees it, and their custom status. */
  status?: VisibleStatus;
  statusText?: string | null;
  statusEmoji?: string | null;
}

/** A message typed here and not yet confirmed by the server (optimistic send). */
export interface PendingMessage {
  clientId: string;
  body: string;
  createdAt: string;
  status: 'sending' | 'failed';
  error?: string;
}

export interface Contact {
  uuid: string;
  name: string;
  email: string | null;
  initials: string;
  /** 1.16: the contact's picture; null: the initials. */
  avatarUrl?: string | null;
  /** Free-form source from the application's directory (`group`, `project`, `search` …). */
  source: string;
  via: string;
}

export type SoundName = 'none' | 'pop' | 'chime' | 'ding' | 'bell' | 'blip' | 'knock' | 'buzz';

/** Events with their own sound (1.10). */
export type SoundEvent = 'message' | 'mention' | 'buzz' | 'support';

/** A status the user picks (1.10); `invisible` shows them offline to everyone. */
export type ChatStatus = 'available' | 'busy' | 'away' | 'dnd' | 'invisible';

/** A status as others see it (invisible people are offline). */
export type VisibleStatus = 'available' | 'busy' | 'away' | 'dnd' | 'offline';

/** The user's own status (ChatUserSettings::statusView()). */
export interface OwnStatus {
  status: ChatStatus;
  text: string | null;
  emoji: string | null;
  /** When it goes back to available (ISO 8601); null = never. */
  expiresAt: string | null;
}

/** The user's automatic reply (1.11). */
export interface AutoReply {
  enabled: boolean;
  text: string;
  /** `status`: while away, busy or in do not disturb; `range`: between `from` and `until`. */
  when: 'status' | 'range';
  from: string | null;
  until: string | null;
  mentions: boolean;
}

/** A saved snippet (1.11). */
export interface QuickReply {
  id: number;
  title: string;
  body: string;
}

/** What others see of someone's status. */
export interface PeerStatus {
  status: VisibleStatus;
  text: string | null;
  emoji: string | null;
}

/** The user's chat preferences (ChatUserSettings::preferences()); fields after `presence` are 1.10. */
export interface ChatPrefs {
  /** Sound of a new message (the 1.x single sound). */
  sound: SoundName;
  buzz: boolean;
  presence: boolean;
  sounds?: Record<SoundEvent, SoundName>;
  /** 0 – 100. */
  volume?: number;
  /** Sounds only while the tab is hidden or unfocused. */
  backgroundOnly?: boolean;
  /** A buzz opens its window (false: it rings and shakes a window already open). */
  buzzOpenWindow?: boolean;
  /** An incoming message opens its window (false: only the badge). */
  openOnMessage?: boolean;
  sendKey?: 'enter' | 'ctrl_enter';
  pushPreview?: boolean;
  push?: boolean;
  emailDigest?: boolean;
}

/** Another participant's read cursor (✓✓ in DMs, "Seen by N" in channels). */
export interface Reader {
  userUuid: string;
  name: string;
  messageId: number;
}

/** Events on the caller's private topic (ChatNotifier). */
export type RealtimeEvent =
  | { type: 'message'; conversationUuid: string; message: ChatMessage }
  | { type: 'message_updated'; conversationUuid: string; message: ChatMessage }
  | { type: 'read'; conversationUuid: string; userUuid: string; userName: string; messageId: number }
  | { type: 'typing'; conversationUuid: string; userUuid: string; userName: string }
  | { type: 'conversation'; conversationUuid: string }
  | ({ type: 'status'; userUuid: string } & PeerStatus)
  | { type: 'my_status'; status: OwnStatus }
  | { type: 'prefs'; prefs: ChatPrefs }
  | { type: 'list' }
  | { type: 'pins'; conversationUuid: string }
  | { type: 'links'; conversationUuid: string };

/** 1.17: `hide` (invisible and inert), `inert` (painted, inert) or `none` (1.16 behaviour). */
export type DockOverlayMode = 'hide' | 'inert' | 'none';

export interface ChatConfig {
  userUuid: string;
  csrf: string;
  locale: string;
  /** Channel of the page being viewed, offered first in the people picker. */
  contextChannel: { key: string; label: string } | null;
  pollMs: number;
  maxLength: number;
  editWindowSeconds: number;
  reactions: string[];
  endpoints: {
    conversations: string;
    contacts: string;
    direct: string;
    channel: string;
    support: string;
    supportInbox: string;
    /** The caller's tickets. */
    supportTickets: string;
    /** Ticket actions (`__uuid__`): choose the topic, continue, change the status, assign. */
    supportTopic: string;
    supportResume: string;
    supportStatus: string;
    supportAssign: string;
    linkPreviews: string;
    /** Full-page view of a conversation (`__uuid__`). */
    page: string;
    /** `__uuid__` is replaced with the conversation UUID (and `__message__` with a message UUID). */
    conversation: string;
    messages: string;
    /** Members a message may @mention (`__uuid__`). */
    members: string;
    /** `/` commands and #reference search (`__uuid__`). */
    references: string;
    message: string;
    reactions: string;
    read: string;
    typing: string;
    buzz: string;
    resolve: string;
    realtime: string;
    presence: string;
    /** 1.10: set the own status (`POST {status, text, emoji, clearAfter}`). */
    status?: string;
    /** 1.10: save one preference (`POST {key, value}`). */
    settings?: string;
    /** 1.10: close (`POST`) or reopen (`DELETE`) a conversation in the user's list (`__uuid__`). */
    archive?: string;
    /** 1.10: close every read conversation. */
    archiveRead?: string;
    /** 1.10: pinned messages of a conversation (`__uuid__`). */
    pins?: string;
    /** 1.10: pin (`POST`) / unpin (`DELETE`) a message (`__uuid__`, `__message__`). */
    pin?: string;
    /** 1.11: save the automatic reply. */
    autoReply?: string;
    /** 1.11: the user's quick replies (`GET`, `POST`; `/{id}` `PATCH` / `DELETE`). */
    quickReplies?: string;
    /** 1.19: pinned links of a conversation (`__uuid__`): `GET` list, `POST` create. */
    pinnedLinks?: string;
    /** 1.19: one pinned link (`__uuid__`, `__link__`): `PATCH`, `DELETE`. */
    pinnedLink?: string;
    /** 1.19: the links panel of a conversation (`__uuid__`; `?q=&provider=&before=`). */
    links?: string;
  };
  prefs: ChatPrefs;
  /** 1.10: the user's own status (absent before 1.10: no status menu). */
  status?: OwnStatus;
  /** 1.10: minutes without input before the user shows as away (0 = never). */
  autoAwayMinutes?: number;
  /** 1.11: the automatic reply (status menu). */
  autoReply?: AutoReply;
  /** 1.10: the user's own name and initials (status button); 1.16: and picture. */
  me?: { name: string; initials: string; avatarUrl?: string | null };
  isAgent: boolean;
  /** Decorative emojis of the support assistant's own lines (`support.emojis`; absent before 1.9.1 = shown). */
  supportEmojis?: boolean;
  /** The application resolves link previews (issue cards …). */
  linkPreviews: boolean;
  /** The application registers reference providers: `/` commands and the `#` picker are offered. */
  references?: boolean;
  /**
   * 1.17: what the floating dock does while the page shows a modal overlay (`dock` config; absent before 1.17 = `hide`
   * without the watcher). Driven by the `nowo:overlay-open` / `nowo:overlay-close` events and `html[data-nowo-overlay]`.
   */
  dock?: { overlay?: DockOverlayMode; watchAriaModal?: boolean };
  /** 1.19: pinned links and the links panel (absent or `enabled: false`: no links UI). */
  links?: LinksSettings;
  i18n: Record<string, string>;
}

/** A link provider of the configuration (Teams, Meet, Zoom …): the hosts it matches (and their subdomains). */
export interface LinkProvider extends LinkProviderRef {
  hosts: string[];
}

/** The provider of a link, as the API reports it. */
export interface LinkProviderRef {
  key: string;
  label: string;
  /** Dock icon name (`provider_<key>`, the application's UX Icon). */
  icon: string;
  /** Built-in icon used when the application has no SVG for `icon`. */
  fallbackIcon: string;
}

/** 1.19: the `links` section of the dock configuration. */
export interface LinksSettings {
  enabled: boolean;
  /** `http:` links are accepted (otherwise `https:` only). */
  allowHttp: boolean;
  /** Pinned links per conversation. */
  maxPinned: number;
  titleMaxLength: number;
  urlMaxLength: number;
  providers: LinkProvider[];
}

/** Someone who pinned or shared a link (null after their erasure). */
export interface LinkPerson {
  uuid: string;
  name: string;
}

/** A link pinned to a conversation (the bar under the header). */
export interface PinnedLink {
  uuid: string;
  url: string;
  /** The title as typed (null: none). */
  title: string | null;
  /** What to show: the title, else the provider label, else the host. */
  label: string;
  host: string;
  provider: LinkProviderRef | null;
  createdBy: LinkPerson | null;
  createdAt: string;
  updatedAt: string | null;
}

/** The pinned links of a conversation and whether the viewer may manage them. */
export interface PinnedLinksState {
  links: PinnedLink[];
  canManage: boolean;
  max: number;
}

/** A link card shared in a message (the links panel). */
export interface SharedLink {
  id: number;
  url: string;
  title: string | null;
  label: string;
  host: string;
  provider: LinkProviderRef | null;
  kind: 'card' | 'link';
  messageId: number;
  messageUuid: string;
  author: LinkPerson | null;
  createdAt: string;
}

/** One page of the links panel (`pinned` only on the first page). */
export interface LinksPage {
  pinned?: PinnedLink[];
  links: SharedLink[];
  hasMore: boolean;
  next: number | null;
  providers: LinkProviderRef[];
  canManage: boolean;
}

export interface LinkPreview {
  title: string;
  subtitle: string;
  badge: string;
}

/** Status of a support ticket. */
export type SupportStatus = 'open' | 'waiting' | 'resolved' | 'closed';

/** A support ticket (ChatPresenter::ticket()). */
export interface SupportTicket {
  uuid: string;
  /** Readable number (SUP-000123); null for the intake draft. */
  number: string | null;
  topic: string | null;
  topicLabel: string;
  /** Decorative emoji of the topic ("" when none or `support.emojis` is off; absent before 1.9.1). */
  topicEmoji?: string;
  status: SupportStatus;
  statusLabel: string;
  /** Decorative emoji of the status ("" when `support.emojis` is off; absent before 1.9.1). */
  statusEmoji?: string;
  requester: { uuid: string; name: string } | null;
  assignee: { uuid: string; name: string } | null;
  createdAt: string;
  lastMessageAt: string;
  resolvedAt: string | null;
  /** Closed, or resolved too long ago: nobody writes, the requester cannot reopen. */
  readOnly: boolean;
}

/** A topic offered when a ticket opens. */
export interface SupportTopicOption {
  key: string;
  label: string;
  icon: string | null;
  /** Decorative emoji ("" when none or `support.emojis` is off; absent before 1.9.1). */
  emoji?: string;
}

/** The intake panel of the requester's draft. */
export interface SupportIntake {
  /** `topic`: pick a topic; `resume`: continue `resume` or open a new ticket. */
  prompt: 'topic' | 'resume';
  topics: SupportTopicOption[];
  resume: SupportTicket | null;
  tickets: SupportTicket[];
  resumeAfterDays: number;
}

/** Where a support request comes from ("Report this problem" links). */
export interface SupportContext {
  url?: string;
  label?: string;
}

/** Layout shared by every browser tab of the same user (localStorage + BroadcastChannel). */
export interface DockState {
  listOpen: boolean;
  /** Whole dock tucked into a tab on the right edge (list and windows hidden, layout kept). */
  tucked: boolean;
  tabs: { uuid: string; minimised: boolean }[];
  drafts: Record<string, string>;
}
