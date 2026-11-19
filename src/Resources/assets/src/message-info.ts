/**
 * 1.35: the "Message info" dialog of the message menu — who read the message and when (read times only when both the
 * viewer and the reader share them: reciprocal), counts in conversations above `per_person_max_members`, and when it
 * was last edited. A native modal <dialog> (focus trapped, Escape closes it, focus returns to the message); text only
 * (`textContent`), no inline style (CSP); a bottom sheet on phones (CSS).
 */
import { h } from './dom';
import { exactTimeLabel, t } from './format';
import type { MessageInfo, MessageRevision } from './types';

/**
 * Builds and opens the dialog.
 *
 * @param info What the server answered (`GET …/info`).
 * @param i18n Translated strings.
 * @param locale Locale of the times.
 * @param returnTo Element focused again when the dialog closes.
 * @returns The open dialog (removed from the page once closed).
 */
export function openMessageInfo(
  info: MessageInfo,
  i18n: Record<string, string>,
  locale: string,
  returnTo: HTMLElement | null,
  revisions: (() => Promise<MessageRevision[]>) | null = null,
): HTMLDialogElement {
  const title = h('h2', { class: 'nowo-chat-info__title', id: `nowo-chat-info-${Date.now()}`, text: t(i18n, 'info_title') });
  const close = h('button', { type: 'button', class: 'nowo-chat-info__close', 'data-part': 'info-close', text: t(i18n, 'info_close') });
  const cross = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-dialog__close', 'data-part': 'info-header-close', 'aria-label': t(i18n, 'close'), title: t(i18n, 'close') }, h('span', { 'aria-hidden': 'true', text: '×' }));
  const body = h('div', { class: 'nowo-chat-info__body nowo-chat-dialog__body' });
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
  // 1.36: the edits (edit history), and their previous texts for the author and moderators.
  const edits = info.edits ?? [];
  if (edits.length > 0) {
    body.append(editsSection(edits, i18n, locale, revisions));
  }
  // 1.36.1: the standard dialog anatomy (header with the title and ×, scrolling body, footer), medium width and height.
  const dialog = h(
    'dialog',
    { class: 'nowo-chat-dialog nowo-chat-dialog--md nowo-chat-info', 'aria-labelledby': title.id, 'data-part': 'info-dialog' },
    h('header', { class: 'nowo-chat-dialog__header' }, title, cross),
    body,
    h('footer', { class: 'nowo-chat-dialog__footer' }, close),
  );
  presentDialog(dialog, close, returnTo);
  return dialog;
}

/**
 * 1.36: the "Edits" part of the info dialog: when the message was edited and, on demand, the previous texts.
 *
 * @param edits Edit times, newest first.
 * @param i18n Translated strings.
 * @param locale Locale of the times.
 * @param revisions Loads the previous texts (null: the viewer may not read them).
 * @returns The section.
 */
function editsSection(edits: { editedAt: string }[], i18n: Record<string, string>, locale: string, revisions: (() => Promise<MessageRevision[]>) | null): HTMLElement {
  const heading = h('h3', { class: 'nowo-chat-info__subtitle', text: t(i18n, 'info_edits') });
  const list = h('ul', { class: 'nowo-chat-info__list', role: 'list', 'data-part': 'info-edits' });
  for (const edit of edits) {
    list.append(h('li', { class: 'nowo-chat-info__edit', 'data-part': 'info-edit' }, h('time', { datetime: edit.editedAt, text: exactTimeLabel(edit.editedAt, locale) })));
  }
  const section = h('section', { class: 'nowo-chat-info__edits', 'aria-label': t(i18n, 'info_edits') }, heading, list);
  if (revisions !== null) {
    const show = h('button', { type: 'button', class: 'nowo-chat-info__history', 'data-part': 'info-history', text: t(i18n, 'info_show_history') });
    const status = h('p', { class: 'nowo-chat-info__hint', role: 'status', 'data-part': 'info-history-status' });
    show.addEventListener('click', () => {
      show.disabled = true;
      revisions()
        .then((rows) => {
          const texts = h('ol', { class: 'nowo-chat-info__revisions', 'data-part': 'info-revisions' });
          for (const row of rows) {
            const text = h('div', { class: 'nowo-chat-bubble nowo-chat-info__revision-text' });
            // Server-sanitised render of the previous text (ChatMarkdownRenderer), as message bubbles.
            // eslint-disable-next-line no-restricted-syntax -- sanitised on the server, see ChatMarkdownRenderer
            text.innerHTML = row.html;
            texts.append(h('li', { class: 'nowo-chat-info__revision', 'data-part': 'info-revision' }, h('time', { datetime: row.editedAt, text: t(i18n, 'info_replaced_at', { time: exactTimeLabel(row.editedAt, locale) }) }), text));
          }
          show.replaceWith(texts);
        })
        .catch(() => {
          show.disabled = false;
          status.textContent = t(i18n, 'error');
        });
    });
    section.append(show, status);
  }
  return section;
}

/**
 * 1.36: shows a modal dialog of the dock (focus on `focus`, Escape and the backdrop close it, focus goes back to
 * `returnTo`, the dialog leaves the page once closed).
 *
 * @param dialog The dialog.
 * @param focus Element focused when it opens.
 * @param returnTo Element focused again when it closes.
 */
export function presentDialog(dialog: HTMLDialogElement, focus: HTMLElement, returnTo: HTMLElement | null): void {
  dialog.querySelectorAll<HTMLElement>('[data-part$="-close"], [data-part="dialog-cancel"]').forEach((button) => button.addEventListener('click', () => closeDialog(dialog)));
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
  focus.focus();
}

/**
 * Closes the dialog (a browser without the dialog API: drops `open` and fires `close` itself).
 *
 * @param dialog The dialog.
 */
export function closeDialog(dialog: HTMLDialogElement): void {
  if (typeof dialog.close === 'function') {
    dialog.close();
    return;
  }
  dialog.removeAttribute('open');
  dialog.dispatchEvent(new Event('close'));
}
