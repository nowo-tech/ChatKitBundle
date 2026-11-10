import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatSidebarView, loadDialogRuntime, navigation, resetDialogRuntime, safeLink, sameOriginPath, sanitizeIcon, type SidebarHost } from '../src/sidebar';
import { Templates } from '../src/templates';
import type { ChatConfig, ConversationSummary, SidebarSection } from '../src/types';
import { templates, templatesRoot } from './support';

const i18n: Record<string, string> = {
  sidebar_recent: 'Recent', sidebar_unread: 'Unread', sidebar_conversations: 'Direct messages and groups', sidebar_empty: 'No conversations yet.',
  sidebar_no_unread: 'All caught up.', sidebar_muted: 'Muted', sidebar_mentions: 'Mentions: %count%', unread: '%count% unread',
};

const config = (over: Partial<ChatConfig> = {}): ChatConfig =>
  ({
    userUuid: 'me', csrf: 't', locale: 'en', contextChannel: null, pollMs: 4000, maxLength: 4000, editWindowSeconds: 900, reactions: [], linkPreviews: false,
    endpoints: {} as ChatConfig['endpoints'], i18n, prefs: { sound: 'pop', buzz: true, presence: true }, isAgent: false,
    sidebar: { page: true, dock: false, endpoint: '/chat/api/sidebar', dialogs: { md: 'dialog-md', wide: 'dialog-wide' } }, ...over,
  }) as ChatConfig;

const summary = (uuid: string, over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid, kind: 'direct', title: `T ${uuid}`, subtitle: `${uuid}@x`, initials: 'TT', peerUuid: null, channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: '2026-10-15T10:00:00Z',
  lastMessage: { preview: 'hola', deleted: false, mine: false, authorName: 'Peer' }, ...over,
});

const channels = (over: Partial<SidebarSection> = {}): SidebarSection => ({
  key: 'channels', label: 'Channels', position: 0, collapsible: true, collapsedByDefault: false,
  items: [
    { key: 'general', label: 'general', subtitle: 'Everyone', icon: 'channel', conversationUuid: 'g', url: null, unread: 4, mentions: 2, muted: false },
    { key: 'secret', label: 'secret', subtitle: null, icon: 'private', conversationUuid: 'h', url: null, unread: 1, mentions: 0, muted: true },
    { key: 'docs', label: 'Docs', subtitle: '', icon: null, iconSvg: '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><path d="M1 1"/></svg>', conversationUuid: null, url: '/docs', unread: 0, mentions: 0, muted: false },
    { key: 'bad', label: 'Bad', subtitle: null, icon: 'project', conversationUuid: null, url: 'javascript:alert(1)', unread: 0, mentions: 0, muted: false },
  ],
  actions: [
    { key: 'browse', label: 'Browse channels', icon: 'search', url: '/rooms', dialogUrl: '/rooms/browse', dialogSize: 'wide' },
    { key: 'create', label: 'Create channel', icon: null, url: null, dialogUrl: '/rooms/new', dialogSize: 'md' },
    { key: 'link', label: 'Help', icon: null, url: 'https://help.example', dialogUrl: null, dialogSize: 'md' },
    { key: 'away', label: 'Away', icon: null, url: null, dialogUrl: 'https://evil.example/x', dialogSize: 'md' },
  ],
  ...over,
});

function host(list: ConversationSummary[], current: string | null = null, cfg: Partial<ChatConfig> = {}) {
  const openConversation = vi.fn<(uuid: string) => void>();
  const archive = vi.fn<(summary: ConversationSummary) => void>();
  const value: SidebarHost = { config: config(cfg), templates: templates(), listed: () => list, current: () => current, openConversation, archive };
  return Object.assign(value, { openConversation, archive });
}

function draw(view: ChatSidebarView): HTMLElement {
  const list = document.createElement('ul');
  list.className = 'nowo-chat-list is-sidebar';
  list.append(...view.render());
  view.restoreTabStop(list);
  document.body.append(list);
  return list;
}

const q = <T extends HTMLElement = HTMLElement>(scope: ParentNode, testid: string): T => scope.querySelector<T>(`[data-testid="${testid}"]`) as T;

