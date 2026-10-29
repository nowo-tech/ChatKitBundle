/**
 * Composer tools (1.11): the formatting toolbar (bold, italic, strikethrough, code, link, quote, list) with Ctrl/⌘+B,
 * I and K, the formatting help, and the user's quick replies (saved snippets: insert, add, edit, delete). Formatting
 * writes the Markdown the server renders (escape-first, closed tag set); nothing here produces HTML from user text.
 */
import type { ChatApi } from './api';
import { h } from './dom';
import { t } from './format';
import type { QuickReply } from './types';

/** Formats of the toolbar, in order. */
export const FORMATS = ['bold', 'italic', 'strike', 'code', 'link', 'quote', 'list'] as const;

export type Format = (typeof FORMATS)[number];

/** Inline markers around the selection. */
const WRAP: Partial<Record<Format, [string, string]>> = {
  bold: ['**', '**'],
  italic: ['_', '_'],
  strike: ['~~', '~~'],
  code: ['`', '`'],
};

/** Line prefixes. */
const PREFIX: Partial<Record<Format, string>> = {
  quote: '> ',
  list: '- ',
};

/**
 * Applies a format to the selection of a text box (or inserts empty markers at the caret) and keeps the edited text
 * selected; fires `input` so drafts, auto-grow and the counter follow.
 *
 * @param box The composer's text box.
 * @param format The format.
 */
export function applyFormat(box: HTMLTextAreaElement, format: Format): void {
  const { selectionStart: start, selectionEnd: end, value } = box;
  const selected = value.slice(start, end);
  let replacement: string;
  let selectFrom: number;
  let selectTo: number;
  const wrap = WRAP[format];
  const prefix = PREFIX[format];
  if (format === 'code' && selected.includes('\n')) {
    replacement = `\`\`\`\n${selected}\n\`\`\``;
    selectFrom = start + 4;
    selectTo = selectFrom + selected.length;
  } else if (wrap !== undefined) {
    replacement = `${wrap[0]}${selected}${wrap[1]}`;
    selectFrom = start + wrap[0].length;
    selectTo = selectFrom + selected.length;
  } else if (prefix !== undefined) {
    // From the start of the first selected line.
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const block = value.slice(lineStart, end);
    replacement = block
      .split('\n')
      .map((line) => `${prefix}${line}`)
      .join('\n');
    box.setRangeText(replacement, lineStart, end, 'end');
    box.setSelectionRange(lineStart, lineStart + replacement.length);
    box.dispatchEvent(new Event('input', { bubbles: true }));
    box.focus();
    return;
  } else {
    // Link: [selection](https://) with the URL selected, ready to paste over.
    const label = selected === '' ? 'text' : selected;
    replacement = `[${label}](https://)`;
    selectFrom = start + label.length + 3;
    selectTo = selectFrom + 'https://'.length;
  }
  box.setRangeText(replacement, start, end, 'end');
  box.setSelectionRange(selectFrom, selectTo);
  box.dispatchEvent(new Event('input', { bubbles: true }));
  box.focus();
}

/**
 * The format of a keyboard shortcut (Ctrl/⌘+B, I, K), or null.
 *
 * @param event The keydown.
 * @returns The format, or null for any other key.
 */
export function shortcutFormat(event: KeyboardEvent): Format | null {
  if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) {
    return null;
  }
  return ({ b: 'bold', i: 'italic', k: 'link' } as Record<string, Format>)[event.key.toLowerCase()] ?? null;
}

/**
 * The formatting help: each syntax and what it gives.
 *
 * @param i18n Dock strings.
 * @returns The help list.
 */
export function formatHelp(i18n: Record<string, string>): HTMLElement {
  const rows: [string, string][] = [
    ['**bold**', 'format_bold'],
    ['_italic_', 'format_italic'],
    ['~~strike~~', 'format_strike'],
    ['`code`', 'format_code'],
    ['```block```', 'format_code_block'],
    ['[text](https://…)', 'format_link'],
    ['> quote', 'format_quote'],
    ['- item', 'format_list'],
    ['www.… · name@mail', 'format_autolink'],
  ];
  return h(
    'dl',
    { class: 'nowo-chat-format-help', 'data-part': 'format-help' },
    ...rows.flatMap(([syntax, key]) => [h('dt', {}, h('code', { text: syntax })), h('dd', { text: t(i18n, key) })]),
  );
}

/** What the quick-replies menu needs from its window. */
export interface QuickRepliesHost {
  readonly api: ChatApi;
  readonly i18n: Record<string, string>;
  /** Inserts text at the caret of the composer. */
  insert(text: string): void;
  /** Shows a short notice in the window. */
  notice(message: string): void;
}

/**
 * The user's quick replies: a popover above the composer listing them (a click inserts one), with Add, Edit and Delete.
 */
export class QuickRepliesMenu {
  readonly element: HTMLElement;
  private items: QuickReply[] = [];
  private loaded = false;

