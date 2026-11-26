import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatApiError, type ChatApi } from '../src/api';
import { plainText } from '../src/message-menu';
import { BEFORE_SEND, bindInsertCard, INSERT_CARD, SLOT_PAYLOAD } from '../src/slots';
import { plain, ThreadView, type ThreadHost } from '../src/thread';
import type { ChatConfig, ChatMessage, ConversationSlots, ConversationSummary } from '../src/types';
import { Templates } from '../src/templates';
import { templates, templatesRoot } from './support';

const i18n: Record<string, string> = { error: 'Error', card_not_allowed: 'Not allowed here', composer_actions: 'Actions', composer_cards: 'Cards', card_remove: 'Remove', card_unavailable: 'Gone', sending: 'Sending', retry: 'Retry', failed: 'Failed' };
const summary = (over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid: 'c1', kind: 'direct', title: 'Luis', subtitle: '', initials: 'LU', peerUuid: 'p', channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: null, lastMessage: null, ...over,
});
let nextId = 1;
const msg = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: nextId++, uuid: `m${nextId}`, authorUuid: 'p', authorName: 'Luis', authorInitials: 'LU', mine: false, kind: 'text', html: '<p>hola</p>',
  createdAt: '2026-11-26T10:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});
const cardHtml = '<p><span class="nowo-chat-ref nowo-chat-card nowo-chat-card--locked nowo-chat-ref--block" data-card-type="vault_item" data-card-id="v1"><span class="nowo-chat-card__head"><span class="nowo-chat-ref__title">Prod root DB</span></span><span class="nowo-chat-card__facts" role="list"><span class="nowo-chat-card__fact">Vault Project X</span></span><span class="nowo-chat-card__actions"><a class="nowo-chat-card__action" href="/vault/v1/request" data-card-action="request_access" data-card-dialog>Request access</a><a class="nowo-chat-card__action" href="/vault/v1">Open</a></span></span></p>';
const cardRef = { type: 'vault_item', id: 'v1', title: 'Prod root DB', url: '', subtitle: '', badge: '', tone: 'neutral', card: { state: 'locked' as const, facts: [], actions: [] } };
const slots: ConversationSlots = {
  cardTypes: [{ key: 'vault_item', label: 'Vault item', icon: 'key', secret: true }],
  composerActions: [{ key: 'secret', label: 'One-time secret', icon: 'lock', slash: 'secret', dialogUrl: '/host/secret' }, { key: 'vault', label: 'Vault', icon: 'key', slash: null, dialogUrl: '/host/vault' }],
  menuSlots: [
    { key: 'report', label: 'Report', icon: 'key', target: 'message', cardTypes: [], ownOnly: false, dialogUrl: '/host/report' },
    { key: 'save_vault', label: 'Save to vault', icon: 'key', target: 'selection', cardTypes: [], ownOnly: false, dialogUrl: '/host/save' },
    { key: 'card_info', label: 'Card info', icon: 'key', target: 'card', cardTypes: ['vault_item'], ownOnly: false, dialogUrl: '/host/card' },
  ],
};
const menu = { order: ['reply', 'forward', 'delete'], items: { reply: true, forward: true, delete: true }, quick: [], picker: false, quoteMaxLength: 40, saveDockEntry: false };