describe('sidebar helpers', () => {
  it('keeps plain SVG shapes only', () => {
    const icon = sanitizeIcon('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="x" onclick="x()"><script>x()</script><g fill="red"><path d="M0 0" onmouseover="y()"/><a href="javascript:x"><text>t</text></a><use href="#x"/></g><rect xlink:href="x" width="1" filter="javascript:x"/></svg>');
    expect(icon?.outerHTML).toBe('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" aria-hidden="true" focusable="false"><g fill="red"><path d="M0 0"></path></g><rect width="1"></rect></svg>');
    expect(sanitizeIcon('<div>no</div>')).toBeNull();
    expect(sanitizeIcon('<svg><path/></svg>')).toBeNull();
    expect(sanitizeIcon('not markup')).toBeNull();
  });

  it('accepts same-origin dialog URLs only', () => {
    expect(sameOriginPath('/rooms/new?x=1#f')).toBe('/rooms/new?x=1#f');
    expect(sameOriginPath(`${window.location.origin}/a`)).toBe('/a');
    expect(sameOriginPath('https://evil.example/a')).toBeNull();
    expect(sameOriginPath('//evil.example/a')).toBeNull();
    expect(sameOriginPath('/\\evil.example')).toBeNull();
    expect(sameOriginPath('/a b')).toBeNull();
    expect(sameOriginPath('')).toBeNull();
    expect(sameOriginPath('javascript:alert(1)')).toBeNull();
    expect(sameOriginPath('http://[')).toBeNull();
  });

  it('accepts paths and http(s) links only', () => {
    expect(safeLink('/a')).toBe('/a');
    expect(safeLink('https://x.example')).toBe('https://x.example');
    expect(safeLink('//x.example')).toBeNull();
    expect(safeLink('javascript:x')).toBeNull();
    expect(safeLink('/a\\b')).toBeNull();
    expect(safeLink(null)).toBeNull();
  });
});

