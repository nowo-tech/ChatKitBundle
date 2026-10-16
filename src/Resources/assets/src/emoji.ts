/**
 * lightweight emoji picker for the composer — a curated set (no external data, no CDN),
 * recent emojis first (localStorage), keyboard reachable, inserts at the caret.
 */
import { h } from './dom';
import { t } from './format';

const CATEGORIES: [string, string[]][] = [
  ['emoji_smileys', ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '🙂', '😉', '😍', '😘', '😎', '🤩', '🤔', '🤨', '😐', '🙄', '😏', '😴', '😌', '😜', '🤪', '😬', '😮', '😯', '😲', '😳', '🥺', '😢', '😭', '😤', '😠', '😡', '🤯', '😱', '🥳', '🤗', '🤫', '🤭', '🙃', '😇', '🤓', '🧐', '😷', '🤒', '🤕', '🥵', '🥶']],
  ['emoji_gestures', ['👍', '👎', '👌', '✌️', '🤞', '🤝', '👏', '🙌', '👐', '🙏', '💪', '👋', '🤙', '👀', '🫡', '🤷', '🤦', '🙋', '🙆', '🙅', '💯', '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💔', '✨']],
  ['emoji_objects', ['🔥', '🚀', '🎉', '🎯', '🏆', '⚡', '💡', '🐛', '🔧', '🛠️', '⚙️', '🧪', '📦', '📈', '📉', '📊', '🗂️', '📌', '📎', '🔒', '🔑', '💻', '🖥️', '📱', '☕', '🍕', '🍺', '⏰', '📅', '📝']],
  ['emoji_symbols', ['✅', '❌', '⚠️', '❗', '❓', '➕', '➖', '🔁', '🔄', '⏳', '🆗', '🆕', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⭐', '💬']],
];

const RECENT_KEY = 'nowo-chat-emoji-recent';

/**
 * Recently picked emojis (most recent first, at most 16) from localStorage.
 *
 * @returns The emojis; empty when nothing is stored or storage is unavailable.
 */
function recent(): string[] {
  try {
    const list = JSON.parse(window.localStorage.getItem(RECENT_KEY) ?? '[]') as unknown;
    return Array.isArray(list) ? list.filter((item): item is string => typeof item === 'string').slice(0, 16) : [];
  } catch {
    return [];
  }
}

/**
 * Moves an emoji to the front of the recent list (best effort).
 *
 * @param emoji The picked emoji.
 */
function remember(emoji: string): void {
  try {
    window.localStorage.setItem(RECENT_KEY, JSON.stringify([emoji, ...recent().filter((item) => item !== emoji)].slice(0, 16)));
  } catch {
    // Best effort.
  }
}

/**
 * Builds the popover; `onPick` inserts the emoji. Closes on pick, Escape or outside click.
 *
 * @param i18n Translated labels (picker and category names).
 * @param onPick Called with the chosen emoji.
 * @param onClose Called when the picker should close (Escape, focus leaving it).
 * @returns The picker element.
 */
export function emojiPicker(i18n: Record<string, string>, onPick: (emoji: string) => void, onClose: () => void): HTMLElement {
  const panel = h('div', { class: 'nowo-chat-emoji', role: 'group', 'aria-label': t(i18n, 'emoji') });
  const sections: [string, string[]][] = [];
  const recents = recent();
  if (recents.length > 0) {
    sections.push(['emoji_recent', recents]);
  }
  sections.push(...CATEGORIES);
  for (const [key, list] of sections) {
    const grid = h('div', { class: 'nowo-chat-emoji__grid', role: 'group', 'aria-label': t(i18n, key) });
    for (const emoji of list) {
      const button = h('button', { type: 'button', class: 'nowo-chat-emoji__item', text: emoji, 'aria-label': emoji });
      button.addEventListener('click', () => {
        remember(emoji);
        onPick(emoji);
      });
      grid.append(button);
    }
    panel.append(h('p', { class: 'nowo-chat-emoji__heading', text: t(i18n, key) }), grid);
  }
  // One tab stop for the whole picker; arrows move inside it (8 columns), Home / End jump to the ends.
  const items = [...panel.querySelectorAll<HTMLButtonElement>('.nowo-chat-emoji__item')];
  items.forEach((item, index) => (item.tabIndex = index === 0 ? 0 : -1));
  /**
   * Roving tab stop: moves focus from one item to another (clamped to the list).
   *
   * @param from Index of the focused item.
   * @param to Wanted index.
   */
  const move = (from: number, to: number): void => {
    // The picker always has items (fixed categories), so the clamped index is always valid.
    const target = items[Math.max(0, Math.min(items.length - 1, to))] as HTMLButtonElement;
    (items[from] as HTMLButtonElement).tabIndex = -1;
    target.tabIndex = 0;
    target.focus();
  };
  panel.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      onClose();
      return;
    }
    const index = items.indexOf(document.activeElement as HTMLButtonElement);
    if (index < 0) {
      return;
    }
    const step: Record<string, number> = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 8, ArrowUp: -8 };
    if (event.key in step) {
      event.preventDefault();
      move(index, index + (step[event.key] as number));
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      move(index, event.key === 'Home' ? 0 : items.length - 1);
    }
  });
  panel.addEventListener('focusout', (event) => {
    const next = event.relatedTarget as Node | null;
    if (next !== null && !panel.contains(next) && !panel.parentElement?.querySelector('[data-nowo-chat-emoji-toggle]')?.contains(next)) {
      onClose();
    }
  });
  return panel;
}

/**
 * Insert text at the caret of a textarea and keep the caret after it.
 *
 * @param input The textarea (an `input` event is dispatched and it gets focus).
 * @param text Text to insert in place of the selection.
 */
export function insertAtCaret(input: HTMLTextAreaElement, text: string): void {
  // Textareas always report a selection (0 when untouched).
  const start = input.selectionStart;
  const end = input.selectionEnd;
  input.value = `${input.value.slice(0, start)}${text}${input.value.slice(end)}`;
  const caret = start + text.length;
  input.setSelectionRange(caret, caret);
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.focus();
}
