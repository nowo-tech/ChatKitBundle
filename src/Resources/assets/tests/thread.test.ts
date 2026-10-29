import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { ChatApiError, type ChatApi } from '../src/api';
import { ThreadView, plain, type ThreadHost } from '../src/thread';
import type { ChatConfig, ChatMessage, ConversationSummary } from '../src/types';
import { templates } from './support';

const i18n: Record<string, string> = {
  conversation_label: 'Conversation with %name%', online: 'Online', support_resolved: 'Resolved', read_only: 'Read only',
  typing_one: '%name% is typing', typing_two: '%first% and %second% are typing', typing_many: 'Several typing',
  buzz_sent: '%name% sent a buzz', you: 'You', buzz_too_soon: 'Too soon', rate_limited: 'Slow down', too_long: 'Max %max%',
  error: 'Error', sending: 'Sending', retry: 'Retry', failed: 'Failed', seen_by: 'Seen by %names%', new_messages: 'New', jump_latest: 'Latest',
  deleted_message: 'Deleted', deleted_message_mine: 'You deleted', just_now: 'now', moderated_message: 'Moderated', edited: 'edited', loading: 'Loading', start_chat: 'Start', support_hint: 'Help',
  today: 'Today', unread: '%count% unread', minimise: 'Minimise', expand: 'Expand', seen: 'Seen', send: 'Send', emoji: 'Emoji', emoji_smileys: 'Smileys',
};

const summary = (over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid: 'c1', kind: 'direct', title: 'Luis', subtitle: 'luis@x', initials: 'LU', peerUuid: 'p', channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: null, lastMessage: null, ...over,
});
let nextId = 1;
const msg = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: nextId++, uuid: 'u', authorUuid: 'p', authorName: 'Luis', authorInitials: 'LU', mine: false, kind: 'text', html: '<p>hola</p>',
  createdAt: '2026-10-15T10:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});

type ApiMock = Record<'messages' | 'send' | 'typing' | 'buzz' | 'resolve' | 'edit' | 'remove' | 'react' | 'members' | 'references', Mock>;

function setup(over: Partial<ConversationSummary> = {}, apiOver: Partial<ApiMock> = {}, configOver: Partial<ChatConfig> = {}) {
  const api: ApiMock = {
    messages: vi.fn().mockResolvedValue({ messages: [], hasMore: false, readUpTo: null, readers: [] }),
    send: vi.fn(),
    typing: vi.fn().mockResolvedValue(undefined),
    buzz: vi.fn(),
    resolve: vi.fn().mockResolvedValue(summary({ kind: 'support', supportStatus: 'resolved' })),
    edit: vi.fn(),
    remove: vi.fn(),
    react: vi.fn(),
    members: vi.fn().mockResolvedValue([{ uuid: 'u-luis', name: 'Luis', initials: 'LU' }]),
    references: vi.fn().mockImplementation(async (_uuid: string, type: string) =>
      type === ''
        ? { types: [{ key: 'task', label: 'Task', icon: 'task' }], references: [] }
        : { types: [], references: [{ type: 'task', id: '42', title: 'Deploy', url: '/t/42', subtitle: '', badge: '', tone: 'neutral' }] },
    ),
    ...apiOver,
  };
  const host: ThreadHost = {
    config: { i18n, endpoints: { page: '/chat/__uuid__' }, userUuid: 'me', locale: 'en', maxLength: 50, editWindowSeconds: 900, reactions: ['👍', '🎉'], isAgent: false, ...configOver } as ChatConfig,
    api: api as unknown as ChatApi,
    templates: templates(),
    minimise: vi.fn(), close: vi.fn(), saveDraft: vi.fn(), draft: vi.fn().mockReturnValue('borrador'),
    messagesChanged: vi.fn(), read: vi.fn(), isMobile: vi.fn().mockReturnValue(false), conversationChanged: vi.fn(),
    replaceWith: vi.fn(), linkPreview: vi.fn().mockResolvedValue(null),
  };
  const view = new ThreadView(host, summary(over));
  document.body.append(view.element);
  const q = <T extends Element = HTMLElement>(selector: string) => view.element.querySelector<T>(selector) as T;
  return { view, host, api, q };
}

function setupWithDraft(draft: string) {
  const ctx = setup();
  ctx.view.element.remove();
  (ctx.host.draft as Mock).mockReturnValue(draft);
  const view = new ThreadView(ctx.host, summary());
  document.body.append(view.element);
  return { ...ctx, view, q: <T extends Element = HTMLElement>(selector: string) => view.element.querySelector<T>(selector) as T };
}

function scrolled(view: ThreadView, top: number, height = 1000, client = 300) {
  const body = view.element.querySelector('.nowo-chat-window__body') as HTMLElement;
  Object.defineProperty(body, 'scrollHeight', { configurable: true, value: height });
  Object.defineProperty(body, 'clientHeight', { configurable: true, value: client });
  body.scrollTop = top;
  return body;
}

