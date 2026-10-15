/** Chat JSON API client. Every call stays on the page (fetch) — nothing navigates or reloads. */
import type { ChatConfig, ChatMessage, Contact, ConversationSummary, LinkPreview, Reactions, Reader, SupportContext } from './types';

export class ChatApiError extends Error {
  readonly status: number;
  readonly code: string;

  constructor(status: number, code: string) {
    super(code);
    this.status = status;
    this.code = code;
  }
}

export class ChatApi {
  private readonly config: ChatConfig;

  constructor(config: ChatConfig) {
    this.config = config;
  }

  async conversations(): Promise<{ conversations: ConversationSummary[]; unreadTotal: number }> {
    return this.get(this.config.endpoints.conversations);
  }

  async conversation(uuid: string): Promise<ConversationSummary> {
    return (await this.get<{ conversation: ConversationSummary }>(this.url('conversation', uuid))).conversation;
  }

  async contacts(query: string): Promise<Contact[]> {
    const url = `${this.config.endpoints.contacts}?q=${encodeURIComponent(query)}`;
    return (await this.get<{ contacts: Contact[] }>(url)).contacts;
  }

  async openDirect(userUuid: string): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.config.endpoints.direct, { userUuid })).conversation;
  }

  async openChannel(channelKey: string): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.config.endpoints.channel, { channelKey })).conversation;
  }

  async openSupport(context?: SupportContext): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.config.endpoints.support, context === undefined ? {} : { context })).conversation;
  }

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

  async send(uuid: string, body: string): Promise<ChatMessage> {
    return (await this.post<{ message: ChatMessage }>(this.url('messages', uuid), { body })).message;
  }

  async edit(uuid: string, messageUuid: string, body: string): Promise<ChatMessage> {
    return (await this.request<{ message: ChatMessage }>('PATCH', this.messageUrl('message', uuid, messageUuid), { body })).message;
  }

  async remove(uuid: string, messageUuid: string): Promise<ChatMessage> {
    return (await this.request<{ message: ChatMessage }>('DELETE', this.messageUrl('message', uuid, messageUuid), {})).message;
  }

  async react(uuid: string, messageUuid: string, emoji: string): Promise<Reactions> {
    return (await this.post<{ reactions: Reactions }>(this.messageUrl('reactions', uuid, messageUuid), { emoji })).reactions;
  }

  async linkPreviews(urls: string[]): Promise<Record<string, LinkPreview>> {
    const query = urls.map((url) => `url[]=${encodeURIComponent(url)}`).join('&');
    const { previews } = await this.get<{ previews: Record<string, LinkPreview> | unknown[] }>(`${this.config.endpoints.linkPreviews}?${query}`);
    // PHP encodes an empty map as [].
    return Array.isArray(previews) ? {} : previews;
  }

  async markRead(uuid: string, messageId: number): Promise<void> {
    await this.post(this.url('read', uuid), { messageId });
  }

  async typing(uuid: string): Promise<void> {
    await this.post(this.url('typing', uuid), {});
  }

  async buzz(uuid: string): Promise<ChatMessage> {
    return (await this.post<{ message: ChatMessage }>(this.url('buzz', uuid), {})).message;
  }

  async resolve(uuid: string): Promise<ConversationSummary> {
    return (await this.post<{ conversation: ConversationSummary }>(this.url('resolve', uuid), {})).conversation;
  }

  async realtime(): Promise<{ enabled: boolean; hubUrl: string | null; token: string | null; topic: string; expiresAt: string }> {
    return this.get(this.config.endpoints.realtime);
  }

  async presence(visible: boolean): Promise<string[]> {
    return (await this.post<{ online: string[] }>(this.config.endpoints.presence, { visible })).online;
  }

  private url(kind: 'conversation' | 'messages' | 'read' | 'typing' | 'buzz' | 'resolve', uuid: string): string {
    return this.config.endpoints[kind].replace('__uuid__', encodeURIComponent(uuid));
  }

  private messageUrl(kind: 'message' | 'reactions', uuid: string, messageUuid: string): string {
    return this.config.endpoints[kind].replace('__uuid__', encodeURIComponent(uuid)).replace('__message__', encodeURIComponent(messageUuid));
  }

  private async get<T>(url: string): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return this.decode<T>(response);
  }

  private async post<T>(url: string, body: unknown): Promise<T> {
    return this.request<T>('POST', url, body);
  }

  private async request<T>(method: 'POST' | 'PATCH' | 'DELETE', url: string, body: unknown): Promise<T> {
    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.config.csrf },
      body: JSON.stringify(body),
    });
    return this.decode<T>(response);
  }

  private async decode<T>(response: Response): Promise<T> {
    let payload: unknown;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }
    if (!response.ok) {
      const code =
        typeof payload === 'object' && payload !== null && 'error' in payload ? String(payload.error) : 'error';
      throw new ChatApiError(response.status, code);
    }
    return payload as T;
  }
}
