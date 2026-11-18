import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatApiError } from '../src/api';
import { ThreadPanel, type ThreadApi } from '../src/thread-panel';
import type { ChatMessage, ThreadPage } from '../src/types';

const i18n = { thread_title: 'Thread', thread_older: 'Earlier', thread_replies: 'Replies', thread_reply_label: 'Reply', thread_one_reply: '1 reply', thread_replies_count: '%count% replies', send: 'Send', close: 'Close', edited: 'edited', deleted_message: 'Deleted', deleted_message_mine: 'You deleted', error: 'Error', too_long: 'Too long' };
const message = (id: number, over: Partial<ChatMessage> = {}): ChatMessage => ({
  id,
  uuid: `m-${id}`,
  authorUuid: 'u',
  authorName: 'Ana',
  authorInitials: 'A',
  mine: false,
  kind: 'text',
  html: `<p>text ${id}</p>`,
  createdAt: '2026-10-15T10:00:00Z',
  edited: false,
  deleted: false,
  moderated: false,
  ...over,
});
const root = message(1, { thread: { count: 2, lastReplyAt: '2026-10-15T10:05:00Z' } });
const page = (over: Partial<ThreadPage> = {}): ThreadPage => ({ root, replies: [message(5, { threadRootUuid: 'm-1' }), message(7, { threadRootUuid: 'm-1', edited: true })], hasMore: true, replyCount: 2, readUpTo: 5, following: true, ...over });

const api = (over: Partial<ThreadApi> = {}): ThreadApi => ({
  thread: vi.fn().mockResolvedValue(page()),
  threadReply: vi.fn().mockResolvedValue({ message: message(9, { threadRootUuid: 'm-1' }), root: { ...root, thread: { count: 3, lastReplyAt: null } } }),
  threadRead: vi.fn().mockResolvedValue(undefined),
  ...over,
});

const replies = (panel: ThreadPanel): string[] => [...panel.dialog.querySelectorAll('[data-part="thread-replies"] [data-part="thread-reply"]')].map((row) => (row as HTMLElement).dataset.id ?? '');

