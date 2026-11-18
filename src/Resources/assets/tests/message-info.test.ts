import { afterEach, describe, expect, it, vi } from 'vitest';
import { openMessageInfo } from '../src/message-info';
import type { MessageInfo } from '../src/types';

const i18n = { info_title: 'Info', info_close: 'Close', info_read_count: '%read% of %total%', info_not_read: 'Not read', info_read: 'Read', info_read_at: 'Read %time%', info_edited: 'Edited %time%', info_times_hidden: 'Hidden times' };
const info = (over: Partial<MessageInfo> = {}): MessageInfo => ({ mode: 'people', memberCount: 2, readCount: 1, readTimes: true, readers: [], editedAt: null, ...over });

describe('message info (1.35)', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('lists the readers with their read times, then closes and gives focus back', () => {
    const opener = document.createElement('button');
    document.body.append(opener);
    const dialog = openMessageInfo(
      info({
        readers: [
          { uuid: 'a', name: 'Ana', initials: 'A', avatarUrl: null, read: true, readAt: '2026-10-15T10:01:00Z' },
          { uuid: 'b', name: 'Bea', initials: 'B', avatarUrl: null, read: true, readAt: null },
          { uuid: 'c', name: 'Carl', initials: 'C', avatarUrl: null, read: false, readAt: null },
        ],
        editedAt: '2026-10-15T10:02:00Z',
      }),
      i18n,
      'en',
      opener,
    );
    expect(dialog.querySelector('[data-part="info-count"]')?.textContent).toBe('1 of 2');
    const readers = [...dialog.querySelectorAll('[data-part="info-reader"]')];
    expect(readers.map((li) => li.querySelector('.nowo-chat-info__name')?.textContent)).toEqual(['Ana', 'Bea', 'Carl']);
    expect(readers[0]?.querySelector('time')?.getAttribute('datetime')).toBe('2026-10-15T10:01:00Z');
    expect(readers[1]?.textContent).toContain('Read');
    expect(readers[2]?.textContent).toContain('Not read');
    expect(dialog.querySelector('[data-part="info-edited"] time')).not.toBeNull();
    expect(dialog.querySelector('[data-part="info-times-hidden"]')).toBeNull();
    expect(dialog.getAttribute('aria-labelledby')).toBe(dialog.querySelector('h2')?.id);
    // A click on the dialog itself (its backdrop) closes it; a click inside does not.
    dialog.querySelector('h2')?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(document.body.contains(dialog)).toBe(true);
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(document.body.contains(dialog)).toBe(false);
    expect(document.activeElement).toBe(opener);
  });

  it('counts only above the size; says when read times are hidden; works without showModal', () => {
    const showModal = HTMLDialogElement.prototype.showModal;
    // @ts-expect-error a browser without the modal API
    HTMLDialogElement.prototype.showModal = undefined;
    const counts = openMessageInfo(info({ mode: 'counts', readCount: 30, memberCount: 40 }), i18n, 'en', null);
    expect(counts.hasAttribute('open')).toBe(true);
    expect(counts.querySelector('[data-part="info-readers"]')).toBeNull();
    (counts.querySelector('[data-part="info-close"]') as HTMLButtonElement).click();
    HTMLDialogElement.prototype.showModal = showModal;
    const hidden = openMessageInfo(info({ readTimes: false, readers: [{ uuid: 'a', name: 'Ana', initials: 'A', avatarUrl: null, read: true, readAt: null }] }), i18n, 'en', null);
    expect(hidden.querySelector('[data-part="info-times-hidden"]')?.textContent).toBe('Hidden times');
    (hidden.querySelector('[data-part="info-close"]') as HTMLButtonElement).click();
    expect(document.body.contains(hidden)).toBe(false);
  });

  it('uses the native modal API when the browser has it', () => {
    const proto = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    const saved = { showModal: proto.showModal, close: proto.close };
    const showModal = vi.fn(function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    });
    const close = vi.fn(function (this: HTMLDialogElement) {
      this.removeAttribute('open');
      this.dispatchEvent(new Event('close'));
    });
    Object.assign(proto, { showModal, close });
    try {
      const dialog = openMessageInfo(info(), i18n, 'en', null);
      expect(showModal).toHaveBeenCalled();
      (dialog.querySelector('[data-part="info-close"]') as HTMLButtonElement).click();
      expect(close).toHaveBeenCalled();
      expect(document.body.contains(dialog)).toBe(false);
    } finally {
      Object.assign(proto, saved);
    }
  });
});

describe('message info: edit history (1.36)', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('lists the edits and, on demand, the previous texts', async () => {
    const revisions = vi.fn().mockResolvedValue([{ editedAt: '2026-10-15T10:03:00Z', text: 'old', html: '<p>old</p>' }]);
    const dialog = openMessageInfo(info({ edits: [{ editedAt: '2026-10-15T10:03:00Z' }] }), { ...i18n, info_edits: 'Edits', info_show_history: 'Show', info_replaced_at: 'Replaced %time%' }, 'en', null, revisions);
    expect(dialog.querySelectorAll('[data-part="info-edit"]')).toHaveLength(1);
    (dialog.querySelector('[data-part="info-history"]') as HTMLButtonElement).click();
    await vi.waitFor(() => expect(dialog.querySelector('[data-part="info-revision"]')).not.toBeNull());
    expect(dialog.querySelector('.nowo-chat-info__revision-text')?.innerHTML).toBe('<p>old</p>');
    expect(dialog.querySelector('[data-part="info-history"]')).toBeNull();
  });

  it('says when the previous texts cannot be read, and offers none without the loader', async () => {
    const failing = vi.fn().mockRejectedValue(new Error('403'));
    const dialog = openMessageInfo(info({ edits: [{ editedAt: '2026-10-15T10:03:00Z' }] }), { ...i18n, error: 'Error' }, 'en', null, failing);
    const show = dialog.querySelector('[data-part="info-history"]') as HTMLButtonElement;
    show.click();
    await vi.waitFor(() => expect(dialog.querySelector('[data-part="info-history-status"]')?.textContent).toBe('Error'));
    expect(show.disabled).toBe(false);
    (dialog.querySelector('[data-part="info-close"]') as HTMLButtonElement).click();
    const plain = openMessageInfo(info({ edits: [{ editedAt: '2026-10-15T10:03:00Z' }] }), i18n, 'en', null);
    expect(plain.querySelector('[data-part="info-history"]')).toBeNull();
    expect(openMessageInfo(info({ edits: [] }), i18n, 'en', null).querySelector('[data-part="info-edits"]')).toBeNull();
  });
});
