/** Chat JSON API client. Every call stays on the page (fetch) — nothing navigates or reloads. */
import { clearChatStorage } from './state';
import type { AutoReply, ChatConfig, ChatMessage, ChatPrefs, ChatStatus, Contact, ConversationSummary, LinkPreview, Member, OwnStatus, PeerStatus, QuickReply, Reactions, Reader, Reference, ReferenceType, SupportContext, SupportStatus, SupportTicket } from './types';

/** Error of a chat API call that answered with a non-2xx status (the HTTP status and the JSON `error` code). */
export class ChatApiError extends Error {
  readonly status: number;
  readonly code: string;

  /**
   * Creates the error; its message is the error code.
   *
   * @param status HTTP status of the response (401, 403, 422, 429…).
   * @param code Machine-readable error code from the JSON body (`error`), or "error" when it has none.
   */
  constructor(status: number, code: string) {
    super(code);
    this.status = status;
    this.code = code;
  }
}

/** Thin client of the bundle JSON endpoints: same-origin credentials, CSRF header on writes, typed answers. */
export class ChatApi {
  private readonly config: ChatConfig;

  /**
   * Creates the client for one dock configuration.
   *
   * @param config Dock configuration (endpoint URLs and the CSRF token).
   */
  constructor(config: ChatConfig) {
    this.config = config;
  }

  /**
   * The caller's conversation list and the total unread count.
   *
   * @returns The conversation summaries and the unread total.
   */
  async conversations(archived = false): Promise<{ conversations: ConversationSummary[]; unreadTotal: number }> {
    return this.get(archived ? `${this.config.endpoints.conversations}?archived=1` : this.config.endpoints.conversations);
  }

  /**
   * Closes (archives) a conversation in the user's list, or reopens it (1.10).
   *
   * @param uuid Conversation uuid.
   * @param archived True to close, false to reopen.
   */
  async archive(uuid: string, archived: boolean): Promise<void> {
    await this.request(archived ? 'POST' : 'DELETE', this.url('archive', uuid), {});
  }

  /**
   * Saves the automatic reply (1.11).
   *
   * @param reply The automatic reply.
   * @returns The stored automatic reply.
   */
  async saveAutoReply(reply: AutoReply): Promise<AutoReply> {
    return (await this.post<{ autoReply: AutoReply }>(this.config.endpoints.autoReply ?? '', reply)).autoReply;
  }

  /**
   * The user's quick replies (1.11).
   *
   * @returns The snippets, by title.
   */
  async quickReplies(): Promise<QuickReply[]> {
    return (await this.get<{ quickReplies: QuickReply[] }>(this.config.endpoints.quickReplies ?? '')).quickReplies;
  }

  /**
   * Saves a new quick reply.
   *
   * @param title Title.
   * @param body Text.
   * @returns The snippet.
   */
  async createQuickReply(title: string, body: string): Promise<QuickReply> {
    return (await this.post<{ quickReply: QuickReply }>(this.config.endpoints.quickReplies ?? '', { title, body })).quickReply;
  }

  /**
   * Changes a quick reply.
   *
   * @param id Snippet id.
   * @param title Title.
   * @param body Text.
   * @returns The snippet.
   */
  async updateQuickReply(id: number, title: string, body: string): Promise<QuickReply> {
    return (await this.request<{ quickReply: QuickReply }>('PATCH', `${this.config.endpoints.quickReplies ?? ''}/${id}`, { title, body })).quickReply;
  }

  /**
   * Deletes a quick reply.
   *
   * @param id Snippet id.
   */
  async deleteQuickReply(id: number): Promise<void> {
    await this.request('DELETE', `${this.config.endpoints.quickReplies ?? ''}/${id}`, {});
  }

  /**
   * Pinned messages of a conversation, latest pin first, and whether the user may pin (1.10).
   *
   * @param uuid Conversation uuid.
   * @returns The pins and the permission.
   */
  async pins(uuid: string): Promise<{ pins: ChatMessage[]; canPin: boolean }> {
    return this.get(this.url('pins', uuid));
  }

  /**
   * Pins a message, or unpins it (1.10).
   *
   * @param uuid Conversation uuid.
   * @param messageUuid Message uuid.
   * @param pinned True to pin, false to unpin.
   * @returns The pins after the change.
   */
  async pin(uuid: string, messageUuid: string, pinned: boolean): Promise<{ pins: ChatMessage[]; canPin: boolean }> {
    return this.request(pinned ? 'POST' : 'DELETE', this.messageUrl('pin', uuid, messageUuid), {});
  }

  /**
   * Closes every conversation of the list without unread messages (1.10).
   *
   * @returns How many were closed.
   */
  async archiveRead(): Promise<number> {
    return (await this.post<{ archived: number }>(this.config.endpoints.archiveRead ?? '', {})).archived;
  }

