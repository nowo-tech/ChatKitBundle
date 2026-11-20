import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FRAGMENT_HEADER, fragment, openSearchDialog, sameOriginUrl, searchParams } from '../src/search-dialog';
import { Templates } from '../src/templates';

/** The `search-dialog` template as `_templates.html.twig` renders it (UiKit confirm_dialog anatomy, trimmed). */
const TEMPLATE = `
<template data-nowo-chat-template="search-dialog">
  <dialog class="nowo-ui-confirm-dialog nowo-ui-confirm-dialog--anatomy nowo-chat-msearch" data-testid="chat-search-dialog" aria-labelledby="nowo-chat-msearch-title">
    <header class="nowo-ui-dialog__header"><h2 id="nowo-chat-msearch-title">Search messages</h2><button type="button" data-confirm-dialog-close aria-label="Close">×</button></header>
    <div class="nowo-ui-dialog__body">
      <div class="nowo-chat-msearch__section">
        <form id="nowo_chat_msearch_form" method="get" action="/chat/search" data-part="search-form">
          <select name="nowo_chat_msearch[scope]"><option value="current" selected>This conversation</option><option value="all">All</option></select>
          <input type="search" name="nowo_chat_msearch[q]" id="nowo_chat_msearch_q">
          <details data-part="search-filters"><summary>More filters</summary>
            <select name="nowo_chat_msearch[from]"><option value="">Anyone</option><option value="me">Me</option></select>
            <input type="checkbox" name="nowo_chat_msearch[link]" value="1">
          </details>
          <input type="hidden" name="nowo_chat_msearch[conversation]" value="">
        </form>
      </div>
      <div class="nowo-chat-msearch__section" data-part="search-results" aria-live="polite" data-loading="Searching…" data-failed="The search failed." data-expired="Session expired." data-reload="Reload" data-notice-class="nowo-ui-flash nowo-ui-flash--error" data-reload-class="nowo-ui-btn"><p>Find a message</p></div>
    </div>
    <footer class="nowo-ui-dialog__footer"><button type="button" data-part="search-close">Close</button><button type="submit" form="nowo_chat_msearch_form" data-part="search-submit">Search</button></footer>
  </dialog>
</template>`;

const HIT = (conversation: string, message: string): string => `<li><a href="/chat/${conversation}?message=${message}" data-nowo-chat-search-hit data-conversation="${conversation}" data-message="${message}">Hit <mark>word</mark></a></li>`;
const PAGE = (hits: string, next: string | null = null): string => `<ol data-testid="chat-msearch-results">${hits}</ol>${next ? `<a href="${next}" data-nowo-chat-search-more>More results</a>` : ''}<script>window.pwned = true</script>`;

const respond = (body: string, status = 200, marked = true): Response => new Response(body, { status, headers: { 'Content-Type': 'text/html', ...(marked ? { [FRAGMENT_HEADER]: 'search-results' } : {}) } });

