import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatConfig, ChatMessage, Contact, ConversationSummary, RealtimeEvent } from '../src/types';
import { templatesRoot } from './support';

const api = {
  conversations: vi.fn(),
  conversation: vi.fn(),
  contacts: vi.fn(),
  openDirect: vi.fn(),
  openChannel: vi.fn(),
  openSupport: vi.fn(),
  messages: vi.fn(),
  send: vi.fn(),
  markRead: vi.fn(),
  typing: vi.fn(),
  buzz: vi.fn(),
  resolve: vi.fn(),
  realtime: vi.fn(),
  presence: vi.fn(),
};
let realtimeHandlers: { onEvent: (event: RealtimeEvent) => void; onState: (connected: boolean) => void } | null = null;
const playOnce = vi.fn();

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
    constructor(_api: unknown, onEvent: (event: RealtimeEvent) => void, onState: (connected: boolean) => void) {
      realtimeHandlers = { onEvent, onState };
    }
    connect = vi.fn().mockResolvedValue(undefined);
    stop = vi.fn();
  },
}));
vi.mock('../src/sounds', () => ({ playOnce: (...args: unknown[]) => playOnce(...args) }));

const { ChatDock } = await import('../src/dock');

const i18n: Record<string, string> = {
  launcher: 'Messaging', loading: 'Loading', empty_title: 'Nothing yet', empty_body: 'Start one', no_results: 'No matches', start_chat: 'Start',
  you: 'You', buzz_sent: '%name% buzzed', deleted_message: 'Deleted', typing_many: 'Several typing', typing_one: '%name% typing',
  support_title: 'Administrators', support_hint: 'Help', channel: 'Channel', people_group: 'Groups', people_project: 'Projects',
  people_search: 'People', via_group: 'Group · %name%', via_project: 'Project · %name%', no_people: 'Nobody', more_tabs: '+%count%',
  filter_support: 'Support', unread: '%count% unread',
};

const config = (over: Partial<ChatConfig> = {}): ChatConfig => ({
  userUuid: 'me', csrf: 't', locale: 'en', contextChannel: null, pollMs: 4000, maxLength: 4000, editWindowSeconds: 900, reactions: ['👍'],
  endpoints: {} as ChatConfig['endpoints'], i18n, prefs: { sound: 'pop', buzz: true, presence: true }, isAgent: false, ...over,
});

const summary = (uuid: string, over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid, kind: 'direct', title: `T ${uuid}`, subtitle: `${uuid}@x`, initials: 'TT', peerUuid: `peer-${uuid}`, channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: '2026-10-15T10:00:00Z',
  lastMessage: { preview: 'hola', deleted: false, mine: false, authorName: 'Peer' }, ...over,
});

let ids = 100;
const message = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: ids++, uuid: 'm', authorUuid: 'peer', authorName: 'Peer', authorInitials: 'PE', mine: false, kind: 'text', html: '<p>nuevo</p>',
  createdAt: '2026-10-15T11:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});

/** Every dock a test mounted: destroyed after the test so no document listener outlives it. */
const mounted: { destroy(): void }[] = [];

function mount(conversations: ConversationSummary[], cfg: Partial<ChatConfig> = {}) {
  api.conversations.mockResolvedValue({ conversations, unreadTotal: 0 });
  const root = templatesRoot();
  document.body.append(root);
  const dock = new ChatDock(root, config(cfg));
  mounted.push(dock);
  const q = <T extends Element = HTMLElement>(selector: string) => root.querySelector<T>(selector) as T;
  const all = (selector: string) => [...root.querySelectorAll<HTMLElement>(selector)];
  return { dock, root, q, all };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    await Promise.resolve();
  }
}

