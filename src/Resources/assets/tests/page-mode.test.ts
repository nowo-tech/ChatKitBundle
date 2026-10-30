import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatConfig, ChatMessage, ConversationSummary, OwnStatus, RealtimeEvent } from '../src/types';
import { templatesRoot } from './support';

/** Page mode (1.13): the dock's list and one conversation inside the full-page chat. */
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
  presence: vi.fn(),
  setStatus: vi.fn(),
  archive: vi.fn(),
  archiveRead: vi.fn(),
  pins: vi.fn(),
  pin: vi.fn(),
  linkPreviews: vi.fn(),
  message: vi.fn(),
};
let realtimeHandlers: { onEvent: (event: RealtimeEvent) => void; onState: (connected: boolean) => void } | null = null;

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
vi.mock('../src/sounds', () => ({ playOnce: vi.fn() }));

const { ChatDock } = await import('../src/dock');

const i18n: Record<string, string> = {
  launcher: 'Messaging', loading: 'Loading', empty_title: 'Nothing yet', empty_body: 'Start one', no_results: 'No matches',
  page_select: 'Pick a conversation.', archived_empty: 'Nothing closed', you: 'You', unread: '%count% unread',
  close_conversation: 'Close %name%', reopen_conversation: 'Reopen %name%', archived_one: 'Closed', unarchived_one: 'Reopened', undo: 'Undo',
  status_yours: 'Your status: %status%', status_available: 'Available', status_busy: 'Busy',
};

const own = (over: Partial<OwnStatus> = {}): OwnStatus => ({ status: 'available', text: null, emoji: null, expiresAt: null, ...over });

const config = (over: Partial<ChatConfig> = {}): ChatConfig => ({
  userUuid: 'me', csrf: 't', locale: 'en', contextChannel: null, pollMs: 4000, maxLength: 4000, editWindowSeconds: 900, reactions: ['👍'], linkPreviews: false,
  endpoints: { page: '/chat/__uuid__', archive: '/archive/__uuid__', status: '/status' } as ChatConfig['endpoints'], i18n,
  prefs: { sound: 'pop', buzz: true, presence: true }, isAgent: false, status: own(), ...over,
});

const summary = (uuid: string, over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid, kind: 'direct', title: `T ${uuid}`, subtitle: `${uuid}@x`, initials: 'TT', peerUuid: `peer-${uuid}`, channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: '2026-10-15T10:00:00Z',
  lastMessage: { preview: 'hola', deleted: false, mine: false, authorName: 'Peer' }, ...over,
});

let ids = 500;
const message = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: ids++, uuid: `m${ids}`, authorUuid: 'peer', authorName: 'Peer', authorInitials: 'PE', mine: false, kind: 'text', html: '<p>nuevo</p>',
  createdAt: '2026-10-15T11:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});

const mounted: { destroy(): void }[] = [];

/**
 * The full-page chat as page.html.twig renders it (server-rendered list + thread) plus the dock root, then the dock in
 * page mode on it.
 */
function mountPage(conversations: ConversationSummary[], attrs: { current?: string; archived?: boolean } = {}, cfg: Partial<ChatConfig> = {}) {
  api.conversations.mockImplementation((archived?: boolean) =>
    Promise.resolve({ conversations: archived === true ? [summary('old', { archived: true })] : conversations, unreadTotal: 0 }),
  );
  const page = document.createElement('div');
  page.className = 'nowo-chat-page';
  page.dataset.nowoChatPageMode = '';
  if (attrs.current !== undefined) {
    page.dataset.nowoChatCurrent = attrs.current;
  }
  if (attrs.archived === true) {
    page.dataset.nowoChatArchived = '1';
  }
  page.innerHTML = '<nav class="nowo-chat-page__list">server list</nav><section class="nowo-chat-page__thread">server thread</section><p hidden>kept hidden</p>';
  const root = templatesRoot();
  document.body.append(page, root);
  const dock = new ChatDock(root, config(cfg), { page });
  mounted.push(dock);
  const q = <T extends Element = HTMLElement>(selector: string) => page.querySelector<T>(selector) as T;
  const all = (selector: string) => [...page.querySelectorAll<HTMLElement>(selector)];
  const row = (uuid: string) => q<HTMLButtonElement>(`.nowo-chat-row[data-uuid="${uuid}"]`);
  return { dock, root, page, q, all, row };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i += 1) {
    await Promise.resolve();
  }
}

