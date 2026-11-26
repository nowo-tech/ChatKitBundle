/**
 * 2.6 (spec 259): typed cards, composer action slots and message menu slots of the host.
 *
 * - A composer action (or its slash alias, `/secret`) opens a host page in the dock's UiKit dialog with
 *   `?conversation=<uuid>`; the page inserts a card by dispatching `nowo-chat:insert-card` `{type, id}` (it bubbles to
 *   the dock root). The card shows as a chip above the composer and is sent as a token `#[…](type:id)` after the text.
 * - A message menu slot opens a host page with `?conversation=&message=(&card=type:id)`. A selection never goes in a
 *   URL or to the chat server: it is handed to the dialog element in the browser (`nowo-chat:slot-payload`).
 * - Before a send, a thread reply or an edit, `nowo-chat:before-send` (cancelable, `kind`) lets the host warn about
 *   secrets in the text; `sendAnyway()` sends without asking again.
 *
 * The server checks everything again: `canShare()` on send (422 `card_not_allowed`), the host page checks the person.
 */
import { h } from './dom';
import { t } from './format';
import { sameOriginPath } from './sidebar';
import type { CardType, ComposerAction, MenuSlot, Reference, SlotSettings } from './types';

/** A card type or slot key (the reference key grammar). */
const KEY = /^[a-z][a-z0-9_-]{0,31}$/;

/** A card id (the reference id grammar). */
const ID = /^[A-Za-z0-9_-]{1,64}$/;

/** The neutral title every stored card token carries. */
export const NEUTRAL = '…';

/** At most this many cards in one message (with the references: ChatReferences::MAX_REFERENCES). */
export const MAX_CARDS = 10;

/** Event the host page dispatches to insert a card. */
export const INSERT_CARD = 'nowo-chat:insert-card';

/** Event the dock dispatches on the dialog element with the selection of a slot. */
export const SLOT_PAYLOAD = 'nowo-chat:slot-payload';

/** Cancelable event the dock dispatches on its root before a send. */
export const BEFORE_SEND = 'nowo-chat:before-send';

/** UiKit's remote dialog: its content has loaded (bubbles, on the dialog). */
export const DIALOG_LOADED = 'nowo-ui:remote-form:loaded';

/** UiKit's remote dialog: it closed (bubbles, on the dialog). */
export const DIALOG_CLOSED = 'nowo-ui:remote-form:closed';

/** Where a `nowo-chat:before-send` comes from: the composer, a thread reply or an edit (audit ZCI-L4). */
export type SendKind = 'send' | 'thread' | 'edit';

/**
 * Calls `stop` once when the dialog closes (UiKit's closed event or the native `close`).
 *
 * @param dialog The dialog element.
 * @param stop What to do.
 * @param signal Removes the listeners when aborted.
 */
function onDialogClose(dialog: HTMLElement, stop: () => void, signal: AbortSignal): void {
  dialog.addEventListener(DIALOG_CLOSED, stop, { once: true, signal });
  dialog.addEventListener('close', stop, { once: true, signal });
}

/** A card picked in the composer. */
export interface CardRef {
  type: string;
  id: string;
}

/**
 * Whether a value is a well-formed `{type, id}` card reference.
 *
 * @param value Event detail.
 * @returns True when it can be inserted.
 */
export function isCardRef(value: unknown): value is CardRef {
  if (value === null || typeof value !== 'object') {
    return false;
  }
  const { type, id } = value as Record<string, unknown>;
  return typeof type === 'string' && typeof id === 'string' && KEY.test(type) && ID.test(id);
}

/**
 * The token of a card, as the server stores it.
 *
 * @param card The card.
 * @returns `#[…](type:id)`.
 */
export function cardToken(card: CardRef): string {
  return `#[${NEUTRAL}](${card.type}:${card.id})`;
}

/**
 * The text with the cards appended, each in its own paragraph (a full-width card).
 *
 * @param text Encoded composer text.
 * @param cards Cards to send.
 * @returns The body to send.
 */