describe('thread panel (1.36)', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('loads the thread, marks it read, loads older replies and closes', async () => {
    const client = api();
    const closed = vi.fn();
    const opener = document.createElement('button');
    document.body.append(opener);
    const panel = new ThreadPanel({ api: client, i18n, locale: 'en', conversationUuid: 'c', message: root, canSend: true, maxLength: 100, returnTo: opener, closed });
    await vi.waitFor(() => expect(replies(panel)).toEqual(['5', '7']));
    expect(panel.dialog.querySelector('[data-part="thread-count"]')?.textContent).toBe('2 replies');
    expect(client.threadRead).toHaveBeenCalledWith('c', 'm-1', 7);
    expect(panel.dialog.querySelector('[data-part="thread-replies"]')?.textContent).toContain('edited');
    const older = panel.dialog.querySelector('[data-part="thread-older"]') as HTMLButtonElement;
    expect(older.hidden).toBe(false);
    vi.mocked(client.thread).mockResolvedValueOnce(page({ replies: [message(3, { threadRootUuid: 'm-1', deleted: true, mine: true })], hasMore: false, root: { ...root, thread: { count: 1, lastReplyAt: null } } }));
    older.click();
    await vi.waitFor(() => expect(replies(panel)).toEqual(['3', '5', '7']));
    expect(older.hidden).toBe(true);
    expect(panel.dialog.querySelector('[data-part="thread-count"]')?.textContent).toBe('1 reply');
    expect(panel.dialog.querySelector('[data-id="3"]')?.textContent).toContain('You deleted');
    expect(client.thread).toHaveBeenLastCalledWith('c', 'm-1', { before: 5 });
    panel.close();
    expect(closed).toHaveBeenCalled();
    expect(document.activeElement).toBe(opener);
  });

  it('opens the root thread of a reply, takes realtime replies of its thread only, and repaints the root', async () => {
    const client = api({ thread: vi.fn().mockResolvedValue(page({ replies: [], hasMore: false, readUpTo: null })) });
    const panel = new ThreadPanel({ api: client, i18n, locale: 'en', conversationUuid: 'c', message: message(5, { threadRootUuid: 'm-1', deleted: true }), canSend: false, maxLength: 100, returnTo: null });
    expect(panel.rootUuid).toBe('m-1');
    await vi.waitFor(() => expect(client.thread).toHaveBeenCalledWith('c', 'm-1', {}));
    expect(client.threadRead).not.toHaveBeenCalled();
    panel.receive(message(8, { threadRootUuid: 'other' }));
    expect(replies(panel)).toEqual([]);
    panel.receive(message(8, { threadRootUuid: 'm-1' }));
    expect(replies(panel)).toEqual(['8']);
    expect(client.threadRead).toHaveBeenCalledWith('c', 'm-1', 8);
    panel.updateRoot({ ...root, uuid: 'other' });
    panel.updateRoot({ ...root, html: '<p>edited root</p>' });
    expect(panel.dialog.querySelector('[data-part="thread-root"]')?.textContent).toContain('edited root');
    expect((panel.dialog.querySelector('.nowo-chat-thread-panel__composer') as HTMLElement).hidden).toBe(true);
  });

  it('sends a reply once per text (the key survives a failed try)', async () => {
    const reply = vi
      .fn()
      .mockRejectedValueOnce(new ChatApiError(422, 'too_long'))
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValue({ message: message(9, { threadRootUuid: 'm-1' }), root: { ...root, thread: { count: 3, lastReplyAt: null } } });
    const rootChanged = vi.fn();
    const panel = new ThreadPanel({ api: api({ threadReply: reply }), i18n, locale: 'en', conversationUuid: 'c', message: root, canSend: true, maxLength: 100, returnTo: null, rootChanged });
    await vi.waitFor(() => expect(replies(panel)).toEqual(['5', '7']));
    const input = panel.dialog.querySelector('[data-part="thread-input"]') as HTMLTextAreaElement;
    const send = panel.dialog.querySelector('[data-part="thread-send"]') as HTMLButtonElement;
    const status = panel.dialog.querySelector('[data-part="thread-status"]') as HTMLElement;
    send.click();
    expect(reply).not.toHaveBeenCalled();
    input.value = 'hi';
    send.click();
    await vi.waitFor(() => expect(status.textContent).toBe('Too long'));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true }));
    await vi.waitFor(() => expect(status.textContent).toBe('Error'));
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    expect(reply).toHaveBeenCalledTimes(2);
    send.click();
    await vi.waitFor(() => expect(replies(panel)).toEqual(['5', '7', '9']));
    expect(input.value).toBe('');
    expect(rootChanged).toHaveBeenCalled();
    expect(new Set(reply.mock.calls.map((call) => call[3])).size).toBe(1);
    // Another text, another key.
    input.value = 'second';
    send.click();
    await vi.waitFor(() => expect(reply).toHaveBeenCalledTimes(4));
    expect(reply.mock.calls[3]?.[3]).not.toBe(reply.mock.calls[0]?.[3]);
  });

  it('counts a root without replies and ignores a failed read', async () => {
    const threadRead = vi.fn().mockRejectedValue(new Error('x'));
    const bare = message(1);
    const panel = new ThreadPanel({ api: api({ threadRead, thread: vi.fn().mockResolvedValue(page({ root: bare })) }), i18n, locale: 'en', conversationUuid: 'c', message: bare, canSend: true, maxLength: 100, returnTo: null });
    await vi.waitFor(() => expect(threadRead).toHaveBeenCalled());
    expect(panel.dialog.querySelector('[data-part="thread-count"]')?.textContent).toBe('0 replies');
  });

  it('says when the thread cannot load', async () => {
    const panel = new ThreadPanel({ api: api({ thread: vi.fn().mockRejectedValue(new Error('x')) }), i18n, locale: 'en', conversationUuid: 'c', message: root, canSend: true, maxLength: 100, returnTo: null });
    await vi.waitFor(() => expect(panel.dialog.querySelector('[data-part="thread-status"]')?.textContent).toBe('Error'));
  });
});
