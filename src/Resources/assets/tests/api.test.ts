import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatApi, ChatApiError } from '../src/api';
import type { ChatConfig } from '../src/types';

const config = {
  csrf: 'tok',
  endpoints: {
    conversations: '/c',
    contacts: '/contacts',
    direct: '/direct',
    channel: '/channel',
    message: '/conv/__uuid__/messages/__message__',
    reactions: '/conv/__uuid__/messages/__message__/reactions',
    linkPreviews: '/previews',
    page: '/chat/__uuid__',
    support: '/support',
    conversation: '/conv/__uuid__',
    messages: '/conv/__uuid__/messages',
    members: '/conv/__uuid__/members',
    references: '/conv/__uuid__/references',
    read: '/conv/__uuid__/read',
    typing: '/conv/__uuid__/typing',
    buzz: '/conv/__uuid__/buzz',
    resolve: '/conv/__uuid__/resolve',
    realtime: '/rt',
    presence: '/presence',
    status: '/status',
    settings: '/settings',
    supportTickets: '/support/tickets',
    supportTopic: '/support/__uuid__/topic',
    supportResume: '/support/__uuid__/resume',
    supportStatus: '/support/__uuid__/status',
    supportAssign: '/support/__uuid__/assign',
  },
} as unknown as ChatConfig;

function respond(body: unknown, ok = true, status = 200) {
  return vi.fn().mockResolvedValue({ ok, status, json: () => (body instanceof Error ? Promise.reject(body) : Promise.resolve(body)) });
}