function setup(messages: ChatMessage[] = [], slotsAnswer: Promise<ConversationSlots> = Promise.resolve(slots), withSlots = true, bare = false) {
  const api = {
    messages: vi.fn().mockResolvedValue({ messages, hasMore: false, readUpTo: null, readers: [] }),
    send: vi.fn().mockImplementation(async (_uuid: string, body: string) => msg({ mine: true, html: `<p>${body}</p>` })),
    typing: vi.fn().mockResolvedValue(undefined),
    members: vi.fn().mockResolvedValue([]),
    pins: vi.fn().mockResolvedValue({ pins: [], canPin: false }),
    slots: vi.fn().mockReturnValue(slotsAnswer),
    card: vi.fn().mockResolvedValue(cardRef),
  };
  const root = document.createElement('div');
  root.setAttribute('data-nowo-chat-config', '{}');
  document.body.append(root);
  const dialog = document.createElement('dialog');
  dialog.id = 'slot-dialog';
  root.append(dialog);
  const host: ThreadHost = {
    config: {
      i18n, endpoints: { page: '/chat/__uuid__', forward: '/f/__uuid__/__message__' }, userUuid: 'me', locale: 'en', maxLength: 200, editWindowSeconds: 900, reactions: [], isAgent: false,
      prefs: { sound: 'pop', buzz: true, presence: true }, menu,
      ...(withSlots ? { slots: { endpoint: '/s/__uuid__', card: '/c/__uuid__/__type__/__id__', dialog: 'slot-dialog', dialogRuntime: '/ui/js/nowo-ui-dialog.js' } } : {}),
    } as unknown as ChatConfig,
    api: api as unknown as ChatApi,
    templates: bare ? bareTemplates() : templates(),
    minimise: vi.fn(), close: vi.fn(), saveDraft: vi.fn(), draft: vi.fn().mockReturnValue(''),
    messagesChanged: vi.fn(), read: vi.fn(), isMobile: vi.fn().mockReturnValue(false), conversationChanged: vi.fn(),
    replaceWith: vi.fn(), linkPreview: vi.fn().mockResolvedValue(null),
  };
  const view = new ThreadView(host, summary());
  root.append(view.element);
  const stop = bindInsertCard(root);
  const opened: { src: string | null; title: string | null }[] = [];
  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;
    if (target.hasAttribute('data-nowo-ui-dialog-open')) {
      opened.push({ src: target.getAttribute('data-nowo-ui-dialog-src'), title: target.getAttribute('data-nowo-ui-dialog-title') });
    }
  });
  const input = view.element.querySelector('[data-part="input"]') as HTMLTextAreaElement;
  const submit = () => (view.element.querySelector('[data-part="composer"]') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
  return { view, api, root, dialog, input, submit, opened, stop };
}

/** A host override of the window and message templates without the composer tools, field and slot model item. */
function bareTemplates(): Templates {
  const root = templatesRoot();
  for (const template of root.querySelectorAll<HTMLTemplateElement>('template')) {
    template.content.querySelectorAll('[data-part="composer-tools"], [data-part="menu-slot"][data-slot-key=""]').forEach((node) => node.remove());
    template.content.querySelectorAll('.nowo-chat-composer__field').forEach((node) => node.classList.remove('nowo-chat-composer__field'));
  }
  return new Templates(root);
}

const flush = async () => {
  for (let i = 0; i < 5; i += 1) {
    await Promise.resolve();
  }
};