  /**
   * Creates the (closed) popover.
   *
   * @param host The window.
   * @param toggle The composer button that opens it.
   */
  constructor(
    private readonly host: QuickRepliesHost,
    private readonly toggle: HTMLButtonElement,
  ) {
    this.element = h('div', { class: 'nowo-chat-quick', role: 'dialog', 'aria-label': t(host.i18n, 'quick_replies'), 'data-testid': 'nowo-chat-quick-replies', hidden: true });
    this.element.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.close();
      }
    });
    toggle.addEventListener('click', () => void (this.element.hidden ? this.open() : Promise.resolve(this.close())));
  }

  /** Opens the popover (loading the snippets the first time). */
  async open(): Promise<void> {
    this.element.hidden = false;
    this.toggle.setAttribute('aria-expanded', 'true');
    if (!this.loaded) {
      try {
        this.items = await this.host.api.quickReplies();
        this.loaded = true;
      } catch {
        this.host.notice(t(this.host.i18n, 'error'));
      }
    }
    this.renderList();
  }

  /** Closes it and returns focus to the button. */
  close(): void {
    this.element.hidden = true;
    this.toggle.setAttribute('aria-expanded', 'false');
    this.toggle.focus();
  }

  /** The list: insert on click, edit / delete per row, Add at the end. */
  private renderList(): void {
    const i18n = this.host.i18n;
    const list = h('ul', { class: 'nowo-chat-quick__list' });
    for (const item of this.items) {
      const use = h('button', { type: 'button', class: 'nowo-chat-quick__use', title: item.body }, h('span', { class: 'nowo-chat-quick__title', text: item.title }), h('span', { class: 'nowo-chat-quick__body', text: item.body }));
      use.addEventListener('click', () => {
        this.host.insert(item.body);
        this.close();
      });
      const edit = h('button', { type: 'button', class: 'nowo-chat-quick__edit', text: t(i18n, 'edit'), 'aria-label': `${t(i18n, 'edit')} — ${item.title}` });
      edit.addEventListener('click', () => this.renderForm(item));
      const remove = h('button', { type: 'button', class: 'nowo-chat-quick__delete', text: t(i18n, 'delete'), 'aria-label': `${t(i18n, 'delete')} — ${item.title}` });
      remove.addEventListener('click', () => void this.remove(item));
      list.append(h('li', { class: 'nowo-chat-quick__item' }, use, edit, remove));
    }
    const add = h('button', { type: 'button', class: 'nowo-chat-quick__add', text: t(i18n, 'quick_reply_add') });
    add.addEventListener('click', () => this.renderForm(null));
    const empty = this.items.length === 0 ? [h('p', { class: 'nowo-chat-quick__empty', text: t(i18n, 'quick_replies_empty') })] : [];
    this.element.replaceChildren(h('p', { class: 'nowo-chat-quick__heading', text: t(i18n, 'quick_replies') }), ...empty, list, add);
    (this.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__use') ?? add).focus();
  }

  /**
   * The add / edit form (title and text).
   *
   * @param item The snippet to edit, or null for a new one.
   */
  private renderForm(item: QuickReply | null): void {
    const i18n = this.host.i18n;
    const title = h('input', { type: 'text', class: 'nowo-chat-quick__input', maxlength: 80, 'aria-label': t(i18n, 'quick_reply_title'), placeholder: t(i18n, 'quick_reply_title') });
    const body = h('textarea', { class: 'nowo-chat-quick__input', rows: 3, maxlength: 2000, 'aria-label': t(i18n, 'quick_reply_text'), placeholder: t(i18n, 'quick_reply_text') });
    title.value = item?.title ?? '';
    body.value = item?.body ?? '';
    const save = h('button', { type: 'submit', class: 'nowo-chat-quick__save', text: t(i18n, 'save') });
    const cancel = h('button', { type: 'button', class: 'nowo-chat-quick__cancel', text: t(i18n, 'cancel') });
    cancel.addEventListener('click', () => this.renderList());
    const form = h('form', { class: 'nowo-chat-quick__form' }, title, body, h('span', { class: 'nowo-chat-quick__actions' }, cancel, save));
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.save(item, title.value, body.value);
    });
    this.element.replaceChildren(form);
    title.focus();
  }

  /** Creates or changes a snippet; the list follows. */
  private async save(item: QuickReply | null, title: string, body: string): Promise<void> {
    try {
      const saved = item === null ? await this.host.api.createQuickReply(title, body) : await this.host.api.updateQuickReply(item.id, title, body);
      this.items = [...this.items.filter((other) => other.id !== saved.id), saved].sort((a, b) => a.title.localeCompare(b.title));
      this.renderList();
    } catch {
      this.host.notice(t(this.host.i18n, 'quick_reply_invalid'));
    }
  }

  /** Deletes a snippet. */
  private async remove(item: QuickReply): Promise<void> {
    try {
      await this.host.api.deleteQuickReply(item.id);
      this.items = this.items.filter((other) => other.id !== item.id);
      this.renderList();
    } catch {
      this.host.notice(t(this.host.i18n, 'error'));
    }
  }
}
