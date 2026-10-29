/**
 * #references in the composer and the inline editor. `/` at the start of the message opens the command menu (`/task`,
 * `/issue` … — only the types the server offers in this conversation); after a command, the text typed searches its
 * items (`/task deploy`). `#` anywhere searches every offered type. ↑ ↓ move, Enter / Tab pick, Escape closes; picking
 * an item writes `#Title` in the text, which becomes the token `#[Title](type:id)` on send (encodeReferences). The
 * server keeps a token only for an item the conversation may open and renders it per viewer (ChatReferences).
 */
import { h } from './dom';
import { t } from './format';
import type { Reference, ReferenceType } from './types';

/** A stored reference token: `#[title](type:id)`. */
const TOKEN = /#\[([^[\]\n]{1,120})\]\(([a-z][a-z0-9_-]{0,31}):([A-Za-z0-9_-]{1,64})\)/g;

/** `/command` (and the query after it) as the whole text before the caret, on the first line. */
const COMMAND = /^\/([a-z0-9_-]{0,32})(?: ([^\n]{0,80}))?$/;

/** `#` and the query typed after it, right before the caret (one or two words). */
const HASH = /(?:^|[\s(])#([^\s#[\]]{0,40}(?: [^\s#[\]]{1,40})?)$/u;

/** A reference picked in a text box: type, id and the title written in the text. */
export interface ReferenceRef {
  type: string;
  id: string;
  title: string;
}

/**
 * Turns stored tokens into the text the composer shows (`#Title`) and the references they stand for.
 *
 * @param body Message text with tokens (a draft).
 * @returns The text to show and the references found.
 */
export function decodeReferences(body: string): { text: string; references: ReferenceRef[] } {
  const references: ReferenceRef[] = [];
  const text = body.replace(TOKEN, (_match, title: string, type: string, id: string) => {
    references.push({ type, id, title });
    return `#${title}`;
  });
  return { text, references };
}

/**
 * Turns `#Title` of the picked references back into tokens (longest titles first, whole titles only).
 *
 * @param text Composer text.
 * @param references References picked in this composer.
 * @returns The text with tokens.
 */
export function encodeReferences(text: string, references: ReferenceRef[]): string {
  const sorted = [...references].sort((a, b) => b.title.length - a.title.length);
  let encoded = text;
  for (const reference of sorted) {
    const title = reference.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    encoded = encoded.replace(new RegExp(`(?<![\\p{L}\\p{N}_#\\[])#${title}(?![\\p{L}\\p{N}_\\]])`, 'gu'), `#[${reference.title}](${reference.type}:${reference.id})`);
  }
  return encoded;
}

/** What the picker needs from its host. */
export interface ReferenceOptions {
  /**
   * The commands (`type` "") or the items of a type (`*` for all) matching the query.
   *
   * @param type Type key, `*` or "".
   * @param query Search text.
   * @returns The server's answer.
   */
  load(type: string, query: string): Promise<{ types: ReferenceType[]; references: Reference[] }>;
  /**
   * Icon element by name (the dock's icons).
   *
   * @param name Icon name.
   * @returns The icon.
   */
  icon(name: string): Element;
  /** Dock strings (`reference_list`, `reference_commands`). */
  i18n: Record<string, string>;
  /** Unique id prefix for the listbox and its options. */
  idPrefix: string;
  /** Wait after the last keystroke before asking the server (ms). */
  debounceMs?: number;
}

/** One option of the list: a command or an item. */
type Option = { kind: 'type'; type: ReferenceType } | { kind: 'item'; reference: Reference };

/** What the text before the caret asks for. */
type Lookup = { mode: 'commands'; prefix: string } | { mode: 'search'; type: string; query: string };

/** The `/` command menu and `#` picker of one text box (ARIA: `aria-autocomplete` list, listbox, `aria-activedescendant`). */
export class ReferenceAutocomplete {
  private readonly input: HTMLTextAreaElement;
  private readonly options: ReferenceOptions;
  private readonly list: HTMLUListElement;
  private readonly picked = new Map<string, ReferenceRef>();
  private types: ReferenceType[] | null = null;
  private items: Option[] = [];
  private active = -1;
  private seq = 0;
  private timer: number | undefined;
  private range: { start: number; end: number } = { start: 0, end: 0 };

  /**
   * Binds the picker to a text box. Create it before the box's own keydown handlers, so Enter / Escape pick or close
   * the list instead of sending the message or closing the window.
   *
   * @param input The composer or editor text box.
   * @param options Loader, icons, strings and ids.
   */
  constructor(input: HTMLTextAreaElement, options: ReferenceOptions) {
    this.input = input;
    this.options = options;
    this.list = h('ul', { class: 'nowo-chat-ref-list', role: 'listbox', id: `${options.idPrefix}-references`, 'aria-label': t(options.i18n, 'reference_list'), hidden: true });
    const host = input.parentElement as HTMLElement;
    host.classList.add('nowo-chat-mention-host');
    host.append(this.list);
    input.setAttribute('aria-autocomplete', 'list');
    input.addEventListener('keydown', (event) => this.onKeydown(event));
    input.addEventListener('input', () => this.schedule());
    input.addEventListener('blur', () => this.close());
  }

  /**
   * Whether the list is shown.
   *
   * @returns True while open.
   */
  get isOpen(): boolean {
    return !this.list.hidden;
  }

  /**
   * References picked (or restored) in this box.
   *
   * @returns The references.
   */
  get references(): ReferenceRef[] {
    return [...this.picked.values()];
  }

  /**
   * Remembers references written before (an edited message, a restored draft).
   *
   * @param references References to keep.
   */
  seed(references: ReferenceRef[]): void {
    for (const reference of references) {
      this.picked.set(`${reference.type}:${reference.id}`, { type: reference.type, id: reference.id, title: reference.title });
    }
  }

  /**
   * The text of a stored body (tokens become `#Title`), remembering its references.
   *
   * @param body Text with tokens.
   * @returns The text to show.
   */
  decode(body: string): string {
    const { text, references } = decodeReferences(body);
    this.seed(references);
    return text;
  }

  /**
   * The text with the picked titles as tokens.
   *
   * @param text Box text (mention tokens already encoded).
   * @returns The encoded text.
   */
  encode(text: string): string {
    return encodeReferences(text, this.references);
  }

  /** Forgets the picked references and closes the list (after a send). */
  reset(): void {
    this.picked.clear();
    this.close();
  }

  /** Hides the list and cancels a pending lookup. */
  close(): void {
    window.clearTimeout(this.timer);
    this.seq += 1;
    this.items = [];
    this.active = -1;
    if (!this.list.hidden) {
      this.input.removeAttribute('aria-controls');
      this.input.removeAttribute('aria-activedescendant');
    }
    this.list.hidden = true;
    this.list.replaceChildren();
  }

  /** Looks for `/command query` or `#query` before the caret and asks the server after a short pause. */
  private schedule(): void {
    const caret = this.input.selectionStart ?? this.input.value.length;
    const before = this.input.value.slice(0, caret);
    const command = COMMAND.exec(before);
    const hash = command === null ? HASH.exec(before) : null;
    let lookup: Lookup;
    if (command !== null) {
      this.range = { start: 0, end: caret };
      lookup = command[2] === undefined ? { mode: 'commands', prefix: command[1] as string } : { mode: 'search', type: command[1] as string, query: command[2] };
    } else if (hash !== null) {
      const query = hash[1] as string;
      this.range = { start: caret - query.length - 1, end: caret };
      lookup = { mode: 'search', type: '*', query };
    } else {
      this.close();
      return;
    }
    window.clearTimeout(this.timer);
    const seq = ++this.seq;
    this.timer = window.setTimeout(() => void this.fetch(lookup, seq), this.options.debounceMs ?? 150);
  }

  /**
   * Loads the commands (once per box) and, for a search, the items; shows them unless a newer lookup started.
   *
   * @param lookup What to load.
   * @param seq Lookup number.
   */
  private async fetch(lookup: Lookup, seq: number): Promise<void> {
    let options: Option[] = [];
    let types: ReferenceType[] = [];
    try {
      this.types ??= (await this.options.load('', '')).types;
      types = this.types;
      if (lookup.mode === 'commands') {
        options = types.filter((type) => type.key.startsWith(lookup.prefix)).map((type) => ({ kind: 'type', type }));
      } else if (lookup.type === '*' || types.some((type) => type.key === lookup.type)) {
        // A command the conversation does not offer asks nothing.
        options = (await this.options.load(lookup.type, lookup.query)).references.map((reference) => ({ kind: 'item', reference }));
      }
    } catch {
      options = [];
    }
    if (seq === this.seq) {
      this.show(options, types);
    }
  }

  /**
   * Renders the options (plain text) and selects the first one; an empty answer closes the list (it never says why).
   *
   * @param options Commands or items to offer.
   * @param types The commands offered (labels and icons of the items).
   */
  private show(options: Option[], types: ReferenceType[]): void {
    if (options.length === 0) {
      this.close();
      return;
    }
    this.items = options;
    const commands = options[0]?.kind === 'type';
    this.list.setAttribute('aria-label', t(this.options.i18n, commands ? 'reference_commands' : 'reference_list'));
    this.list.replaceChildren(
      ...options.map((item, index) => {
        const option = h('li', { class: 'nowo-chat-mention-option nowo-chat-ref-option', role: 'option', id: `${this.list.id}-${index}`, 'aria-selected': 'false' });
        if (item.kind === 'type') {
          option.dataset.command = item.type.key;
          option.append(
            h('span', { class: 'nowo-chat-ref-option__icon', 'aria-hidden': 'true' }, this.options.icon(item.type.icon)),
            h('span', { class: 'nowo-chat-ref-option__command', text: `/${item.type.key}` }),
            h('span', { class: 'nowo-chat-ref-option__meta', text: item.type.label }),
          );
        } else {
          const type = types.find((candidate) => candidate.key === item.reference.type);
          option.dataset.refType = item.reference.type;
          option.append(
            h('span', { class: 'nowo-chat-ref-option__icon', 'aria-hidden': 'true' }, this.options.icon(type?.icon ?? 'link')),
            h('span', { class: 'nowo-chat-mention-option__name', text: item.reference.title }),
            h('span', { class: 'nowo-chat-ref-option__meta', text: item.reference.subtitle === '' ? (type?.label ?? '') : item.reference.subtitle }),
          );
          if (item.reference.badge !== '') {
            option.append(h('span', { class: `nowo-chat-ref__badge nowo-chat-ref__badge--${item.reference.tone}`, text: item.reference.badge }));
          }
        }
        // Keep the focus in the text box while clicking an option.
        option.addEventListener('mousedown', (event) => {
          event.preventDefault();
          this.pick(index);
        });
        return option;
      }),
    );
    this.list.hidden = false;
    this.input.setAttribute('aria-controls', this.list.id);
    this.setActive(0);
  }

  /**
   * Moves the selection.
   *
   * @param index Option to select.
   */
  private setActive(index: number): void {
    this.active = index;
    this.list.querySelectorAll<HTMLElement>('[role="option"]').forEach((option, i) => {
      option.setAttribute('aria-selected', String(i === index));
      if (i === index) {
        this.input.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView?.({ block: 'nearest' });
      }
    });
  }

  /**
   * Keyboard while the list is open: ↑ ↓ move, Enter / Tab pick, Escape closes. Handled keys go no further.
   *
   * @param event Key press in the text box.
   */
  private onKeydown(event: KeyboardEvent): void {
    if (!this.isOpen || event.isComposing) {
      return;
    }
    const count = this.items.length;
    if (event.key === 'ArrowDown') {
      this.setActive((this.active + 1) % count);
    } else if (event.key === 'ArrowUp') {
      this.setActive((this.active - 1 + count) % count);
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      this.pick(this.active);
    } else if (event.key === 'Escape') {
      this.close();
    } else {
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
  }

  /**
   * A command writes `/key ` and searches its items; an item writes `#Title ` in place of the typed lookup and is
   * remembered.
   *
   * @param index Option picked.
   */
  private pick(index: number): void {
    const item = this.items[index] as Option;
    const value = this.input.value;
    let insert: string;
    if (item.kind === 'type') {
      insert = `/${item.type.key} `;
    } else {
      insert = `#${item.reference.title} `;
      this.picked.set(`${item.reference.type}:${item.reference.id}`, { type: item.reference.type, id: item.reference.id, title: item.reference.title });
    }
    this.input.value = value.slice(0, this.range.start) + insert + value.slice(this.range.end);
    const caret = this.range.start + insert.length;
    this.input.setSelectionRange(caret, caret);
    this.close();
    // The host keeps its draft, counter and send button in step (and a command searches its items).
    this.input.dispatchEvent(new Event('input', { bubbles: true }));
  }
}
