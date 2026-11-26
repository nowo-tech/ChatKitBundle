import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  BEFORE_SEND,
  bindInsertCard,
  beforeSend,
  CardTray,
  cardToken,
  dialogUrl,
  INSERT_CARD,
  isCardRef,
  MAX_CARDS,
  openSlotDialog,
  selectionIn,
  sendSlotPayload,
  setCardTarget,
  slashAction,
  SLOT_PAYLOAD,
  slotFor,
  withCards,
  withoutCards,
} from '../src/slots';
import type { ComposerAction, MenuSlot, Reference } from '../src/types';

const i18n = { composer_cards: 'Cards to send', card_remove: 'Remove card', card_unavailable: 'Not available' };
const settings = { endpoint: '/s/__uuid__', card: '/c', dialog: 'slot-dialog' };
const action = (over: Partial<ComposerAction> = {}): ComposerAction => ({ key: 'secret', label: 'Secret', icon: 'lock', slash: 'secret', dialogUrl: '/host/secret', ...over });
const slot = (over: Partial<MenuSlot> = {}): MenuSlot => ({ key: 's', label: 'S', icon: 'key', target: 'message', cardTypes: [], ownOnly: false, dialogUrl: '/host/s', ...over });
const card = (type: string, id: string): Reference => ({ type, id, title: 'T', url: '', subtitle: '', badge: '', tone: 'neutral', card: { state: 'open', facts: [], actions: [] } });