describe('ChatDock page mode', () => {
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
    api.contacts.mockResolvedValue([]);
    api.pins.mockResolvedValue({ pins: [], canPin: false });
    api.conversation.mockRejectedValue(new Error('unknown'));
    api.archive.mockResolvedValue(undefined);
  });
  afterEach(() => {
    mounted.splice(0).forEach((dock) => dock.destroy());
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
    window.history.replaceState(null, '', '/');
  });

  it('replaces the server-rendered page with the list panel and a placeholder, and keeps the floating dock away', async () => {
    const { dock, root, page, q } = mountPage([summary('a'), summary('b')]);
    expect(page.classList.contains('is-page-mode')).toBe(true);
    expect(q<HTMLElement>('.nowo-chat-page__list').hidden).toBe(true);
    expect(q<HTMLElement>('.nowo-chat-page__thread').hidden).toBe(true);
    expect(root.classList.contains('has-page-mode')).toBe(true);
    expect(root.querySelector<HTMLElement>('.nowo-chat-dock')?.hidden).toBe(true);
    expect(root.querySelector<HTMLElement>('.nowo-chat-edge')?.hidden).toBe(true);
    // The list panel lives in the page: open, with a heading instead of the launcher.
    const panel = q('.nowo-chat-pagemode__list .nowo-chat-panel');
    expect(panel.classList.contains('is-open')).toBe(true);
    expect(q('.nowo-chat-pagemode__title').textContent).toBe('Messaging');
    expect(q<HTMLElement>('.nowo-chat-launcher').hidden).toBe(true);
    expect(q('.nowo-chat-pagemode__thread').textContent).toBe('Pick a conversation.');
    expect(window.history.state).toEqual({ nowoChatPage: '' });

    await dock.start();
    expect(q('.nowo-chat-list').querySelectorAll('.nowo-chat-row')).toHaveLength(2);
    expect(q('[aria-current]')).toBeNull();
    // Tucking, the list toggle and minimising belong to the floating dock: nothing happens on the page.
    dock.setTucked(true);
    expect(root.classList.contains('is-tucked')).toBe(false);
    q<HTMLButtonElement>('.nowo-chat-launcher').click();
    expect(panel.classList.contains('is-open')).toBe(true);
    window.dispatchEvent(new Event('resize'));
    expect(q('.nowo-chat-pagemode__thread').textContent).toBe('Pick a conversation.');

    // Unmounting brings the server-rendered page back.
    dock.destroy();
    expect(page.classList.contains('is-page-mode')).toBe(false);
    expect(q('.nowo-chat-pagemode')).toBeNull();
    expect(q<HTMLElement>('.nowo-chat-page__list').hidden).toBe(false);
    expect(page.querySelector<HTMLElement>('p')?.hidden).toBe(true);
    expect(root.classList.contains('has-page-mode')).toBe(false);
  });

  it('opens the deep-linked conversation as a region of the page', async () => {
    window.history.replaceState(null, '', '/chat/b');
    const { dock, q, row } = mountPage([summary('a'), summary('b')], { current: 'b' });
    window.dispatchEvent(new Event('resize'));
    expect(q('.nowo-chat-pagemode__thread').textContent).toBe('Loading');
    expect(q('.nowo-chat-pagemode').classList.contains('has-thread')).toBe(false);
    await dock.start();
    await flush();
    const view = q('.nowo-chat-pagemode__thread .nowo-chat-window');
    expect(view.dataset.uuid).toBe('b');
    expect(view.getAttribute('role')).toBe('region');
    expect(view.classList.contains('nowo-chat-window--page')).toBe(true);
    expect(view.querySelector('[data-part="identity"]')?.hasAttribute('aria-expanded')).toBe(false);
    expect(q('.nowo-chat-pagemode').classList.contains('has-thread')).toBe(true);
    expect(row('b').getAttribute('aria-current')).toBe('true');
    expect(row('b').classList.contains('is-current')).toBe(true);
    expect(row('a').hasAttribute('aria-current')).toBe(false);
    expect(window.history.state).toEqual({ nowoChatPage: 'b' });

    // Minimise (header click, Escape) never folds it.
    view.querySelector<HTMLButtonElement>('[data-part="identity"]')?.click();
    view.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(view.classList.contains('is-minimised')).toBe(false);
  });

  it('switches conversations with pushState and follows Back / Forward', async () => {
    const push = vi.spyOn(window.history, 'pushState');
    const { dock, q, row } = mountPage([summary('a'), summary('b')], { current: 'a' });
    await dock.start();
    await flush();
    row('b').click();
    await flush();
    expect(push).toHaveBeenLastCalledWith({ nowoChatPage: 'b' }, '', '/chat/b');
    expect(window.location.pathname).toBe('/chat/b');
    expect(q('.nowo-chat-pagemode__thread .nowo-chat-window').dataset.uuid).toBe('b');
    expect(document.activeElement).toBe(q('.nowo-chat-window textarea'));
    expect(row('b').getAttribute('aria-current')).toBe('true');

    // The same conversation again: no new history entry.
    push.mockClear();
    row('b').click();
    await flush();
    expect(push).not.toHaveBeenCalled();

    window.dispatchEvent(new PopStateEvent('popstate', { state: { nowoChatPage: 'a' } }));
    expect(q('.nowo-chat-pagemode__thread .nowo-chat-window').dataset.uuid).toBe('a');
    expect(row('a').getAttribute('aria-current')).toBe('true');
    window.dispatchEvent(new PopStateEvent('popstate', { state: { nowoChatPage: '' } }));
    expect(q('.nowo-chat-pagemode__thread').textContent).toBe('Pick a conversation.');
    expect(q('.nowo-chat-pagemode').classList.contains('has-thread')).toBe(false);
    // Entries the page did not write are not its business.
    window.dispatchEvent(new PopStateEvent('popstate', { state: null }));
    expect(q('.nowo-chat-pagemode__thread').textContent).toBe('Pick a conversation.');
    expect(push).not.toHaveBeenCalled();
  });

  it('opens the "Closed" filter from ?archived=1 and keeps the address in step with the filter', async () => {
    window.history.replaceState(null, '', '/chat/?archived=1');
    const replace = vi.spyOn(window.history, 'replaceState');
    const { dock, q, row } = mountPage([summary('a')], { archived: true });
    expect(q('.nowo-chat-chip[data-filter="archived"]').getAttribute('aria-pressed')).toBe('true');
    expect(q('.nowo-chat-chip[data-filter="all"]').getAttribute('aria-pressed')).toBe('false');
    await dock.start();
    expect(api.conversations).toHaveBeenCalledWith(true);
    expect(row('old')).not.toBeNull();
    expect(row('a')).toBeNull();

    q<HTMLButtonElement>('.nowo-chat-chip[data-filter="all"]').click();
    expect(replace).toHaveBeenLastCalledWith({ nowoChatPage: '' }, '', '/chat/');
    expect(row('a')).not.toBeNull();
    q<HTMLButtonElement>('.nowo-chat-chip[data-filter="archived"]').click();
    await flush();
    expect(window.location.search).toBe('?archived=1');
    // Reopen from the "Closed" list.
    row('old').closest('li')?.querySelector<HTMLButtonElement>('[data-part="row-archive"]')?.click();
    await flush();
    expect(api.archive).toHaveBeenCalledWith('old', false);
  });

  it('keeps search, filters, the people picker and the status menu reachable', async () => {
    api.setStatus.mockResolvedValue(own({ status: 'busy' }));
    const { dock, q, all, page } = mountPage([summary('a', { unread: 2 }), summary('b', { kind: 'channel', title: '#Acme' })]);
    await dock.start();
    const search = q<HTMLInputElement>('.nowo-chat-panel__list .nowo-chat-search');
    search.value = 'acme';
    search.dispatchEvent(new Event('input'));
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(1);
    search.value = '';
    search.dispatchEvent(new Event('input'));
    q<HTMLButtonElement>('.nowo-chat-chip[data-filter="unread"]').click();
    expect(all('.nowo-chat-list .nowo-chat-row')).toHaveLength(1);
    q<HTMLButtonElement>('.nowo-chat-chip[data-filter="all"]').click();

    // "/" outside a text field focuses the search (the panel is outside the dock root now).
    const compose = q<HTMLButtonElement>('[data-part="compose"]');
    compose.focus();
    compose.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));
    expect(document.activeElement).toBe(search);

    // New conversation: the people picker in the list column; Escape goes back to the list, never closes it.
    compose.click();
    await flush();
    expect(q<HTMLElement>('.nowo-chat-picker').hidden).toBe(false);
    q('.nowo-chat-panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(q<HTMLElement>('.nowo-chat-picker').hidden).toBe(true);
    q('.nowo-chat-panel').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(q('.nowo-chat-panel').classList.contains('is-open')).toBe(true);

    // The user's status: the button in the list header opens the status menu (also from data-nowo-chat-status-open).
    const me = q<HTMLButtonElement>('[data-part="me"]');
    expect(me.hidden).toBe(false);
    me.click();
    expect(q<HTMLElement>('.nowo-chat-status-menu').hidden).toBe(false);
    q<HTMLButtonElement>('.nowo-chat-status-option[data-status="busy"]').click();
    await flush();
    expect(api.setStatus).toHaveBeenCalledWith(expect.objectContaining({ status: 'busy' }));
    const opener = document.createElement('button');
    opener.dataset.nowoChatStatusOpen = '';
    page.append(opener);
    opener.click();
    expect(q<HTMLElement>('.nowo-chat-status-menu').hidden).toBe(false);
  });

  it('closes the conversation shown: back to the list, with the address', async () => {
    const { dock, q, row } = mountPage([summary('a'), summary('b')], { current: 'a' });
    await dock.start();
    await flush();
    row('a').focus();
    row('a').closest('li')?.querySelector<HTMLButtonElement>('[data-part="row-archive"]')?.click();
    await flush();
    expect(api.archive).toHaveBeenCalledWith('a', true);
    expect(window.location.pathname).toBe('/chat/');
    expect(q('.nowo-chat-pagemode__thread').textContent).toBe('Pick a conversation.');
    expect(q('.nowo-chat-toast').textContent).toContain('Closed');
    // Closing a conversation that is not shown leaves the page as it is.
    row('b').click();
    await flush();
    dock.close('zzz');
    expect(window.location.pathname).toBe('/chat/b');
  });

  it('shows one column on phones, with a back button to the list', async () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener: () => undefined }));
    const { dock, q, row } = mountPage([summary('a')]);
    await dock.start();
    row('a').click();
    await flush();
    expect(q('.nowo-chat-pagemode').classList.contains('has-thread')).toBe(true);
    const back = q<HTMLButtonElement>('.nowo-chat-window [data-part="back"]');
    back.focus();
    back.click();
    expect(q('.nowo-chat-pagemode').classList.contains('has-thread')).toBe(false);
    expect(window.location.pathname).toBe('/chat/');
    // Focus lands on the conversation's row in the list.
    expect(document.activeElement).toBe(row('a'));

    // A conversation without a row (an empty direct one stays out of the list): focus goes to the search.
    await dock.open(summary('fresh', { lastMessage: null }));
    await flush();
    q<HTMLButtonElement>('.nowo-chat-window [data-part="back"]').focus();
    q<HTMLButtonElement>('.nowo-chat-window [data-part="back"]').click();
    expect(document.activeElement).toBe(q('.nowo-chat-panel__list .nowo-chat-search'));
  });

  it('loads a deep-linked conversation that is not in the list, or goes back to the list when it is gone', async () => {
    api.conversation.mockResolvedValueOnce(summary('x', { archived: true }));
    const first = mountPage([summary('a')], { current: 'x' });
    await first.dock.start();
    await flush();
    expect(first.q('.nowo-chat-pagemode__thread .nowo-chat-window').dataset.uuid).toBe('x');
    first.dock.destroy();
    document.body.replaceChildren();

    window.history.replaceState(null, '', '/chat/gone');
    const second = mountPage([summary('a')], { current: 'gone' });
    await second.dock.start();
    await flush();
    expect(second.q('.nowo-chat-pagemode__thread').textContent).toBe('Pick a conversation.');
    expect(window.location.pathname).toBe('/chat/');
  });

  it('replaces the history entry for ?chat= links and support tickets, and never switches on its own', async () => {
    window.history.replaceState(null, '', '/chat/?chat=00000000-0000-4000-8000-000000000001');
    const push = vi.spyOn(window.history, 'pushState');
    const uuid = '00000000-0000-4000-8000-000000000001';
    const { dock, q } = mountPage([summary(uuid), summary('b')], {}, { prefs: { sound: 'pop', buzz: true, presence: true, openOnMessage: true } });
    await dock.start();
    await flush();
    expect(q('.nowo-chat-pagemode__thread .nowo-chat-window').dataset.uuid).toBe(uuid);
    expect(window.location.pathname).toBe(`/chat/${uuid}`);
    expect(push).not.toHaveBeenCalled();

    // A support draft continued as a ticket takes the draft's history entry.
    dock.replaceWith(uuid, summary('ticket', { kind: 'support' }));
    await flush();
    expect(window.location.pathname).toBe('/chat/ticket');
    expect(push).not.toHaveBeenCalled();

    // A message or a buzz elsewhere does not take the page away from the conversation being read.
    realtimeHandlers?.onEvent({ type: 'message', conversationUuid: 'b', message: message() });
    realtimeHandlers?.onEvent({ type: 'message', conversationUuid: 'b', message: message({ kind: 'buzz' }) });
    vi.advanceTimersByTime(200);
    await flush();
    expect(q('.nowo-chat-pagemode__thread .nowo-chat-window').dataset.uuid).toBe('ticket');
  });
});
