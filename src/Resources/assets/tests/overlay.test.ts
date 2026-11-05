import { afterEach, describe, expect, it, vi } from 'vitest';
import { acquireOverlay, OVERLAY_CLOSE_EVENT, OVERLAY_OPEN_EVENT } from '../src/overlay';
import * as entry from '../src/index';

describe('acquireOverlay (1.18: host drawers tell the dock to step aside)', () => {
  afterEach(() => {
    delete (window as unknown as { NowoUiOverlay?: unknown }).NowoUiOverlay;
    document.documentElement.removeAttribute('data-nowo-overlay');
  });

  it('counts the overlays itself without UiKit: events with the depth and the root marker', () => {
    const seen: Array<{ type: string; depth: number; kind: string; owner: Element }> = [];
    const listener = (event: Event): void => {
      const detail = (event as CustomEvent<{ depth: number; kind: string; owner: Element }>).detail;
      seen.push({ type: event.type, ...detail });
    };
    document.addEventListener(OVERLAY_OPEN_EVENT, listener);
    document.addEventListener(OVERLAY_CLOSE_EVENT, listener);
    const drawer = document.createElement('aside');
    const modal = document.createElement('div');

    const first = acquireOverlay(drawer);
    expect(first.kind).toBe('drawer');
    expect(first.owner).toBe(drawer);
    expect(first.active()).toBe(true);
    expect(document.documentElement.getAttribute('data-nowo-overlay')).toBe('1');
    const second = acquireOverlay(modal, { kind: 'host' });
    expect(document.documentElement.getAttribute('data-nowo-overlay')).toBe('2');

    first.release();
    first.release();
    expect(first.active()).toBe(false);
    expect(document.documentElement.getAttribute('data-nowo-overlay')).toBe('1');
    second.release();
    expect(document.documentElement.hasAttribute('data-nowo-overlay')).toBe(false);
    expect(seen.map(({ type, depth, kind }) => [type, depth, kind])).toEqual([
      [OVERLAY_OPEN_EVENT, 1, 'drawer'],
      [OVERLAY_OPEN_EVENT, 2, 'host'],
      [OVERLAY_CLOSE_EVENT, 1, 'drawer'],
      [OVERLAY_CLOSE_EVENT, 0, 'host'],
    ]);
    expect(seen[0]?.owner).toBe(drawer);
    document.removeEventListener(OVERLAY_OPEN_EVENT, listener);
    document.removeEventListener(OVERLAY_CLOSE_EVENT, listener);
  });

  it('hands the overlay to UiKit\'s registry when the page has it (one depth for UiKit dialogs and host drawers)', () => {
    const handle = { kind: 'drawer', owner: document.body, active: () => true, release: vi.fn() };
    const acquire = vi.fn(() => handle);
    (window as unknown as { NowoUiOverlay: unknown }).NowoUiOverlay = { acquire };
    const opened = vi.fn();
    document.addEventListener(OVERLAY_OPEN_EVENT, opened);

    expect(acquireOverlay(document.body)).toBe(handle);
    expect(acquireOverlay(document.body, { kind: 'dialog', inertShell: true })).toBe(handle);
    expect(acquire).toHaveBeenNthCalledWith(1, document.body, { kind: 'drawer', inertShell: false });
    expect(acquire).toHaveBeenNthCalledWith(2, document.body, { kind: 'dialog', inertShell: true });
    expect(opened).not.toHaveBeenCalled();
    document.removeEventListener(OVERLAY_OPEN_EVENT, opened);
  });

  it('is part of the public entry (window.NowoChat.acquireOverlay in the IIFE)', () => {
    expect(entry.acquireOverlay).toBe(acquireOverlay);
  });
});