describe('ChatDock', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    window.localStorage.clear();
    window.sessionStorage.clear();
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
    api.presence.mockResolvedValue([]);
    api.contacts.mockResolvedValue([]);
    api.conversation.mockRejectedValue(new Error('unknown'));
    api.openDirect.mockResolvedValue(summary('d'));
    api.openChannel.mockResolvedValue(summary('p', { kind: 'channel' }));
    api.openSupport.mockResolvedValue(summary('s', { kind: 'support' }));
    playOnce.mockReset();
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1440 });
  });
  afterEach(() => {
    mounted.splice(0).forEach((dock) => dock.destroy());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
    window.history.replaceState(null, '', '/');
  });

  it('renders the launcher, list, filters, search and unread badge', async () => {
    const { dock, q, all } = mount([
      summary('a', { unread: 120 }),
      summary('b', { kind: 'channel', title: '#Acme', lastMessage: { preview: 'x', deleted: false, mine: false, authorName: 'Ana' } }),
      summary('c', { kind: 'support', title: 'Administrators', lastMessage: { preview: '', deleted: false, buzz: true, mine: true, authorName: null } }),
      summary('d', { lastMessage: { preview: '', deleted: true, mine: false, authorName: null } }),
      summary('e', { lastMessage: { preview: 'mio', deleted: false, mine: true, authorName: 'Me' } }),
      summary('f', { kind: 'support', lastMessage: { preview: '', deleted: false, buzz: true, mine: false, authorName: null } }),
      summary('empty', { lastMessage: null }),
      summary('ch', { kind: 'channel', lastMessage: null, lastMessageAt: null }),
    ]);
    expect(q('.nowo-chat-list').textContent).toBe('');
    (dock as unknown as { renderList: () => void }).renderList();
    expect(q('.nowo-chat-list .nowo-chat-list__empty-title').textContent).toBe('Loading');
    await dock.start();
    expect(q('.nowo-chat-launcher__label').textContent).toBe('Messaging');
    expect(q('.nowo-chat-launcher .nowo-chat-badge [aria-hidden]').textContent).toBe('99+');
    expect(q('.nowo-chat-launcher .nowo-chat-badge .nowo-chat-sr-only').textContent).toBe('120 unread');
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(7);
    expect(q('.nowo-chat-list').textContent).toContain('Ana: x');
    expect(q('.nowo-chat-list').textContent).toContain('You buzzed');
    expect(q('.nowo-chat-list').textContent).toContain('T f buzzed');
    expect(q('.nowo-chat-list').textContent).toContain('Deleted');
    expect(q('.nowo-chat-list').textContent).toContain('You: mio');
    expect(q('.nowo-chat-row__tag').textContent).toBe('Support');

    q('.nowo-chat-launcher').click();
    expect(q('.nowo-chat-panel').classList.contains('is-open')).toBe(true);
    const chip = (filter: string) => q<HTMLButtonElement>(`.nowo-chat-chip[data-filter="${filter}"]`);
    chip('unread').click();
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(1);
    chip('channel').click();
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(2);
    chip('support').click();
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(2);
    chip('all').click();
    const search = q<HTMLInputElement>('.nowo-chat-panel__list .nowo-chat-search');
    search.value = 'zzz';
    search.dispatchEvent(new Event('input'));
    expect(q('.nowo-chat-list__empty-title').textContent).toBe('No matches');
    search.value = 'a@x';
    search.dispatchEvent(new Event('input'));
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(1);

    // Escape closes the list and returns focus to the launcher.
    q('.nowo-chat-panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }));
    q('.nowo-chat-panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(q('.nowo-chat-panel').classList.contains('is-open')).toBe(false);
    vi.advanceTimersByTime(300);
  });

  it('shows the empty state with a start button, and the offline state', async () => {
    const { dock, q, root } = mount([]);
    await dock.start();
    expect(q('.nowo-chat-list__empty-title').textContent).toBe('Nothing yet');
    api.contacts.mockResolvedValue([]);
    q<HTMLButtonElement>('.nowo-chat-list__start').click();
    await flush();
    expect(q<HTMLElement>('.nowo-chat-picker').hidden).toBe(false);
    expect(q('.nowo-chat-picker__results').textContent).toContain('Nobody');

    api.conversations.mockRejectedValue(new Error('offline'));
    document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    expect(root.classList.contains('is-offline')).toBe(true);
    api.conversations.mockReset();
  });

  it('opens windows from the list, minimises, closes and keeps them across reloads', async () => {
    const { dock, q, all, root } = mount([summary('a'), summary('b')]);
    await dock.start();
    q('.nowo-chat-launcher').click();
    all('.nowo-chat-list .nowo-chat-row')[0]?.click();
    await flush();
    expect(all('.nowo-chat-window')).toHaveLength(1);
    expect(q('.nowo-chat-window').classList.contains('is-entering')).toBe(true);
    vi.advanceTimersByTime(300);

    dock.minimise('a', true);
    expect(q('.nowo-chat-window').classList.contains('is-minimised')).toBe(true);
    dock.minimise('zzz', true);
    dock.saveDraft('a', 'hola');
    expect(dock.draft('a')).toBe('hola');
    dock.saveDraft('a', '  ');
    expect(dock.draft('a')).toBe('');

    // A new dock (next page) restores the window without entry motion.
    const again = new ChatDock(templatesRoot(), config());
    mounted.push(again);
    document.body.append((again as unknown as { root: HTMLElement }).root);
    await again.start();
    expect((again as unknown as { root: HTMLElement }).root.querySelector('.nowo-chat-window.is-entering')).toBeNull();
    expect((again as unknown as { root: HTMLElement }).root.querySelectorAll('.nowo-chat-window')).toHaveLength(1);

    dock.close('a');
    expect(root.querySelectorAll('.nowo-chat-window')).toHaveLength(0);
  });

  it('puts windows that do not fit into the "+N" menu', async () => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 800 });
    const { dock, q, all } = mount([summary('a'), summary('b'), summary('c')]);
    await dock.start();
    for (const uuid of ['a', 'b', 'c']) {
      await dock.open(summary(uuid));
    }
    expect(all('.nowo-chat-window:not([hidden])')).toHaveLength(1);
    expect(q<HTMLElement>('.nowo-chat-overflow').hidden).toBe(false);
    expect(q('.nowo-chat-overflow__btn').textContent).toBe('+2');
    q('.nowo-chat-overflow__btn').click();
    expect(q<HTMLElement>('.nowo-chat-overflow__menu').hidden).toBe(false);
    q('.nowo-chat-overflow__btn').click();
    q('.nowo-chat-overflow__btn').click();
    document.body.click();
    expect(q<HTMLElement>('.nowo-chat-overflow__menu').hidden).toBe(true);
    q('.nowo-chat-overflow__btn').click();
    q('.nowo-chat-overflow__item').click();
    await flush();
    expect(q<HTMLElement>('.nowo-chat-overflow__menu').hidden).toBe(true);
    window.dispatchEvent(new Event('resize'));

    // A stored tab whose summary is unknown (not in the list).
    const store = JSON.parse(window.localStorage.getItem('nowo-chat:me') ?? '{}');
    store.tabs.push({ uuid: 'ghost', minimised: false });
    window.localStorage.setItem('nowo-chat:me', JSON.stringify(store));
    api.conversation.mockRejectedValueOnce(new Error('gone'));
    const other = mount([summary('a')]);
    await other.dock.start();
    await flush();
    const after = JSON.parse(window.localStorage.getItem('nowo-chat:me') ?? '{}');
    expect(after.tabs.some((tab: { uuid: string }) => tab.uuid === 'ghost')).toBe(false);
  });

  it('loads a stored tab that is not in the list', async () => {
    window.localStorage.setItem('nowo-chat:me', JSON.stringify({ listOpen: false, tabs: [{ uuid: 'late', minimised: false }], drafts: {} }));
    api.conversation.mockResolvedValueOnce(summary('late'));
    const { dock, all } = mount([]);
    await dock.start();
    await flush();
    expect(all('.nowo-chat-window')).toHaveLength(1);
  });

  it('uses the people picker: pinned rows, sections, search debounce and errors', async () => {
    const contacts: Contact[] = [
      { uuid: 'u1', name: 'Lucía', email: 'l@x', initials: 'LU', source: 'group', via: 'Backend' },
      { uuid: 'u2', name: 'Carlos', email: 'c@x', initials: 'CA', source: 'project', via: 'Acme' },
      { uuid: 'u3', name: 'Eve', email: 'e@x', initials: 'EV', source: 'admin', via: '' },
    ];
    api.contacts.mockResolvedValue(contacts);
    const { dock, q, all } = mount([], { contextChannel: { key: 'project:p1', label: 'Acme' } });
    await dock.start();
    q('.nowo-chat-panel__compose').click();
    await flush();
    expect(q('.nowo-chat-picker__results').textContent).toContain('Administrators');
    expect(q('.nowo-chat-picker__results').textContent).toContain('#Acme');
    expect(q('.nowo-chat-picker__results').textContent).toContain('Group · Backend');
    expect(q('.nowo-chat-picker__results').textContent).toContain('Project · Acme');
    expect(q('.nowo-chat-picker__results').textContent).toContain('e@x');

    const input = q<HTMLInputElement>('.nowo-chat-picker .nowo-chat-search');
    input.value = 'zz';
    input.dispatchEvent(new Event('input'));
    input.dispatchEvent(new Event('input'));
    api.contacts.mockResolvedValueOnce([]);
    vi.advanceTimersByTime(250);
    await flush();
    expect(q('.nowo-chat-picker__results').textContent?.trim()).toBe('Nobody');

    // A slow answer for an old query is ignored.
    let resolveOld: (value: Contact[]) => void = () => undefined;
    api.contacts.mockImplementationOnce(() => new Promise<Contact[]>((r) => (resolveOld = r)));
    input.value = 'old';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(250);
    api.contacts.mockResolvedValueOnce([contacts[0] as Contact]);
    input.value = 'luc';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(250);
    await flush();
    resolveOld([]);
    await flush();
    expect(q('.nowo-chat-picker__results').textContent).toContain('Lucía');

    api.contacts.mockRejectedValueOnce(new Error('x'));
    input.value = 'adm';
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(250);
    await flush();
    expect(q('.nowo-chat-picker__results [role="alert"]').textContent).toBe('error');

    // Pick: direct, project, support, and a failing pick.
    api.contacts.mockResolvedValue(contacts);
    q('.nowo-chat-picker__back').click();
    q('.nowo-chat-panel__compose').click();
    await flush();
    api.openDirect.mockResolvedValueOnce(summary('d1'));
    all('.nowo-chat-picker .nowo-chat-row').find((row) => row.textContent?.includes('Lucía'))?.click();
    await flush();
    expect(q<HTMLElement>('.nowo-chat-picker').hidden).toBe(true);
    q('.nowo-chat-panel__compose').click();
    await flush();
    api.openChannel.mockResolvedValueOnce(summary('p', { kind: 'channel' }));
    all('.nowo-chat-picker .nowo-chat-row').find((row) => row.textContent?.includes('#Acme'))?.click();
    await flush();
    q('.nowo-chat-panel__compose').click();
    await flush();
    api.openSupport.mockResolvedValueOnce(summary('s', { kind: 'support' }));
    all('.nowo-chat-picker .nowo-chat-row').find((row) => row.textContent?.includes('Administrators'))?.click();
    await flush();
    q('.nowo-chat-panel__compose').click();
    await flush();
    api.openDirect.mockRejectedValueOnce(new Error('denied'));
    const carlos = all('.nowo-chat-picker .nowo-chat-row').find((row) => row.textContent?.includes('Carlos')) as HTMLButtonElement;
    carlos.click();
    await flush();
    expect(carlos.classList.contains('is-error')).toBe(true);
    expect(carlos.disabled).toBe(false);

    q('.nowo-chat-panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(q<HTMLElement>('.nowo-chat-picker').hidden).toBe(true);
  });

  it('reacts to realtime events: messages, reads, typing, conversation changes, buzz and sounds', async () => {
    const { dock, q, root } = mount([summary('a'), summary('b'), summary('sup', { kind: 'support' })]);
    await dock.start();
    const rt = realtimeHandlers as NonNullable<typeof realtimeHandlers>;
    rt.onState(true);
    expect(root.classList.contains('is-live')).toBe(true);
    rt.onState(true);
    rt.onState(false);

    // List-only conversation: preview, unread and a sound.
    vi.spyOn(document, 'hasFocus').mockReturnValue(true);
    rt.onEvent({ type: 'message', conversationUuid: 'b', message: message() });
    expect(q('.nowo-chat-list').textContent).toContain('nuevo');
    expect(playOnce).toHaveBeenCalledWith(expect.stringMatching(/^msg-/), 'pop');
    rt.onEvent({ type: 'message', conversationUuid: 'b', message: message({ authorUuid: 'me' }) });
    api.conversations.mockResolvedValueOnce({ conversations: [summary('a'), summary('b'), summary('new')], unreadTotal: 0 });
    rt.onEvent({ type: 'message', conversationUuid: 'new', message: message() });
    await flush();

    // Open window in front: no sound.
    await dock.open(summary('a'));
    playOnce.mockClear();
    rt.onEvent({ type: 'message', conversationUuid: 'a', message: message() });
    expect(playOnce).not.toHaveBeenCalled();

    rt.onEvent({ type: 'typing', conversationUuid: 'a', userUuid: 'peer', userName: 'Peer' });
    rt.onEvent({ type: 'typing', conversationUuid: 'a', userUuid: 'peer', userName: 'Peer' });
    rt.onEvent({ type: 'typing', conversationUuid: 'b', userUuid: 'peer', userName: 'Peer' });
    expect(q('.nowo-chat-row.is-typing')).not.toBeNull();
    vi.advanceTimersByTime(5_100);
    expect(q('.nowo-chat-row.is-typing')).toBeNull();

    rt.onEvent({ type: 'read', conversationUuid: 'a', userUuid: 'peer', userName: 'Peer', messageId: 999 });
    rt.onEvent({ type: 'read', conversationUuid: 'b', userUuid: 'me', userName: 'Me', messageId: 999 });
    rt.onEvent({ type: 'read', conversationUuid: 'zz', userUuid: 'me', userName: 'Me', messageId: 1 });

    api.conversation.mockResolvedValueOnce(summary('sup', { kind: 'support', supportStatus: 'resolved' }));
    rt.onEvent({ type: 'conversation', conversationUuid: 'sup' });
    api.conversation.mockRejectedValueOnce(new Error('x'));
    rt.onEvent({ type: 'conversation', conversationUuid: 'sup' });
    await flush();

    // Buzz: opens a minimised / closed window, shakes once and plays the buzz sound.
    dock.minimise('a', true);
    rt.onEvent({ type: 'message', conversationUuid: 'a', message: message({ kind: 'buzz', html: '' }) });
    vi.advanceTimersByTime(200);
    expect(q('.nowo-chat-window[data-buzzed-at]')).not.toBeNull();
    expect(playOnce).toHaveBeenCalledWith(expect.stringMatching(/^buzz-/), 'buzz');
    rt.onEvent({ type: 'message', conversationUuid: 'unknown-buzz', message: message({ kind: 'buzz', html: '' }) });
    await flush();

    // Hidden tab: sound plays even with the window open.
    playOnce.mockClear();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    rt.onEvent({ type: 'message', conversationUuid: 'a', message: message() });
    expect(playOnce).toHaveBeenCalled();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
  });

  it('ignores buzzes when the user turned them off', async () => {
    const { dock } = mount([summary('a')], { prefs: { sound: 'none', buzz: false, presence: true } });
    await dock.start();
    realtimeHandlers?.onEvent({ type: 'message', conversationUuid: 'a', message: message({ kind: 'buzz', html: '' }) });
    expect(playOnce).not.toHaveBeenCalled();
  });

  it('applies message updates (edit, delete, reactions) to an open window only', async () => {
    const original = message({ html: '<p>before</p>' });
    api.messages.mockResolvedValue({ messages: [original], hasMore: false, readUpTo: null, readers: [] });
    const { dock, q } = mount([summary('a')]);
    await dock.start();
    await dock.open(summary('a'));
    await flush();
    realtimeHandlers?.onEvent({ type: 'message_updated', conversationUuid: 'a', message: { ...original, html: '<p>after</p>', edited: true } });
    expect(q(`[data-id="${original.id}"] .nowo-chat-bubble`).textContent).toBe('after');
    realtimeHandlers?.onEvent({ type: 'message_updated', conversationUuid: 'closed', message: original });
    expect(playOnce).not.toHaveBeenCalled();
  });

  it('opens conversations from data-nowo-chat-open links (support with context, people, channels)', async () => {
    const { dock, all, q } = mount([]);
    await dock.start();
    const link = (attrs: Record<string, string>): HTMLAnchorElement => {
      const element = document.createElement('a');
      element.href = '#report';
      for (const [name, value] of Object.entries(attrs)) {
        element.setAttribute(name, value);
      }
      element.append(document.createElement('span'));
      document.body.append(element);
      return element;
    };
    const report = link({ 'data-nowo-chat-open': 'support', 'data-nowo-chat-context-url': '/projects/x/issues/9', 'data-nowo-chat-context-label': 'Issue #9' });
    const click = new MouseEvent('click', { bubbles: true, cancelable: true });
    (report.firstElementChild as HTMLElement).dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    await flush();
    expect(api.openSupport).toHaveBeenCalledWith({ url: '/projects/x/issues/9', label: 'Issue #9' });
    expect(all('.nowo-chat-window')).toHaveLength(1);

    link({ 'data-nowo-chat-open': 'support' }).click();
    await flush();
    expect(api.openSupport).toHaveBeenLastCalledWith(undefined);
    link({ 'data-nowo-chat-open': 'user:peer-1' }).click();
    await flush();
    expect(api.openDirect).toHaveBeenCalledWith('peer-1');
    link({ 'data-nowo-chat-open': 'channel:project:1' }).click();
    await flush();
    expect(api.openChannel).toHaveBeenCalledWith('project:1');
    link({ 'data-nowo-chat-open': 'nothing' }).click();
    link({ 'data-nowo-chat-open': 'support', 'data-nowo-chat-context-label': 'Only a label' }).click();
    await flush();
    expect(api.openSupport).toHaveBeenLastCalledWith({ label: 'Only a label' });

    // A failure shows the status line for a few seconds; ordinary clicks are ignored.
    api.openDirect.mockRejectedValueOnce(new Error('denied'));
    link({ 'data-nowo-chat-open': 'user:x' }).click();
    await flush();
    await flush();
    expect(q('.nowo-chat-status').hidden).toBe(false);
    vi.advanceTimersByTime(4_000);
    expect(q('.nowo-chat-status').hidden).toBe(true);
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    dock.destroy();
  });

  it('receives the other tabs over BroadcastChannel until destroyed', async () => {
    vi.useRealTimers();
    const { dock, q } = mount([summary('a', { unread: 3 })]);
    await dock.start();
    expect(q('.nowo-chat-launcher .nowo-chat-badge [aria-hidden]').textContent).toBe('3');
    const otherTab = new BroadcastChannel('nowo-chat:me');
    otherTab.postMessage({ type: 'read', uuid: 'a', messageId: 1 });
    await vi.waitFor(() => expect(q('.nowo-chat-launcher .nowo-chat-badge').hidden).toBe(true));
    dock.destroy();
    otherTab.close();
  });

  it('marks online peers from the presence heartbeat', async () => {
    api.presence.mockResolvedValueOnce(['peer-a']).mockResolvedValueOnce(['peer-a']).mockRejectedValueOnce(new Error('x'));
    const { dock, q } = mount([summary('a'), summary('p', { kind: 'channel', peerUuid: null })]);
    await dock.start();
    await dock.open(summary('a'));
    await flush();
    expect(q('.nowo-chat-list .nowo-chat-avatar.is-online')).not.toBeNull();
    vi.advanceTimersByTime(30_000);
    await flush();
    document.dispatchEvent(new Event('visibilitychange'));
    await flush();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
  });

  // Many fake-timer ticks: allow for a loaded CI / coverage run.
  it('mirrors other browser tabs and polls when realtime is down', { timeout: 20_000 }, async () => {
    const { dock, q } = mount([summary('a'), summary('b')]);
    await dock.start();
    await dock.open(summary('a'));
    dock.minimise('a', true);
    const sync = (event: unknown) => (dock as unknown as { onSync: (e: unknown) => void }).onSync(event);
    sync({ type: 'messages', uuid: 'a', messages: [message()] });
    sync({ type: 'messages', uuid: 'b', messages: [message({ mine: true })] });
    sync({ type: 'messages', uuid: 'b', messages: [] });
    api.conversations.mockResolvedValueOnce({ conversations: [summary('a'), summary('b'), summary('zz')], unreadTotal: 0 });
    sync({ type: 'messages', uuid: 'zz', messages: [message()] });
    sync({ type: 'read', uuid: 'a', messageId: 5 });
    sync({ type: 'read', uuid: 'nope', messageId: 5 });
    sync({ type: 'state', state: { listOpen: true, tabs: [], drafts: {} } });
    await flush();

    // Window catch-up on poll ticks, list refresh every 4th tick; a changed list entry triggers catch-up.
    await dock.open(summary('a'));
    api.messages.mockResolvedValue({ messages: [], hasMore: false, readUpTo: null, readers: [] });
    for (let i = 0; i < 4; i += 1) {
      await vi.advanceTimersByTimeAsync(4_000);
    }
    api.messages.mockRejectedValueOnce(new Error('x'));
    await vi.advanceTimersByTimeAsync(4_000);
    api.conversations.mockResolvedValueOnce({ conversations: [summary('a', { lastMessageAt: '2026-10-16T00:00:00Z' })], unreadTotal: 0 });
    await vi.advanceTimersByTimeAsync(12_000);
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    await vi.advanceTimersByTimeAsync(30_000);
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    realtimeHandlers?.onState(true);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(q('.nowo-chat-dock')).not.toBeNull();

    // Direct: a failing catch-up marks the dock offline; a changed list entry whose catch-up fails is ignored.
    const internals = dock as unknown as { live: boolean; tick: number; poll: (force: boolean) => Promise<void>; refreshList: () => Promise<void>; root: HTMLElement };
    internals.live = false;
    internals.tick = 0;
    api.messages.mockReset();
    api.messages.mockResolvedValue({ messages: [], hasMore: false, readUpTo: null, readers: [] });
    await dock.open(summary('a'));
    await flush();
    api.messages.mockRejectedValue(new Error('x'));
    await internals.poll(false);
    expect(internals.root.classList.contains('is-offline')).toBe(true);
    api.conversations.mockResolvedValueOnce({ conversations: [summary('a', { lastMessageAt: '2026-10-17T00:00:00Z' })], unreadTotal: 0 });
    await internals.refreshList();
    await flush();
    api.messages.mockResolvedValue({ messages: [], hasMore: false, readUpTo: null, readers: [] });
  });

  it('applies messages from the thread to the list, cache and other tabs', async () => {
    const { dock, q } = mount([summary('a')]);
    await dock.start();
    dock.messagesChanged('zz', [], false, []);
    dock.messagesChanged('a', [message({ html: '<p>cache</p>' })], false, []);
    expect(q('.nowo-chat-list').textContent).toContain('cache');
    await dock.open(summary('a'));
    dock.minimise('a', true);
    dock.messagesChanged('a', [message()], false, [message(), message({ mine: true })]);
    expect(q('.nowo-chat-launcher .nowo-chat-badge [aria-hidden]').textContent).toBe('1');
    dock.read('a', 10);
    dock.read('nope', 10);
    api.markRead.mockRejectedValueOnce(new Error('x'));
    dock.read('a', 11);
    await flush();
    dock.conversationChanged(summary('a', { title: 'Renamed' }));
    expect(q('.nowo-chat-list').textContent).toContain('Renamed');
  });

  it('opens the conversation of a push notification (?chat=)', async () => {
    window.history.replaceState(null, '', '/dashboard?chat=11111111-1111-1111-1111-111111111111&x=1');
    const { dock, all } = mount([summary('11111111-1111-1111-1111-111111111111')]);
    await dock.start();
    await flush();
    expect(window.location.search).toBe('?x=1');
    expect(all('.nowo-chat-window')).toHaveLength(1);

    window.history.replaceState(null, '', '/dashboard?chat=22222222-2222-2222-2222-222222222222');
    window.localStorage.clear();
    api.conversation.mockResolvedValueOnce(summary('22222222-2222-2222-2222-222222222222'));
    const second = mount([]);
    await second.dock.start();
    await flush();
    expect(second.all('.nowo-chat-window').length).toBeGreaterThan(0);

    window.history.replaceState(null, '', '/dashboard?chat=33333333-3333-3333-3333-333333333333');
    api.conversation.mockRejectedValueOnce(new Error('denied'));
    await mount([]).dock.start();
    await flush();
    window.history.replaceState(null, '', '/dashboard?chat=not-a-uuid');
    await mount([]).dock.start();
    expect(window.location.search).toBe('?chat=not-a-uuid');
  });

  it('switches to the full-screen sheet on phones', async () => {
    let listener: (() => void) | null = null;
    let matches = true;
    vi.stubGlobal('matchMedia', () => ({ get matches() { return matches; }, addEventListener: (_: string, cb: () => void) => (listener = cb) }));
    const { dock, root, q, all } = mount([summary('a')]);
    await dock.start();
    expect(dock.isMobile()).toBe(true);
    expect(root.classList.contains('is-mobile')).toBe(true);
    q('.nowo-chat-launcher').click();
    all('.nowo-chat-list .nowo-chat-row')[0]?.click();
    await flush();
    expect(root.classList.contains('has-mobile-thread')).toBe(true);
    dock.minimise('a', false);
    dock.minimise('a', true);
    expect(root.classList.contains('has-mobile-thread')).toBe(false);
    await dock.open(summary('a'), false);
    dock.close('a');
    expect(all('.nowo-chat-window')).toHaveLength(0);
    q('.nowo-chat-panel__close').click();
    matches = false;
    (listener as unknown as () => void)();
    expect(root.classList.contains('is-mobile')).toBe(false);
  });
  it('keyboard: Alt+arrows between windows, "/" to search, Esc closes the +N list; focus after closing', async () => {
    const { dock, q, all, root } = mount([summary('a'), summary('b')]);
    await dock.start();
    await dock.open(summary('a'));
    await dock.open(summary('b'));
    const windows = all('.nowo-chat-window');
    const focusedUuid = (): string | null | undefined => document.activeElement?.parentElement?.parentElement?.getAttribute('data-uuid');
    windows[0]?.querySelector<HTMLButtonElement>('.nowo-chat-window__identity')?.focus();
    const first = focusedUuid();
    root.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', altKey: true, bubbles: true }));
    expect(focusedUuid()).not.toBe(first);
    root.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', altKey: true, bubbles: true }));
    root.dispatchEvent(new KeyboardEvent('keydown', { key: 'x', bubbles: true }));

    // "/" from the panel (not a text field) opens the list and focuses search.
    q('.nowo-chat-launcher').focus();
    q('.nowo-chat-launcher').dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
    expect(document.activeElement).toBe(q('.nowo-chat-panel__list .nowo-chat-search'));
    q('.nowo-chat-panel__list .nowo-chat-search').dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));

    // Closing the focused window moves focus to the next one, then to the launcher.
    q<HTMLButtonElement>('[data-uuid="b"] .nowo-chat-window__identity').focus();
    dock.close('b');
    expect(focusedUuid()).toBe('a');
    dock.close('a');
    expect(document.activeElement).toBe(q('.nowo-chat-launcher'));
    const empty = mount([]);
    await empty.dock.start();
    empty.root.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', altKey: true, bubbles: true }));

    // +N list: Esc closes it and returns focus.
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 700 });
    await dock.open(summary('a'));
    await dock.open(summary('b'));
    q('.nowo-chat-overflow__btn').click();
    q('.nowo-chat-overflow__item').focus();
    q('.nowo-chat-overflow').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(q<HTMLElement>('.nowo-chat-overflow__menu').hidden).toBe(true);
    q('.nowo-chat-overflow').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  });

  it('forgets chat storage on logout, shows connection status, recent people, leader and destroy', async () => {
    window.localStorage.setItem('nowo-chat-drafts:me', '{"a":"x"}');
    vi.stubGlobal('navigator', { ...navigator, locks: { request: (_: string, cb: () => Promise<void>) => cb() } });
    const viewport = new EventTarget() as EventTarget & { height: number };
    viewport.height = 500;
    Object.defineProperty(window, 'visualViewport', { configurable: true, value: viewport });
    const { dock, q, root } = mount([summary('a', { lastMessageAt: '2026-10-15T09:00:00Z' }), summary('b', { lastMessageAt: '2026-10-15T10:00:00Z' })]);
    await dock.start();
    viewport.dispatchEvent(new Event('resize'));
    expect(root.style.getPropertyValue('--nowo-chat-vvh')).toBe('500px');
    const link = document.createElement('a');
    link.href = '/logout';
    document.body.append(link);
    link.click();
    expect(window.localStorage.getItem('nowo-chat-drafts:me')).toBeNull();
    window.localStorage.setItem('nowo-chat-drafts:me', '{}');
    const form = document.createElement('form');
    form.action = '/logout';
    document.body.append(form);
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    expect(window.localStorage.getItem('nowo-chat-drafts:me')).toBeNull();
    const other = document.createElement('form');
    other.action = '/search';
    document.body.append(other);
    other.dispatchEvent(new Event('submit', { bubbles: true }));

    realtimeHandlers?.onState(true);
    realtimeHandlers?.onState(false);
    expect(q('.nowo-chat-status').textContent).toBe('reconnecting');
    realtimeHandlers?.onState(true);
    expect(q('.nowo-chat-status').textContent).toBe('reconnected');
    vi.advanceTimersByTime(2_600);
    expect(q<HTMLElement>('.nowo-chat-status').hidden).toBe(true);

    q('.nowo-chat-panel__compose').click();
    await flush();
    expect(q('.nowo-chat-picker__results').textContent).toContain('T b');
    [...root.querySelectorAll<HTMLElement>('.nowo-chat-picker .nowo-chat-row')].find((row) => row.textContent?.includes('T a'))?.click();
    await flush();

    dock.destroy();
    expect(root.querySelector('.nowo-chat-dock')).toBeNull();
    Object.defineProperty(window, 'visualViewport', { configurable: true, value: undefined });

    // A follower tab (lock held elsewhere) skips the heartbeat while hidden.
    vi.stubGlobal('navigator', { ...navigator, locks: { request: () => new Promise(() => undefined) } });
    api.presence.mockClear();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    const follower = mount([]);
    await follower.dock.start();
    expect(api.presence).not.toHaveBeenCalled();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    follower.dock.destroy();
  });

});
