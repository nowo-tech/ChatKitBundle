import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatConfig, ChatMessage, ConversationSummary, RealtimeEvent, SidebarSection } from '../src/types';
import { templatesRoot } from './support';
import { readFileSync } from 'node:fs';

/** 1.25: the grouped sidebar inside the dock (page mode and, with `sidebar.dock`, the floating dock). */
const api = {
  conversations: vi.fn(),
  conversation: vi.fn(),
  contacts: vi.fn(),
  sidebar: vi.fn(),
  messages: vi.fn(),
  markRead: vi.fn(),
  presence: vi.fn(),
  archive: vi.fn(),
  pins: vi.fn(),
  linkPreviews: vi.fn(),
  message: vi.fn(),
};
let realtime: { onEvent: (event: RealtimeEvent) => void } | null = null;

vi.mock('../src/api', async (importOriginal) => {
  const original = await importOriginal<typeof import('../src/api')>();
  return {
    ...original,
    ChatApi: class {
      constructor() {
        return api;
      }
    },
  };
});
vi.mock('../src/realtime', () => ({
  ChatRealtime: class {
    constructor(_api: unknown, onEvent: (event: RealtimeEvent) => void) {
      realtime = { onEvent };
    }
    connect = vi.fn().mockResolvedValue(undefined);
    stop = vi.fn();
  },
}));
vi.mock('../src/sounds', () => ({ playOnce: vi.fn() }));

const { ChatDock } = await import('../src/dock');

const i18n: Record<string, string> = {
  launcher: 'Messaging', loading: 'Loading', empty_title: 'Nothing yet', empty_body: 'Start one', no_results: 'No matches', page_select: 'Pick a conversation.',
  unread: '%count% unread', sidebar_recent: 'Recent', sidebar_conversations: 'Direct messages and groups', sidebar_empty: 'No conversations yet.', archived_one: 'Closed', undo: 'Undo',
};

const sidebarSettings = (over: Partial<NonNullable<ChatConfig['sidebar']>> = {}): NonNullable<ChatConfig['sidebar']> => ({
  page: true, dock: false, endpoint: '/chat/api/sidebar', dialogs: { md: 'nowo-chat-sidebar-dialog', wide: 'nowo-chat-sidebar-dialog-wide' }, ...over,
});

const config = (over: Partial<ChatConfig> = {}): ChatConfig => ({
  userUuid: 'me', csrf: 't', locale: 'en', contextChannel: null, pollMs: 4000, maxLength: 4000, editWindowSeconds: 900, reactions: ['👍'], linkPreviews: false,
  endpoints: { page: '/chat/__uuid__', archive: '/archive/__uuid__' } as ChatConfig['endpoints'], i18n,
  prefs: { sound: 'pop', buzz: true, presence: true }, isAgent: false, sidebar: sidebarSettings(), ...over,
});

const summary = (uuid: string, over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid, kind: 'direct', title: `T ${uuid}`, subtitle: `${uuid}@x`, initials: 'TT', peerUuid: `peer-${uuid}`, channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: '2026-10-15T10:00:00Z',
  lastMessage: { preview: 'hola', deleted: false, mine: false, authorName: 'Peer' }, ...over,
});

const section = (over: Partial<SidebarSection> = {}): SidebarSection => ({
  key: 'channels', label: 'Channels', position: 0, collapsible: true, collapsedByDefault: false,
  items: [{ key: 'general', label: 'general', subtitle: null, icon: 'channel', conversationUuid: 'g', url: null, unread: 3, mentions: 1, muted: false }],
  actions: [{ key: 'create', label: 'Create channel', icon: 'plus', url: '/rooms', dialogUrl: '/rooms/new', dialogSize: 'md' }],
  ...over,
});

let ids = 900;
const message = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: ids++, uuid: `m${ids}`, authorUuid: 'peer', authorName: 'Peer', authorInitials: 'PE', mine: false, kind: 'text', html: '<p>hi</p>',
  createdAt: '2026-10-15T11:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});

const mounted: { destroy(): void }[] = [];

function dialogsIn(root: HTMLElement): void {
  for (const id of ['nowo-chat-sidebar-dialog', 'nowo-chat-sidebar-dialog-wide']) {
    const dialog = document.createElement('dialog');
    dialog.id = id;
    root.append(dialog);
  }
}

