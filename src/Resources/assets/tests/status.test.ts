import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { ChatApi } from '../src/api';
import { ActivityTracker, currentStatus, customStatus, ownVisible, StatusMenu, statusDot, statusLabel } from '../src/status';
import type { ChatConfig, OwnStatus } from '../src/types';
import { templates } from './support';

const i18n = { status_busy: 'Busy', status_available: 'Available', status_yours: 'Your status: %status%', status_error: 'Could not save' };
const own = (over: Partial<OwnStatus> = {}): OwnStatus => ({ status: 'available', text: null, emoji: null, expiresAt: null, ...over });

function menu(status: OwnStatus = own()) {
  const setStatus = vi.fn();
  const trigger = document.createElement('button');
  trigger.innerHTML = '<span data-part="me-dot"></span>';
  const popover = templates().clone('status-menu');
  document.body.append(trigger, popover);
  const changed = vi.fn();
  const abort = new AbortController();
  const host = { config: { i18n, status } as unknown as ChatConfig, api: { setStatus } as unknown as ChatApi };
  const instance = new StatusMenu(host, trigger, popover, changed, abort.signal);
  const options = [...popover.querySelectorAll<HTMLButtonElement>('[data-status]')];
  return { instance, trigger, popover, setStatus, changed, abort, options };
}

const flush = async () => {
  for (let i = 0; i < 4; i += 1) {
    await Promise.resolve();
  }
};