  /**
   * One conversation summary (e.g. a window restored from another tab or a push link).
   *
   * @param uuid Conversation uuid.
   * @returns The conversation summary.
   */
  async conversation(uuid: string): Promise<ConversationSummary> {
    return (await this.get<{ conversation: ConversationSummary }>(this.url('conversation', uuid))).conversation;
  }

  /**
   * People the caller may start a direct conversation with (the application's directory).
   *
   * @param query Search text ("" for the default suggestions).
   * @returns The matching contacts.
   */
  async contacts(query: string): Promise<Contact[]> {
    const url = `${this.config.endpoints.contacts}?q=${encodeURIComponent(query)}`;
    return (await this.get<{ contacts: Contact[] }>(url)).contacts;
  }

  /**
   * Members of a conversation the caller may @mention (filtered by name on the server, at most 20).
   *
   * @param uuid Conversation uuid.
   * @param query Text typed after `@` ("" for the first members).
   * @returns The matching members.
   */
  async members(uuid: string, query: string): Promise<Member[]> {
    return (await this.get<{ members: Member[] }>(`${this.url('members', uuid)}?q=${encodeURIComponent(query)}`)).members;
  }

  /**
   * `/` commands offered in a conversation (no `type`), or the #references matching a query (a type key, or `*`
   * for every offered type). The server offers only what everyone in the conversation may open.
   *
   * @param uuid Conversation uuid.
   * @param type Type key, `*`, or "" for the commands.
   * @param query Search text.
   * @returns The commands and the matching items.
   */
  async references(uuid: string, type: string, query: string): Promise<{ types: ReferenceType[]; references: Reference[] }> {
    return this.get<{ types: ReferenceType[]; references: Reference[] }>(`${this.url('references', uuid)}?type=${encodeURIComponent(type)}&q=${encodeURIComponent(query)}`);
  }

  /**
   * One message as the caller sees it (its #reference cards resolved for the caller).
   *
   * @param uuid Conversation uuid.
   * @param messageUuid Message uuid.
   * @returns The message.
   */
  async message(uuid: string, messageUuid: string): Promise<ChatMessage> {
    return (await this.get<{ message: ChatMessage }>(this.messageUrl('message', uuid, messageUuid))).message;
  }

