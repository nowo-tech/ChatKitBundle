/**
 * 1.35: the "Message info" dialog of the message menu — who read the message and when (read times only when both the
 * viewer and the reader share them: reciprocal), counts in conversations above `per_person_max_members`, and when it
 * was last edited. A native modal <dialog> (focus trapped, Escape closes it, focus returns to the message); text only
 * (`textContent`), no inline style (CSP); a bottom sheet on phones (CSS).
 */
import { h } from './dom';
import { exactTimeLabel, t } from './format';
import type { MessageInfo } from './types';

/**
 * Builds and opens the dialog.
 *
 * @param info What the server answered (`GET …/info`).
 * @param i18n Translated strings.
 * @param locale Locale of the times.
 * @param returnTo Element focused again when the dialog closes.
 * @returns The open dialog (removed from the page once closed).
 */
export function openMessageInfo(info: MessageInfo, i18n: Record<string, string>, locale: string, returnTo: HTMLElement | null): HTMLDialogElement {
  const title = h('h2', { class: 'nowo-chat-info__title', id: `nowo-chat-info-${Date.now()}`, text: t(i18n, 'info_title') });
  const close = h('button', { type: 'button', class: 'nowo-chat-info__close', 'data-part': 'info-close', text: t(i18n, 'info_close') });
  const body = h('div', { class: 'nowo-chat-info__body' });
  const summary = t(i18n, 'info_read_count', { read: info.readCount, total: info.memberCount });
  body.append(h('p', { class: 'nowo-chat-info__count', 'data-part': 'info-count', text: summary }));
  if (info.mode === 'people') {
    const list = h('ul', { class: 'nowo-chat-info__list', role: 'list', 'data-part': 'info-readers' });
    for (const reader of info.readers) {
      const state = !reader.read ? t(i18n, 'info_not_read') : reader.readAt !== null ? t(i18n, 'info_read_at', { time: exactTimeLabel(reader.readAt, locale) }) : t(i18n, 'info_read');
      const when = reader.readAt !== null ? h('time', { datetime: reader.readAt, text: state }) : h('span', { text: state });
      list.append(
        h(
          'li',
          { class: `nowo-chat-info__reader${reader.read ? ' is-read' : ''}`, 'data-part': 'info-reader' },
          h('span', { class: 'nowo-chat-info__name', text: reader.name }),
          when,
        ),
      );
    }
    body.append(list);
    if (!info.readTimes && info.readers.some((reader) => reader.read)) {
      body.append(h('p', { class: 'nowo-chat-info__hint', 'data-part': 'info-times-hidden', text: t(i18n, 'info_times_hidden') }));
    }
  }
  if (info.editedAt !== null) {
    body.append(h('p', { class: 'nowo-chat-info__edited', 'data-part': 'info-edited' }, h('time', { datetime: info.editedAt, text: t(i18n, 'info_edited', { time: exactTimeLabel(info.editedAt, locale) }) })));
  }
  const dialog = h('dialog', { class: 'nowo-chat-info', 'aria-labelledby': title.id, 'data-part': 'info-dialog' }, title, body, close);
  close.addEventListener('click', () => closeDialog(dialog));
  dialog.addEventListener('close', () => {
    dialog.remove();
    returnTo?.focus({ preventScroll: true });
  });
  // A click on the backdrop (outside the dialog box) closes it.
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
      closeDialog(dialog);
    }
  });
  document.body.append(dialog);
  if (typeof dialog.showModal === 'function') {
    dialog.showModal();
  } else {
    dialog.setAttribute('open', '');
  }
  close.focus();
  return dialog;
}

/**
 * Closes the dialog (a browser without the dialog API: drops `open` and fires `close` itself).
 *
 * @param dialog The dialog.
 */
function closeDialog(dialog: HTMLDialogElement): void {
  if (typeof dialog.close === 'function') {
    dialog.close();
    return;
  }
  dialog.removeAttribute('open');
  dialog.dispatchEvent(new Event('close'));
}