function mountPage(conversations: ConversationSummary[], cfg: Partial<ChatConfig> = {}, current?: string) {
  api.conversations.mockResolvedValue({ conversations, unreadTotal: 0 });
  const page = document.createElement('div');
  page.className = 'nowo-chat-page';
  page.dataset.nowoChatPageMode = '';
  if (current !== undefined) {
    page.dataset.nowoChatCurrent = current;
  }
  const root = templatesRoot();
  dialogsIn(root);
  document.body.append(page, root);
  const dock = new ChatDock(root, config(cfg), { page });
  mounted.push(dock);
  const q = <T extends HTMLElement = HTMLElement>(testid: string) => page.querySelector<T>(`[data-testid="${testid}"]`) as T;
  return { dock, page, root, q, list: page.querySelector('[data-part="list"]') as HTMLElement };
}

function mountDock(conversations: ConversationSummary[], cfg: Partial<ChatConfig> = {}, root = templatesRoot()) {
  api.conversations.mockResolvedValue({ conversations, unreadTotal: 0 });
  document.body.append(root);
  const dock = new ChatDock(root, config(cfg));
  mounted.push(dock);
  return { dock, root, list: root.querySelector('[data-part="list"]') as HTMLElement };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 10; i += 1) {
    await Promise.resolve();
  }
}