  /**
   * Opens (or creates) the direct conversation with a person.
   *
   * @param userUuid Public id (uuid) of the other user.
   * @returns The direct conversation summary.
   */
  async openDirect(userUuid: string): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.config.endpoints.direct, { userUuid })).conversation;
  }

  /**
   * Opens (or creates) a channel conversation.
   *
   * @param channelKey Channel key (e.g. "project:<id>").
   * @returns The channel conversation summary.
   */
  async openChannel(channelKey: string): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.config.endpoints.channel, { channelKey })).conversation;
  }

  /**
   * Opens the caller's support conversation with the administrators.
   *
   * @param context Optional page the request comes from (url / label), shown to the agents.
   * @returns The support conversation summary.
   */
  async openSupport(context?: SupportContext): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.config.endpoints.support, context === undefined ? {} : { context })).conversation;
  }

  /**
   * One page of messages of a conversation (newest page by default).
   *
   * @param uuid Conversation uuid.
   * @param cursor `before`: older page before this message id; `after`: only messages newer than this id.
   * @returns The messages, whether older ones exist, the caller's read cursor and the other readers.
   */
  async messages(
    uuid: string,
    cursor: { before?: number; after?: number } = {},
  ): Promise<{ messages: ChatMessage[]; hasMore: boolean; readUpTo: number | null; readers: Reader[] }> {
    const params = new URLSearchParams();
    if (cursor.before !== undefined) {
      params.set('before', String(cursor.before));
    }
    if (cursor.after !== undefined) {
      params.set('after', String(cursor.after));
    }
    const query = params.toString();
    return this.get(`${this.url('messages', uuid)}${query === '' ? '' : `?${query}`}`);
  }

  /**
   * Sends a text message.
   *
   * @param uuid Conversation uuid.
   * @param body Message text (Markdown subset, rendered by the server).
   * @returns The stored message.
   */
  async send(uuid: string, body: string): Promise<ChatMessage> {
    return (await this.post<{ message: ChatMessage }>(this.url('messages', uuid), { body })).message;
  }

  /**
   * Edits one of the caller's messages (within the edit window).
   *
   * @param uuid Conversation uuid.
   * @param messageUuid Uuid of the message to edit.
   * @param body New message text.
   * @returns The updated message.
   */
  async edit(uuid: string, messageUuid: string, body: string): Promise<ChatMessage> {
    return (await this.request<{ message: ChatMessage }>('PATCH', this.messageUrl('message', uuid, messageUuid), { body })).message;
  }

  /**
   * Deletes a message (own message, or moderation by an agent).
   *
   * @param uuid Conversation uuid.
   * @param messageUuid Uuid of the message to delete.
   * @returns The message as a tombstone.
   */
  async remove(uuid: string, messageUuid: string): Promise<ChatMessage> {
    return (await this.request<{ message: ChatMessage }>('DELETE', this.messageUrl('message', uuid, messageUuid), {})).message;
  }

  /**
   * Toggles the caller's reaction on a message.
   *
   * @param uuid Conversation uuid.
   * @param messageUuid Uuid of the message.
   * @param emoji Reaction emoji (one of the configured reactions).
   * @returns The message reactions after the toggle (emoji → user uuids).
   */
  async react(uuid: string, messageUuid: string, emoji: string): Promise<Reactions> {
    return (await this.post<{ reactions: Reactions }>(this.messageUrl('reactions', uuid, messageUuid), { emoji })).reactions;
  }

  /**
   * Link preview cards the application allows this viewer to see.
   *
   * @param urls Links found in messages (at most ten per call).
   * @returns Previews keyed by URL; links without a preview are absent.
   */
  async linkPreviews(urls: string[]): Promise<Record<string, LinkPreview>> {
    const query = urls.map((url) => `url[]=${encodeURIComponent(url)}`).join('&');
    const { previews } = await this.get<{ previews: Record<string, LinkPreview> | unknown[] }>(`${this.config.endpoints.linkPreviews}?${query}`);
    // PHP encodes an empty map as [].
    return Array.isArray(previews) ? {} : previews;
  }

  /**
   * Moves the caller's read cursor.
   *
   * @param uuid Conversation uuid.
   * @param messageId Id of the newest message the caller has seen.
   */
  async markRead(uuid: string, messageId: number): Promise<void> {
    await this.post(this.url('read', uuid), { messageId });
  }

  /**
   * Tells the other participants that the caller is typing.
   *
   * @param uuid Conversation uuid.
   */
  async typing(uuid: string): Promise<void> {
    await this.post(this.url('typing', uuid), {});
  }

  /**
   * Sends a buzz (direct conversations only, rate limited).
   *
   * @param uuid Conversation uuid.
   * @returns The buzz message.
   */
  async buzz(uuid: string): Promise<ChatMessage> {
    return (await this.post<{ message: ChatMessage }>(this.url('buzz', uuid), {})).message;
  }

  /**
   * Marks a support conversation as resolved (agents only).
   *
   * @param uuid Support conversation uuid.
   * @returns The updated conversation summary.
   */
  async resolve(uuid: string): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.url('resolve', uuid), {})).conversation;
  }

  /**
   * The caller's support tickets, newest activity first.
   *
   * @returns The tickets.
   */
  async supportTickets(): Promise<SupportTicket[]> {
    return (await this.get<{ tickets: SupportTicket[] }>(this.config.endpoints.supportTickets)).tickets;
  }

  /**
   * Chooses the topic of the caller's draft (it becomes a ticket) or, as an agent, changes a ticket's topic.
   *
   * @param uuid Draft or ticket uuid.
   * @param topic Topic key.
   * @returns The updated summary and the bot lines posted.
   */
  async chooseTopic(uuid: string, topic: string): Promise<{ conversation: ConversationSummary; messages: ChatMessage[] }> {
    return this.post(this.url('supportTopic', uuid), { topic });
  }

  /**
   * Continues an earlier ticket; the messages of the draft (if given) move into it.
   *
   * @param uuid Ticket uuid.
   * @param draft Uuid of the caller's draft, or null.
   * @returns The ticket summary.
   */
  async resumeTicket(uuid: string, draft: string | null): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.url('supportResume', uuid), draft === null ? {} : { draft })).conversation;
  }

  /**
   * Changes a ticket's status (the requester may only reopen).
   *
   * @param uuid Ticket uuid.
   * @param status New status.
   * @returns The updated summary.
   */
  async setTicketStatus(uuid: string, status: SupportStatus): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.url('supportStatus', uuid), { status })).conversation;
  }

  /**
   * Assigns a ticket to an agent (agents only).
   *
   * @param uuid Ticket uuid.
   * @param assignee Public id of the agent, or null to unassign.
   * @returns The updated summary.
   */
  async assignTicket(uuid: string, assignee: string | null): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.url('supportAssign', uuid), { assignee })).conversation;
  }

  /**
   * Mercure settings for this user: hub URL, short-lived subscriber token and private topic.
   *
   * @returns Whether realtime is enabled, the hub URL, the token, the topic and the token expiry.
   */
  async realtime(): Promise<{ enabled: boolean; hubUrl: string | null; token: string | null; topic: string; expiresAt: string }> {
    return this.get(this.config.endpoints.realtime);
  }

  /**
   * Tab heartbeat (`idle`: no input in any tab for the auto-away delay).
   *
   * @param visible Whether this tab is visible.
   * @param idle Whether the user is idle.
   * @returns Online peers, the status each shows and the user's own status.
   */
  async presence(visible: boolean, idle = false): Promise<{ online: string[]; statuses: Record<string, PeerStatus>; me: OwnStatus | null }> {
    const answer = await this.post<{ online: string[]; statuses?: Record<string, PeerStatus>; me?: OwnStatus }>(this.config.endpoints.presence, { visible, idle });
    return { online: answer.online, statuses: answer.statuses ?? {}, me: answer.me ?? null };
  }

  /**
   * Sets the user's own status.
   *
   * @param status The status, with its optional text / emoji and when it clears (never, 30m, 1h, 4h, today, week).
   * @returns The stored status.
   */
  async setStatus(status: { status: ChatStatus; text?: string | null; emoji?: string | null; clearAfter?: string }): Promise<OwnStatus> {
    return (await this.post<{ status: OwnStatus }>(this.config.endpoints.status ?? '', status)).status;
  }

  /**
   * Saves one preference.
   *
   * @param key Preference key (ChatUserSettings::PREFERENCE_KEYS).
   * @param value Its new value.
   * @returns Every preference after the change.
   */
  async saveSetting(key: string, value: string | number | boolean): Promise<ChatPrefs> {
    return (await this.post<{ prefs: ChatPrefs }>(this.config.endpoints.settings ?? '', { key, value })).prefs;
  }


  /**
   * Endpoint URL of a conversation action (`__uuid__` replaced).
   *
   * @param kind Endpoint name in the configuration.
   * @param uuid Conversation uuid.
   * @returns The URL.
   */
  private url(
    kind: 'conversation' | 'messages' | 'members' | 'references' | 'read' | 'typing' | 'buzz' | 'resolve' | 'supportTopic' | 'supportResume' | 'supportStatus' | 'supportAssign' | 'archive' | 'pins',
    uuid: string,
  ): string {
    return (this.config.endpoints[kind] ?? '').replace('__uuid__', encodeURIComponent(uuid));
  }

  /**
   * Endpoint URL of a message action (`__uuid__` and `__message__` replaced).
   *
   * @param kind Endpoint name in the configuration.
   * @param uuid Conversation uuid.
   * @param messageUuid Message uuid.
   * @returns The URL.
   */
  private messageUrl(kind: 'message' | 'reactions' | 'pin', uuid: string, messageUuid: string): string {
    return (this.config.endpoints[kind] ?? '').replace('__uuid__', encodeURIComponent(uuid)).replace('__message__', encodeURIComponent(messageUuid));
  }

  /**
   * GET with same-origin credentials, decoded as JSON.
   *
   * @param url Endpoint URL (with its query string).
   * @returns The decoded payload.
   */
  private async get<T>(url: string): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return this.decode<T>(response);
  }

  /**
   * POST a JSON body (CSRF header included).
   *
   * @param url Endpoint URL.
   * @param body Value sent as JSON.
   * @returns The decoded payload.
   */
  private async post<T>(url: string, body: unknown): Promise<T> {
    return this.request<T>('POST', url, body);
  }

  /**
   * Write request with a JSON body and the CSRF token header.
   *
   * @param method HTTP method.
   * @param url Endpoint URL.
   * @param body Value sent as JSON.
   * @returns The decoded payload.
   */
  private async request<T>(method: 'POST' | 'PATCH' | 'DELETE', url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
      body: JSON.stringify(body),
    });
    return this.decode<T>(response);
  }

  /**
   * Decodes a JSON answer. A 401 clears the chat storage of this device; any non-2xx status throws.
   *
   * @param response Fetch response.
   * @returns The payload (null when the body is not JSON).
   * @throws ChatApiError When the status is not 2xx (code from the body `error`, else "error").
   */
  private async decode<T>(response: Response): Promise<T> {
    // A firewall that redirects to its login page (302 → HTML 200) means the session is gone too (CHAT-SEC-12).
    if (response.redirected === true) {
      clearChatStorage();
      throw new ChatApiError(401, 'unauthorized');
    }
    let payload: unknown;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    if (response.status === 401) {
      // Session gone (timeout, logout elsewhere): drafts, layout and cached threads leave this device (CHAT-SEC-10).
      clearChatStorage();
    }
    if (!response.ok) {
      const code =
        typeof payload === 'object' && payload !== null && 'error' in payload ? String(payload.error) : 'error';
      throw new ChatApiError(response.status, code);
    }
    return payload as T;
  }
}