describe('search modal (1.40)', () => {
  let templates: Templates;
  let opener: HTMLButtonElement;

  beforeEach(() => {
    document.body.innerHTML = TEMPLATE;
    templates = new Templates(document);
    opener = document.createElement('button');
    document.body.append(opener);
  });

  afterEach(() => {
    document.body.replaceChildren();
  });

  const open = (fetcher: typeof fetch, pick = vi.fn()) => ({ pick, modal: openSearchDialog({ templates, endpoint: '/chat/search/results', conversationUuid: 'conv-1', returnTo: opener, pick, fetcher }) });
  const field = (dialog: HTMLElement, name: string): HTMLInputElement => dialog.querySelector(`[name="nowo_chat_msearch[${name}]"]`) as HTMLInputElement;

  it('builds unprefixed parameters: this conversation by default, never a conversation for "all"', () => {
    const { modal } = open(vi.fn());
    const form = modal.dialog.querySelector('form') as HTMLFormElement;
    field(modal.dialog, 'q').value = 'budget';
    expect(searchParams(form, 'conv-1').toString()).toBe('scope=current&q=budget&conversation=conv-1');
    (field(modal.dialog, 'scope') as unknown as HTMLSelectElement).value = 'all';
    (field(modal.dialog, 'from') as unknown as HTMLSelectElement).value = 'me';
    field(modal.dialog, 'link').checked = true;
    expect(searchParams(form, 'conv-1').toString()).toBe('scope=all&q=budget&from=me&link=1');
  });

  it('opens focused on the text, searches, shows the escaped fragment and drops scripts', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(respond(PAGE(HIT('conv-1', 'm-1'))));
    const { modal } = open(fetcher);
    expect(document.body.contains(modal.dialog)).toBe(true);
    expect(document.activeElement).toBe(field(modal.dialog, 'q'));
    // Nothing typed: no request, focus stays on the text.
    await modal.search();
    expect(fetcher).not.toHaveBeenCalled();

    field(modal.dialog, 'q').value = 'word';
    (modal.dialog.querySelector('form') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
    await vi.waitFor(() => expect(modal.dialog.querySelectorAll('[data-nowo-chat-search-hit]')).toHaveLength(1));
    expect(fetcher.mock.calls[0]?.[0]).toBe('/chat/search/results?scope=current&q=word&conversation=conv-1');
    expect(fetcher.mock.calls[0]?.[1]).toMatchObject({ headers: { Accept: 'text/html' }, credentials: 'same-origin' });
    expect(modal.dialog.querySelector('mark')?.textContent).toBe('word');
    expect(modal.dialog.querySelector('script')).toBeNull();
    expect((window as unknown as { pwned?: boolean }).pwned).toBeUndefined();
    expect(modal.dialog.querySelector('[data-part="search-results"]')?.getAttribute('aria-busy')).toBe('false');
  });

  it('appends the next page and keeps one list', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(respond(PAGE(HIT('conv-1', 'm-1'), '/chat/search/results?cursor=abc')))
      .mockResolvedValueOnce(respond(PAGE(HIT('conv-1', 'm-2'))));
    const { modal } = open(fetcher);
    field(modal.dialog, 'q').value = 'word';
    await modal.search();
    const more = modal.dialog.querySelector<HTMLAnchorElement>('[data-nowo-chat-search-more]');
    expect(more).not.toBeNull();
    more!.click();
    await vi.waitFor(() => expect(modal.dialog.querySelectorAll('[data-nowo-chat-search-hit]')).toHaveLength(2));
    expect(modal.dialog.querySelectorAll('ol')).toHaveLength(1);
    expect(modal.dialog.querySelector('[data-nowo-chat-search-more]')).toBeNull();
    expect(String(fetcher.mock.calls[1]?.[0])).toContain('cursor=abc');
  });

  it('shows the 400 / 429 notices and a failure line for anything else', async () => {
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(respond('<p role="alert" data-testid="chat-msearch-error">Too short</p>', 400))
      .mockResolvedValueOnce(respond('oops', 500))
      .mockRejectedValueOnce(new TypeError('offline'));
    const { modal } = open(fetcher);
    field(modal.dialog, 'q').value = 'w';
    await modal.search();
    expect(modal.dialog.querySelector('[data-testid="chat-msearch-error"]')?.textContent).toBe('Too short');
    await modal.search();
    expect(modal.dialog.querySelector('[role="alert"]')?.textContent).toBe('The search failed.');
    await modal.search();
    expect(modal.dialog.querySelector('[role="alert"]')?.textContent).toBe('The search failed.');
  });

  it('a result closes the modal, gives focus back and reports the pick; modified clicks are left to the browser', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(respond(PAGE(HIT('conv-2', 'm-9'))));
    const { modal, pick } = open(fetcher);
    field(modal.dialog, 'q').value = 'word';
    await modal.search();
    const hit = modal.dialog.querySelector<HTMLAnchorElement>('[data-nowo-chat-search-hit]')!;
    const modified = new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
    hit.dispatchEvent(modified);
    expect(modified.defaultPrevented).toBe(false);
    expect(pick).not.toHaveBeenCalled();
    hit.click();
    expect(pick).toHaveBeenCalledWith('conv-2', 'm-9', expect.stringContaining('/chat/conv-2?message=m-9'));
    expect(document.body.contains(modal.dialog)).toBe(false);
    expect(document.activeElement).toBe(opener);
  });

  it('closing aborts the pending request (Close, ×, Escape)', async () => {
    const signals: AbortSignal[] = [];
    const fetcher = vi.fn<typeof fetch>().mockImplementation((_url, init) => {
      signals.push(init!.signal!);
      return new Promise<Response>((_resolve, reject) => init!.signal!.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError'))));
    });
    for (const close of ['[data-part="search-close"]', '[data-confirm-dialog-close]', 'cancel']) {
      const { modal } = open(fetcher);
      field(modal.dialog, 'q').value = 'word';
      const pending = modal.search();
      if (close === 'cancel') {
        modal.dialog.dispatchEvent(new Event('cancel'));
        modal.close();
      } else {
        (modal.dialog.querySelector(close) as HTMLButtonElement).click();
      }
      await pending;
      expect(signals.at(-1)?.aborted).toBe(true);
      expect(document.body.contains(modal.dialog)).toBe(false);
    }
  });

  it('keeps Tab inside the modal', () => {
    const { modal } = open(vi.fn());
    const visible = vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList);
    const focusable = [...modal.dialog.querySelectorAll<HTMLElement>('button, input:not([type="hidden"]), select, summary')];
    const first = focusable[0]!;
    const last = focusable.at(-1)!;
    last.focus();
    const forward = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    modal.dialog.dispatchEvent(forward);
    expect(forward.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(first);
    const back = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true });
    modal.dialog.dispatchEvent(back);
    expect(document.activeElement).toBe(last);
    const other = new KeyboardEvent('keydown', { key: 'a', bubbles: true, cancelable: true });
    modal.dialog.dispatchEvent(other);
    expect(other.defaultPrevented).toBe(false);
    visible.mockReturnValue([] as unknown as DOMRectList);
    const none = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    modal.dialog.dispatchEvent(none);
    expect(none.defaultPrevented).toBe(false);
  });

  it('needs the form, the results section and the text field', () => {
    document.body.innerHTML = '<template data-nowo-chat-template="search-dialog"><dialog></dialog></template>';
    expect(() => openSearchDialog({ templates: new Templates(document), endpoint: '/x', conversationUuid: 'c', returnTo: null, pick: vi.fn() })).toThrow(/search-dialog template/);
  });

  it('shows "session expired, reload" instead of a login page (401, a redirect, or an answer without the fragment header)', async () => {
    const redirected = respond('<form><input name="_username"></form>', 200, false);
    Object.defineProperty(redirected, 'redirected', { value: true });
    const fetcher = vi.fn<typeof fetch>()
      .mockResolvedValueOnce(respond('', 401, false))
      .mockResolvedValueOnce(redirected)
      .mockResolvedValueOnce(respond('<h1>Sign in</h1><input name="_username">', 200, false));
    const reload = vi.fn();
    vi.stubGlobal('location', { ...window.location, href: window.location.href, origin: window.location.origin, reload });
    const { modal } = open(fetcher);
    field(modal.dialog, 'q').value = 'word';
    for (let i = 0; i < 3; i += 1) {
      await modal.search();
      const notice = modal.dialog.querySelector<HTMLElement>('[data-testid="chat-msearch-expired"]');
      expect(notice?.getAttribute('role')).toBe('alert');
      expect(notice?.className).toContain('nowo-ui-flash--error');
      expect(notice?.textContent).toContain('Session expired.');
      expect(modal.dialog.querySelector('[name="_username"], h1')).toBeNull();
    }
    const button = modal.dialog.querySelector<HTMLButtonElement>('[data-testid="chat-msearch-expired"] button')!;
    expect(button.textContent).toBe('Reload');
    expect(button.className).toBe('nowo-ui-btn');
    button.click();
    expect(reload).toHaveBeenCalledOnce();
    vi.unstubAllGlobals();
  });

  it('keeps only same-site addresses and drops on* attributes and active elements from the fragment', () => {
    const nodes = fragment([
      '<a href="/chat/c1?message=m1" onclick="alert(1)" onmouseover="x()">ok</a>',
      `<a href="${window.location.origin}/chat/c2">same origin</a>`,
      '<a href="https://evil.example/x">elsewhere</a>',
      '<a href="//evil.example/x">protocol-relative</a>',
      '<a href="javascript:alert(1)">script</a>',
      '<a href="data:text/html,x">data</a>',
      '<img src="https://evil.example/pixel.png" onerror="alert(2)">',
      '<img src="/icons/ok.png">',
      '<svg><a xlink:href="javascript:alert(3)"><text>x</text></a></svg>',
      '<form action="https://evil.example"><button formaction="https://evil.example">go</button></form>',
      '<iframe src="/x"></iframe><object data="/x"></object><base href="https://evil.example/"><meta http-equiv="refresh" content="0"><link rel="stylesheet" href="/x"><template><b>t</b></template>',
    ].join(''));
    const box = document.createElement('div');
    box.append(...nodes);
    const anchors = [...box.querySelectorAll('a')];
    expect(anchors.map((a) => a.getAttribute('href'))).toEqual(['/chat/c1?message=m1', `${window.location.origin}/chat/c2`, null, null, null, null, null]);
    expect(box.querySelectorAll('[onclick], [onmouseover], [onerror]')).toHaveLength(0);
    expect([...box.querySelectorAll('img')].map((img) => img.getAttribute('src'))).toEqual([null, '/icons/ok.png']);
    expect(box.querySelector('svg a')?.getAttribute('xlink:href')).toBeNull();
    expect(box.querySelectorAll('form, iframe, object, base, meta, link, template, script')).toHaveLength(0);
  });

  it('tells same-site addresses apart', () => {
    expect(sameOriginUrl('/chat/x')).toBe(true);
    expect(sameOriginUrl('chat/x?y=1')).toBe(true);
    expect(sameOriginUrl(`${window.location.origin}/a`)).toBe(true);
    expect(sameOriginUrl('https://evil.example/a')).toBe(false);
    expect(sameOriginUrl('//evil.example/a')).toBe(false);
    expect(sameOriginUrl('javascript:alert(1)')).toBe(false);
    expect(sameOriginUrl('http://[')).toBe(false);
  });
});
