import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatApiError } from '../src/api';
import { newIdempotencyKey, openForwardDialog, type ForwardApi } from '../src/forward-dialog';
import type { ForwardTarget } from '../src/types';

const i18n = { forward_title: 'Forward', forward_search: 'Search', forward_max: 'Up to %max%', forward_comment: 'Comment', forward_send: 'Send', cancel: 'Cancel', forward_none: 'None', forward_kind_channel: 'Channel', forward_kind_direct: 'DM', forward_new_direct: 'New DM', forward_sending: 'Sending', forward_forbidden: 'Refused', forward_forbidden_target: 'Refused: %name%', error: 'Error', rate_limited: 'Slow down' };
const room: ForwardTarget = { conversationUuid: 'c-1', contactUuid: null, kind: 'channel', title: 'Acme', initials: 'A', avatarUrl: null };
const bea: ForwardTarget = { conversationUuid: null, contactUuid: 'u-2', kind: 'direct', title: 'Bea', initials: 'B', avatarUrl: null };
const dm: ForwardTarget = { conversationUuid: 'c-3', contactUuid: null, kind: 'direct', title: 'Luis', initials: 'L', avatarUrl: null };

const api = (over: Partial<ForwardApi> = {}): ForwardApi => ({
  forwardTargets: vi.fn().mockResolvedValue([room, bea, dm]),
  forward: vi.fn().mockResolvedValue([{ conversationUuid: 'c-1', message: {} }]),
  ...over,
});

const boxes = (dialog: HTMLElement): HTMLInputElement[] => [...dialog.querySelectorAll<HTMLInputElement>('[data-part="forward-target"]')];

describe('forward dialog (1.36)', () => {
  afterEach(() => {
    document.body.replaceChildren();
    vi.useRealTimers();
  });

  it('makes keys', () => {
    expect(newIdempotencyKey()).toMatch(/^[A-Za-z0-9-]{16,}$/);
    const random = crypto.randomUUID;
    // @ts-expect-error a browser without randomUUID
    crypto.randomUUID = undefined;
    expect(newIdempotencyKey()).toMatch(/^[0-9a-f]{32}$/);
    crypto.randomUUID = random;
  });

  it('picks up to the limit, forwards with one key, and closes', async () => {
    const client = api();
    const done = vi.fn();
    const opener = document.createElement('button');
    document.body.append(opener);
    const dialog = openForwardDialog(client, i18n, 'src', 'msg', 2, opener, done);
    await vi.waitFor(() => expect(boxes(dialog)).toHaveLength(3));
    expect(dialog.querySelector('[data-part="forward-hint"]')?.textContent).toBe('Up to 2');
    expect(dialog.querySelectorAll('.nowo-chat-forward__kind')[1]?.textContent).toBe('New DM');
    const send = dialog.querySelector('[data-part="forward-send"]') as HTMLButtonElement;
    expect(send.disabled).toBe(true);
    send.click();
    expect(client.forward).not.toHaveBeenCalled();
    const [first, second, third] = boxes(dialog);
    first!.click();
    second!.click();
    expect(third!.disabled).toBe(true);
    second!.click();
    expect(third!.disabled).toBe(false);
    second!.click();
    (dialog.querySelector('[data-part="forward-comment"]') as HTMLTextAreaElement).value = 'look';
    send.click();
    await vi.waitFor(() => expect(done).toHaveBeenCalledWith(1));
    expect(client.forward).toHaveBeenCalledWith('src', 'msg', [{ conversationUuid: 'c-1' }, { contactUuid: 'u-2' }], 'look', expect.any(String));
    expect(document.body.contains(dialog)).toBe(false);
    expect(document.activeElement).toBe(opener);
  });

  it('searches after a pause and keeps the picked targets listed', async () => {
    vi.useFakeTimers();
    const targets = vi.fn().mockResolvedValueOnce([room]).mockResolvedValueOnce([]).mockRejectedValueOnce(new Error('down'));
    const dialog = openForwardDialog(api({ forwardTargets: targets }), i18n, 'src', 'msg', 5, null, vi.fn());
    await vi.waitFor(() => expect(boxes(dialog)).toHaveLength(1));
    boxes(dialog)[0]!.click();
    const search = dialog.querySelector('[data-part="forward-search"]') as HTMLInputElement;
    search.value = 'zz';
    search.dispatchEvent(new Event('input'));
    search.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(300);
    await vi.waitFor(() => expect(targets).toHaveBeenCalledTimes(2));
    expect(boxes(dialog).map((box) => box.value)).toEqual(['c:c-1']);
    // Unpicked and nothing found: "None".
    boxes(dialog)[0]!.click();
    search.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(300);
    await vi.waitFor(() => expect(dialog.querySelector('[data-part="forward-status"]')?.textContent).toBe('Error'));
  });

  it('says which target was refused, or why it failed', async () => {
    const forward = vi
      .fn()
      .mockRejectedValueOnce(new ChatApiError(403, 'forward_forbidden', '', null, 1))
      .mockRejectedValueOnce(new ChatApiError(403, 'forward_forbidden'))
      .mockRejectedValueOnce(new ChatApiError(429, 'rate_limited'))
      .mockRejectedValueOnce(new Error('offline'));
    const dialog = openForwardDialog(api({ forward }), i18n, 'src', 'msg', 5, null, vi.fn());
    await vi.waitFor(() => expect(boxes(dialog)).toHaveLength(3));
    boxes(dialog)[0]!.click();
    boxes(dialog)[2]!.click();
    const send = dialog.querySelector('[data-part="forward-send"]') as HTMLButtonElement;
    const status = dialog.querySelector('[data-part="forward-status"]') as HTMLElement;
    for (const expected of ['Refused: Luis', 'Refused', 'Slow down', 'Error']) {
      send.click();
      await vi.waitFor(() => expect(status.textContent).toBe(expected));
      expect(send.disabled).toBe(false);
    }
    // The same key for every try.
    const keys = forward.mock.calls.map((call) => call[4]);
    expect(new Set(keys).size).toBe(1);
  });

  it('shows "None" when there is nothing to pick', async () => {
    const dialog = openForwardDialog(api({ forwardTargets: vi.fn().mockResolvedValue([]) }), i18n, 'src', 'msg', 5, null, vi.fn());
    await vi.waitFor(() => expect(dialog.querySelector('.nowo-chat-forward__empty')?.textContent).toBe('None'));
    // 1.36.1: the standard anatomy, a header with the title and the ×; the × closes it.
    expect(dialog.classList.contains('nowo-chat-dialog')).toBe(true);
    expect(dialog.querySelector('header .nowo-chat-forward__title')).not.toBeNull();
    const cross = dialog.querySelector('header [data-part="forward-close"]') as HTMLButtonElement;
    expect(cross).not.toBeNull();
    (dialog.querySelector('[data-part="dialog-cancel"]') as HTMLButtonElement).click();
    expect(document.body.contains(dialog)).toBe(false);
  });
});
