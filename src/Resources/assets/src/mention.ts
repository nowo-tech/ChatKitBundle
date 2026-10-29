/**
 * @mentions in the composer and the inline editor. Typing `@` opens a list of the conversation's members (filtered as
 * you type, ↑ ↓ to move, Enter / Tab to pick, Escape to close); picking one writes `@Name` in the text. On send the
 * picked names become tokens `@[Name](publicId)` (encodeMentions); the server keeps a token only for a member who
 * may read the conversation and renders it as a chip (ChatMentions, ChatMarkdownRenderer).
 */
import { h } from './dom';
import { t } from './format';
import { customStatus, statusDot } from './status';
import type { ChatMessage, Member, MentionRef } from './types';

/** A stored mention token: `@[name](publicId)`. */
const TOKEN = /@\[([^[\]\n]{1,80})\]\(([A-Za-z0-9_-]{1,64})\)/g;

/** `@` and the query typed after it, right before the caret (one or two words). */
const TRIGGER = /(?:^|[\s(])@([^\s@[\]]{0,40}(?: [^\s@[\]]{1,40})?)$/u;

/**
 * Turns stored tokens into the text the composer shows (`@Name`) and the mentions they stand for.
 *
 * @param body Message text with tokens (a draft).
 * @returns The text to show and the mentions found.
 */
export function decodeMentions(body: string): { text: string; mentions: MentionRef[] } {
  const mentions: MentionRef[] = [];
  const text = body.replace(TOKEN, (_match, name: string, uuid: string) => {
    mentions.push({ uuid, name });
    return `@${name}`;
  });
  return { text, mentions };
}

/**
 * Turns `@Name` of the picked mentions back into tokens (longest names first, whole names only).
 *
 * @param text Composer text.
 * @param mentions Mentions picked in this composer.
 * @returns The text with tokens.
 */
export function encodeMentions(text: string, mentions: MentionRef[]): string {
  const sorted = [...mentions].sort((a, b) => b.name.length - a.name.length);
  let encoded = text;
  for (const mention of sorted) {
    const name = mention.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    encoded = encoded.replace(new RegExp(`(?<![\\p{L}\\p{N}_@])@${name}(?![\\p{L}\\p{N}_])`, 'gu'), `@[${mention.name}](${mention.uuid})`);
  }
  return encoded;
}

/**
 * Whether the message mentions the viewer (realtime payloads carry `mentions`, not `mentionsMe`).
 *
 * @param message A message.
 * @param userUuid Public id of the viewer.
 * @returns True when the viewer is mentioned.
 */
export function mentionsViewer(message: ChatMessage, userUuid: string): boolean {
  return (message.mentions ?? []).some((mention) => mention.uuid === userUuid);
}

/**
 * Marks the chips that name the viewer (`is-me`) inside a rendered message.
 *
 * @param bubble Rendered message body.
 * @param userUuid Public id of the viewer.
 */
export function markOwnMentions(bubble: ParentNode, userUuid: string): void {
  bubble.querySelectorAll<HTMLElement>('.nowo-chat-mention').forEach((chip) => {
    chip.classList.toggle('is-me', chip.dataset.mention === userUuid);
  });
}

/** What the autocomplete needs from its host. */
export interface MentionOptions {
  /**
   * Members matching the query (the server filters and caps the list).
   *
   * @param query Text typed after `@`.
   * @returns The members to offer.
   */
  load(query: string): Promise<Member[]>;
  /** Dock strings (`mention_list`). */
  i18n: Record<string, string>;
  /** Unique id prefix for the listbox and its options. */
  idPrefix: string;
  /** Wait after the last keystroke before asking the server (ms). */
  debounceMs?: number;
}

/** The @mention list of one text box (ARIA: `aria-autocomplete` list, listbox, `aria-activedescendant`). */
export class MentionAutocomplete {
  private readonly input: HTMLTextAreaElement;
  private readonly options: MentionOptions;
  private readonly list: HTMLUListElement;
  private readonly picked = new Map<string, MentionRef>();
  private items: Member[] = [];
  private active = -1;
  private seq = 0;
  private timer: number | undefined;
  private range: { start: number; end: number } = { start: 0, end: 0 };

  /**
   * Binds the list to a text box. Create it before the box's own keydown handlers, so Enter / Escape pick or close
   * the list instead of sending the message or closing the window.
   *
   * @param input The composer or editor text box.
   * @param options Members loader, strings and ids.
   */
  constructor(input: HTMLTextAreaElement, options: MentionOptions) {
    this.input = input;
    this.options = options;
    this.list = h('ul', { class: 'nowo-chat-mention-list', role: 'listbox', id: `${options.idPrefix}-mentions`, 'aria-label': t(options.i18n, 'mention_list'), hidden: true });
    const host = input.parentElement as HTMLElement;
    host.classList.add('nowo-chat-mention-host');
    host.append(this.list);
    // 1.11.2: a <textarea> keeps its textbox role (ARIA in HTML allows no combobox role on it), so only the attributes
    // a textbox supports go on it — aria-expanded / aria-haspopup were axe `aria-allowed-attr` violations.
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
   * Mentions picked (or restored) in this box.
   *
   * @returns The mentions.
   */
  get mentions(): MentionRef[] {
    return [...this.picked.values()];
  }

  /**
   * Remembers mentions written before (an edited message, a restored draft).
   *
   * @param mentions Mentions to keep.
   */
  seed(mentions: MentionRef[]): void {
    for (const mention of mentions) {
      this.picked.set(mention.uuid, mention);
    }
  }

  /**
   * Shows a stored text (tokens become `@Name`) and remembers its mentions.
   *
   * @param body Text with tokens.
   */
  load(body: string): void {
    const { text, mentions } = decodeMentions(body);
    this.input.value = text;
    this.seed(mentions);
  }

  /**
   * The box text with the picked names as tokens (what is sent and stored as a draft).
   *
   * @returns The encoded text.
   */
  encode(): string {
    return encodeMentions(this.input.value, this.mentions);
  }

  /** Forgets the picked mentions and closes the list (after a send). */
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
    // The box may also host the #reference picker: leave its ARIA state alone unless this list was open.
    if (!this.list.hidden) {
      this.input.removeAttribute('aria-controls');
      this.input.removeAttribute('aria-activedescendant');
    }
    this.list.hidden = true;
    this.list.replaceChildren();
  }

  /** Looks for `@query` before the caret and asks for members after a short pause. */
  private schedule(): void {
    const caret = this.input.selectionStart ?? this.input.value.length;
    const before = this.input.value.slice(0, caret);
    const match = TRIGGER.exec(before);
    if (match === null) {
      this.close();
      return;
    }
    const query = match[1] as string;
    this.range = { start: caret - query.length - 1, end: caret };
    window.clearTimeout(this.timer);
    const seq = ++this.seq;
    this.timer = window.setTimeout(() => void this.fetch(query, seq), this.options.debounceMs ?? 150);
  }

  /**
   * Loads the members and shows them, unless a newer lookup started meanwhile.
   *
   * @param query Text typed after `@`.
   * @param seq Lookup number.
   */
  private async fetch(query: string, seq: number): Promise<void> {
    let members: Member[];
    try {
      members = await this.options.load(query);
    } catch {
      members = [];
    }
    if (seq === this.seq) {
      this.show(members);
    }
  }

  /**
   * Renders the options (names as text) and selects the first one; an empty answer closes the list.
   *
   * @param members Members to offer.
   */
  private show(members: Member[]): void {
    if (members.length === 0) {
      this.close();
      return;
    }
    this.items = members;
    this.list.replaceChildren(
      ...members.map((member, index) => {
        const option = h(
          'li',
          { class: 'nowo-chat-mention-option', role: 'option', id: `${this.list.id}-${index}`, 'aria-selected': 'false' },
          h('span', { class: 'nowo-chat-mention-option__initials', 'aria-hidden': 'true', text: member.initials }),
          h('span', { class: 'nowo-chat-mention-option__name', text: member.name }),
        );
        // 1.10: the member's status dot and custom status (as this viewer may see them).
        if (member.status !== undefined && member.status !== 'offline') {
          option.dataset.status = member.status;
          option.querySelector('.nowo-chat-mention-option__initials')?.append(statusDot(member.status));
        }
        const custom = customStatus(member.statusText, member.statusEmoji);
        if (custom !== '') {
          option.append(h('span', { class: 'nowo-chat-mention-option__status', text: custom }));
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
   * Writes `@Name ` in place of the typed `@query` and remembers the mention.
   *
   * @param index Option picked.
   */
  private pick(index: number): void {
    const member = this.items[index] as Member;
    const value = this.input.value;
    const insert = `@${member.name} `;
    this.input.value = value.slice(0, this.range.start) + insert + value.slice(this.range.end);
    const caret = this.range.start + insert.length;
    this.input.setSelectionRange(caret, caret);
    this.picked.set(member.uuid, { uuid: member.uuid, name: member.name });
    this.close();
    // The host keeps its draft, counter and send button in step.
    this.input.dispatchEvent(new Event('input', { bubbles: true }));
  }
}
