import { afterEach, describe, expect, it, vi } from 'vitest';
import { markPageRead } from '../src/page';

function page(attributes: Record<string, string>): HTMLElement {
  const element = document.createElement('div');
  Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, value));
  document.body.append(element);
  return element;
}

function visibility(state: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
}

describe('markPageRead', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
    // @ts-expect-error restore jsdom's own getter
    delete document.visibilityState;
  });

  it('posts the read cursor with the CSRF header once per page', async () => {
    const fetchMock = vi.fn(() => Promise.resolve(new Response('{}')));
    vi.stubGlobal('fetch', fetchMock);
    visibility('visible');
    const element = page({ 'data-nowo-chat-read-url': '/chat/api/conversations/u/read', 'data-nowo-chat-read-id': '42', 'data-nowo-chat-read-csrf': 'tok' });
    markPageRead();
    markPageRead();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/chat/api/conversations/u/read', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': 'tok' },
      body: '{"messageId":42}',
    });
    expect(element.hasAttribute('data-nowo-chat-read-url')).toBe(false);
  });

  it('waits until a background tab is shown, and swallows network errors', async () => {
    const fetchMock = vi.fn(() => Promise.reject(new Error('offline')));
    vi.stubGlobal('fetch', fetchMock);
    visibility('hidden');
    page({ 'data-nowo-chat-read-url': '/read', 'data-nowo-chat-read-id': '7' });
    markPageRead(document);
    document.dispatchEvent(new Event('visibilitychange'));
    expect(fetchMock).not.toHaveBeenCalled();
    visibility('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    document.dispatchEvent(new Event('visibilitychange'));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect((fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].headers).toMatchObject({ 'X-CSRF-TOKEN': '' });
    await Promise.resolve();
  });
});