describe('ChatDock sidebar', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    window.localStorage.clear();
    window.sessionStorage.clear();
    window.history.replaceState(null, '', '/chat/');
    Element.prototype.scrollTo = vi.fn();
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
      cb(0);
      return 1;
    });
    for (const fn of Object.values(api)) {
      fn.mockReset();
    }
    api.messages.mockResolvedValue({ messages: [], hasMore: false, readUpTo: null, readers: [] });
    api.markRead.mockResolvedValue(undefined);
    api.presence.mockResolvedValue({ online: [], statuses: {}, me: null });
    api.pins.mockResolvedValue({ pins: [], canPin: false });
    api.conversation.mockRejectedValue(new Error('unknown'));
    api.archive.mockResolvedValue(undefined);
    api.sidebar.mockResolvedValue([section()]);
  });
  afterEach(() => {
    mounted.splice(0).forEach((dock) => dock.destroy());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
  });

  it('groups the full-page list and opens host rows', async () => {
    const { dock, page, q, list } = mountPage([summary('a', { unread: 1 }), summary('g', { kind: 'channel', title: '#general', unread: 5 })]);
    await dock.start();
    expect(api.sidebar).toHaveBeenCalledTimes(1);
    expect(page.querySelector('.nowo-chat-pagemode')?.classList.contains('has-sidebar')).toBe(true);
    expect(list.classList.contains('is-sidebar')).toBe(true);
    expect([...list.querySelectorAll<HTMLElement>('[data-part="section"]')].map((element) => element.dataset.section)).toEqual(['recent', 'channels', 'conversations']);
    expect(q('chat-sidebar-item-channels-general').querySelector('[data-part="badge"]')?.textContent).toContain('5');

    q('chat-sidebar-item-channels-general').click();
    await flush();
    expect(window.location.pathname).toBe('/chat/g');
    expect(q('chat-sidebar-item-channels-general').getAttribute('aria-selected')).toBe('true');

    // A host row the list does not hold yet is fetched first.
    api.conversation.mockResolvedValueOnce(summary('z', { kind: 'channel' }));
    api.sidebar.mockResolvedValue([section({ items: [{ key: 'z', label: 'z', subtitle: null, icon: 'channel', conversationUuid: 'z', url: null, unread: 0, mentions: 0, muted: false }] })]);
    realtime?.onEvent({ type: 'list' } as RealtimeEvent);
    await flush();
    q('chat-sidebar-item-channels-z').click();
    await flush();
    expect(api.conversation).toHaveBeenCalledWith('z');
    expect(window.location.pathname).toBe('/chat/z');

    // A refused one says why (the list's toast).
    api.conversation.mockRejectedValueOnce(new Error('gone'));
    api.sidebar.mockResolvedValue([section({ items: [{ key: 'y', label: 'y', subtitle: null, icon: 'channel', conversationUuid: 'y', url: null, unread: 0, mentions: 0, muted: false }] })]);
    realtime?.onEvent({ type: 'list' } as RealtimeEvent);
    await flush();
    q('chat-sidebar-item-channels-y').click();
    await flush();
    expect(window.location.pathname).toBe('/chat/z');
  });

  it('lists flat while a filter or a search is on, and keeps the last sections when the sidebar fails', async () => {
    const { dock, page, list } = mountPage([summary('a', { unread: 1 })]);
    await dock.start();
    const search = page.querySelector<HTMLInputElement>('[data-part="search"]') as HTMLInputElement;
    search.value = 'T a';
    search.dispatchEvent(new Event('input'));
    expect(list.classList.contains('is-sidebar')).toBe(false);
    expect(list.querySelector('.nowo-chat-row')).not.toBeNull();
    search.value = '';
    search.dispatchEvent(new Event('input'));
    expect(list.classList.contains('is-sidebar')).toBe(true);
    (page.querySelector('[data-filter="unread"]') as HTMLButtonElement).click();
    expect(list.classList.contains('is-sidebar')).toBe(false);
    (page.querySelector('[data-filter="all"]') as HTMLButtonElement).click();

    api.sidebar.mockRejectedValue(new Error('down'));
    realtime?.onEvent({ type: 'list' } as RealtimeEvent);
    await flush();
    expect(list.querySelector('[data-testid="chat-sidebar-section-channels"]')).not.toBeNull();
  });

  it('counts realtime messages and mentions on host rows, and clears them once read', async () => {
    const { dock, q } = mountPage([summary('a')]);
    await dock.start();
    realtime?.onEvent({ type: 'message', conversationUuid: 'g', message: message({ mentions: [{ uuid: 'me', name: 'Me' }] }) } as RealtimeEvent);
    realtime?.onEvent({ type: 'message', conversationUuid: 'g', message: message({ mentionAll: 'here' }) } as RealtimeEvent);
    realtime?.onEvent({ type: 'message', conversationUuid: 'g', message: message({ authorUuid: 'me' }) } as RealtimeEvent);
    // An unknown conversation reloads the list (and the sidebar) — keep the counted rows for this check.
    api.sidebar.mockImplementation(() => new Promise(() => undefined));
    realtime?.onEvent({ type: 'message', conversationUuid: 'g', message: message() } as RealtimeEvent);
    await flush();
    dock.read('a', 1);
    const general = q('chat-sidebar-item-channels-general');
    expect(general.querySelector('[data-part="badge"]')?.textContent).toContain('6');
    expect(general.querySelector<HTMLElement>('[data-part="mentions"]')?.hidden).toBe(false);

    realtime?.onEvent({ type: 'read', conversationUuid: 'g', userUuid: 'me', userName: 'Me', messageId: 1 } as RealtimeEvent);
    dock.read('a', 1);
    expect(q('chat-sidebar-item-channels-general').querySelector<HTMLElement>('[data-part="badge"]')?.hidden).toBe(true);
    expect(q('chat-sidebar-item-channels-general').querySelector<HTMLElement>('[data-part="mentions"]')?.hidden).toBe(true);
  });

  it('clears host rows read in another tab', async () => {
    vi.useRealTimers();
    const { dock, q } = mountPage([summary('g', { kind: 'channel', unread: 2 })]);
    await dock.start();
    const otherTab = new BroadcastChannel('nowo-chat:me');
    otherTab.postMessage({ type: 'read', uuid: 'g', messageId: 1 });
    await vi.waitFor(() => expect(q('chat-sidebar-item-channels-general').querySelector<HTMLElement>('[data-part="badge"]')?.hidden).toBe(true));
    otherTab.close();
  });

  it('reloads the sidebar when a dialog closes and gives the focus back to its action', async () => {
    const { dock, root, q } = mountPage([summary('a')]);
    await dock.start();
    const action = q<HTMLAnchorElement>('chat-sidebar-action-channels-create');
    expect(action.dataset.nowoUiDialogOpen).toBe('nowo-chat-sidebar-dialog');
    // UiKit's dialog script would take the click; here it must not navigate.
    document.addEventListener('click', (event) => event.preventDefault());
    action.click();
    api.sidebar.mockResolvedValue([section({ label: 'Rooms' })]);
    (document.activeElement as HTMLElement | null)?.blur();
    root.querySelector('#nowo-chat-sidebar-dialog')?.dispatchEvent(new Event('close'));
    await flush();
    expect(q('chat-sidebar-section-channels').querySelector('[data-part="label"]')?.textContent).toBe('Rooms');
    expect(document.activeElement).toBe(q('chat-sidebar-action-channels-create'));

    // Closed with the focus elsewhere (or without an action click): the focus stays.
    const other = document.createElement('button');
    document.body.append(other);
    q('chat-sidebar-action-channels-create').click();
    other.focus();
    root.querySelector('#nowo-chat-sidebar-dialog-wide')?.dispatchEvent(new Event('close'));
    await flush();
    expect(document.activeElement).toBe(other);
    root.querySelector('#nowo-chat-sidebar-dialog')?.dispatchEvent(new Event('close'));
    await flush();
    expect(document.activeElement).toBe(other);
  });

  it('closes a conversation with Delete', async () => {
    const { dock, q } = mountPage([summary('a')]);
    await dock.start();
    q('chat-sidebar-item-recent-a').dispatchEvent(new KeyboardEvent('keydown', { key: 'Delete', bubbles: true }));
    await flush();
    expect(api.archive).toHaveBeenCalledWith('a', true);
  });

  it('opens the most recent conversation when asked to, unread until the viewer interacts', async () => {
    api.messages.mockResolvedValue({ messages: [message({ id: 42 })], hasMore: false, readUpTo: null, readers: [] });
    const { dock, page } = mountPage([summary('old', { lastMessageAt: '2026-10-01T10:00:00Z' }), summary('empty', { lastMessage: null, lastMessageAt: '2026-11-01T10:00:00Z' }), summary('new', { lastMessageAt: '2026-10-20T10:00:00Z' })], { openRecent: true });
    await dock.start();
    await flush();
    expect(window.location.pathname).toBe('/chat/new');
    // Audit KJ-L2: shown, not read, until the viewer touches it.
    expect(api.markRead).not.toHaveBeenCalled();
    const view = page.querySelector('.nowo-chat-pagemode__thread .nowo-chat-window') as HTMLElement;
    view.dispatchEvent(new Event('pointerdown'));
    expect(api.markRead).toHaveBeenCalledWith('new', 42);
    api.markRead.mockClear();
    view.dispatchEvent(new Event('keydown'));
    expect(api.markRead).not.toHaveBeenCalled();
    api.messages.mockResolvedValue({ messages: [], hasMore: false, readUpTo: null, readers: [] });

    // Not when the address names a conversation, nor on phones, nor without the option.
    window.history.replaceState(null, '', '/chat/old');
    const named = mountPage([summary('old'), summary('new', { lastMessageAt: '2026-10-20T10:00:00Z' })], { openRecent: true }, 'old');
    await named.dock.start();
    expect(window.location.pathname).toBe('/chat/old');
    window.history.replaceState(null, '', '/chat/');
    const off = mountPage([summary('new')]);
    await off.dock.start();
    expect(window.location.pathname).toBe('/chat/');
    vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener: () => undefined }));
    const phone = mountPage([summary('new')], { openRecent: true });
    await phone.dock.start();
    expect(window.location.pathname).toBe('/chat/');
  });

  it('does not count programmatic focus as interaction, but a Tab into the view does (audit KJ-I11)', async () => {
    api.messages.mockResolvedValue({ messages: [message({ id: 7 })], hasMore: false, readUpTo: null, readers: [] });
    const { dock, page } = mountPage([summary('new', { lastMessageAt: '2026-10-20T10:00:00Z' })], { openRecent: true });
    await dock.start();
    await flush();
    const view = page.querySelector('.nowo-chat-pagemode__thread .nowo-chat-window') as HTMLElement;
    const input = view.querySelector('textarea') as HTMLElement;
    // A host script focusing the thread on load: still held.
    input.focus();
    view.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    expect(api.markRead).not.toHaveBeenCalled();
    // A Tab that lands elsewhere does not arm the next focus.
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }));
    document.body.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    view.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    expect(api.markRead).not.toHaveBeenCalled();
    // Another key, then focus: still held.
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
    view.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    expect(api.markRead).not.toHaveBeenCalled();
    // The viewer tabs into the view.
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }));
    view.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
    expect(api.markRead).toHaveBeenCalledWith('new', 7);
  });

  it('releases a held read when the viewer picks the conversation, and drops it when the window goes', async () => {
    api.messages.mockResolvedValue({ messages: [message({ id: 9 })], hasMore: false, readUpTo: null, readers: [] });
    const recent = { lastMessageAt: '2026-10-20T10:00:00Z' };
    const { dock } = mountPage([summary('a', { lastMessageAt: '2026-10-01T10:00:00Z' }), summary('b', recent)], { openRecent: true });
    await dock.start();
    await flush();
    expect(window.location.pathname).toBe('/chat/b');
    expect(api.markRead).not.toHaveBeenCalled();
    // The same row in the list: the viewer chose it.
    await dock.open(summary('b', recent));
    expect(api.markRead).toHaveBeenCalledWith('b', 9);

    // Held, then the page moves to another conversation: the old view's listeners are gone.
    api.markRead.mockClear();
    window.history.replaceState(null, '', '/chat/');
    const again = mountPage([summary('a', { lastMessageAt: '2026-10-01T10:00:00Z' }), summary('b', recent)], { openRecent: true });
    await again.dock.start();
    await flush();
    const held = again.page.querySelector('.nowo-chat-pagemode__thread .nowo-chat-window') as HTMLElement;
    await again.dock.open(summary('a'), false);
    await flush();
    held.dispatchEvent(new Event('pointerdown'));
    expect(api.markRead).not.toHaveBeenCalledWith('b', 9);
  });

  it('shows the empty state only when there is no conversation', async () => {
    api.sidebar.mockResolvedValue([]);
    const { dock, page, list } = mountPage([], { openRecent: true });
    await dock.start();
    expect(page.querySelector('.nowo-chat-pagemode__empty')?.textContent).toBe('Nothing yet');
    expect(list.classList.contains('is-sidebar')).toBe(false);
    expect(list.querySelector('.nowo-chat-list__empty-title')?.textContent).toBe('Nothing yet');

    // Host rows but no conversation: the sections, with the bundle's own empty line.
    api.sidebar.mockResolvedValue([section()]);
    const hosted = mountPage([]);
    await hosted.dock.start();
    expect(hosted.list.classList.contains('is-sidebar')).toBe(true);
    expect(hosted.page.querySelector('.nowo-chat-pagemode__empty')?.textContent).toBe('Pick a conversation.');
  });

  it('groups the floating dock only with sidebar.dock, and never without the templates', async () => {
    const off = mountDock([summary('a')]);
    await off.dock.start();
    expect(off.list.classList.contains('is-sidebar')).toBe(false);
    expect(api.sidebar).not.toHaveBeenCalled();

    const on = mountDock([summary('a')], { sidebar: sidebarSettings({ dock: true, endpoint: null }) });
    await on.dock.start();
    expect(on.list.classList.contains('is-sidebar')).toBe(true);
    expect(on.root.querySelector('.nowo-chat-panel')?.classList.contains('has-sidebar')).toBe(true);
    expect(api.sidebar).not.toHaveBeenCalled();

    const legacy = templatesRoot();
    legacy.querySelector('template[data-nowo-chat-template="sidebar-section"]')?.remove();
    const copied = mountDock([summary('a')], { sidebar: sidebarSettings({ dock: true }) }, legacy);
    await copied.dock.start();
    expect(copied.list.classList.contains('is-sidebar')).toBe(false);

    const none = mountDock([summary('a')], { sidebar: undefined });
    await none.dock.start();
    expect(none.list.classList.contains('is-sidebar')).toBe(false);
  });

  it('never hides the floating dock under its own dialogs (audit KJ-M1)', async () => {
    const { dock, root } = mountDock([summary('a')], { sidebar: sidebarSettings({ dock: true }) });
    await dock.start();
    const own = document.createElement('dialog');
    root.append(own);
    const outside = document.createElement('dialog');
    document.body.append(outside);
    const fire = (type: string, detail: Record<string, unknown> | null) => document.dispatchEvent(new CustomEvent(type, { detail }));
    fire('nowo:overlay-open', { depth: 1, owner: own });
    expect(root.hasAttribute('data-overlay')).toBe(false);
    fire('nowo:overlay-open', { depth: 2, owner: outside });
    expect(root.getAttribute('data-overlay')).toBe('hidden');
    fire('nowo:overlay-close', { depth: 1, owner: outside });
    expect(root.hasAttribute('data-overlay')).toBe(false);
    fire('nowo:overlay-close', { depth: 0, owner: own });
    expect(root.hasAttribute('data-overlay')).toBe(false);
    // Without a depth: its own dialog is ignored, another one hides the dock.
    fire('nowo:overlay-open', { owner: own });
    expect(root.hasAttribute('data-overlay')).toBe(false);
    fire('nowo:overlay-open', { owner: outside });
    expect(root.getAttribute('data-overlay')).toBe('hidden');
    fire('nowo:overlay-close', null);
    expect(root.hasAttribute('data-overlay')).toBe(false);
  });

  it('gives the pointer back to dialogs inside the dock root (audit KJ-M1)', () => {
    // jsdom cannot cascade this stylesheet: read its two rules (the real click runs in the demo e2e, sidebar-dialog.spec.ts).
    const CSS = readFileSync('src/Resources/public/css/nowo-chat.css', 'utf8');
    const rule = (selector: string): string => new RegExp(`(?:^|\\n)${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\{([^}]*)\\}`).exec(CSS)?.[1] ?? '';
    expect(rule('.nowo-chat')).toMatch(/pointer-events: none;/);
    expect(rule('.nowo-chat .nowo-ui-dialog')).toMatch(/pointer-events: auto;/);
    const root = document.createElement('div');
    root.className = 'nowo-chat';
    root.innerHTML = '<div class="nowo-chat-dock"></div><dialog class="nowo-ui-dialog nowo-ui-dialog--remote-form"></dialog>';
    expect(root.querySelector('dialog')?.matches('.nowo-chat .nowo-ui-dialog')).toBe(true);
  });
});
