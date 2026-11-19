/**
 * 1.36: the "Forward" dialog of the message menu — search the viewer's conversations (and, typing a name, people for a
 * new direct conversation), pick up to `forwardMaxTargets`, add an optional comment, forward. All or nothing: the
 * server refuses the whole forward when one target is refused, and the dialog says which. One `Idempotency-Key` per
 * dialog, so pressing Forward again after a lost answer forwards once. A native modal <dialog> (a full-height sheet on
 * phones, CSS); text only (`textContent`), no inline style (CSP).
 */
import { ChatApiError } from './api';
import { h } from './dom';
import { t } from './format';
import { presentDialog, closeDialog } from './message-info';
import type { ChatMessage, ForwardTarget } from './types';

/** What the dialog needs from the API client. */
export interface ForwardApi {
  forwardTargets(query: string): Promise<ForwardTarget[]>;
  forward(uuid: string, messageUuid: string, targets: ({ conversationUuid: string } | { contactUuid: string })[], comment: string, key: string): Promise<{ conversationUuid: string; message: ChatMessage }[]>;
}

/** Milliseconds the search waits for the typing to pause. */
const SEARCH_DELAY = 250;

/**
 * A fresh `Idempotency-Key` (random, URL-safe).
 *
 * @returns The key.
 */
