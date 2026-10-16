/** Chat JSON API client. Every call stays on the page (fetch) — nothing navigates or reloads. */
import { clearChatStorage } from './state';
import type { ChatConfig, ChatMessage, Contact, ConversationSummary, LinkPreview, Reactions, Reader, SupportContext } from './types';

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
  async conversations(): Promise<{ conversations: ConversationSummary[]; unreadTotal: number }> {
    return this.get(this.config.endpoints.conversations);
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
   * Mercure settings for this user: hub URL, short-lived subscriber token and private topic.
   *
   * @returns Whether realtime is enabled, the hub URL, the token, the topic and the token expiry.
   */
  async realtime(): Promise<{ enabled: boolean; hubUrl: string | null; token: string | null; topic: string; expiresAt: string }> {
    return this.get(this.config.endpoints.realtime);
  }

  /**
   * Presence heartbeat.
   *
   * @param visible Whether the page is currently visible to the user.
   * @returns Uuids of the users that are online (matched against the peers of direct conversations).
   */
  async presence(visible: boolean): Promise<string[]> {
    return (await this.post<{ online: string[] }>(this.config.endpoints.presence, { visible })).online;
  }

  /**
   * Endpoint URL of a conversation action (`__uuid__` replaced).
   *
   * @param kind Endpoint name in the configuration.
   * @param uuid Conversation uuid.
   * @returns The URL.
   */
  private url(kind: 'conversation' | 'messages' | 'read' | 'typing' | 'buzz' | 'resolve', uuid: string): string {
    return this.config.endpoints[kind].replace('__uuid__', encodeURIComponent(uuid));
  }

  /**
   * Endpoint URL of a message action (`__uuid__` and `__message__` replaced).
   *
   * @param kind Endpoint name in the configuration.
   * @param uuid Conversation uuid.
   * @param messageUuid Message uuid.
   * @returns The URL.
   */
  private messageUrl(kind: 'message' | 'reactions', uuid: string, messageUuid: string): string {
    return this.config.endpoints[kind].replace('__uuid__', encodeURIComponent(uuid)).replace('__message__', encodeURIComponent(messageUuid));
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