describe('slots', () => {
  afterEach(() => {
    document.body.replaceChildren();
    setCardTarget(null);
    vi.restoreAllMocks();
  });

  it('checks card references and builds tokens', () => {
    expect(isCardRef({ type: 'vault_item', id: 'v-1' })).toBe(true);
    for (const bad of [null, 'x', { type: 'Vault', id: '1' }, { type: 'vault', id: 'a b' }, { type: 1, id: '1' }, { type: 'vault' }]) {
      expect(isCardRef(bad)).toBe(false);
    }
    expect(cardToken({ type: 'note', id: 'n1' })).toBe('#[…](note:n1)');
    expect(withCards(' hi ', [])).toBe('hi');
    expect(withCards('', [{ type: 'note', id: 'n1' }])).toBe('#[…](note:n1)');
    expect(withCards('hi', [{ type: 'note', id: 'n1' }, { type: 'vault-item', id: 'v1' }])).toBe('hi\n\n#[…](note:n1)\n\n#[…](vault-item:v1)');
    expect(withoutCards('a\n\n#[…](note:n1)\n\n\n#[x](vault-item:v1) #[t](task:1)', ['note', 'vault-item'])).toBe('a\n\n #[t](task:1)');
    expect(withoutCards('same', [])).toBe('same');
  });

  it('builds same-origin dialog URLs only', () => {
    expect(dialogUrl('/host/secret?x=1', { conversation: 'c1', card: 'note:n1' })).toBe('/host/secret?x=1&conversation=c1&card=note%3An1');
    expect(dialogUrl(`${window.location.origin}/h`, { conversation: 'c' })).toBe('/h?conversation=c');
    expect(dialogUrl('https://evil.test/h', {})).toBeNull();
    expect(dialogUrl('//evil', {})).toBeNull();
  });

  it('opens the dialog through a transient opener', () => {
    const root = document.createElement('div');
    document.body.append(root);
    expect(openSlotDialog(settings, '/host/x', 'X', root)).toBeNull();
    const dialog = document.createElement('dialog');
    dialog.id = 'slot-dialog';
    document.body.append(dialog);
    const clicks: HTMLElement[] = [];
    document.addEventListener('click', (event) => clicks.push(event.target as HTMLElement), { once: true });
    expect(openSlotDialog(settings, '/host/x', 'X', root)).toBe(dialog);
    expect(clicks[0]?.getAttribute('data-nowo-ui-dialog-open')).toBe('slot-dialog');
    expect(clicks[0]?.getAttribute('data-nowo-ui-dialog-src')).toBe('/host/x');
    expect(clicks[0]?.getAttribute('data-nowo-ui-dialog-title')).toBe('X');
    expect(root.children).toHaveLength(0);
    expect(openSlotDialog(settings, 'https://evil.test/x', 'X', root)).toBeNull();
  });

  it('hands the selection to the dialog now and when its content arrives', async () => {
    vi.useFakeTimers();
    const dialog = document.createElement('div');
    const seen: string[] = [];
    dialog.addEventListener(SLOT_PAYLOAD, (event) => seen.push((event as CustomEvent<{ selection: string }>).detail.selection));
    sendSlotPayload(dialog, 'secret text', 1000);
    expect(seen).toEqual(['secret text']);
    dialog.append(document.createElement('p'));
    await Promise.resolve();
    expect(seen).toEqual(['secret text', 'secret text']);
    sendSlotPayload(dialog, 'again', 1000);
    vi.advanceTimersByTime(1001);
    dialog.append(document.createElement('p'));
    await Promise.resolve();
    expect(seen).toEqual(['secret text', 'secret text', 'again']);
    const observer = globalThis.MutationObserver;
    // @ts-expect-error no MutationObserver
    delete globalThis.MutationObserver;
    sendSlotPayload(dialog, 'plain');
    globalThis.MutationObserver = observer;
    expect(seen.at(-1)).toBe('plain');
    vi.useRealTimers();
  });

  it('delivers inserted cards to the composer that opened the dialog', () => {
    const root = document.createElement('div');
    const got: unknown[] = [];
    const stop = bindInsertCard(root);
    root.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'note', id: 'n1' } }));
    expect(got).toEqual([]);
    setCardTarget((value) => got.push(value));
    root.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'note', id: 'n1', extra: 'x' } }));
    root.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'note', id: 'bad id' } }));
    expect(got).toEqual([{ type: 'note', id: 'n1' }]);
    stop();
    root.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'note', id: 'n2' } }));
    expect(got).toHaveLength(1);
  });

  it('asks the host before a send', () => {
    const root = document.createElement('div');
    const again = vi.fn();
    expect(beforeSend(root, 'c1', 'text', again)).toBe(false);
    let detail: { text: string; conversationUuid: string; sendAnyway: () => void } | null = null;
    root.addEventListener(BEFORE_SEND, (event) => {
      detail = (event as CustomEvent).detail;
      event.preventDefault();
    });
    expect(beforeSend(root, 'c1', 'password=hunter2', again)).toBe(true);
    expect(detail).not.toBeNull();
    const seen = detail as unknown as { text: string; conversationUuid: string; sendAnyway: () => void };
    expect(Object.isFrozen(seen)).toBe(true);
    expect([seen.text, seen.conversationUuid]).toEqual(['password=hunter2', 'c1']);
    seen.sendAnyway();
    seen.sendAnyway();
    expect(again).toHaveBeenCalledTimes(1);
  });

  it('finds slash aliases and the slots of a message', () => {
    expect(slashAction('/secret ', [action()])?.key).toBe('secret');
    expect(slashAction('/secret more', [action()])).toBeNull();
    expect(slashAction('/other', [action()])).toBeNull();
    expect(slashAction('/task', [action({ slash: null })])).toBeNull();

    const own = { uuid: 'm', mine: true, references: [card('task', '1'), card('vault_item', 'v1')] };
    expect(slotFor(slot(), own, '')).toEqual({ shown: true, card: null });
    expect(slotFor(slot({ ownOnly: true }), { ...own, mine: false }, '')).toEqual({ shown: false, card: null });
    expect(slotFor(slot({ target: 'selection' }), own, ' ')).toEqual({ shown: false, card: null });
    expect(slotFor(slot({ target: 'selection' }), own, 'x')).toEqual({ shown: true, card: null });
    expect(slotFor(slot({ target: 'card', cardTypes: ['vault_item'] }), own, '')).toEqual({ shown: true, card: { type: 'vault_item', id: 'v1' } });
    expect(slotFor(slot({ target: 'card', cardTypes: ['note'] }), { uuid: 'm', mine: false }, '')).toEqual({ shown: false, card: null });
    expect(slotFor(slot({ target: 'card', cardTypes: ['task'] }), { uuid: 'm', mine: false, references: [{ ...card('task', '1'), card: undefined }] }, '')).toEqual({ shown: false, card: null });
  });

  it('reads the selection inside an element only', () => {
    const inside = document.createElement('p');
    inside.textContent = 'secret words';
    const outside = document.createElement('p');
    outside.textContent = 'other';
    document.body.append(inside, outside);
    window.getSelection()?.removeAllRanges();
    expect(selectionIn(inside)).toBe('');
    const range = document.createRange();
    range.selectNodeContents(inside);
    window.getSelection()?.addRange(range);
    expect(selectionIn(inside)).toBe('secret words');
    expect(selectionIn(outside)).toBe('');
    vi.spyOn(window, 'getSelection').mockReturnValue(null);
    expect(selectionIn(inside)).toBe('');
  });

  it('keeps the cards of a composer as chips', async () => {
    const changed = vi.fn();
    const load = vi.fn().mockResolvedValue(null).mockResolvedValueOnce(card('vault_item', 'v1')).mockResolvedValueOnce(null).mockRejectedValueOnce(new Error('down'));
    const tray = new CardTray({ i18n, icon: () => document.createElement('i'), load, changed });
    expect(tray.element.hidden).toBe(true);
    expect(tray.add({ type: 'vault_item', id: 'v1' })).toBe(false);
    tray.setTypes([{ key: 'vault_item', label: 'Vault item', icon: 'key', secret: true }]);
    expect(tray.add({ type: 'vault_item', id: 'v1' })).toBe(true);
    expect(tray.add({ type: 'vault_item', id: 'v1' })).toBe(false);
    expect(tray.add({ type: 'vault_item', id: 'v2' })).toBe(true);
    expect(tray.add({ type: 'vault_item', id: 'v3' })).toBe(true);
    await Promise.resolve();
    await Promise.resolve();
    const titles = [...tray.element.querySelectorAll('.nowo-chat-cardtray__title')].map((title) => title.textContent);
    expect(titles).toEqual(['T', 'Not available', 'Not available']);
    expect(tray.element.querySelectorAll('.is-unavailable')).toHaveLength(2);
    expect(tray.element.hidden).toBe(false);
    expect(tray.element.getAttribute('aria-label')).toBe('Cards to send');
    document.body.append(tray.element);
    (tray.element.querySelector('.nowo-chat-cardtray__remove') as HTMLButtonElement).click();
    expect(tray.items).toEqual([{ type: 'vault_item', id: 'v2' }, { type: 'vault_item', id: 'v3' }]);
    expect(document.activeElement?.closest('[data-card-type]')).not.toBeNull();
    for (let i = 0; i < MAX_CARDS; i += 1) {
      tray.add({ type: 'vault_item', id: `x${i}` });
    }
    expect(tray.items).toHaveLength(MAX_CARDS);
    tray.reset();
    expect(tray.items).toEqual([]);
    // The last chip removed: focus goes to the text box.
    const focusAfterRemove = vi.fn();
    const single = new CardTray({ i18n, icon: () => document.createElement('i'), load: vi.fn().mockResolvedValue(null), changed, focusAfterRemove });
    single.setTypes([{ key: 'note', label: 'Note', icon: 'key', secret: false }]);
    single.add({ type: 'note', id: 'n1' });
    (single.element.querySelector('.nowo-chat-cardtray__remove') as HTMLButtonElement).click();
    expect(focusAfterRemove).toHaveBeenCalled();
    const bare = new CardTray({ i18n, icon: () => document.createElement('i'), load: vi.fn().mockResolvedValue(null), changed });
    bare.setTypes([{ key: 'note', label: 'Note', icon: 'key', secret: false }]);
    bare.add({ type: 'note', id: 'n1' });
    (bare.element.querySelector('.nowo-chat-cardtray__remove') as HTMLButtonElement).click();
    expect(bare.items).toEqual([]);
    expect(tray.element.hidden).toBe(true);
    expect(changed).toHaveBeenCalled();
  });
});