describe('ThreadView', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    Element.prototype.scrollTo = vi.fn();
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
      cb(0);
      return 1;
    });
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
    // @ts-expect-error test cleanup
    delete HTMLElement.prototype.animate;
  });

  it('renders the header, draft and summary states', () => {
    const { view, host, q } = setup({ peerOnline: true });
    expect(view.uuid).toBe('c1');
    expect(q('.nowo-chat-window__title').textContent).toBe('Luis');
    expect(q('.nowo-chat-window__subtitle').textContent).toBe('Online');
    expect(q<HTMLTextAreaElement>('.nowo-chat-composer__input').value).toBe('borrador');
    expect(view.element.getAttribute('aria-label')).toBe('Conversation with Luis');

    view.applySummary(summary({ peerOnline: false }));
    expect(q('.nowo-chat-window__subtitle').textContent).toBe('luis@x');
    view.applySummary(summary({ kind: 'support', supportStatus: 'resolved', subtitle: 's' }));
    expect(q('.nowo-chat-window__subtitle').textContent).toBe('Resolved');
    view.applySummary(summary({ kind: 'channel', subtitle: 'Channel', canSend: false }));
    expect(q('.nowo-chat-notice').textContent).toBe('Read only');
    expect(q<HTMLFormElement>('.nowo-chat-composer').hidden).toBe(true);
    expect(q<HTMLButtonElement>('.nowo-chat-window__buzz').hidden).toBe(true);
    view.focusComposer();
    view.applySummary(summary());
    view.focusComposer();
    expect(document.activeElement).toBe(q('.nowo-chat-composer__input'));

    q('.nowo-chat-window__identity').click();
    q<HTMLButtonElement>('[data-part="minimise"]').click();
    expect(host.minimise).toHaveBeenCalledWith('c1', true);
    view.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
    view.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(host.minimise).toHaveBeenCalledTimes(3);
    q<HTMLButtonElement>('.nowo-chat-window__actions .nowo-chat-icon-btn:last-child').click();
    q<HTMLButtonElement>('.nowo-chat-window__back').click();
    expect(host.close).toHaveBeenCalledTimes(2);
  });

  it('minimises with an unread badge and expands marking as read', () => {
    const { view, host, q } = setup({ unread: 120 });
    view.setMinimised(true);
    view.applySummary(summary({ unread: 120 }));
    expect(view.isMinimised).toBe(true);
    expect(q('.nowo-chat-badge [aria-hidden]').textContent).toBe('99+');
    view.merge([msg()], false);
    view.setMinimised(false);
    expect(q('.nowo-chat-badge').hidden).toBe(true);
    expect(host.read).toHaveBeenCalled();
  });

  it('hydrates from cache, loads, catches up and reports errors', async () => {
    const { view, api, host, q } = setup();
    view.hydrate(null);
    expect(q('.nowo-chat-thread').textContent).toBe('Loading');
    await view.catchUp();
    expect(api.messages).not.toHaveBeenCalled();

    const first = msg();
    api.messages.mockResolvedValueOnce({ messages: [first], hasMore: true, readUpTo: null, readers: [{ userUuid: 'p', name: 'Luis', messageId: first.id }] });
    await view.load();
    expect(view.lastId()).toBe(first.id);
    expect(host.messagesChanged).toHaveBeenCalledWith('c1', [first], true, []);

    const second = msg();
    api.messages.mockResolvedValueOnce({ messages: [second], hasMore: false, readUpTo: 99, readers: [] });
    await view.load();
    expect(api.messages).toHaveBeenLastCalledWith('c1', { after: first.id });
    api.messages.mockResolvedValueOnce({ messages: [], hasMore: false, readUpTo: null, readers: [] });
    await view.catchUp();

    const cached = setup();
    cached.view.hydrate({ messages: [msg()], hasMore: false });
    expect(cached.q('.nowo-chat-msg')).not.toBeNull();
    const failing = setup({}, { messages: vi.fn().mockRejectedValue(new Error('x')) });
    await failing.view.load();
    expect(failing.q('.nowo-chat-thread').textContent).toBe('Error');
  });

  it('renders days, groups, tombstones, buzz lines, receipts and the empty state', async () => {
    const { view, q } = setup();
    await view.load();
    expect(q('.nowo-chat-thread__empty').textContent).toContain('Start');
    const supportView = setup({ kind: 'support' });
    await supportView.view.load();
    expect(supportView.q('.nowo-chat-thread__empty').textContent).toContain('Help');

    const earlier = [
      msg({ createdAt: '2026-10-14T10:00:00Z' }),
      msg({ deleted: true }),
      msg({ deleted: true, moderated: true }),
      msg({ kind: 'buzz', html: '' }),
      msg({ kind: 'buzz', mine: true, html: '' }),
    ];
    const mine = msg({ mine: true, authorUuid: 'me', edited: true, html: '<p>mio</p>' });
    view.merge([...earlier, mine], false);
    expect(view.element.querySelectorAll('.nowo-chat-day')).toHaveLength(2);
    expect(q('.nowo-chat-bubble--deleted').textContent).toBe('Deleted');
    expect(view.element.querySelectorAll('.nowo-chat-bubble--deleted')[1]?.textContent).toBe('Moderated');
    expect(view.element.querySelectorAll('.nowo-chat-buzz-line')).toHaveLength(2);
    expect(q('.nowo-chat-buzz-line').textContent).toContain('Luis sent a buzz');
    expect(view.element.querySelector('.nowo-chat-msg__receipt')?.textContent).toBe(' ✓');
    view.readBy('p', 'Luis', mine.id);
    expect(q('.nowo-chat-msg__receipt.is-read')).not.toBeNull();
    view.readBy('p', 'Luis', 1);
    view.merge([msg({ kind: 'buzz', html: '' })], false);
    expect(q('.nowo-chat-buzz-line.is-new')).not.toBeNull();
  });

  it('shows "Seen by" for the newest own message in channels', () => {
    const { view, q } = setup({ kind: 'channel' });
    const other = msg();
    view.merge([other], false);
    expect(q('.nowo-chat-msg__author').textContent).toBe('Luis');
    const own = msg({ mine: true });
    view.merge([own], false);
    expect(q('.nowo-chat-seen')).toBeNull();
    for (const name of ['A', 'B', 'C', 'D']) {
      view.readBy(name, name, own.id);
    }
    expect(q('.nowo-chat-seen').textContent).toBe('Seen by A, B, C +1');
    view.merge([msg()], false);
    expect(q('.nowo-chat-seen')).toBeNull();
  });

  it('drops the pending bubble when the HTTP confirmation lands before the realtime event', async () => {
    const { view, api, q } = setup();
    view.merge([msg({ html: '<p>earlier</p>' })], false);
    const input = q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    api.send.mockResolvedValueOnce(msg({ mine: true, html: '<p>now</p>' }));
    input.value = 'now';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await vi.runOnlyPendingTimersAsync();
    expect(view.element.querySelector('.nowo-chat-msg--pending')).toBeNull();
    expect(view.element.querySelectorAll('.nowo-chat-msg--mine')).toHaveLength(1);
  });

  it('sends optimistically, confirms, and keeps failed messages with retry', async () => {
    const { view, api, host, q } = setup();
    const input = q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    const confirmed = msg({ mine: true, html: '<p>hola</p>' });
    api.send.mockResolvedValueOnce(confirmed);
    input.value = '  hola  ';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await vi.runOnlyPendingTimersAsync();
    expect(api.send).toHaveBeenCalledWith('c1', 'hola');
    expect(host.saveDraft).toHaveBeenCalledWith('c1', '');
    expect(view.element.querySelector('.nowo-chat-msg--pending')).toBeNull();

    for (const [code, text] of [['rate_limited', 'Slow down'], ['too_long', 'Max 50'], ['boom', 'Error']] as const) {
      api.send.mockRejectedValueOnce(new ChatApiError(400, code));
      input.value = 'otra';
      q<HTMLFormElement>('.nowo-chat-composer').dispatchEvent(new Event('submit', { cancelable: true }));
      await vi.runOnlyPendingTimersAsync();
      expect(view.element.querySelector('.nowo-chat-msg--failed')?.textContent).toContain(text);
      api.send.mockResolvedValueOnce(msg({ mine: true }));
      view.element.querySelector<HTMLButtonElement>('.nowo-chat-retry')?.click();
      await vi.runOnlyPendingTimersAsync();
    }
    api.send.mockRejectedValueOnce(new Error('network'));
    input.value = 'red';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await vi.runOnlyPendingTimersAsync();
    expect(view.element.querySelector('.nowo-chat-msg--failed')?.textContent).toContain('Error');

    api.send.mockRejectedValueOnce(new ChatApiError(403, 'read_only'));
    input.value = 'ro';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await vi.runOnlyPendingTimersAsync();
    expect(q<HTMLFormElement>('.nowo-chat-composer').hidden).toBe(true);

    // Nothing to send / read-only / too long / Shift+Enter.
    const fresh = setup();
    const freshInput = fresh.q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    freshInput.value = '   ';
    freshInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    freshInput.value = 'x'.repeat(60);
    freshInput.dispatchEvent(new Event('input'));
    expect(fresh.q('.nowo-chat-composer__counter').classList.contains('is-over')).toBe(true);
    freshInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    freshInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true }));
    expect(fresh.api.send).not.toHaveBeenCalled();
    const readOnly = setup({ canSend: false });
    readOnly.q<HTMLTextAreaElement>('.nowo-chat-composer__input').value = 'x';
    readOnly.q<HTMLFormElement>('.nowo-chat-composer').dispatchEvent(new Event('submit', { cancelable: true }));
    expect(readOnly.api.send).not.toHaveBeenCalled();
  });

  it('pings typing at most every 3 s and shows who is typing', async () => {
    const { view, api, q } = setup();
    const input = q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    input.value = 'h';
    api.typing.mockRejectedValueOnce(new Error('offline'));
    input.dispatchEvent(new Event('input'));
    input.dispatchEvent(new Event('input'));
    await Promise.resolve();
    expect(api.typing).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(3_100);
    input.dispatchEvent(new Event('input'));
    expect(api.typing).toHaveBeenCalledTimes(2);
    input.value = '';
    vi.advanceTimersByTime(3_100);
    input.dispatchEvent(new Event('input'));
    expect(api.typing).toHaveBeenCalledTimes(2);

    expect(view.typingLabel()).toBeNull();
    view.typingBy('p', 'Luis');
    expect(q('.nowo-chat-typing').textContent).toBe('Luis is typing');
    view.typingBy('p', 'Luis');
    view.typingBy('q', 'Ana');
    expect(view.typingLabel()).toBe('Luis and Ana are typing');
    view.typingBy('r', 'Eva');
    expect(view.typingLabel()).toBe('Several typing');
    view.merge([msg({ authorUuid: 'p' }), msg({ authorUuid: null })], false);
    expect(view.typingLabel()).toBe('Several typing'.replace('Several typing', 'Ana and Eva are typing'));
    vi.advanceTimersByTime(5_100);
    expect(q('.nowo-chat-typing').hidden).toBe(true);
  });

  it('buzzes with feedback, handles "too soon", and shakes on receipt', async () => {
    const animate = vi.fn();
    HTMLElement.prototype.animate = animate;
    const { view, api, q } = setup();
    api.buzz.mockResolvedValueOnce(msg({ kind: 'buzz', mine: true, html: '' }));
    q<HTMLButtonElement>('.nowo-chat-window__buzz').click();
    await vi.runOnlyPendingTimersAsync();
    expect(view.element.querySelector('.nowo-chat-buzz-line')).not.toBeNull();
    api.buzz.mockRejectedValueOnce(new ChatApiError(429, 'buzz_too_soon'));
    q<HTMLButtonElement>('.nowo-chat-window__buzz').click();
    await Promise.resolve();
    await Promise.resolve();
    expect(q('.nowo-chat-notice').textContent).toBe('Too soon');
    vi.advanceTimersByTime(3_100);
    expect(q('.nowo-chat-notice').hidden).toBe(true);
    api.buzz.mockRejectedValueOnce(new Error('other'));
    q<HTMLButtonElement>('.nowo-chat-window__buzz').click();
    await vi.runOnlyPendingTimersAsync();
    expect(q<HTMLButtonElement>('.nowo-chat-window__buzz').disabled).toBe(false);

    view.shake();
    expect(view.element.dataset.buzzedAt).toBeDefined();
    expect(animate.mock.calls.length).toBeGreaterThanOrEqual(4);
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    animate.mockClear();
    view.shake();
    expect(animate).toHaveBeenCalledTimes(2); // halo + bolt, no shake
    vi.unstubAllGlobals();
    // Without Web Animations: only the marker.
    // @ts-expect-error simulate an engine without element.animate
    delete HTMLElement.prototype.animate;
    view.shake();
    const readOnlyNotice = setup({ canSend: false });
    readOnlyNotice.api.buzz.mockRejectedValueOnce(new ChatApiError(429, 'buzz_too_soon'));
  });

  it('opens the emoji picker, inserts and closes on outside click or Escape', () => {
    const { q } = setup();
    const button = q<HTMLButtonElement>('.nowo-chat-composer__emoji');
    button.click();
    expect(q('.nowo-chat-emoji')).not.toBeNull();
    expect(button.getAttribute('aria-expanded')).toBe('true');
    q<HTMLButtonElement>('.nowo-chat-emoji__item').click();
    expect(q<HTMLTextAreaElement>('.nowo-chat-composer__input').value).toContain('😀');
    expect(q('.nowo-chat-emoji')).toBeNull();

    // Reopened after a pick: a click inside the panel keeps it open (no stale outside-click listener).
    button.click();
    q('.nowo-chat-emoji').click();
    expect(q('.nowo-chat-emoji')).not.toBeNull();
    document.body.click();
    expect(q('.nowo-chat-emoji')).toBeNull();

    button.click();
    q('.nowo-chat-emoji').dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(q('.nowo-chat-emoji')).toBeNull();
    button.click();
    button.click();
    expect(q('.nowo-chat-emoji')).toBeNull();
  });

  it('resolves support threads for admins', async () => {
    const { host, q } = setup({ kind: 'support', supportStatus: 'open' }, {}, { isAgent: true });
    const resolve = q<HTMLButtonElement>('.nowo-chat-window__resolve');
    expect(resolve.hidden).toBe(false);
    resolve.click();
    await vi.runOnlyPendingTimersAsync();
    expect(host.conversationChanged).toHaveBeenCalled();
    expect(resolve.disabled).toBe(false);
  });

  it('offers resolve on waiting tickets, renders the support panel and replaces the window with another ticket', async () => {
    const ticket = { uuid: 'c1', number: null, topic: null, topicLabel: 'No topic', status: 'open' as const, statusLabel: 'Open', requester: { uuid: 'me', name: 'Me' }, assignee: null, createdAt: '2026-10-15T10:00:00Z', lastMessageAt: '2026-10-15T10:00:00Z', resolvedAt: null, readOnly: false };
    const intake = { prompt: 'topic' as const, topics: [{ key: 'billing', label: 'Billing', icon: null }], resume: null, tickets: [ticket], resumeAfterDays: 7 };
    const conversation = vi.fn().mockResolvedValue(summary({ uuid: 'old', kind: 'support' }));
    const chooseTopic = vi.fn().mockResolvedValue({ conversation: summary({ kind: 'support', ticket: { ...ticket, number: 'SUP-000001' } }), messages: [msg({ kind: 'bot', authorName: 'Support assistant', html: '<p>Ticket opened</p>' })] });
    const { view, host, q } = setup({ kind: 'support', supportStatus: 'waiting', ticket, intake }, { conversation, chooseTopic } as unknown as Partial<ApiMock>, { isAgent: true });
    expect(q<HTMLButtonElement>('.nowo-chat-window__resolve').hidden).toBe(false);
    expect(q('.nowo-chat-support').hidden).toBe(false);
    q<HTMLButtonElement>('[data-topic="billing"]').click();
    await vi.runOnlyPendingTimersAsync();
    expect(host.conversationChanged).toHaveBeenCalled();
    expect(view.element.textContent).toContain('Ticket opened');
    q<HTMLButtonElement>('.nowo-chat-support__ticket-link').click();
    await vi.runOnlyPendingTimersAsync();
    expect(host.replaceWith).toHaveBeenCalledWith('c1', expect.objectContaining({ uuid: 'old' }));
    chooseTopic.mockRejectedValueOnce(new Error('x'));
    q<HTMLButtonElement>('[data-topic="billing"]').click();
    await vi.advanceTimersByTimeAsync(10);
    expect(q('.nowo-chat-notice').hidden).toBe(false);
  });

  it('keeps the reading position: new-messages divider, jump button, older pages', async () => {
    const { view, api, host, q } = setup();
    const first = msg();
    view.merge([first], false);
    scrolled(view, 100);
    const incoming = msg();
    view.merge([incoming], true);
    expect(q('.nowo-chat-unseen')).not.toBeNull();
    expect(q<HTMLButtonElement>('.nowo-chat-jump').hidden).toBe(false);
    expect(q('.nowo-chat-jump').textContent).toContain('New');
    expect(view.element.querySelector('.nowo-chat-window__live')?.textContent).toBe('Luis: hola');
    q<HTMLButtonElement>('.nowo-chat-jump').click();

    const body = scrolled(view, 700);
    body.dispatchEvent(new Event('scroll'));
    expect(host.read).toHaveBeenLastCalledWith('c1', incoming.id);

    // Older page on scroll near the top keeps the position.
    view.hydrate({ messages: [msg()], hasMore: true });
    api.messages.mockResolvedValueOnce({ messages: [msg({ id: 0 })], hasMore: false, readUpTo: null, readers: [] });
    scrolled(view, 10).dispatchEvent(new Event('scroll'));
    await vi.runOnlyPendingTimersAsync();
    expect(api.messages).toHaveBeenCalledWith('c1', { before: expect.any(Number) });
    api.messages.mockRejectedValueOnce(new Error('x'));
    view.hydrate({ messages: [msg()], hasMore: true });
    scrolled(view, 10).dispatchEvent(new Event('scroll'));
    await vi.runOnlyPendingTimersAsync();

    // Not near the bottom but no unseen: "Latest".
    scrolled(view, 0, 2000, 300);
    view.merge([msg({ mine: true })], false);
    scrolled(view, 0, 2000, 300).dispatchEvent(new Event('scroll'));
    view.merge([], false);
    const empty = setup({}, { messages: vi.fn().mockResolvedValue({ messages: [], hasMore: true, readUpTo: null, readers: [] }) });
    await empty.view.load();
    scrolled(empty.view, 0).dispatchEvent(new Event('scroll'));
    expect(empty.api.messages).toHaveBeenCalledTimes(1);

    // Hidden tab: no read receipts sent.
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    const hidden = setup();
    hidden.view.merge([msg()], false);
    expect(hidden.host.read).not.toHaveBeenCalled();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    view.markReadLocally(5);
  });

  it('patches receipts and the seen line in place, appends new lines and reports a failed resolve', async () => {
    const dm = setup();
    const own = msg({ mine: true });
    dm.view.merge([own], false);
    dm.view.readBy('p', 'Luis', own.id);
    expect(dm.q('.nowo-chat-msg__receipt.is-read')).not.toBeNull();
    dm.view.readBy('p', 'Luis', own.id - 1);
    // Full render with a known reader: receipts computed while building the bubbles.
    dm.view.hydrate({ messages: [own, msg({ mine: true })], hasMore: false });
    expect(dm.view.element.querySelectorAll('.nowo-chat-msg__receipt.is-read')).toHaveLength(1);

    const channel = setup({ kind: 'channel' });
    const first = msg({ mine: true });
    channel.view.merge([first], false);
    channel.view.readBy('a', 'Ana', first.id);
    expect(channel.q('.nowo-chat-seen').textContent).toBe('Seen by Ana');
    channel.view.readBy('b', 'Bea', first.id);
    expect(channel.q('.nowo-chat-seen').textContent).toBe('Seen by Ana, Bea');
    // Appending (fast path) keeps / recomputes the seen line; a newer own message without readers drops it.
    const second = msg({ mine: true, createdAt: '2026-10-15T10:01:00Z' });
    channel.view.merge([second], false);
    expect(channel.q('.nowo-chat-seen')).toBeNull();
    channel.view.readBy('a', 'Ana', second.id);
    expect(channel.q('.nowo-chat-seen').textContent).toBe('Seen by Ana');
    const third = msg({ mine: true, createdAt: '2026-10-15T10:02:00Z' });
    channel.view.readBy('a', 'Ana', third.id + 1);
    channel.view.merge([third], false);
    expect(channel.q('.nowo-chat-seen').textContent).toBe('Seen by Ana');
    // Seen line inserted before a pending message.
    channel.api.send.mockReturnValueOnce(new Promise(() => undefined));
    const input = channel.q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    input.value = 'pendiente';
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await Promise.resolve();
    channel.q('.nowo-chat-seen').remove();
    channel.view.readBy('z', 'Zoe', third.id);
    expect(channel.q('.nowo-chat-seen').nextElementSibling?.classList.contains('nowo-chat-msg--pending')).toBe(true);

    const support = setup({ kind: 'support', supportStatus: 'open' }, { resolve: vi.fn().mockRejectedValue(new Error('x')) }, { isAgent: true });
    support.q<HTMLButtonElement>('.nowo-chat-window__resolve').click();
    for (let i = 0; i < 5; i += 1) {
      await Promise.resolve();
    }
    expect(support.q('.nowo-chat-notice').textContent).toBe('Error');
    support.view.focusHeader();
    expect(document.activeElement).toBe(support.q('.nowo-chat-window__identity'));
  });


  it('shows link previews the application returns, up to three per message', async () => {
    const links = '<p><a href="/issues/1">a</a> <a href="/issues/2">b</a> <a href="/issues/1">again</a> <a href="/x">c</a> <a href="/y">d</a></p>';
    const message = msg({ html: links });
    const gone = msg({ html: '', deleted: true });
    const { view, host, q } = setup({}, { messages: vi.fn().mockResolvedValue({ messages: [message, gone], hasMore: false, readUpTo: null, readers: [] }) }, { linkPreviews: true });
    (host.linkPreview as Mock).mockImplementation(async (url: string) => (url === '/issues/1' ? { title: 'Checkout crash', subtitle: 'Acme · error', badge: 'Open' } : null));
    await view.load();
    await Promise.resolve();
    await Promise.resolve();
    expect((host.linkPreview as Mock).mock.calls.map((call) => call[0])).toEqual(['/issues/1', '/issues/2', '/x']);
    const card = q<HTMLAnchorElement>('.nowo-chat-preview');
    expect(card.getAttribute('href')).toBe('/issues/1');
    expect(card.textContent).toContain('Checkout crash');
    expect(card.querySelector<HTMLElement>('[data-part="badge"]')?.textContent).toBe('Open');
    expect(view.element.querySelectorAll('.nowo-chat-preview')).toHaveLength(1);
    expect(view.element.querySelector<HTMLElement>(`[data-id="${gone.id}"] [data-part="previews"]`)?.hidden).toBe(true);
  });

  it('asks for no previews when the application has none', async () => {
    const { view, host } = setup({}, { messages: vi.fn().mockResolvedValue({ messages: [msg({ html: '<p><a href="/x">x</a></p>' })], hasMore: false, readUpTo: null, readers: [] }) });
    await view.load();
    expect(host.linkPreview).not.toHaveBeenCalled();
  });

  it('shows where a support request comes from', () => {
    const { q, view } = setup({ kind: 'support', supportStatus: 'open', supportContext: { url: '/projects/x/issues/9', label: 'Issue #9' } });
    const chip = q<HTMLAnchorElement>('.nowo-chat-window__context');
    expect(chip.hidden).toBe(false);
    expect(chip.textContent).toContain('Issue #9');
    expect(chip.getAttribute('href')).toBe('/projects/x/issues/9');
    view.applySummary(summary({ kind: 'support', supportStatus: 'open', supportContext: { label: 'Dashboard' } }));
    expect(chip.hasAttribute('href')).toBe(false);
    view.applySummary(summary({ kind: 'support', supportStatus: 'open', supportContext: { url: '/settings' } }));
    expect(chip.textContent).toContain('/settings');
    view.applySummary(summary({ kind: 'direct', supportContext: { label: 'ignored' } }));
    expect(chip.hidden).toBe(true);
    view.applySummary(summary({ kind: 'support', supportStatus: 'open' }));
    expect(chip.hidden).toBe(true);
  });

  it('fills a context chip that is not a link (custom template)', () => {
    const { q, view } = setup({ kind: 'support', supportStatus: 'open' });
    const anchor = q('.nowo-chat-window__context');
    const chip = document.createElement('span');
    chip.dataset.part = 'context';
    chip.append(...anchor.childNodes);
    anchor.replaceWith(chip);
    view.applySummary(summary({ kind: 'support', supportStatus: 'open', supportContext: { url: '/x', label: 'Issue #1' } }));
    expect(chip.hidden).toBe(false);
    expect(chip.textContent).toContain('Issue #1');
    expect(chip.hasAttribute('href')).toBe(false);
  });

  it('covers the rarer paths: known messages, halo over a shadow, read-only notice, older page loading, new day, tall and nearly full composer', async () => {
    const { view, api, q } = setup();
    // A message merged twice (another tab, then a poll) is replaced, not duplicated.
    const first = msg({ createdAt: '2026-10-15T10:00:00Z' });
    view.merge([first], false);
    view.merge([{ ...first, html: '<p>again</p>' }], false);
    expect(view.element.querySelectorAll('.nowo-chat-msg')).toHaveLength(1);
    expect(q('.nowo-chat-bubble').textContent).toBe('again');

    // A message on the next day is appended with its own day separator.
    view.merge([msg({ createdAt: '2026-10-16T10:00:00Z' })], false);
    expect(view.element.querySelectorAll('.nowo-chat-day')).toHaveLength(2);

    // The halo keeps the window's own shadow.
    const animate = vi.fn();
    HTMLElement.prototype.animate = animate;
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({ getPropertyValue: () => '', boxShadow: '0 1px 2px red' } as unknown as CSSStyleDeclaration);
    view.shake();
    expect(JSON.stringify(animate.mock.calls)).toContain(', 0 1px 2px red');
    vi.mocked(window.getComputedStyle).mockRestore();

    // While an older page loads, a re-render shows the "Loading" row.
    api.messages.mockReturnValueOnce(new Promise(() => undefined));
    view.hydrate({ messages: [msg()], hasMore: true });
    scrolled(view, 10).dispatchEvent(new Event('scroll'));
    view.hydrate({ messages: [msg()], hasMore: true });
    expect(q('.nowo-chat-thread__more').textContent).toBe('Loading');

    // The composer grows up to its maximum, then scrolls; the counter announces the last 20 characters.
    const input = q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    Object.defineProperty(input, 'scrollHeight', { configurable: true, value: 300 });
    input.value = 'x'.repeat(35);
    input.dispatchEvent(new Event('input'));
    expect(input.style.overflowY).toBe('auto');
    expect(q('[data-part="counter-announce"]').textContent).toBe('15');

    // A failed action on a read-only thread restores the read-only notice.
    const readOnly = setup({ kind: 'support', supportStatus: 'open', canSend: false }, { resolve: vi.fn().mockRejectedValue(new Error('x')) }, { isAgent: true });
    readOnly.q<HTMLButtonElement>('.nowo-chat-window__resolve').click();
    for (let i = 0; i < 5; i += 1) {
      await Promise.resolve();
    }
    expect(readOnly.q('.nowo-chat-notice').textContent).toBe('Error');
    vi.advanceTimersByTime(3_000);
    expect(readOnly.q('.nowo-chat-notice').textContent).toBe('Read only');
    expect(readOnly.q('.nowo-chat-notice').hidden).toBe(false);
  });

  it('mentions members: @ opens the list, Enter picks instead of sending, the token is sent and drafts keep it', async () => {
    const { view, api, host, q } = setup({ kind: 'channel' }, {}, {});
    (host.draft as Mock).mockReturnValue('');
    const input = q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    input.value = 'hi @lu';
    input.setSelectionRange(6, 6);
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(200);
    expect(api.members).toHaveBeenCalledWith('c1', 'lu');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }));
    expect(api.send).not.toHaveBeenCalled();
    expect(input.value).toBe('hi @Luis ');
    await vi.advanceTimersByTimeAsync(600);
    expect(host.saveDraft).toHaveBeenLastCalledWith('c1', 'hi @[Luis](u-luis) ');

    api.send.mockReturnValueOnce(new Promise(() => undefined));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }));
    expect(api.send).toHaveBeenCalledWith('c1', 'hi @[Luis](u-luis)');
    expect(view.element.querySelector('.nowo-chat-msg--pending .nowo-chat-bubble')?.textContent).toBe('hi @Luis');

    // A restored draft shows names, not tokens.
    expect(setupWithDraft('@[Ana](u-ana) hola').q<HTMLTextAreaElement>('.nowo-chat-composer__input').value).toBe('@Ana hola');
  });

  it('references items: / opens the commands, a picked task is sent as a token and drafts keep it', async () => {
    const { view, api, host, q } = setup({ kind: 'channel' }, {}, { references: true });
    (host.draft as Mock).mockReturnValue('');
    const input = q<HTMLTextAreaElement>('.nowo-chat-composer__input');
    input.value = '/';
    input.setSelectionRange(1, 1);
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(200);
    expect(api.references).toHaveBeenCalledWith('c1', '', '');
    expect(view.element.querySelector('.nowo-chat-ref-list [data-command="task"] svg')).not.toBeNull();
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }));
    expect(input.value).toBe('/task ');
    await vi.advanceTimersByTimeAsync(200);
    expect(api.references).toHaveBeenLastCalledWith('c1', 'task', '');
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }));
    expect(api.send).not.toHaveBeenCalled();
    expect(input.value).toBe('#Deploy ');
    await vi.advanceTimersByTimeAsync(600);
    expect(host.saveDraft).toHaveBeenLastCalledWith('c1', '#[Deploy](task:42) ');

    api.send.mockReturnValueOnce(new Promise(() => undefined));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', cancelable: true }));
    expect(api.send).toHaveBeenCalledWith('c1', '#[Deploy](task:42)');
    expect(view.element.querySelector('.nowo-chat-msg--pending .nowo-chat-bubble')?.textContent).toBe('#Deploy');

    // A restored draft shows titles, not tokens; without providers the picker is not bound.
    const restored = setupWithDraft('#[Deploy](task:42) hola');
    expect(restored.q<HTMLTextAreaElement>('.nowo-chat-composer__input').value).toBe('#[Deploy](task:42) hola');
    expect(restored.view.element.querySelector('.nowo-chat-ref-list')).toBeNull();
    const enabled = setup({}, {}, { references: true });
    enabled.view.element.remove();
    (enabled.host.draft as Mock).mockReturnValue('#[Deploy](task:42) hola');
    const view2 = new ThreadView(enabled.host, summary());
    expect(view2.element.querySelector<HTMLTextAreaElement>('.nowo-chat-composer__input')?.value).toBe('#Deploy hola');
  });

  it('renders the icons of reference cards in a message', async () => {
    const { view } = setup();
    view.merge([msg({ html: '<p><a class="nowo-chat-ref" href="/t/1"><span class="nowo-chat-ref__icon" data-icon="task" data-icon-size="14"></span><span class="nowo-chat-ref__title">Deploy</span></a></p>' })], false);
    expect(view.element.querySelector('.nowo-chat-ref__icon svg')?.getAttribute('width')).toBe('14');
  });

  it('highlights messages that mention the viewer and marks their chips', async () => {
    const chip = (uuid: string, name: string) => `<p><span class="nowo-chat-mention" data-mention="${uuid}">@${name}</span> hi</p>`;
    const forMe = msg({ html: chip('me', 'Me'), mentions: [{ uuid: 'me', name: 'Me' }] });
    const forOther = msg({ html: chip('x', 'X'), mentions: [{ uuid: 'x', name: 'X' }] });
    const mine = msg({ mine: true, authorUuid: 'me', html: chip('x', 'X'), mentions: [{ uuid: 'me', name: 'Me' }] });
    const { view } = setup({ kind: 'channel' }, { messages: vi.fn().mockResolvedValue({ messages: [forMe, forOther, mine], hasMore: false, readUpTo: null, readers: [] }) });
    await view.load();
    const line = (id: number) => view.element.querySelector<HTMLElement>(`[data-id="${id}"]`) as HTMLElement;
    expect(line(forMe.id).classList.contains('nowo-chat-msg--mentioned')).toBe(true);
    expect(line(forMe.id).querySelector('.nowo-chat-mention')?.classList.contains('is-me')).toBe(true);
    expect(line(forOther.id).classList.contains('nowo-chat-msg--mentioned')).toBe(false);
    expect(line(mine.id).classList.contains('nowo-chat-msg--mentioned')).toBe(false);
  });

    describe('edit, delete and reactions', () => {
    const now = new Date('2026-10-15T10:05:00Z');
    async function loaded(messages: ChatMessage[], over: Partial<ConversationSummary> = {}, configOver: Partial<ChatConfig> = {}) {
      vi.setSystemTime(now);
      const ctx = setup(over, { messages: vi.fn().mockResolvedValue({ messages, hasMore: false, readUpTo: null, readers: [] }) }, configOver);
      await ctx.view.load();
      const article = (id: number) => ctx.view.element.querySelector<HTMLElement>(`[data-id="${id}"]`) as HTMLElement;
      const action = (id: number, name: string) => article(id).querySelector<HTMLButtonElement>(`[data-part="${name}"]`) as HTMLButtonElement;
      return { ...ctx, article, action };
    }

    it('offers the actions that apply to each message', async () => {
      const mine = msg({ mine: true, authorUuid: 'me', uuid: 'mine' });
      const old = msg({ mine: true, authorUuid: 'me', createdAt: '2026-10-15T09:00:00Z' });
      const theirs = msg({ uuid: 'theirs' });
      const gone = msg({ deleted: true });
      const { action, article } = await loaded([old, mine, theirs, gone]);
      expect(action(mine.id, 'edit').hidden).toBe(false);
      expect(action(mine.id, 'delete').hidden).toBe(false);
      expect(action(old.id, 'edit').hidden).toBe(true);
      expect(action(old.id, 'delete').hidden).toBe(true);
      expect(action(theirs.id, 'edit').hidden).toBe(true);
      expect(action(theirs.id, 'react').hidden).toBe(false);
      expect(article(gone.id).querySelector<HTMLElement>('[data-part="actions"]')?.hidden).toBe(true);
    });

    it('never offers deleting someone else\'s message, not even to agents', async () => {
      const theirs = msg();
      const channel = await loaded([theirs], { kind: 'channel' }, { isAgent: true });
      expect(channel.action(theirs.id, 'delete').hidden).toBe(true);
      document.body.replaceChildren();
      const direct = await loaded([msg({ id: theirs.id + 100 })], {}, { isAgent: true, reactions: [] });
      expect(direct.action(theirs.id + 100, 'delete').hidden).toBe(true);
      expect(direct.action(theirs.id + 100, 'react').hidden).toBe(true);
    });

    it('reacts from the quick bar and toggles existing chips', async () => {
      const theirs = msg({ uuid: 'r1', reactions: { '🎉': ['p'] } });
      const { action, article, api, host, view } = await loaded([theirs]);
      expect(article(theirs.id).querySelector('.nowo-chat-reaction')?.getAttribute('aria-pressed')).toBe('false');
      api.react.mockResolvedValue({ '🎉': ['p'], '👍': ['me'] });
      action(theirs.id, 'react').click();
      const bar = view.element.querySelector('.nowo-chat-reaction-bar') as HTMLElement;
      expect([...bar.querySelectorAll('button')].map((b) => b.textContent)).toEqual(['👍', '🎉']);
      (bar.querySelector('button') as HTMLButtonElement).click();
      await Promise.resolve();
      await Promise.resolve();
      expect(api.react).toHaveBeenCalledWith('c1', 'r1', '👍');
      const chips = [...article(theirs.id).querySelectorAll<HTMLButtonElement>('.nowo-chat-reaction')];
      expect(chips.map((chip) => chip.getAttribute('aria-label'))).toEqual(['🎉 1', '👍 1']);
      expect(chips[1]?.classList.contains('is-mine')).toBe(true);
      expect(host.messagesChanged).toHaveBeenCalled();

      api.react.mockRejectedValueOnce(new Error('x'));
      chips[0]?.click();
      await Promise.resolve();
      await Promise.resolve();
      expect(view.element.querySelector('.nowo-chat-notice')?.textContent).toBe('Error');
    });

    it('closes the reaction bar with Escape, a second click or an outside click', async () => {
      const theirs = msg();
      const { action, view } = await loaded([theirs]);
      const bar = () => view.element.querySelector('.nowo-chat-reaction-bar');
      action(theirs.id, 'react').click();
      bar()?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      expect(bar()).toBeNull();
      expect(document.activeElement).toBe(action(theirs.id, 'react'));
      action(theirs.id, 'react').click();
      bar()?.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
      expect(bar()).not.toBeNull();
      action(theirs.id, 'react').click();
      expect(bar()).toBeNull();
      action(theirs.id, 'react').click();
      (bar() as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }));
      expect(bar()).not.toBeNull();
      document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      expect(bar()).toBeNull();
    });

    it('edits in place: Enter saves, Escape and Cancel restore, errors restore with a notice', async () => {
      const mine = msg({ mine: true, authorUuid: 'me', uuid: 'e1', html: '<p>one<br>two</p><ul><li>three</li></ul>' });
      const { action, article, api, view } = await loaded([mine]);
      action(mine.id, 'edit').click();
      const input = () => article(mine.id).querySelector<HTMLTextAreaElement>('.nowo-chat-edit__input') as HTMLTextAreaElement;
      expect(input().value).toBe('one\ntwo\nthree');
      expect(document.activeElement).toBe(input());
      input().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      expect(article(mine.id).querySelector('.nowo-chat-edit')).toBeNull();

      action(mine.id, 'edit').click();
      (article(mine.id).querySelector('[data-part="cancel"]') as HTMLButtonElement).click();
      expect(article(mine.id).querySelector('.nowo-chat-bubble')?.innerHTML).toContain('one');

      action(mine.id, 'edit').click();
      input().value = '   ';
      input().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      expect(api.edit).not.toHaveBeenCalled();
      input().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true }));
      input().dispatchEvent(new KeyboardEvent('keydown', { key: 'x', bubbles: true }));

      api.edit.mockResolvedValueOnce({ ...mine, html: '<p>uno</p>', edited: true });
      input().value = 'uno';
      input().dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      await Promise.resolve();
      await Promise.resolve();
      expect(api.edit).toHaveBeenCalledWith('c1', 'e1', 'uno');
      expect(article(mine.id).querySelector('.nowo-chat-bubble')?.innerHTML).toBe('<p>uno</p>');
      expect(article(mine.id).querySelector<HTMLElement>('[data-part="edited"]')?.hidden).toBe(false);

      action(mine.id, 'edit').click();
      api.edit.mockRejectedValueOnce(new Error('too late'));
      input().value = 'dos';
      (article(mine.id).querySelector('form.nowo-chat-edit') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
      await Promise.resolve();
      await Promise.resolve();
      expect(article(mine.id).querySelector('.nowo-chat-edit')).toBeNull();
      expect(view.element.querySelector('.nowo-chat-notice')?.textContent).toBe('Error');
    });

    it('keeps the mentions of an edited message as tokens and offers the list in the editor', async () => {
      const mine = msg({ mine: true, authorUuid: 'me', uuid: 'm1', html: '<p>hola <span class="nowo-chat-mention" data-mention="u-luis">@Luis</span></p>', mentions: [{ uuid: 'u-luis', name: 'Luis' }] });
      const { action, article, api } = await loaded([mine]);
      action(mine.id, 'edit').click();
      const input = article(mine.id).querySelector<HTMLTextAreaElement>('.nowo-chat-edit__input') as HTMLTextAreaElement;
      expect(input.value).toBe('hola @Luis');
      expect(input.getAttribute('aria-autocomplete')).toBe('list');
      api.edit.mockReturnValueOnce(new Promise(() => undefined));
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      expect(api.edit).toHaveBeenCalledWith('c1', 'm1', 'hola @[Luis](u-luis)');

      const other = msg({ mine: true, authorUuid: 'me', uuid: 'm2' });
      const second = await loaded([other]);
      second.action(other.id, 'edit').click();
      const box = second.article(other.id).querySelector<HTMLTextAreaElement>('.nowo-chat-edit__input') as HTMLTextAreaElement;
      box.value = '@lu';
      box.setSelectionRange(3, 3);
      box.dispatchEvent(new Event('input'));
      await vi.advanceTimersByTimeAsync(200);
      expect(second.api.members).toHaveBeenCalledWith('c1', 'lu');
      box.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
      expect(second.article(other.id).querySelector('.nowo-chat-edit')).not.toBeNull();
    });

    it('keeps the readable references of an edited message as tokens; a restricted chip stays text', async () => {
      const card = '<a class="nowo-chat-ref" href="/t/42"><span class="nowo-chat-ref__type">Task</span><span class="nowo-chat-ref__title">Deploy</span></a>';
      const hidden = '<span class="nowo-chat-ref nowo-chat-ref--restricted"><span class="nowo-chat-ref__title">Hidden</span></span>';
      const mine = msg({ mine: true, authorUuid: 'me', uuid: 'r1', html: `<p>see ${card} ${hidden}</p>`, references: [{ type: 'task', id: '42', title: 'Deploy', url: '/t/42', subtitle: '', badge: '', tone: 'neutral' }] });
      const plainOne = msg({ mine: true, authorUuid: 'me', uuid: 'r2', html: '<p>x</p>' });
      const { action, article, api } = await loaded([mine, plainOne], {}, { references: true });
      action(mine.id, 'edit').click();
      const input = article(mine.id).querySelector<HTMLTextAreaElement>('.nowo-chat-edit__input') as HTMLTextAreaElement;
      expect(input.value).toBe('see #Deploy [Hidden]');
      api.edit.mockReturnValueOnce(new Promise(() => undefined));
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      expect(api.edit).toHaveBeenCalledWith('c1', 'r1', 'see #[Deploy](task:42) [Hidden]');

      action(plainOne.id, 'edit').click();
      const box = article(plainOne.id).querySelector<HTMLTextAreaElement>('.nowo-chat-edit__input') as HTMLTextAreaElement;
      expect(box.value).toBe('x');
    });

    it('cancels an edit whose message left the thread (cache repaint) with the original text', async () => {
      const mine = msg({ mine: true, authorUuid: 'me', uuid: 'e2', html: '<p>original</p>' });
      const { action, article, view } = await loaded([mine]);
      const editing = article(mine.id);
      action(mine.id, 'edit').click();
      view.hydrate({ messages: [msg()], hasMore: false });
      const log = view.element.querySelector('[data-part="log"]') as HTMLElement;
      log.append(editing);
      (editing.querySelector('[data-part="cancel"]') as HTMLButtonElement).click();
      expect(log.querySelector('.nowo-chat-edit')).toBeNull();
      expect(log.lastElementChild?.querySelector('.nowo-chat-bubble')?.textContent).toBe('original');
    });

    it('deletes after confirming; cancel and errors restore the actions', async () => {
      const mine = msg({ mine: true, authorUuid: 'me', uuid: 'd1' });
      const { action, article, api, view } = await loaded([mine]);
      action(mine.id, 'delete').click();
      const confirm = () => article(mine.id).querySelector('.nowo-chat-confirm') as HTMLElement;
      expect(document.activeElement).toBe(confirm().querySelector('[data-part="cancel"]'));
      (confirm().querySelector('[data-part="cancel"]') as HTMLButtonElement).click();
      expect(confirm()).toBeNull();
      expect(article(mine.id).querySelector<HTMLElement>('[data-part="actions"]')?.hidden).toBe(false);

      api.remove.mockRejectedValueOnce(new Error('x'));
      action(mine.id, 'delete').click();
      (confirm().querySelector('[data-part="confirm"]') as HTMLButtonElement).click();
      await Promise.resolve();
      await Promise.resolve();
      expect(confirm()).toBeNull();
      expect(view.element.querySelector('.nowo-chat-notice')?.textContent).toBe('Error');

      api.remove.mockResolvedValueOnce({ ...mine, deleted: true, html: '' });
      action(mine.id, 'delete').click();
      (confirm().querySelector('[data-part="confirm"]') as HTMLButtonElement).click();
      await Promise.resolve();
      await Promise.resolve();
      const tombstone = article(mine.id).querySelector('.nowo-chat-bubble--deleted');
      expect(tombstone?.textContent).toBe('You deleted');
      expect(tombstone?.querySelector('svg')).not.toBeNull();
      expect(article(mine.id).classList.contains('is-confirming')).toBe(false);
    });

    it('tapping a message shows its actions, one message at a time', async () => {
      const first = msg({ html: '<p>uno <a href="https://x.test">link</a></p>' });
      const second = msg();
      const { article } = await loaded([first, second]);
      (article(first.id).querySelector('.nowo-chat-bubble') as HTMLElement).click();
      expect(article(first.id).classList.contains('is-active')).toBe(true);
      (article(second.id).querySelector('.nowo-chat-bubble') as HTMLElement).click();
      expect(article(first.id).classList.contains('is-active')).toBe(false);
      expect(article(second.id).classList.contains('is-active')).toBe(true);
      (article(second.id).querySelector('.nowo-chat-bubble') as HTMLElement).click();
      expect(article(second.id).classList.contains('is-active')).toBe(false);
      (article(first.id).querySelector('a') as HTMLAnchorElement).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      // Links keep their own behaviour.
      expect(article(first.id).classList.contains('is-active')).toBe(false);
    });

        it('applies updates from elsewhere, but not over an open editor; unknown ids are ignored', async () => {
      const mine = msg({ mine: true, authorUuid: 'me' });
      const { action, article, view, host } = await loaded([mine]);
      view.update({ ...mine, id: 99_999 });
      view.update({ ...mine, html: '<p>edited elsewhere</p>', mine: false });
      expect(article(mine.id).querySelector('.nowo-chat-bubble')?.textContent).toBe('edited elsewhere');
      expect(article(mine.id).classList.contains('nowo-chat-msg--mine')).toBe(true);
      action(mine.id, 'edit').click();
      view.update({ ...mine, html: '<p>again</p>' });
      expect(article(mine.id).querySelector('.nowo-chat-edit')).not.toBeNull();
      view.update({ ...mine, deleted: true, html: '' });
      expect(article(mine.id).querySelector('.nowo-chat-edit')).toBeNull();
      expect(host.messagesChanged).toHaveBeenCalled();
    });
  });
});


describe('plain', () => {
  it('keeps one line for previews and paragraphs for the editor', () => {
    const html = '<p>It supports:</p><ul><li>dms</li><li>channels<br>and more</li></ul>';
    expect(plain(html)).toBe('It supports: dms channels and more');
    expect(plain(html, true)).toBe('It supports:\ndms\nchannels\nand more');
    expect(plain('<p><span class="nowo-chat-emoji" aria-hidden="true">🎫</span>Ticket opened</p>')).toBe('Ticket opened');
    expect(plain('<p>see <a class="nowo-chat-ref"><span>Task</span><span class="nowo-chat-ref__title">Deploy</span></a>, <span class="nowo-chat-ref nowo-chat-ref--restricted"><span class="nowo-chat-ref__title">Hidden</span></span> <span class="nowo-chat-ref"></span></p>')).toBe('see #Deploy, [Hidden] #');
  });
});