describe('ChatSidebarView', () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.body.replaceChildren();
  });
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.replaceChildren();
  });

  it('groups the conversations and the host sections by position', () => {
    const list = [
      summary('a', { unread: 2, lastMessageAt: '2026-10-15T12:00:00Z' }),
      summary('g', { kind: 'channel', title: '#general', unread: 7, lastMessageAt: '2026-10-15T11:00:00Z' }),
      summary('empty', { lastMessage: null }),
      summary('h', { kind: 'channel', unread: 3, mutedUntil: '2999-01-01T00:00:00Z', lastMessageAt: '2026-10-14T10:00:00Z' }),
    ];
    const view = new ChatSidebarView(host(list, 'g'));
    view.setSections([channels(), { key: 'first', label: 'First', position: -2000, collapsible: false, collapsedByDefault: false, items: [], actions: [] }]);
    expect(view.hasHostContent()).toBe(true);
    const root = draw(view);
    expect([...root.querySelectorAll<HTMLElement>('[data-part="section"]')].map((section) => section.dataset.section)).toEqual(['first', 'recent', 'channels', 'conversations']);

    // Recent: every listed conversation (no empty direct one), newest first.
    const recent = q(root, 'chat-sidebar-section-recent');
    expect([...recent.querySelectorAll<HTMLElement>('[role="option"]')].map((row) => row.dataset.uuid)).toEqual(['a', 'g', 'h']);
    expect(q(root, 'chat-sidebar-item-recent-g').getAttribute('aria-selected')).toBe('true');
    expect(q(root, 'chat-sidebar-item-recent-a').classList.contains('is-unread')).toBe(true);
    expect(q(root, 'chat-sidebar-item-recent-h').classList.contains('is-muted')).toBe(true);
    expect(q(root, 'chat-sidebar-item-recent-a').title).toBe('a@x');

    // Host rows: the chat's counts when it knows the conversation (7, muted from the summary), else the server's.
    const general = q(root, 'chat-sidebar-item-channels-general');
    expect(general.querySelector('[data-part="badge"]')?.textContent).toContain('7');
    expect(general.querySelector<HTMLElement>('[data-part="mentions"]')?.hidden).toBe(false);
    expect(general.querySelector('[data-part="mentions"]')?.getAttribute('aria-label')).toBe('Mentions: 2');
    expect(general.title).toBe('Everyone');
    expect(general.querySelector('[data-part="icon"] svg')).not.toBeNull();
    const secret = q(root, 'chat-sidebar-item-channels-secret');
    expect(secret.classList.contains('is-muted')).toBe(true);
    expect(secret.querySelector<HTMLElement>('[data-part="muted"]')?.hidden).toBe(false);
    expect(q(root, 'chat-sidebar-item-channels-docs').querySelector('svg')?.hasAttribute('onload')).toBe(false);
    expect(q(root, 'chat-sidebar-item-channels-docs').title).toBe('');

    // Direct messages and groups: what no host section shows.
    const others = q(root, 'chat-sidebar-section-conversations');
    expect([...others.querySelectorAll<HTMLElement>('[role="option"]')].map((row) => row.dataset.uuid)).toEqual(['a']);

    // A section that cannot fold has a plain heading.
    const first = q(root, 'chat-sidebar-section-first');
    expect(first.querySelector<HTMLElement>('[data-part="toggle"]')?.hidden).toBe(true);
    expect(first.querySelector('[data-part="heading"]')?.textContent).toBe('First');
  });

  it('opens rows, follows links and closes conversations with Delete', () => {
    const list = [summary('a', { unread: 1 }), summary('g', { kind: 'channel' })];
    const h = host(list);
    const view = new ChatSidebarView(h);
    view.setSections([channels()]);
    const root = draw(view);
    const assign = vi.spyOn(navigation, 'assign').mockImplementation(() => undefined);
    q(root, 'chat-sidebar-item-recent-a').click();
    expect(h.openConversation).toHaveBeenCalledWith('a');
    q(root, 'chat-sidebar-item-channels-general').click();
    expect(h.openConversation).toHaveBeenCalledWith('g');
    q(root, 'chat-sidebar-item-channels-docs').click();
    expect(assign).toHaveBeenCalledWith('/docs');
    q(root, 'chat-sidebar-item-channels-bad').click();
    expect(assign).toHaveBeenCalledTimes(1);
    q(root, 'chat-sidebar-item-recent-a').dispatchEvent(new KeyboardEvent('keydown', { key: 'Delete', bubbles: true }));
    expect(h.archive).toHaveBeenCalledWith(list[0]);
    q(root, 'chat-sidebar-item-recent-a').dispatchEvent(new KeyboardEvent('keydown', { key: 'x', bubbles: true }));
    expect(h.archive).toHaveBeenCalledTimes(1);
    vi.restoreAllMocks();
    navigation.assign('#sidebar');
    expect(window.location.hash).toBe('#sidebar');
  });

  it('turns dialog actions into UiKit remote form openers, same origin only', () => {
    const dialogs = templatesRoot();
    dialogs.innerHTML = '<dialog id="dialog-md"></dialog><dialog id="dialog-wide"></dialog>';
    document.body.append(dialogs);
    const view = new ChatSidebarView(host([]));
    view.setSections([channels()]);
    const root = draw(view);
    const browse = q<HTMLAnchorElement>(root, 'chat-sidebar-action-channels-browse');
    expect(browse.getAttribute('href')).toBe('/rooms');
    expect(browse.dataset.nowoUiDialogOpen).toBe('dialog-wide');
    expect(browse.dataset.nowoUiDialogSrc).toBe('/rooms/browse');
    expect(browse.hasAttribute('data-nowo-ui-dialog-form')).toBe(true);
    expect(browse.dataset.nowoUiDialogTitle).toBe('Browse channels');
    expect(browse.getAttribute('aria-haspopup')).toBe('dialog');
    expect(browse.querySelector('[data-part="icon"] svg')).not.toBeNull();
    const create = q<HTMLAnchorElement>(root, 'chat-sidebar-action-channels-create');
    expect(create.getAttribute('href')).toBe('/rooms/new');
    expect(create.dataset.nowoUiDialogOpen).toBe('dialog-md');
    expect(create.querySelector('[data-part="icon"]')).toBeNull();
    const link = q<HTMLAnchorElement>(root, 'chat-sidebar-action-channels-link');
    expect(link.getAttribute('href')).toBe('https://help.example');
    expect(link.hasAttribute('data-nowo-ui-dialog-open')).toBe(false);
    expect(q(root, 'chat-sidebar-action-channels-away')).toBeNull();
  });

  it('keeps a plain link when the dialogs are not on the page', () => {
    const view = new ChatSidebarView(host([], null, { sidebar: undefined }));
    view.setSections([channels({ actions: [{ key: 'x', label: 'X', icon: 'plus', iconSvg: '<p>no svg</p>', url: null, dialogUrl: '/x', dialogSize: 'md' }] })]);
    const root = draw(view);
    const action = q<HTMLAnchorElement>(root, 'chat-sidebar-action-channels-x');
    expect(action.getAttribute('href')).toBe('/x');
    expect(action.hasAttribute('data-nowo-ui-dialog-open')).toBe(false);
    expect(action.querySelector('[data-part="icon"] svg')).not.toBeNull();
  });

  it('folds sections, remembers it and shows the folded unread count', () => {
    const list = [summary('a', { unread: 2 }), summary('b', { unread: 5, mutedUntil: '2999-01-01T00:00:00Z' })];
    const view = new ChatSidebarView(host(list));
    const root = draw(view);
    const toggle = q(root, 'chat-sidebar-section-conversations').querySelector<HTMLButtonElement>('[data-part="toggle"]') as HTMLButtonElement;
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(toggle.getAttribute('aria-controls')).toMatch(/^nowo-chat-sidebar-list-\d+$/);
    expect(document.getElementById(toggle.getAttribute('aria-controls') ?? '')?.getAttribute('role')).toBe('listbox');
    toggle.focus();
    toggle.click();
    const folded = q(root, 'chat-sidebar-section-conversations');
    const foldedToggle = folded.querySelector<HTMLButtonElement>('[data-part="toggle"]') as HTMLButtonElement;
    expect(foldedToggle.getAttribute('aria-expanded')).toBe('false');
    expect(folded.querySelector<HTMLElement>('[data-part="items"]')?.hidden).toBe(true);
    expect(foldedToggle.querySelector('[data-part="badge"]')?.textContent).toContain('2');
    expect(document.activeElement).toBe(foldedToggle);
    expect(JSON.parse(window.localStorage.getItem('nowo-chat-sidebar:me') ?? '{}')).toEqual({ collapsed: { conversations: true }, mode: 'recent' });

    // A new view of the same viewer reads it back.
    const again = draw(new ChatSidebarView(host(list)));
    expect(q(again, 'chat-sidebar-section-conversations').classList.contains('is-collapsed')).toBe(true);
  });

  it('folds a section by default until the viewer opens it', () => {
    const view = new ChatSidebarView(host([]));
    view.setSections([channels({ collapsedByDefault: true })]);
    const root = draw(view);
    expect(q(root, 'chat-sidebar-section-channels').classList.contains('is-collapsed')).toBe(true);
    expect(q(root, 'chat-sidebar-section-channels').querySelector<HTMLElement>('[data-part="actions"]')?.hidden).toBe(true);
    // Without conversations: the bundle's section says so; Recent is not drawn.
    expect(q(root, 'chat-sidebar-section-recent')).toBeNull();
    expect(q(root, 'chat-sidebar-section-conversations').querySelector('[data-part="empty"]')?.textContent).toBe('No conversations yet.');
  });

  it('switches Recent and Unread', () => {
    const list = [summary('a', { unread: 0 }), summary('b', { unread: 1 }), summary('c', { unread: 4, mutedUntil: '2999-01-01T00:00:00Z' })];
    const root = draw(new ChatSidebarView(host(list)));
    const unread = q(root, 'chat-sidebar-section-recent').querySelector<HTMLButtonElement>('[data-mode="unread"]') as HTMLButtonElement;
    expect(unread.getAttribute('aria-pressed')).toBe('false');
    unread.click();
    const recent = q(root, 'chat-sidebar-section-recent');
    expect([...recent.querySelectorAll<HTMLElement>('[role="option"]')].map((row) => row.dataset.uuid)).toEqual(['b']);
    expect(recent.querySelector('[data-mode="unread"]')?.getAttribute('aria-pressed')).toBe('true');
    expect(JSON.parse(window.localStorage.getItem('nowo-chat-sidebar:me') ?? '{}').mode).toBe('unread');

    const caughtUp = draw(new ChatSidebarView(host([summary('a')])));
    expect(q(caughtUp, 'chat-sidebar-section-recent').querySelector('[data-part="empty"]')?.textContent).toBe('All caught up.');
    (q(caughtUp, 'chat-sidebar-section-recent').querySelector('[data-mode="recent"]') as HTMLButtonElement).click();
    expect(q(caughtUp, 'chat-sidebar-section-recent').querySelectorAll('[role="option"]')).toHaveLength(1);
  });

  it('shows at most five recent conversations', () => {
    const list = Array.from({ length: 7 }, (_, index) => summary(`c${index}`, { lastMessageAt: `2026-10-1${index}T10:00:00Z` }));
    const root = draw(new ChatSidebarView(host(list)));
    expect([...q(root, 'chat-sidebar-section-recent').querySelectorAll<HTMLElement>('[role="option"]')].map((row) => row.dataset.uuid)).toEqual(['c6', 'c5', 'c4', 'c3', 'c2']);
  });

  it('counts realtime messages and reads on host rows', () => {
    const view = new ChatSidebarView(host([]));
    const section = channels();
    view.setSections([section]);
    view.received('g', true);
    view.received('g', false);
    view.received('unknown', true);
    expect([section.items[0]?.unread, section.items[0]?.mentions]).toEqual([6, 3]);
    view.markRead('g');
    expect([section.items[0]?.unread, section.items[0]?.mentions]).toEqual([0, 0]);
    expect(new ChatSidebarView(host([])).hasHostContent()).toBe(false);
  });

  it('survives a storage that throws or holds garbage', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    const root = draw(new ChatSidebarView(host([summary('a')])));
    (q(root, 'chat-sidebar-section-conversations').querySelector('[data-part="toggle"]') as HTMLButtonElement).click();
    expect(q(root, 'chat-sidebar-section-conversations').classList.contains('is-collapsed')).toBe(true);
    vi.restoreAllMocks();

    window.localStorage.setItem('nowo-chat-sidebar:me', JSON.stringify({ mode: 'other', collapsed: { recent: 'yes', conversations: true } }));
    const read = draw(new ChatSidebarView(host([summary('a')])));
    expect(q(read, 'chat-sidebar-section-recent').classList.contains('is-collapsed')).toBe(false);
    expect(q(read, 'chat-sidebar-section-conversations').classList.contains('is-collapsed')).toBe(true);
    window.localStorage.setItem('nowo-chat-sidebar:me', JSON.stringify({ collapsed: null }));
    expect(() => draw(new ChatSidebarView(host([summary('a')])))).not.toThrow();
    window.localStorage.setItem('nowo-chat-sidebar:me', '{broken');
    expect(() => draw(new ChatSidebarView(host([summary('a')])))).not.toThrow();
  });

  it('is one tab stop with arrow keys inside', () => {
    const list = [summary('a', { lastMessageAt: '2026-10-15T12:00:00Z' }), summary('b')];
    const view = new ChatSidebarView(host(list, 'b'));
    view.setSections([channels({ actions: [] })]);
    const root = draw(view);
    const signal = new AbortController();
    view.bindKeys(root, signal.signal);
    const stops = () => [...root.querySelectorAll<HTMLElement>('[data-nav-key]')].filter((element) => element.closest('[hidden]') === null);
    // The current conversation holds the tab stop.
    expect(stops().filter((stop) => stop.tabIndex === 0).map((stop) => stop.dataset.navKey)).toEqual(['item/recent/b']);
    const key = (key: string) => (document.activeElement as HTMLElement).dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }));
    const at = () => (document.activeElement as HTMLElement).dataset.navKey;

    q(root, 'chat-sidebar-item-recent-b').focus();
    key('Home');
    expect(at()).toBe('section/recent');
    key('ArrowUp');
    expect(at()).toBe('section/recent');
    key('ArrowDown');
    expect(at()).toBe('mode/recent');
    key('ArrowDown');
    key('ArrowDown');
    expect(at()).toBe('item/recent/a');
    // ← on a row goes up to its header; ← on an open header folds it; → unfolds; → again goes in.
    key('ArrowLeft');
    expect(at()).toBe('section/recent');
    key('ArrowLeft');
    expect(q(root, 'chat-sidebar-section-recent').classList.contains('is-collapsed')).toBe(true);
    expect(at()).toBe('section/recent');
    key('ArrowRight');
    expect(q(root, 'chat-sidebar-section-recent').classList.contains('is-collapsed')).toBe(false);
    key('ArrowRight');
    expect(at()).toBe('mode/recent');
    key('ArrowRight');
    expect(at()).toBe('mode/recent');
    key('End');
    expect(at()).toBe('item/conversations/b');
    key('ArrowDown');
    expect(at()).toBe('item/conversations/b');
    // Enter / Space open a row; other keys do nothing.
    const opened = vi.fn();
    (document.activeElement as HTMLElement).addEventListener('click', opened);
    key('Enter');
    key(' ');
    key('a');
    expect(opened).toHaveBeenCalledTimes(2);
    expect(stops().filter((stop) => stop.tabIndex === 0)).toHaveLength(1);
    // Enter on a header is the button's own.
    q(root, 'chat-sidebar-section-recent').querySelector<HTMLElement>('[data-part="toggle"]')?.focus();
    key('Enter');
    expect(q(root, 'chat-sidebar-section-recent').classList.contains('is-collapsed')).toBe(false);

    // Keys outside the stops, or while the list is not grouped, are left alone.
    const outside = document.createElement('button');
    root.append(outside);
    outside.focus();
    key('ArrowDown');
    expect(document.activeElement).toBe(outside);
    root.classList.remove('is-sidebar');
    q(root, 'chat-sidebar-item-recent-a').focus();
    key('ArrowDown');
    expect(at()).toBe('item/recent/a');
    signal.abort();
  });

  it('draws nothing again when the section left the page', () => {
    const view = new ChatSidebarView(host([summary('a')]));
    const [, conversations] = view.render();
    (conversations?.querySelector('[data-part="toggle"]') as HTMLButtonElement).click();
    expect(conversations?.isConnected).toBe(false);
  });
});

