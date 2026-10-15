/**
 * the dock layout (open / minimised tabs, list open, drafts) lives in localStorage per
 * user and is mirrored live to every other browser tab through BroadcastChannel (storage events as the
 * fallback), so opening, minimising or closing a conversation in one tab does the same in all of them.
 */
import type { ChatMessage, DockState } from './types';

const EMPTY: DockState = { listOpen: false, tabs: [], drafts: {} };

export type SyncEvent =
  | { type: 'state'; state: DockState }
  | { type: 'messages'; uuid: string; messages: ChatMessage[] }
  | { type: 'read'; uuid: string; messageId: number };

export class DockStore {
  private state: DockState;
  private readonly channel: BroadcastChannel | null;
  private readonly key: string;
  private readonly listeners = new Set<(event: SyncEvent) => void>();
  private readonly onStorage = (event: StorageEvent): void => {
    if (event.key === this.key && this.channel === null) {
      this.state = this.load();
      this.emit({ type: 'state', state: this.state });
    }
  };

  constructor(userUuid: string) {
    this.key = `nowo-chat:${userUuid}`;
    this.state = this.load();
    this.channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(`nowo-chat:${userUuid}`) : null;
    this.channel?.addEventListener('message', (event: MessageEvent<SyncEvent>) => this.receive(event.data));
    window.addEventListener('storage', this.onStorage);
  }

  /** Stop syncing (the dock was destroyed: page swap, logout): close the channel, drop the listeners. */
  close(): void {
    this.channel?.close();
    window.removeEventListener('storage', this.onStorage);
    this.listeners.clear();
  }

  get(): DockState {
    return this.state;
  }

  update(mutate: (state: DockState) => void): void {
    const next: DockState = structuredClone(this.state);
    mutate(next);
    this.state = next;
    try {
      window.localStorage.setItem(this.key, JSON.stringify(next));
    } catch {
      // Private mode / quota: the layout still works for this page.
    }
    this.channel?.postMessage({ type: 'state', state: next } satisfies SyncEvent);
  }

  /** Share freshly fetched / sent messages with the other tabs so they paint without polling. */
  broadcast(event: Exclude<SyncEvent, { type: 'state' }>): void {
    this.channel?.postMessage(event);
  }

  subscribe(listener: (event: SyncEvent) => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  private receive(event: SyncEvent): void {
    if (event.type === 'state') {
      this.state = event.state;
    }
    this.emit(event);
  }

  private emit(event: SyncEvent): void {
    this.listeners.forEach((listener) => listener(event));
  }

  private load(): DockState {
    try {
      const raw = window.localStorage.getItem(this.key);
      if (raw === null) {
        return structuredClone(EMPTY);
      }
      const parsed = JSON.parse(raw) as Partial<DockState>;
      return {
        listOpen: parsed.listOpen === true,
        tabs: Array.isArray(parsed.tabs)
          ? parsed.tabs.filter((tab) => typeof tab?.uuid === 'string').map((tab) => ({ uuid: tab.uuid, minimised: tab.minimised === true }))
          : [],
        drafts: typeof parsed.drafts === 'object' && parsed.drafts !== null ? parsed.drafts : {},
      };
    } catch {
      return structuredClone(EMPTY);
    }
  }
}

/** Per-tab cache of the last messages of open conversations: instant paint after navigating (sessionStorage). */
export class ThreadCache {
  private readonly key: string;

  constructor(userUuid: string) {
    this.key = `nowo-chat-cache:${userUuid}`;
  }

  /** Keep only the conversations that are still open (the cache never grows past the open windows). */
  prune(openUuids: string[]): void {
    try {
      const all = JSON.parse(window.sessionStorage.getItem(this.key) ?? '{}') as Record<string, unknown>;
      const kept = Object.fromEntries(Object.entries(all).filter(([uuid]) => openUuids.includes(uuid)));
      window.sessionStorage.setItem(this.key, JSON.stringify(kept));
    } catch {
      // Best effort.
    }
  }

  read(uuid: string): { messages: ChatMessage[]; hasMore: boolean } | null {
    try {
      const all = JSON.parse(window.sessionStorage.getItem(this.key) ?? '{}') as Record<string, { messages: ChatMessage[]; hasMore: boolean }>;
      return all[uuid] ?? null;
    } catch {
      return null;
    }
  }

  write(uuid: string, messages: ChatMessage[], hasMore: boolean): void {
    try {
      const all = JSON.parse(window.sessionStorage.getItem(this.key) ?? '{}') as Record<string, unknown>;
      all[uuid] = { messages: messages.slice(-40), hasMore: hasMore || messages.length > 40 };
      window.sessionStorage.setItem(this.key, JSON.stringify(all));
    } catch {
      // Cache is best effort.
    }
  }
}

/**
 * Unsent drafts per conversation (localStorage, this user only). Kept apart from the shared layout so typing
 * never broadcasts state to other tabs (audit PERF-7).
 */
export class DraftStore {
  private readonly key: string;

  constructor(userUuid: string) {
    this.key = `nowo-chat-drafts:${userUuid}`;
  }

  get(uuid: string): string {
    return this.all()[uuid] ?? '';
  }

  set(uuid: string, text: string): void {
    const drafts = this.all();
    if (text.trim() === '') {
      delete drafts[uuid];
    } else {
      drafts[uuid] = text;
    }
    try {
      window.localStorage.setItem(this.key, JSON.stringify(drafts));
    } catch {
      // Private mode / quota: the draft stays in the composer.
    }
  }

  private all(): Record<string, string> {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(this.key) ?? '{}') as unknown;
      return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? (parsed as Record<string, string>) : {};
    } catch {
      return {};
    }
  }
}

/** Remove every chat entry of this browser (logout on a shared device — audit SEC-L5). */
export function clearChatStorage(): void {
  for (const storage of [window.localStorage, window.sessionStorage]) {
    try {
      Object.keys(storage)
        .filter((key) => key.startsWith('nowo-chat'))
        .forEach((key) => storage.removeItem(key));
    } catch {
      // Ignore.
    }
  }
}
