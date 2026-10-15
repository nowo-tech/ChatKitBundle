import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const start = vi.fn();
const destroy = vi.fn();
const created: HTMLElement[] = [];
vi.mock('../src/dock', () => ({
  ChatDock: class {
    constructor(root: HTMLElement) {
      created.push(root);
    }
    start = start;
    destroy = destroy;
  },
}));

const { CSS_TOKEN_PREFIX, VERSION, autoStart, mount, mountAll, unmount } = await import('../src/index');

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
    unmount(element);
    expect(destroy).toHaveBeenCalledTimes(1);
    unmount(element);
    expect(destroy).toHaveBeenCalledTimes(1);
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