describe('ChatSidebarView rows (1.26)', () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.body.replaceChildren();
    document.head.querySelectorAll('script').forEach((script) => script.remove());
    resetDialogRuntime();
    delete (window as Window & { nowoUiOpenRemoteForm?: unknown }).nowoUiOpenRemoteForm;
  });

  it('shows the status, the preview and the Close button of conversation rows', () => {
    const peer = summary('a', { peerUuid: 'p', peerOnline: true, peerStatusEmoji: '🌴' });
    const base = host([peer, summary('g', { kind: 'channel', title: '#general' })], null, { endpoints: { archive: '/a/__uuid__' } as ChatConfig['endpoints'], i18n: { ...i18n, status_available: 'Available', status_custom_emoji: 'Status %emoji%', close_conversation: 'Close %name%' } });
    const view = new ChatSidebarView(Object.assign(base, { preview: (item: ConversationSummary) => `last of ${item.uuid}` }));
    view.setSections([channels()]);
    const root = draw(view);
    const row = q(root, 'chat-sidebar-item-recent-a');
    expect(row.dataset.peerStatus).toBe('available');
    expect(row.querySelector('[data-part="status"]')?.getAttribute('aria-label')).toBe('Status 🌴');
    expect(row.querySelector('[data-part="preview"]')?.textContent).toBe('last of a');
    // A channel row: no peer status, a preview.
    const channel = q(root, 'chat-sidebar-item-recent-g');
    expect(channel.dataset.peerStatus).toBeUndefined();
    expect(channel.querySelector<HTMLElement>('[data-part="status"]')?.hidden).toBe(true);
    // A host row of a listed conversation shows its preview too, keeps its own icon and has no Close button.
    const general = q(root, 'chat-sidebar-item-channels-general');
    expect(general.querySelector('[data-part="preview"]')?.textContent).toBe('last of g');
    expect(general.querySelector('.nowo-chat-avatar')).toBeNull();
    expect(general.querySelector<HTMLElement>('[data-part="close"]')?.hidden).toBe(true);
    // A host row of an unknown conversation has no preview.
    expect(q(root, 'chat-sidebar-item-channels-secret').querySelector<HTMLElement>('[data-part="preview"]')?.hidden).toBe(true);

    const close = q<HTMLButtonElement>(root, 'chat-sidebar-item-recent-a-close');
    close.click();
    expect(base.archive).toHaveBeenCalledWith(peer);
    expect(base.openConversation).not.toHaveBeenCalled();

    // Repainted in place after a status change.
    Object.assign(peer, { peerStatus: 'dnd', peerStatusEmoji: null, peerStatusText: 'Focus' });
    view.repaintPeer(root, peer);
    expect(q(root, 'chat-sidebar-item-recent-a')).toBe(row);
    expect(row.dataset.peerStatus).toBe('dnd');
    expect(row.querySelector('[data-part="status"]')?.textContent).toBe('Focus');
    expect(row.querySelector('[data-part="status"]')?.hasAttribute('aria-label')).toBe(false);
  });

  it('leaves the Close button out without the archive endpoint, and works with a pre-1.26 row template', () => {
    const view = new ChatSidebarView(host([summary('a', { peerOnline: true })]));
    const root = draw(view);
    expect(root.querySelector('[data-part="close"]')).toBeNull();
    expect(q(root, 'chat-sidebar-item-recent-a').hasAttribute('aria-keyshortcuts')).toBe(false);
    // No preview callback: the preview stays hidden.
    expect(q(root, 'chat-sidebar-item-recent-a').querySelector<HTMLElement>('[data-part="preview"]')?.hidden).toBe(true);

    const legacy = templatesRoot();
    const template = legacy.querySelector<HTMLTemplateElement>('template[data-nowo-chat-template="sidebar-item"]') as HTMLTemplateElement;
    template.innerHTML = '<li class="nowo-chat-sidebar__item" role="option" tabindex="-1"><span data-part="icon"></span><span data-part="label"></span><span data-part="muted" hidden></span><span data-part="mentions" hidden></span><span class="nowo-chat-badge" data-part="badge" hidden></span></li>';
    const old = new ChatSidebarView({ ...host([summary('b', { peerOnline: true })], null, { endpoints: { archive: '/a' } as ChatConfig['endpoints'] }), templates: new Templates(legacy) });
    const rows = draw(old);
    expect(q(rows, 'chat-sidebar-item-recent-b').dataset.peerStatus).toBe('available');
    expect(q(rows, 'chat-sidebar-item-recent-b').querySelector('[data-part="label"]')?.textContent).toBe('T b');
  });

  it('loads the dialog runtime once, only when needed and not already on the page', () => {
    const cfg = { sidebar: { page: true, dock: false, endpoint: null, dialogs: { md: 'dialog-md', wide: 'dialog-wide' }, dialogRuntime: '/bundles/nowouikit/js/nowo-ui-dialog.js' } } as Partial<ChatConfig>;
    // No dialog action: nothing to load.
    const plain = new ChatSidebarView(host([summary('a')], null, cfg));
    plain.setSections([channels({ actions: [] })]);
    draw(plain);
    expect(document.querySelector('script[data-nowo-chat-dialog-runtime]')).toBeNull();

    const nonce = document.createElement('script');
    nonce.nonce = 'n0nce';
    document.head.append(nonce);
    const view = new ChatSidebarView(host([summary('a')], null, cfg));
    view.setSections([channels()]);
    draw(view);
    draw(view);
    const added = document.querySelectorAll<HTMLScriptElement>('script[data-nowo-chat-dialog-runtime]');
    expect(added).toHaveLength(1);
    const script = added[0] as HTMLScriptElement;
    expect(script.getAttribute('src')).toBe('/bundles/nowouikit/js/nowo-ui-dialog.js');
    expect(script.defer).toBe(true);
    expect(script.nonce).toBe('n0nce');
  });

  it('never loads a second dialog runtime', () => {
    const url = '/bundles/nowouikit/js/nowo-ui-dialog.js';
    (window as Window & { nowoUiOpenRemoteForm?: unknown }).nowoUiOpenRemoteForm = () => true;
    expect(loadDialogRuntime(url)).toBe(false);
    delete (window as Window & { nowoUiOpenRemoteForm?: unknown }).nowoUiOpenRemoteForm;

    const host = document.createElement('script');
    host.setAttribute('src', '/assets/nowo-ui-dialog.js?v=2');
    document.head.append(host);
    expect(loadDialogRuntime(url)).toBe(false);
    host.remove();

    expect(loadDialogRuntime('https://evil.example/nowo-ui-dialog.js')).toBe(false);
    expect(loadDialogRuntime(url)).toBe(true);
    expect(loadDialogRuntime(url)).toBe(false);
    expect(document.querySelectorAll('script[data-nowo-chat-dialog-runtime]')).toHaveLength(1);
  });
});
