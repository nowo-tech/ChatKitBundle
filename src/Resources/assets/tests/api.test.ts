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
    read: '/conv/__uuid__/read',
    typing: '/conv/__uuid__/typing',
    buzz: '/conv/__uuid__/buzz',
    resolve: '/conv/__uuid__/resolve',
    realtime: '/rt',
    presence: '/presence',
  },
} as unknown as ChatConfig;

function respond(body: unknown, ok = true, status = 200) {
  return vi.fn().mockResolvedValue({ ok, status, json: () => (body instanceof Error ? Promise.reject(body) : Promise.resolve(body)) });
}

describe('chat api client', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('calls every endpoint with CSRF on writes and unwraps payloads', async () => {
    const fetchMock = respond({ conversations: [], unreadTotal: 0, conversation: { uuid: 'x' }, contacts: [1], message: { id: 1 }, online: ['u'], messages: [], reactions: { '👍': ['me'] } });
    vi.stubGlobal('fetch', fetchMock);
    const api = new ChatApi(config);

    expect(await api.conversations()).toMatchObject({ unreadTotal: 0 });
    expect(await api.conversation('a b')).toEqual({ uuid: 'x' });
    expect(fetchMock).toHaveBeenLastCalledWith('/conv/a%20b', expect.objectContaining({ credentials: 'same-origin' }));
    expect(await api.contacts('lu cía')).toEqual([1]);
    expect(fetchMock).toHaveBeenLastCalledWith('/contacts?q=lu%20c%C3%ADa', expect.anything());
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
    expect(await api.presence(true)).toEqual(['u']);
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
