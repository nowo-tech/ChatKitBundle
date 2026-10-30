import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const start = vi.fn();
const destroy = vi.fn();
const created: HTMLElement[] = [];
const pages: (HTMLElement | null | undefined)[] = [];
vi.mock('../src/dock', () => ({
  ChatDock: class {
    constructor(root: HTMLElement, _config: unknown, options: { page?: HTMLElement | null }) {
      created.push(root);
      pages.push(options.page);
    }
    start = start;
    destroy = destroy;
  },
}));

const { AUTO_ROOT_SELECTOR, CSS_TOKEN_PREFIX, PAGE_SELECTOR, VERSION, autoStart, findChatPage, mount, mountAll, mountPage, unmount } = await import('../src/index');

function root(config: string): HTMLElement {
  const element = document.createElement('div');
  element.dataset.nowoChatConfig = config;
  document.body.append(element);
  return element;
}

describe('nowo-chat entry', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    created.length = 0;
    pages.length = 0;
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
  });

  it('exposes the version and the token prefix', () => {
    expect(VERSION).toMatch(/^\d+\.\d+\.\d+/);
    expect(CSS_TOKEN_PREFIX).toBe('--nowo-chat-');
  });

  it('mounts once per root, skips invalid configs and unmounts', () => {
    const element = root('{"userUuid":"me"}');
    const dock = mount(element);
    expect(dock).not.toBeNull();
    expect(mount(element)).toBe(dock);
    expect(start).toHaveBeenCalledTimes(1);
    expect(mount(root('not json'))).toBeNull();
    // A root without the attribute at all (mounted by hand) is skipped too.
    const bare = document.createElement('div');
    expect(mount(bare)).toBeNull();
    unmount(element);
    expect(destroy).toHaveBeenCalledTimes(1);
    unmount(element);
    expect(destroy).toHaveBeenCalledTimes(1);
  });

  it('starts page mode on the full-page chat, found in the document or given', () => {
    expect(PAGE_SELECTOR).toBe('[data-nowo-chat-page-mode]');
    expect(findChatPage()).toBeNull();
    mount(root('{}'));
    expect(pages).toEqual([null]);

    const page = document.createElement('div');
    page.dataset.nowoChatPageMode = '';
    document.body.append(page);
    expect(findChatPage()).toBe(page);
    mount(root('{}'));
    // A host may keep the floating dock on that page (page: null) or point at its own element.
    mount(root('{}'), { page: null });
    const own = document.createElement('section');
    expect(mountPage(root('{}'), own)).not.toBeNull();
    expect(pages).toEqual([null, page, null, own]);
  });

  it('mounts every root when the browser is idle, or after a short delay', () => {
    root('{}');
    const idle = vi.fn((callback: () => void) => callback());
    vi.stubGlobal('requestIdleCallback', idle);
    mountAll();
    expect(idle).toHaveBeenCalled();
    expect(created).toHaveLength(1);

    vi.stubGlobal('requestIdleCallback', undefined);
    root('{}');
    mountAll();
    expect(created).toHaveLength(1);
    vi.advanceTimersByTime(200);
    expect(created).toHaveLength(2);
  });

  it('leaves roots marked data-nowo-chat-manual to the host', () => {
    vi.stubGlobal('requestIdleCallback', undefined);
    const auto = root('{}');
    const manual = root('{}');
    manual.dataset.nowoChatManual = '';
    expect(AUTO_ROOT_SELECTOR).toBe('[data-nowo-chat-config]:not([data-nowo-chat-manual])');

    autoStart();
    vi.advanceTimersByTime(200);
    expect(created).toEqual([auto]);

    // The host mounts its own dock explicitly; a Turbo swap does not tear it down, the next visit does not re-mount it.
    expect(mount(manual)).not.toBeNull();
    destroy.mockClear();
    document.dispatchEvent(new Event('turbo:before-render'));
    expect(destroy).toHaveBeenCalledTimes(1);
    document.dispatchEvent(new Event('turbo:load'));
    vi.advanceTimersByTime(200);
    expect(created.filter((item) => item === manual)).toHaveLength(1);
    unmount(manual);
  });

  it('starts on document ready and follows Turbo visits', () => {
    vi.stubGlobal('requestIdleCallback', undefined);
    const element = root('{}');
    autoStart();
    vi.advanceTimersByTime(200);
    expect(created).toContain(element);

    document.dispatchEvent(new Event('turbo:before-render'));
    expect(destroy).toHaveBeenCalled();
    document.dispatchEvent(new Event('turbo:load'));
    vi.advanceTimersByTime(200);
    expect(created.filter((item) => item === element)).toHaveLength(2);

    Object.defineProperty(document, 'readyState', { configurable: true, get: () => 'loading' });
    autoStart();
    document.dispatchEvent(new Event('DOMContentLoaded'));
    vi.advanceTimersByTime(200);
    // @ts-expect-error restore jsdom's own getter
    delete document.readyState;
  });
});