describe('ThreadView cards and slots (2.6)', () => {
  beforeEach(() => {
    Element.prototype.scrollTo = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
      cb(0);
      return 1;
    });
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
    document.head.querySelectorAll('script').forEach((script) => script.remove());
  });

  it('shows the composer actions, inserts a card from the dialog and sends it after the text', async () => {
    const ctx = setup();
    await flush();
    const buttons = [...ctx.view.element.querySelectorAll<HTMLButtonElement>('.nowo-chat-composer__action')];
    expect(buttons.map((button) => button.getAttribute('aria-label'))).toEqual(['One-time secret (/secret)', 'Vault']);
    expect(document.head.querySelector('script[data-nowo-chat-dialog-runtime]')).not.toBeNull();
    buttons[1]?.click();
    expect(ctx.opened.at(-1)).toEqual({ src: '/host/vault?conversation=c1', title: 'Vault' });
    // The host page inserts a card (it bubbles to the dock root).
    ctx.dialog.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'vault_item', id: 'v1' }, bubbles: true }));
    await flush();
    expect(ctx.view.element.querySelector('.nowo-chat-cardtray__title')?.textContent).toBe('Prod root DB');
    expect(ctx.api.card).toHaveBeenCalledWith('c1', 'vault_item', 'v1');
    // A second card, then both removed: focus goes to the other chip, then back to the text box.
    ctx.dialog.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'vault_item', id: 'v2' }, bubbles: true }));
    const removes = () => [...ctx.view.element.querySelectorAll<HTMLButtonElement>('.nowo-chat-cardtray__remove')];
    expect(removes()).toHaveLength(2);
    removes()[1]?.click();
    expect(document.activeElement).toBe(removes()[0]);
    removes()[0]?.click();
    expect(document.activeElement).toBe(ctx.input);
    ctx.dialog.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'vault_item', id: 'v1' }, bubbles: true }));
    await flush();
    const send = ctx.view.element.querySelector('[data-part="send"]') as HTMLButtonElement;
    expect(send.disabled).toBe(false);
    ctx.submit();
    await flush();
    expect(ctx.api.send).toHaveBeenCalledWith('c1', '#[…](vault_item:v1)');
    expect(ctx.view.element.querySelector('.nowo-chat-cardtray')?.hasAttribute('hidden')).toBe(true);
    ctx.stop();
  });

  it('opens an action from its slash alias, and asks the host before sending', async () => {
    const ctx = setup();
    await flush();
    ctx.input.value = '/secret';
    ctx.submit();
    await flush();
    expect(ctx.api.send).not.toHaveBeenCalled();
    expect(ctx.opened.at(-1)?.src).toBe('/host/secret?conversation=c1');
    expect(ctx.input.value).toBe('');

    let pending: { sendAnyway: () => void; text: string } | null = null;
    ctx.root.addEventListener(BEFORE_SEND, (event) => {
      pending = (event as CustomEvent).detail;
      event.preventDefault();
    });
    ctx.input.value = 'password hunter2';
    ctx.submit();
    await flush();
    expect(ctx.api.send).not.toHaveBeenCalled();
    expect(ctx.input.value).toBe('password hunter2');
    expect((pending as unknown as { text: string }).text).toBe('password hunter2');
    (pending as unknown as { sendAnyway: () => void }).sendAnyway();
    await flush();
    expect(ctx.api.send).toHaveBeenCalledWith('c1', 'password hunter2');
  });

  it('says why a card was refused and keeps working without a dialog', async () => {
    const ctx = setup();
    await flush();
    ctx.api.send.mockRejectedValueOnce(new ChatApiError(422, 'card_not_allowed'));
    ctx.input.value = 'x';
    ctx.submit();
    await flush();
    expect(ctx.view.element.textContent).toContain('Not allowed here');
    ctx.dialog.remove();
    (ctx.view.element.querySelector('.nowo-chat-composer__action') as HTMLButtonElement).click();
    expect(ctx.view.element.querySelector('[data-part="notice"]')?.textContent).toBe('Error');
    // A card inserted with no composer waiting is ignored.
    ctx.root.dispatchEvent(new CustomEvent(INSERT_CARD, { detail: { type: 'vault_item', id: 'v9' } }));
    expect(ctx.view.element.querySelectorAll('.nowo-chat-cardtray__chip')).toHaveLength(0);
  });

  it('offers the menu slots on the messages they fit, and card actions in the dialog', async () => {
    const carded = msg({ uuid: 'carded', html: cardHtml, references: [cardRef], hasCards: true });
    const own = msg({ uuid: 'own', mine: true, authorUuid: 'me' });
    const ctx = setup([carded, own]);
    await ctx.view.load();
    await flush();
    const article = (id: number) => ctx.view.element.querySelector(`[data-id="${id}"]`) as HTMLElement;
    const a = article(carded.id);
    const slot = (node: Element, key: string) => node.querySelector<HTMLElement>(`[data-part="menu-slot"][data-slot-key="${key}"]`) as HTMLElement;
    expect(slot(a, 'report').hidden).toBe(false);
    expect(slot(a, 'report').textContent).toBe('Report');
    expect(slot(a, 'card_info').hidden).toBe(false);
    expect(slot(a, 'save_vault').hidden).toBe(true);
    expect((a.querySelector('[data-part="menu-forward"]') as HTMLElement).hidden).toBe(true);
    const o = article(own.id);
    expect(slot(o, 'card_info').hidden).toBe(true);

    slot(a, 'card_info').click();
    expect(ctx.opened.at(-1)?.src).toBe('/host/card?conversation=c1&message=carded&card=vault_item%3Av1');

    // A selection inside the message shows the selection slot; its text reaches the dialog in the browser only.
    const words = a.querySelector('.nowo-chat-ref__title') as HTMLElement;
    const range = document.createRange();
    range.selectNodeContents(words);
    window.getSelection()?.removeAllRanges();
    window.getSelection()?.addRange(range);
    a.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true }));
    expect(slot(a, 'save_vault').hidden).toBe(false);
    const payloads: string[] = [];
    ctx.dialog.addEventListener(SLOT_PAYLOAD, (event) => payloads.push((event as CustomEvent<{ selection: string }>).detail.selection));
    slot(a, 'save_vault').click();
    expect(payloads[0]).toBe('Prod root DB');
    expect(ctx.opened.at(-1)?.src).toBe('/host/save?conversation=c1&message=carded');
    expect(ctx.opened.at(-1)?.src).not.toContain('Prod');

    // A card action marked for the dialog opens there; the plain link stays a link.
    (a.querySelector('[data-card-dialog]') as HTMLAnchorElement).click();
    expect(ctx.opened.at(-1)).toEqual({ src: '/vault/v1/request', title: 'Request access' });
    expect(a.querySelector('[data-card-dialog]')?.getAttribute('aria-haspopup')).toBe('dialog');

    // Copy text and the editor never read a card's facts or actions.
    expect(plainText(a.querySelector('[data-part="bubble"]') as HTMLElement)).toBe('#Prod root DB');
    expect(plain(cardHtml)).toBe('#Prod root DB');
    expect(plain(`<p>text</p>${cardHtml}`, true)).toBe('text');

    // Without a dialog element, a slot says so.
    ctx.dialog.remove();
    slot(a, 'report').click();
    expect(ctx.view.element.querySelector('[data-part="notice"]')?.textContent).toBe('Error');
  });

  it('fits host templates without the composer tools or the slot model item', async () => {
    const ctx = setup([msg()], Promise.resolve(slots), true, true);
    await ctx.view.load();
    await flush();
    const composer = ctx.view.element.querySelector('[data-part="composer"]') as HTMLElement;
    expect(composer.firstElementChild?.getAttribute('data-part')).toBe('composer-actions');
    expect(composer.querySelector('.nowo-chat-cardtray')).not.toBeNull();
    expect(ctx.view.element.querySelectorAll('[data-part="menu-slot"]')).toHaveLength(1);
  });

  it('stays as before without slots, or when they cannot be loaded', async () => {
    const off = setup([msg({ html: cardHtml })], Promise.resolve(slots), false);
    await off.view.load();
    expect(off.api.slots).not.toHaveBeenCalled();
    expect(off.view.element.querySelector('.nowo-chat-cardtray')).toBeNull();
    (off.view.element.querySelector('[data-card-dialog]') as HTMLAnchorElement).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    document.body.replaceChildren();
    const failing = setup([], Promise.reject(new Error('down')));
    await flush();
    expect(failing.view.element.querySelector('.nowo-chat-composer__action')).toBeNull();
    const empty = setup([], Promise.resolve({ cardTypes: [], composerActions: [], menuSlots: [] }));
    await flush();
    expect(empty.view.element.querySelector('[data-part="composer-actions"]')).toBeNull();
  });
});