export function withCards(text: string, cards: CardRef[]): string {
  const tokens = cards.map(cardToken).join('\n\n');
  const trimmed = text.trim();
  if (tokens === '') {
    return trimmed;
  }
  return trimmed === '' ? tokens : `${trimmed}\n\n${tokens}`;
}

/**
 * The editor text without the tokens of the given card types (an edit changes the text only: the server keeps the
 * message's cards).
 *
 * @param body Text with tokens.
 * @param types Card type keys.
 * @returns The text.
 */
export function withoutCards(body: string, types: string[]): string {
  if (types.length === 0) {
    return body;
  }
  const pattern = new RegExp(`#\\[[^[\\]\\n]{1,120}\\]\\((?:${types.map((type) => type.replace(/-/g, '\\-')).join('|')}):[A-Za-z0-9_-]{1,64}\\)`, 'g');
  return body.replace(pattern, '').replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * A host dialog URL with the given parameters (same origin only).
 *
 * @param base The host's dialog URL.
 * @param params Parameters to add (`conversation`, `message`, `card`).
 * @returns The URL, or null when it is not same-origin.
 */
export function dialogUrl(base: string, params: Record<string, string>): string | null {
  const path = sameOriginPath(base);
  if (path === null) {
    return null;
  }
  const url = new URL(path, window.location.origin);
  for (const [name, value] of Object.entries(params)) {
    url.searchParams.set(name, value);
  }
  return `${url.pathname}${url.search}`;
}

/**
 * Opens a host page in the dock's UiKit dialog (through a transient opener: UiKit's runtime binds by delegation).
 *
 * @param settings The `slots` settings.
 * @param url Same-origin URL of the page.
 * @param title Dialog title.
 * @param root Element the opener is added to (inside the dock).
 * @returns The dialog element, or null when the dialog cannot open (no dialog or no runtime on the page).
 */
export function openSlotDialog(settings: SlotSettings, url: string, title: string, root: HTMLElement): HTMLElement | null {
  const dialog = document.getElementById(settings.dialog);
  if (dialog === null || sameOriginPath(url) === null) {
    return null;
  }
  const opener = h('a', {
    href: url,
    hidden: true,
    'data-nowo-ui-dialog-open': settings.dialog,
    'data-nowo-ui-dialog-src': url,
    'data-nowo-ui-dialog-form': '',
    'data-nowo-ui-dialog-title': title,
  });
  // A click the runtime does not handle must not navigate away from the draft.
  opener.addEventListener('click', (event) => event.preventDefault());
  root.append(opener);
  opener.click();
  opener.remove();
  return dialog;
}

/**
 * Hands a selection to the host dialog, in the browser only: now, and once more when the dialog's content has loaded
 * (UiKit's `nowo-ui:remote-form:loaded`; the remote page arrives after the dialog opens). Audit ZCI-I2: never after the
 * dialog closed or `waitMs` passed, so a later dialog never receives this selection.
 *
 * @param dialog The dialog element.
 * @param selection The selected text.
 * @param waitMs How long to wait for the content (ms).
 */
export function sendSlotPayload(dialog: HTMLElement, selection: string, waitMs = 10_000): void {
  const fire = (): void => {
    dialog.dispatchEvent(new CustomEvent(SLOT_PAYLOAD, { detail: Object.freeze({ selection }) }));
  };
  fire();
  const done = new AbortController();
  const signal = AbortSignal.any([done.signal, AbortSignal.timeout(waitMs)]);
  dialog.addEventListener(
    DIALOG_LOADED,
    () => {
      done.abort();
      fire();
    },
    { once: true, signal },
  );
  onDialogClose(dialog, () => done.abort(), signal);
}

/** Where an inserted card goes: the composer whose action opened the dialog. */
let cardTarget: ((card: CardRef) => void) | null = null;

/** Stops watching the dialog of the current target. */
let cardTargetWatch: AbortController | null = null;

/**
 * Sets (or clears) the composer an inserted card goes to. Audit ZCI-I1: with the dialog its action opened, the target
 * is cleared when that dialog closes, so a later `nowo-chat:insert-card` (another dialog, another widget) is ignored.
 *
 * @param target The composer's insert function; null for none.
 * @param dialog The dialog the composer's action opened.
 */
export function setCardTarget(target: ((card: CardRef) => void) | null, dialog: HTMLElement | null = null): void {
  cardTargetWatch?.abort();
  cardTargetWatch = null;
  cardTarget = target;
  if (target === null || dialog === null) {
    return;
  }
  const watch = new AbortController();
  cardTargetWatch = watch;
  onDialogClose(
    dialog,
    () => {
      watch.abort();
      if (cardTarget === target) {
        cardTarget = null;
        cardTargetWatch = null;
      }
    },
    watch.signal,
  );
}

/**
 * Listens to `nowo-chat:insert-card` on the dock root: a well-formed card goes to the composer whose action opened the
 * dialog; anything else is ignored.
 *
 * @param root The dock root.
 * @returns A function that stops listening.
 */
export function bindInsertCard(root: HTMLElement): () => void {
  const listener = (event: Event): void => {
    const detail = (event as CustomEvent<unknown>).detail;
    if (cardTarget !== null && isCardRef(detail)) {
      cardTarget({ type: detail.type, id: detail.id });
    }
  };
  root.addEventListener(INSERT_CARD, listener);
  return () => root.removeEventListener(INSERT_CARD, listener);
}

/**
 * Asks the host before a send (`nowo-chat:before-send`, cancelable). The text is read-only; `sendAnyway()` sends it
 * without asking again. Audit ZCI-L4: asked for the composer, thread replies and edits (`kind`).
 *
 * @param root The dock root.
 * @param conversationUuid The conversation.
 * @param text The text about to be sent.
 * @param sendAnyway Sends the same text without the event.
 * @param kind Where the text is sent from.
 * @returns True when the host cancelled the send.
 */
export function beforeSend(root: HTMLElement, conversationUuid: string, text: string, sendAnyway: () => void, kind: SendKind = 'send'): boolean {
  let used = false;
  const detail = Object.freeze({
    conversationUuid,
    text,
    kind,
    sendAnyway: (): void => {
      if (!used) {
        used = true;
        sendAnyway();
      }
    },
  });
  return !root.dispatchEvent(new CustomEvent(BEFORE_SEND, { detail, cancelable: true, bubbles: true }));
}

/**
 * The composer action of a slash alias typed as the whole text (`/secret`).
 *
 * @param text Composer text.
 * @param actions The conversation's composer actions.
 * @returns The action, or null.
 */
export function slashAction(text: string, actions: ComposerAction[]): ComposerAction | null {
  const match = /^\/([a-z][a-z0-9_-]{0,31})\s*$/.exec(text.trim());
  return match === null ? null : (actions.find((action) => action.slash === match[1]) ?? null);
}

/** A message as the menu slots see it. */
export interface SlotMessage {
  uuid: string;
  mine: boolean;
  references?: Reference[];
}

/**
 * Whether a slot applies to a message, and the card it acts on.
 *
 * @param slot The slot.
 * @param message The message.
 * @param selection The text selected in the message ('' for none).
 * @returns `{shown, card}` (card for card slots).
 */
export function slotFor(slot: MenuSlot, message: SlotMessage, selection: string): { shown: boolean; card: CardRef | null } {
  if (slot.ownOnly && !message.mine) {
    return { shown: false, card: null };
  }
  if (slot.target === 'selection') {
    return { shown: selection.trim() !== '', card: null };
  }
  if (slot.target === 'card') {
    const card = (message.references ?? []).find((reference) => reference.card !== undefined && slot.cardTypes.includes(reference.type));
    return card === undefined ? { shown: false, card: null } : { shown: true, card: { type: card.type, id: card.id } };
  }
  return { shown: true, card: null };
}

/**
 * The text the viewer selected inside an element ('' when the selection is elsewhere or empty).
 *
 * @param element The message bubble.
 * @returns The selected text.
 */
export function selectionIn(element: Element): string {
  const selection = window.getSelection();
  if (selection === null || selection.rangeCount === 0 || selection.isCollapsed) {
    return '';
  }
  const range = selection.getRangeAt(0);
  return element.contains(range.commonAncestorContainer) ? selection.toString() : '';
}

/** What the composer's card tray needs from its host. */
export interface CardTrayHost {
  i18n: Record<string, string>;
  icon(name: string): Element;
  /**
   * The card as the writer sees it (null: not available).
   *
   * @param card The card.
   * @returns The rendered card.
   */
  load(card: CardRef): Promise<Reference | null>;
  /** Called when the tray changes (send button state). */
  changed(): void;
  /** Where focus goes when the last chip is removed (the text box). */
  focusAfterRemove?(): void;
}

/** The cards waiting in a composer (chips above the text box). Never kept in the draft. */
export class CardTray {
  readonly element: HTMLElement;
  private readonly host: CardTrayHost;
  private readonly list: HTMLElement;
  private cards: CardRef[] = [];
  private types: CardType[] = [];

  /**
   * @param host Strings, icons and the card loader.
   */
  constructor(host: CardTrayHost) {
    this.host = host;
    this.list = h('ul', { class: 'nowo-chat-cardtray__list', role: 'list' });
    this.element = h('div', { class: 'nowo-chat-cardtray', role: 'group', 'aria-label': t(host.i18n, 'composer_cards'), 'data-testid': 'nowo-chat-cardtray', hidden: true }, this.list);
  }

  /**
   * The card types of the conversation (only these may be inserted).
   *
   * @param types Card types.
   */
  setTypes(types: CardType[]): void {
    this.types = types;
  }

  /**
   * The cards waiting.
   *
   * @returns A copy of the list.
   */
  get items(): CardRef[] {
    return [...this.cards];
  }

  /**
   * Adds a card (ignored when its type is unknown, it is already there, or the tray is full).
   *
   * @param card The card.
   * @returns True when added.
   */
  add(card: CardRef): boolean {
    const type = this.types.find((item) => item.key === card.type);
    if (type === undefined || this.cards.length >= MAX_CARDS || this.cards.some((item) => item.type === card.type && item.id === card.id)) {
      return false;
    }
    this.cards.push(card);
    const title = h('span', { class: 'nowo-chat-cardtray__title', text: '…' });
    const remove = h('button', { type: 'button', class: 'nowo-chat-cardtray__remove', 'aria-label': `${t(this.host.i18n, 'card_remove')}: ${type.label}`, title: t(this.host.i18n, 'card_remove') }, h('span', { 'aria-hidden': 'true', text: '×' }));
    const chip = h('li', { class: 'nowo-chat-cardtray__chip', 'data-card-type': card.type }, this.host.icon(type.icon), h('span', { class: 'nowo-chat-cardtray__type', text: type.label }), title, remove);
    remove.addEventListener('click', () => {
      // Focus never falls back to the page: the next chip's remove button, else the text box.
      const next = (chip.nextElementSibling ?? chip.previousElementSibling)?.querySelector<HTMLButtonElement>('.nowo-chat-cardtray__remove') ?? null;
      this.cards = this.cards.filter((item) => item !== card);
      chip.remove();
      this.refresh();
      if (next !== null) {
        next.focus();
      } else {
        this.host.focusAfterRemove?.();
      }
    });
    this.list.append(chip);
    this.refresh();
    void this.host.load(card).then(
      (view) => {
        title.textContent = view === null ? t(this.host.i18n, 'card_unavailable') : view.title;
        chip.classList.toggle('is-unavailable', view === null);
      },
      () => {
        title.textContent = t(this.host.i18n, 'card_unavailable');
        chip.classList.add('is-unavailable');
      },
    );
    return true;
  }

  /** Empties the tray (after a send). */
  reset(): void {
    this.cards = [];
    this.list.replaceChildren();
    this.refresh();
  }

  private refresh(): void {
    this.element.hidden = this.cards.length === 0;
    this.host.changed();
  }
}
