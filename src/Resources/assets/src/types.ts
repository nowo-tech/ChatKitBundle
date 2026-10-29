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
  /** Direct only (1.10): what the viewer sees of the other person's status (absent before 1.10 = offline). */
  peerStatus?: VisibleStatus;
  /** Direct only (1.10): the other person's custom status text and emoji. */
  peerStatusText?: string | null;
  peerStatusEmoji?: string | null;
  unread: number;
  lastMessageAt: string | null;
  lastMessage: { preview: string; deleted: boolean; buzz?: boolean; mine: boolean; authorName: string | null } | null;
}

export interface ChatMessage {
  id: number;
  uuid: string;
  authorUuid: string | null;
  authorName: string;
  authorInitials: string;
  mine: boolean;
  kind: 'text' | 'buzz' | 'bot';
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
  | { type: 'prefs'; prefs: ChatPrefs };

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
  };
  prefs: ChatPrefs;
  /** 1.10: the user's own status (absent before 1.10: no status menu). */
  status?: OwnStatus;
  /** 1.10: minutes without input before the user shows as away (0 = never). */
  autoAwayMinutes?: number;
  /** 1.10: the user's own name and initials (status button). */
  me?: { name: string; initials: string };
  isAgent: boolean;
  /** Decorative emojis of the support assistant's own lines (`support.emojis`; absent before 1.9.1 = shown). */
  supportEmojis?: boolean;
  /** The application resolves link previews (issue cards …). */
  linkPreviews: boolean;
  /** The application registers reference providers: `/` commands and the `#` picker are offered. */
  references?: boolean;
  i18n: Record<string, string>;
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
