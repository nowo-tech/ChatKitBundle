import { afterEach, describe, expect, it, vi } from 'vitest';
import { bindSavedPage } from '../src/saved-page';

const PAGE = `
  <section data-nowo-chat-saved-page data-nowo-chat-csrf="tok" data-nowo-chat-removed="Removed">
    <ul>
      <li data-nowo-chat-saved-item id="one"><a href="/m/1">Open</a><button type="button" data-nowo-chat-unsave="/api/1/save" hidden>Remove</button></li>
      <li data-nowo-chat-saved-item id="two"><a href="/m/2">Open</a><button type="button" data-nowo-chat-unsave="https://evil.example/save" hidden>Remove</button></li>
    </ul>
    <p data-nowo-chat-saved-live></p>
  </section>`;

describe('Saved page (1.35)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
  });

  it('shows "Remove", unsaves through the API and takes the entry off; once per page; never another origin', async () => {
    document.body.innerHTML = PAGE;
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response('{"saved":false}', { status: 200 })).mockResolvedValueOnce(new Response('{}', { status: 500 }));
    vi.stubGlobal('fetch', fetchMock);
    bindSavedPage();
    bindSavedPage();
    const [first, second] = [...document.querySelectorAll<HTMLButtonElement>('[data-nowo-chat-unsave]')];
    expect(first?.hidden).toBe(false);
    second?.click();
    expect(fetchMock).not.toHaveBeenCalled();
    first?.click();
    expect(fetchMock).toHaveBeenCalledWith('/api/1/save', { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': 'tok' } });
    await vi.waitFor(() => expect(document.getElementById('one')).toBeNull());
    expect(document.querySelector('[data-nowo-chat-saved-live]')?.textContent).toBe('Removed');
    expect(document.activeElement).toBe(document.querySelector('#two a'));
  });

  it('keeps the entry and enables the button again when the server refuses', async () => {
    document.body.innerHTML = PAGE.replace(' data-nowo-chat-removed="Removed"', '').replace('<p data-nowo-chat-saved-live></p>', '');
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 500 })));
    bindSavedPage();
    const button = document.querySelector<HTMLButtonElement>('#one button') as HTMLButtonElement;
    button.click();
    expect(button.disabled).toBe(true);
    await vi.waitFor(() => expect(button.disabled).toBe(false));
    expect(document.getElementById('one')).not.toBeNull();
    // A successful one without a live region removes the last entry quietly.
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 200 })));
    document.getElementById('two')?.remove();
    button.click();
    await vi.waitFor(() => expect(document.getElementById('one')).toBeNull());
  });

  it('a button without an address does nothing', () => {
    document.body.innerHTML = '<section data-nowo-chat-saved-page><li data-nowo-chat-saved-item><button type="button" data-nowo-chat-unsave hidden>x</button></li></section>';
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    bindSavedPage();
    (document.querySelector('button') as HTMLButtonElement).click();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