export function newIdempotencyKey(): string {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

/**
 * Key of a target in the selection.
 *
 * @param target The target.
 * @returns `c:<uuid>` for a conversation, `u:<uuid>` for a person.
 */
function keyOf(target: ForwardTarget): string {
  return target.conversationUuid !== null ? `c:${target.conversationUuid}` : `u:${target.contactUuid ?? ''}`;
}

/**
 * Builds and opens the dialog.
 *
 * @param api The API client.
 * @param i18n Translated strings.
 * @param conversationUuid Conversation of the message.
 * @param messageUuid The message.
 * @param max Conversations one forward may go to.
 * @param returnTo Element focused again when the dialog closes.
 * @param done Called with the number of conversations it went to.
 * @returns The open dialog (removed from the page once closed).
 */
export function openForwardDialog(api: ForwardApi, i18n: Record<string, string>, conversationUuid: string, messageUuid: string, max: number, returnTo: HTMLElement | null, done: (count: number) => void): HTMLDialogElement {
  const id = `nowo-chat-forward-${Date.now()}`;
  const key = newIdempotencyKey();
  const selected = new Map<string, ForwardTarget>();
  const title = h('h2', { class: 'nowo-chat-forward__title', id: `${id}-title`, text: t(i18n, 'forward_title') });
  const search = h('input', { type: 'search', class: 'nowo-chat-forward__search', id: `${id}-search`, 'data-part': 'forward-search', autocomplete: 'off', 'aria-controls': `${id}-list`, placeholder: t(i18n, 'forward_search') });
  const searchLabel = h('label', { class: 'nowo-chat-forward__label', for: `${id}-search`, text: t(i18n, 'forward_search') });
  const list = h('ul', { class: 'nowo-chat-forward__list', id: `${id}-list`, role: 'list', 'data-part': 'forward-targets', 'aria-label': t(i18n, 'forward_targets') });
  const hint = h('p', { class: 'nowo-chat-forward__hint', 'data-part': 'forward-hint', text: t(i18n, 'forward_max', { max }) });
  const comment = h('textarea', { class: 'nowo-chat-forward__comment', id: `${id}-comment`, rows: 2, maxlength: 4000, 'data-part': 'forward-comment' });
  const commentLabel = h('label', { class: 'nowo-chat-forward__label', for: `${id}-comment`, text: t(i18n, 'forward_comment') });
  const status = h('p', { class: 'nowo-chat-forward__status', role: 'status', 'data-part': 'forward-status' });
  const cancel = h('button', { type: 'button', class: 'nowo-chat-forward__cancel', 'data-part': 'dialog-cancel', text: t(i18n, 'cancel') });
  const send = h('button', { type: 'button', class: 'nowo-chat-forward__send', 'data-part': 'forward-send', text: t(i18n, 'forward_send'), disabled: true });
  const cross = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-dialog__close', 'data-part': 'forward-close', 'aria-label': t(i18n, 'close'), title: t(i18n, 'close') }, h('span', { 'aria-hidden': 'true', text: '×' }));
  // 1.36.1: the standard dialog anatomy (header with the title and ×, scrolling body, footer), medium width and height.
  const dialog = h(
    'dialog',
    { class: 'nowo-chat-dialog nowo-chat-dialog--md nowo-chat-forward', 'aria-labelledby': title.id, 'data-part': 'forward-dialog' },
    h('header', { class: 'nowo-chat-dialog__header' }, title, cross),
    h('div', { class: 'nowo-chat-dialog__body' }, searchLabel, search, hint, list, commentLabel, comment, status),
    h('footer', { class: 'nowo-chat-dialog__footer nowo-chat-forward__actions' }, cancel, send),
  );

  const paint = (targets: ForwardTarget[]): void => {
    // Picked targets stay listed (first) whatever the search shows.
    const shown = [...selected.values(), ...targets.filter((target) => !selected.has(keyOf(target)))];
    list.replaceChildren(
      ...shown.map((target) => {
        const box = h('input', { type: 'checkbox', 'data-part': 'forward-target', value: keyOf(target), checked: selected.has(keyOf(target)) });
        box.disabled = !box.checked && selected.size >= max;
        box.addEventListener('change', () => {
          if (box.checked) {
            selected.set(keyOf(target), target);
          } else {
            selected.delete(keyOf(target));
          }
          send.disabled = selected.size === 0;
          list.querySelectorAll<HTMLInputElement>('input[type="checkbox"]').forEach((other) => {
            other.disabled = !other.checked && selected.size >= max;
          });
        });
        const kind = t(i18n, target.contactUuid !== null ? 'forward_new_direct' : `forward_kind_${target.kind}`);
        return h('li', { class: 'nowo-chat-forward__item' }, h('label', { class: 'nowo-chat-forward__option' }, box, h('span', { class: 'nowo-chat-forward__name', text: target.title }), h('span', { class: 'nowo-chat-forward__kind', text: kind })));
      }),
    );
    if (shown.length === 0) {
      list.append(h('li', { class: 'nowo-chat-forward__empty', text: t(i18n, 'forward_none') }));
    }
  };
  let timer = 0;
  let asked = 0;
  const load = (): void => {
    const ask = ++asked;
    api
      .forwardTargets(search.value)
      .then((targets) => {
        if (ask === asked) {
          paint(targets);
        }
      })
      .catch(() => {
        status.textContent = t(i18n, 'error');
      });
  };
  search.addEventListener('input', () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(load, SEARCH_DELAY);
  });
  // The button is disabled while nothing is picked.
  send.addEventListener('click', () => {
    const picked = [...selected.values()];
    send.disabled = true;
    status.textContent = t(i18n, 'forward_sending');
    api
      .forward(
        conversationUuid,
        messageUuid,
        picked.map((target) => (target.conversationUuid !== null ? { conversationUuid: target.conversationUuid } : { contactUuid: target.contactUuid ?? '' })),
        comment.value,
        key,
      )
      .then((copies) => {
        closeDialog(dialog);
        done(copies.length);
      })
      .catch((error: unknown) => {
        send.disabled = false;
        if (error instanceof ChatApiError && error.code === 'forward_forbidden') {
          const name = error.target === null ? undefined : picked[error.target]?.title;
          status.textContent = name === undefined ? t(i18n, 'forward_forbidden') : t(i18n, 'forward_forbidden_target', { name });
          return;
        }
        status.textContent = t(i18n, error instanceof ChatApiError && error.code === 'rate_limited' ? 'rate_limited' : 'error');
      });
  });
  presentDialog(dialog, search, returnTo);
  load();
  return dialog;
}