describe('chat api client', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('calls the support ticket endpoints', async () => {
    const fetchMock = respond({ tickets: [{ uuid: 't' }], conversation: { uuid: 'x' }, messages: [{ id: 1 }] });
    vi.stubGlobal('fetch', fetchMock);
    const api = new ChatApi(config);

    expect(await api.supportTickets()).toEqual([{ uuid: 't' }]);
    expect(fetchMock.mock.lastCall?.[0]).toBe('/support/tickets');
    expect(await api.chooseTopic('d 1', 'billing')).toEqual({ tickets: [{ uuid: 't' }], conversation: { uuid: 'x' }, messages: [{ id: 1 }] });
    expect(fetchMock).toHaveBeenLastCalledWith('/support/d%201/topic', expect.objectContaining({ method: 'POST', body: '{"topic":"billing"}' }));
    expect(await api.resumeTicket('t', 'd')).toEqual({ uuid: 'x' });
    expect(fetchMock).toHaveBeenLastCalledWith('/support/t/resume', expect.objectContaining({ body: '{"draft":"d"}' }));
    await api.resumeTicket('t', null);
    expect(fetchMock.mock.lastCall?.[1]).toMatchObject({ body: '{}' });
    expect(await api.setTicketStatus('t', 'open')).toEqual({ uuid: 'x' });
    expect(fetchMock).toHaveBeenLastCalledWith('/support/t/status', expect.objectContaining({ body: '{"status":"open"}' }));
    expect(await api.assignTicket('t', null)).toEqual({ uuid: 'x' });
    expect(fetchMock).toHaveBeenLastCalledWith('/support/t/assign', expect.objectContaining({ body: '{"assignee":null}' }));
  });

  it('calls every endpoint with CSRF on writes and unwraps payloads', async () => {
    const fetchMock = respond({ conversations: [], unreadTotal: 0, conversation: { uuid: 'x' }, contacts: [1], message: { id: 1 }, online: ['u'], messages: [], reactions: { '👍': ['me'] } });
    vi.stubGlobal('fetch', fetchMock);
    const api = new ChatApi(config);

    expect(await api.conversations()).toMatchObject({ unreadTotal: 0 });
    expect(await api.conversation('a b')).toEqual({ uuid: 'x' });
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/a%20b', expect.objectContaining({ credentials: 'same-origin' }));
    expect(await api.contacts('lu cía')).toEqual([1]);
    expect(fetchMock).toHaveBeenLastCalledWith('/contacts?q=lu%20c%C3%ADa', expect.anything());
    fetchMock.mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ members: [{ uuid: 'u' }] }) });
    expect(await api.members('c 1', 'lu')).toEqual([{ uuid: 'u' }]);
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/c%201/members?q=lu', expect.anything());
    fetchMock.mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ types: [], references: [{ id: '1' }] }) });
    expect(await api.references('c 1', 'task', 'de ploy')).toEqual({ types: [], references: [{ id: '1' }] });
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/c%201/references?type=task&q=de%20ploy', expect.anything());
    fetchMock.mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ message: { id: 7 } }) });
    expect(await api.message('c1', 'm 1')).toEqual({ id: 7 });
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/c1/messages/m%201', expect.anything());
    expect(await api.openDirect('u')).toEqual({ uuid: 'x' });
    expect(fetchMock.mock.lastCall?.[1]).toMatchObject({ method: 'POST', body: '{"userUuid":"u"}', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'tok' }) });
    expect(await api.openChannel('project:p')).toEqual({ uuid: 'x' });
    expect(fetchMock.mock.lastCall?.[1]).toMatchObject({ body: '{"channelKey":"project:p"}' });
    expect(await api.edit('c', 'm 1', 'new')).toEqual({ id: 1 });
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/c/messages/m%201', expect.objectContaining({ method: 'PATCH', body: '{"body":"new"}' }));
    expect(await api.remove('c', 'm')).toEqual({ id: 1 });
    expect(fetchMock.mock.lastCall?.[1]).toMatchObject({ method: 'DELETE' });
    expect(await api.react('c', 'm', '👍')).toEqual({ '👍': ['me'] });
    expect(fetchMock.mock.lastCall?.[0]).toBe('/conv/c/messages/m/reactions');
    expect(await api.openSupport()).toEqual({ uuid: 'x' });
    expect(fetchMock.mock.lastCall?.[1]).toMatchObject({ body: '{}' });
    await api.openSupport({ url: '/x', label: 'X' });
    expect(fetchMock.mock.lastCall?.[1]).toMatchObject({ body: '{"context":{"url":"/x","label":"X"}}' });
    await api.messages('c');
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/c/messages', expect.anything());
    await api.messages('c', { before: 5, after: 2 });
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/c/messages?before=5&after=2', expect.anything());
    expect(await api.send('c', 'hola')).toEqual({ id: 1 });
    await api.markRead('c', 3);
    await api.typing('c');
    expect(await api.buzz('c')).toEqual({ id: 1 });
    expect(await api.resolve('c')).toEqual({ uuid: 'x' });
    await api.realtime();
    // A server before 1.10 answers only `online`.
    expect(await api.presence(true)).toEqual({ online: ['u'], statuses: {}, me: null });
    const own = { status: 'busy', text: 'Lunch', emoji: '🍝', expiresAt: null };
    vi.stubGlobal('fetch', respond({ online: [], statuses: { p: { status: 'away', text: null, emoji: null } }, me: own, status: own, prefs: { sound: 'bell' } }));
    expect(await api.presence(false, true)).toEqual({ online: [], statuses: { p: { status: 'away', text: null, emoji: null } }, me: own });
    expect(JSON.parse(String((vi.mocked(fetch).mock.calls.at(-1)?.[1] as RequestInit).body))).toEqual({ visible: false, idle: true });
    expect(await api.setStatus({ status: 'busy', text: 'Lunch', emoji: '🍝', clearAfter: '1h' })).toEqual(own);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/status', expect.objectContaining({ method: 'POST' }));
    expect(await api.saveSetting('soundBuzz', 'bell')).toEqual({ sound: 'bell' });
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/settings', expect.objectContaining({ method: 'POST' }));
    // Without the 1.10 endpoints the calls go nowhere useful (the dock never offers them then).
    const old = new ChatApi({ ...config, endpoints: { ...config.endpoints, status: undefined, settings: undefined } } as unknown as ChatConfig);
    await old.setStatus({ status: 'available' });
    await old.saveSetting('volume', 5);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('', expect.anything());

    // Closing conversations and pins (1.10).
    const full = new ChatApi({ ...config, endpoints: { ...config.endpoints, archive: '/c/__uuid__/archive', archiveRead: '/c/archive-read', pins: '/c/__uuid__/pins', pin: '/c/__uuid__/m/__message__/pin' } } as unknown as ChatConfig);
    vi.stubGlobal('fetch', respond({ conversations: [], unreadTotal: 0, archived: 3, pins: [], canPin: true }));
    await full.conversations(true);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/c?archived=1', expect.anything());
    await full.archive('x', true);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/c/x/archive', expect.objectContaining({ method: 'POST' }));
    await full.archive('x', false);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/c/x/archive', expect.objectContaining({ method: 'DELETE' }));
    expect(await full.archiveRead()).toBe(3);
    expect(await full.pins('x')).toMatchObject({ canPin: true });
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/c/x/pins', expect.anything());
    await full.pin('x', 'm', true);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/c/x/m/m/pin', expect.objectContaining({ method: 'POST' }));
    await full.pin('x', 'm', false);
    expect(vi.mocked(fetch)).toHaveBeenLastCalledWith('/c/x/m/m/pin', expect.objectContaining({ method: 'DELETE' }));
    await old.archiveRead();
    await old.pins('x');
    vi.stubGlobal('fetch', respond({ previews: { '/a b': { title: 'A', subtitle: '', badge: '' } } }));
    expect(await api.linkPreviews(['/a b', '/c'])).toEqual({ '/a b': { title: 'A', subtitle: '', badge: '' } });
    vi.stubGlobal('fetch', respond({ previews: [] }));
    expect(await api.linkPreviews(['/z'])).toEqual({});
  });

  it('raises a typed error with the server code, or "error" without a body', async () => {
    vi.stubGlobal('fetch', respond({ error: 'rate_limited' }, false, 429));
    await expect(new ChatApi(config).send('c', 'x')).rejects.toMatchObject({ status: 429, code: 'rate_limited' });
    vi.stubGlobal('fetch', respond(new Error('no json'), false, 500));
    const error = await new ChatApi(config).conversations().catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ChatApiError);
    expect(error).toMatchObject({ status: 500, code: 'error' });

    // An expired session clears the chat data of this browser.
    window.localStorage.setItem('nowo-chat.draft', 'secret draft');
    window.localStorage.setItem('other-app', 'kept');
    vi.stubGlobal('fetch', respond({ error: 'unauthorized' }, false, 401));
    await expect(new ChatApi(config).conversations()).rejects.toMatchObject({ status: 401 });
    expect(window.localStorage.getItem('nowo-chat.draft')).toBeNull();
    expect(window.localStorage.getItem('other-app')).toBe('kept');
    window.localStorage.removeItem('other-app');

    // A login redirect (firewall entry point) counts as an expired session.
    window.localStorage.setItem('nowo-chat.layout', 'x');
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, status: 200, redirected: true, json: () => Promise.reject(new Error('html')) }));
    await expect(new ChatApi(config).conversations()).rejects.toMatchObject({ status: 401, code: 'unauthorized' });
    expect(window.localStorage.getItem('nowo-chat.layout')).toBeNull();
    vi.stubGlobal('fetch', respond(['list'], false, 400));
    await expect(new ChatApi(config).conversations()).rejects.toMatchObject({ code: 'error' });
  });
});