describe('chat status', () => {
  beforeEach(() => window.localStorage.clear());
  afterEach(() => {
    document.body.replaceChildren();
    vi.restoreAllMocks();
  });

  it('reads statuses, labels and custom text', () => {
    expect(currentStatus(own({ status: 'busy', expiresAt: '2000-01-01T00:00:00Z' }))).toEqual(own());
    expect(currentStatus(own({ status: 'busy', expiresAt: '2999-01-01T00:00:00Z' })).status).toBe('busy');
    expect(ownVisible('invisible')).toBe('offline');
    expect(ownVisible('dnd')).toBe('dnd');
    expect(statusLabel(i18n, 'busy')).toBe('Busy');
    expect(customStatus('Lunch', '🍝')).toBe('🍝 Lunch');
    expect(customStatus(null, undefined)).toBe('');
    expect(statusDot('away').className).toBe('nowo-chat-status-dot nowo-chat-status-dot--away');
  });

  it('shares activity between tabs and says when the user is idle', () => {
    const abort = new AbortController();
    const tracker = new ActivityTracker('me', abort.signal);
    const now = Date.now();
    expect(tracker.isIdle(0, now + 3_600_000)).toBe(false);
    expect(tracker.isIdle(10, now + 60_000)).toBe(false);
    expect(tracker.isIdle(10, now + 11 * 60_000)).toBe(true);
    // Another tab saw input a minute ago.
    window.localStorage.setItem('nowo-chat-activity:me', String(now + 10 * 60_000));
    expect(tracker.isIdle(10, now + 11 * 60_000)).toBe(false);
    tracker.touch(now + 5_000);
    expect(window.localStorage.getItem('nowo-chat-activity:me')).toBe(String(now + 10 * 60_000));
    document.dispatchEvent(new Event('pointerdown'));
    expect(tracker.isIdle(1, Date.now() + 30_000)).toBe(false);
    // Without storage, this tab's own activity still counts.
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked');
    });
    tracker.touch(now + 100_000);
    expect(tracker.isIdle(1, now + 100_000 + 61_000)).toBe(true);
    abort.abort();
  });

  it('opens as an ARIA menu, moves with the arrow keys and closes with Escape or outside', () => {
    const { instance, trigger, popover, options } = menu(own({ status: 'busy', text: 'Lunch', emoji: '🍝' }));
    expect(trigger.dataset.status).toBe('busy');
    expect(trigger.getAttribute('aria-label')).toBe('Your status: Busy — 🍝 Lunch');
    expect(trigger.querySelector('[data-part="me-dot"]')?.className).toContain('--busy');
    expect(popover.querySelector<HTMLInputElement>('[data-part="status-text"]')?.value).toBe('Lunch');

    trigger.click();
    expect(instance.isOpen).toBe(true);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(options[1]);
    const key = (target: HTMLElement, name: string) => target.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));
    key(options[1] as HTMLElement, 'ArrowDown');
    expect(document.activeElement).toBe(options[2]);
    key(options[2] as HTMLElement, 'End');
    expect(document.activeElement).toBe(options[4]);
    key(options[4] as HTMLElement, 'ArrowDown');
    expect(document.activeElement).toBe(options[0]);
    key(options[0] as HTMLElement, 'ArrowUp');
    expect(document.activeElement).toBe(options[4]);
    key(options[4] as HTMLElement, 'Home');
    expect(document.activeElement).toBe(options[0]);
    key(options[0] as HTMLElement, 'ArrowUp');
    key(options[4] as HTMLElement, 'ArrowUp');
    key(options[3] as HTMLElement, 'a');
    key(options[3] as HTMLElement, 'Escape');
    expect(instance.isOpen).toBe(false);
    expect(document.activeElement).toBe(trigger);

    trigger.click();
    popover.click();
    expect(instance.isOpen).toBe(true);
    document.body.click();
    expect(instance.isOpen).toBe(false);
    document.body.click();
    trigger.click();
    trigger.click();
    expect(instance.isOpen).toBe(false);
  });

  it('saves a picked status, the custom status form and "clear", and reports errors', async () => {
    const { trigger, popover, options, setStatus, changed } = menu();
    expect(options[0]?.getAttribute('aria-checked')).toBe('true');

    setStatus.mockResolvedValueOnce(own({ status: 'dnd' }));
    trigger.click();
    options[3]?.click();
    await flush();
    expect(setStatus).toHaveBeenLastCalledWith({ status: 'dnd', text: null, emoji: null, clearAfter: 'never' });
    expect(changed).toHaveBeenCalledWith(own({ status: 'dnd' }));
    expect(options[3]?.getAttribute('aria-checked')).toBe('true');
    expect(trigger.querySelector('[data-part="me-dot"]')?.className).toContain('--dnd');

    setStatus.mockResolvedValueOnce(own({ status: 'dnd', text: 'Focus', emoji: '🎧', expiresAt: '2999-01-01T00:00:00Z' }));
    (popover.querySelector('[data-part="status-text"]') as HTMLInputElement).value = ' Focus ';
    (popover.querySelector('[data-part="status-emoji"]') as HTMLInputElement).value = '🎧';
    (popover.querySelector('[data-part="status-clear-after"]') as HTMLSelectElement).value = '1h';
    popover.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(setStatus).toHaveBeenLastCalledWith({ status: 'dnd', text: 'Focus', emoji: '🎧', clearAfter: '1h' });

    setStatus.mockResolvedValueOnce(own({ status: 'dnd' }));
    popover.querySelector<HTMLButtonElement>('[data-part="status-clear"]')?.click();
    await flush();
    expect(setStatus).toHaveBeenLastCalledWith({ status: 'dnd', text: null, emoji: null, clearAfter: 'never' });

    setStatus.mockRejectedValueOnce(new Error('422'));
    trigger.click();
    options[1]?.click();
    await flush();
    const error = popover.querySelector<HTMLElement>('[data-part="status-error"]');
    expect(error?.hidden).toBe(false);
    expect(error?.textContent).toBe('Could not save');
  });

  it('saves a suggested status in one click and picks the emoji with the chat picker (1.10.1)', async () => {
    const { popover, setStatus } = menu();
    setStatus.mockResolvedValue(own({ status: 'available', text: 'Lunch', emoji: '🍕' }));
    const preset = popover.querySelector<HTMLButtonElement>('[data-preset-emoji]') as HTMLButtonElement;
    preset.click();
    await flush();
    expect(setStatus).toHaveBeenLastCalledWith({ status: 'available', text: preset.dataset.presetText, emoji: '🍕', clearAfter: '1h' });

    const pick = popover.querySelector<HTMLButtonElement>('[data-part="status-emoji-pick"]') as HTMLButtonElement;
    const holder = popover.querySelector<HTMLElement>('[data-part="status-emoji-panel"]') as HTMLElement;
    pick.click();
    expect(pick.getAttribute('aria-expanded')).toBe('true');
    holder.querySelector<HTMLButtonElement>('.nowo-chat-emoji__item')?.click();
    expect(popover.querySelector<HTMLInputElement>('[data-part="status-emoji"]')?.value).not.toBe('');
    expect(holder.childElementCount).toBe(0);
    pick.click();
    pick.click();
    expect(pick.getAttribute('aria-expanded')).toBe('false');
    pick.click();
    holder.querySelector('.nowo-chat-emoji')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  });

  it('sets the automatic reply from the status menu (1.11)', async () => {
    const saveAutoReply = vi.fn();
    const trigger = document.createElement('button');
    const popover = templates().clone('status-menu');
    document.body.append(trigger, popover);
    const abort = new AbortController();
    const reply = { enabled: true, text: 'Away', when: 'range' as const, from: '2026-11-01T10:00:00Z', until: null, mentions: true };
    const config = { i18n: { ...i18n, auto_reply_invalid: 'Invalid' }, status: own(), autoReply: reply, endpoints: { autoReply: '/auto' } } as unknown as ChatConfig;
    new StatusMenu({ config, api: { setStatus: vi.fn(), saveAutoReply } as unknown as ChatApi }, trigger, popover, vi.fn(), abort.signal);
    const field = <T extends HTMLElement>(name: string) => popover.querySelector<T>(`[data-part="${name}"]`) as T;
    expect(field<HTMLInputElement>('auto-reply-enabled').checked).toBe(true);
    expect(field<HTMLTextAreaElement>('auto-reply-text').value).toBe('Away');
    expect(field('auto-reply-range').hidden).toBe(false);
    expect(field<HTMLInputElement>('auto-reply-from').value).not.toBe('');
    field<HTMLSelectElement>('auto-reply-when').value = 'status';
    field<HTMLSelectElement>('auto-reply-when').dispatchEvent(new Event('change'));
    expect(field('auto-reply-range').hidden).toBe(true);
    field<HTMLInputElement>('auto-reply-from').value = '';

    saveAutoReply.mockRejectedValueOnce(new Error('422')).mockResolvedValueOnce({ ...reply, when: 'status' });
    field<HTMLButtonElement>('auto-reply-save').click();
    await flush();
    expect(field('auto-reply-error').textContent).toBe('Invalid');
    field<HTMLButtonElement>('auto-reply-save').click();
    await flush();
    expect(saveAutoReply).toHaveBeenLastCalledWith(expect.objectContaining({ enabled: true, text: 'Away', when: 'status', from: null, mentions: true }));
    expect(config.autoReply?.when).toBe('status');
    expect(field('auto-reply-error').hidden).toBe(true);
    abort.abort();

    // Without the 1.11 configuration the section goes away.
    const bare = templates().clone('status-menu');
    new StatusMenu({ config: { i18n, status: own(), endpoints: {} } as unknown as ChatConfig, api: {} as unknown as ChatApi }, document.createElement('button'), bare, vi.fn(), new AbortController().signal);
    expect(bare.querySelector('[data-part="auto-reply"]')).toBeNull();
    const nothing = templates().clone('status-menu');
    nothing.querySelector('[data-part="auto-reply"]')?.remove();
    expect(() => new StatusMenu({ config, api: {} as unknown as ChatApi }, document.createElement('button'), nothing, vi.fn(), new AbortController().signal)).not.toThrow();
  });

  it('paints a trigger without a dot', () => {
    const setStatus = vi.fn();
    const trigger = document.createElement('button');
    const popover = templates().clone('status-menu');
    const abort = new AbortController();
    new StatusMenu({ config: { i18n, status: own() } as unknown as ChatConfig, api: { setStatus } as unknown as ChatApi }, trigger, popover, vi.fn(), abort.signal).apply(own({ status: 'busy' }));
    expect(trigger.dataset.status).toBe('busy');
    abort.abort();
  });
});
