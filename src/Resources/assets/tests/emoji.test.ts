import { beforeEach, describe, expect, it, vi } from 'vitest';
import { emojiPicker, insertAtCaret } from '../src/emoji';

const i18n = { emoji: 'Emoji', emoji_recent: 'Recent', emoji_smileys: 'Smileys' };

describe('chat emoji picker', () => {
  beforeEach(() => window.localStorage.clear());

  it('lists categories, remembers recent picks and closes on Escape', () => {
    const picked = vi.fn();
    const closed = vi.fn();
    const panel = emojiPicker(i18n, picked, closed);
    expect(panel.querySelector('.nowo-chat-emoji__heading')?.textContent).toBe('Smileys');
    const thumbs = [...panel.querySelectorAll<HTMLButtonElement>('.nowo-chat-emoji__item')].find((b) => b.textContent === '👍');
    thumbs?.click();
    expect(picked).toHaveBeenCalledWith('👍');
    expect(JSON.parse(window.localStorage.getItem('nowo-chat-emoji-recent') ?? '[]')).toEqual(['👍']);

    const again = emojiPicker(i18n, picked, closed);
    expect(again.querySelector('.nowo-chat-emoji__heading')?.textContent).toBe('Recent');
    [...again.querySelectorAll<HTMLButtonElement>('.nowo-chat-emoji__item')].find((b) => b.textContent === '🔥')?.click();
    expect(JSON.parse(window.localStorage.getItem('nowo-chat-emoji-recent') ?? '[]')).toEqual(['🔥', '👍']);
    again.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
    again.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(closed).toHaveBeenCalledTimes(1);
  });

  it('survives broken or unavailable storage', () => {
    window.localStorage.setItem('nowo-chat-emoji-recent', '{"x":1}');
    expect(emojiPicker(i18n, vi.fn(), vi.fn()).querySelector('.nowo-chat-emoji__heading')?.textContent).toBe('Smileys');
    window.localStorage.setItem('nowo-chat-emoji-recent', 'not json');
    expect(emojiPicker(i18n, vi.fn(), vi.fn()).querySelector('.nowo-chat-emoji__heading')?.textContent).toBe('Smileys');
    const spy = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('quota');
    });
    emojiPicker(i18n, vi.fn(), vi.fn()).querySelector<HTMLButtonElement>('.nowo-chat-emoji__item')?.click();
    spy.mockRestore();
  });

  it('inserts at the caret and keeps the caret after the emoji', () => {
    const input = document.createElement('textarea');
    document.body.append(input);
    const onInput = vi.fn();
    input.addEventListener('input', onInput);
    input.value = 'ab';
    input.setSelectionRange(1, 1);
    insertAtCaret(input, '😀');
    expect(input.value).toBe('a😀b');
    expect(input.selectionStart).toBe(3);
    expect(onInput).toHaveBeenCalled();
    input.remove();
  });

  it('moves with arrow keys inside one tab stop and closes when focus leaves', () => {
    const closed = vi.fn();
    const host = document.createElement('div');
    const toggle = document.createElement('button');
    toggle.setAttribute('data-nowo-chat-emoji-toggle', '');
    toggle.className = 'nowo-chat-composer__emoji';
    const outside = document.createElement('button');
    document.body.append(host, outside);
    host.append(toggle);
    const panel = emojiPicker(i18n, vi.fn(), closed);
    host.append(panel);
    const items = [...panel.querySelectorAll<HTMLButtonElement>('.nowo-chat-emoji__item')];
    expect(items.filter((item) => item.tabIndex === 0)).toHaveLength(1);
    items[0]?.focus();
    const key = (k: string) => panel.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true }));
    key('ArrowRight');
    expect(document.activeElement).toBe(items[1]);
    key('ArrowDown');
    expect(document.activeElement).toBe(items[9]);
    key('ArrowUp');
    key('ArrowLeft');
    expect(document.activeElement).toBe(items[0]);
    key('End');
    expect(document.activeElement).toBe(items.at(-1));
    key('Home');
    expect(document.activeElement).toBe(items[0]);
    key('ArrowLeft');
    expect(document.activeElement).toBe(items[0]);
    key('a');
    panel.dispatchEvent(new FocusEvent('focusout', { relatedTarget: items[1] }));
    panel.dispatchEvent(new FocusEvent('focusout', { relatedTarget: toggle }));
    panel.dispatchEvent(new FocusEvent('focusout', { relatedTarget: null }));
    expect(closed).not.toHaveBeenCalled();
    panel.dispatchEvent(new FocusEvent('focusout', { relatedTarget: outside }));
    expect(closed).toHaveBeenCalledTimes(1);
    // Keys while focus is outside the grid are ignored.
    outside.focus();
    key('ArrowRight');
    host.remove();
    outside.remove();
  });

});
