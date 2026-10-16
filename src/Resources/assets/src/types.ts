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
  supportStatus: 'open' | 'resolved' | null;
  /** Support only: where the problem was reported from. */
  supportContext?: { url?: string; label?: string } | null;
  canSend: boolean;
  peerOnline: boolean;
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
  kind: 'text' | 'buzz';
  html: string;
  createdAt: string;
  edited: boolean;
  deleted: boolean;
  moderated: boolean;
  reactions?: Reactions;
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

export type SoundName = 'none' | 'pop' | 'chime' | 'ding';

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
  | { type: 'conversation'; conversationUuid: string };

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
    linkPreviews: string;
    /** Full-page view of a conversation (`__uuid__`). */
    page: string;
    /** `__uuid__` is replaced with the conversation UUID (and `__message__` with a message UUID). */
    conversation: string;
    messages: string;
    message: string;
    reactions: string;
    read: string;
    typing: string;
    buzz: string;
    resolve: string;
    realtime: string;
    presence: string;
  };
  prefs: { sound: SoundName; buzz: boolean; presence: boolean };
  isAgent: boolean;
  /** The application resolves link previews (issue cards …). */
  linkPreviews: boolean;
  i18n: Record<string, string>;
}

export interface LinkPreview {
  title: string;
  subtitle: string;
  badge: string;
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
