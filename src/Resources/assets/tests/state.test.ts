import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DockStore, type SyncEvent, ThreadCache } from '../src/state';
import type { ChatMessage } from '../src/types';

class FakeChannel {
  static channels: FakeChannel[] = [];
  listeners: ((event: MessageEvent) => void)[] = [];
  readonly name: string;
  constructor(name: string) {
    this.name = name;
    FakeChannel.channels.push(this);
  }
  addEventListener(_: string, listener: (event: MessageEvent) => void) {
    this.listeners.push(listener);
  }
  postMessage(data: unknown) {
    for (const other of FakeChannel.channels) {
      if (other !== this && other.name === this.name) {
        other.listeners.forEach((listener) => listener({ data } as MessageEvent));
      }
    }
  }
}

const message = (id: number): ChatMessage => ({ id, uuid: `m${id}`, authorUuid: 'a', authorName: 'A', authorInitials: 'A', mine: false, kind: 'text', html: '<p>x</p>', createdAt: '2026-10-15T10:00:00Z', edited: false, deleted: false, moderated: false });

describe('chat dock state', () => {
  beforeEach(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
    FakeChannel.channels = [];
  });
  afterEach(() => vi.unstubAllGlobals());

  it('persists the layout and mirrors it to other tabs', () => {
    vi.stubGlobal('BroadcastChannel', FakeChannel);
    const tabA = new DockStore('u1');
    const tabB = new DockStore('u1');
    const seen: SyncEvent[] = [];
    const unsubscribe = tabB.subscribe((event) => seen.push(event));

    tabA.update((state) => {
      state.listOpen = true;
      state.tabs.push({ uuid: 'c1', minimised: false });
      state.drafts.c1 = 'hola';
    });
    expect(tabB.get().tabs).toEqual([{ uuid: 'c1', minimised: false }]);
    expect(seen[0]?.type).toBe('state');
    expect(JSON.parse(window.localStorage.getItem('nowo-chat:u1') ?? '{}').drafts).toEqual({ c1: 'hola' });

    tabA.broadcast({ type: 'read', uuid: 'c1', messageId: 4 });
    expect(seen[1]).toEqual({ type: 'read', uuid: 'c1', messageId: 4 });
    unsubscribe();
    tabA.broadcast({ type: 'messages', uuid: 'c1', messages: [] });
    expect(seen).toHaveLength(2);

    expect(new DockStore('u1').get().listOpen).toBe(true);
  });

  it('sanitises stored state and falls back to storage events without BroadcastChannel', () => {
    vi.stubGlobal('BroadcastChannel', undefined);
    window.localStorage.setItem('nowo-chat:u2', JSON.stringify({ listOpen: 'yes', tucked: 1, tabs: [{ uuid: 'ok', minimised: 1 }, { nope: true }, null], drafts: null }));
    const store = new DockStore('u2');
    expect(store.get()).toEqual({ listOpen: false, tucked: false, tabs: [{ uuid: 'ok', minimised: false }], drafts: {} });
    window.localStorage.setItem('nowo-chat:u3', JSON.stringify({}));
    expect(new DockStore('u3').get()).toEqual({ listOpen: false, tucked: false, tabs: [], drafts: {} });
    window.localStorage.setItem('nowo-chat:u4', '{broken');
    expect(new DockStore('u4').get().tabs).toEqual([]);

    const events: SyncEvent[] = [];
    store.subscribe((event) => events.push(event));
    window.localStorage.setItem('nowo-chat:u2', JSON.stringify({ listOpen: true, tabs: [], drafts: {} }));
    window.dispatchEvent(new StorageEvent('storage', { key: 'nowo-chat:other' }));
    window.dispatchEvent(new StorageEvent('storage', { key: 'nowo-chat:u2' }));
    expect(events).toHaveLength(1);
    expect(store.get().listOpen).toBe(true);
    store.broadcast({ type: 'read', uuid: 'x', messageId: 1 });
  });

  it('keeps working when storage throws', () => {
    vi.stubGlobal('BroadcastChannel', undefined);
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota');
    });
    const store = new DockStore('u5');
    store.update((state) => {
      state.listOpen = true;
    });
    expect(store.get().listOpen).toBe(true);
    new ThreadCache('u5').write('c', [message(1)], false);
    spy.mockRestore();
  });

  it('caches the last 40 messages per conversation for this tab', () => {
    const cache = new ThreadCache('u6');
    expect(cache.read('c')).toBeNull();
    cache.write('c', Array.from({ length: 45 }, (_, i) => message(i + 1)), false);
    const cached = cache.read('c');
    expect(cached?.messages).toHaveLength(40);
    expect(cached?.messages[0]?.id).toBe(6);
    expect(cached?.hasMore).toBe(true);
    cache.write('d', [message(1)], false);
    expect(cache.read('d')?.hasMore).toBe(false);
    window.sessionStorage.setItem('nowo-chat-cache:u6', '{broken');
    expect(cache.read('c')).toBeNull();
  });

  it('forgets one conversation of the cache (a resumed ticket), best effort', () => {
    const cache = new ThreadCache('u7');
    cache.remove('nothing cached yet');
    cache.write('c', [message(1)], false);
    cache.write('d', [message(2)], false);
    cache.remove('c');
    expect(cache.read('c')).toBeNull();
    expect(cache.read('d')?.messages[0]?.id).toBe(2);
    cache.remove('none');
    window.sessionStorage.setItem('nowo-chat-cache:u7', '{broken');
    expect(() => cache.remove('d')).not.toThrow();
  });

  it('keeps drafts apart from the shared layout and clears chat storage on logout', async () => {
    const { DraftStore, clearChatStorage } = await import('../src/state');
    const drafts = new DraftStore('u9');
    expect(drafts.get('c')).toBe('');
    drafts.set('c', 'hola');
    expect(drafts.get('c')).toBe('hola');
    drafts.set('c', '  ');
    expect(drafts.get('c')).toBe('');
    window.localStorage.setItem('nowo-chat-drafts:u9', '[1]');
    expect(drafts.get('c')).toBe('');
    window.localStorage.setItem('nowo-chat-drafts:u9', '{broken');
    expect(drafts.get('c')).toBe('');
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota');
    });
    drafts.set('c', 'x');
    spy.mockRestore();

    const cache = new ThreadCache('u9');
    cache.write('open', [message(1)], false);
    cache.write('closed', [message(2)], false);
    cache.prune(['open']);
    expect(cache.read('closed')).toBeNull();
    expect(cache.read('open')).not.toBeNull();
    window.sessionStorage.setItem('nowo-chat-cache:u9', '{broken');
    cache.prune([]);

    window.localStorage.setItem('nowo-chat:u9', '{}');
    window.localStorage.setItem('other-app', '1');
    window.sessionStorage.setItem('nowo-chat-cache:u9', '{}');
    clearChatStorage();
    expect(window.localStorage.getItem('nowo-chat:u9')).toBeNull();
    expect(window.sessionStorage.getItem('nowo-chat-cache:u9')).toBeNull();
    expect(window.localStorage.getItem('other-app')).toBe('1');
    const keys = vi.spyOn(Object, 'keys').mockImplementationOnce(() => {
      throw new Error('blocked');
    });
    clearChatStorage();
    keys.mockRestore();
  });

});
