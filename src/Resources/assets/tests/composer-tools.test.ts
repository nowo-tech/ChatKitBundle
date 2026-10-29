import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ChatApi } from '../src/api';
import { applyFormat, FORMATS, formatHelp, QuickRepliesMenu, shortcutFormat } from '../src/composer-tools';

const i18n: Record<string, string> = { quick_replies: 'Quick replies', quick_reply_add: 'New', quick_replies_empty: 'None yet', edit: 'Edit', delete: 'Delete', save: 'Save', cancel: 'Cancel', error: 'Error', quick_reply_invalid: 'Invalid', format_bold: 'bold' };

function box(value: string, start: number, end = start): HTMLTextAreaElement {
  const area = document.createElement('textarea');
  document.body.append(area);
  area.value = value;
  area.setSelectionRange(start, end);
  return area;
}

const flush = async (): Promise<void> => {
  for (let i = 0; i < 4; i += 1) {
    await Promise.resolve();
  }
};

describe('composer tools', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  it('wraps or prefixes the selection and keeps it selected', () => {
    const input = vi.fn();
    const bold = box('say hi now', 4, 6);
    bold.addEventListener('input', input);
    applyFormat(bold, 'bold');
    expect(bold.value).toBe('say **hi** now');
    expect(bold.value.slice(bold.selectionStart, bold.selectionEnd)).toBe('hi');
    expect(input).toHaveBeenCalled();

    const empty = box('x', 1);
    applyFormat(empty, 'italic');
    expect(empty.value).toBe('x__');
    applyFormat(box('a', 0, 1), 'strike');
    const code = box('a\nb', 0, 3);
    applyFormat(code, 'code');
    expect(code.value).toBe('```\na\nb\n```');
    const inline = box('run x', 4, 5);
    applyFormat(inline, 'code');
    expect(inline.value).toBe('run `x`');

    const link = box('see docs', 4, 8);
    applyFormat(link, 'link');
    expect(link.value).toBe('see [docs](https://)');
    expect(link.value.slice(link.selectionStart, link.selectionEnd)).toBe('https://');
    const bare = box('', 0);
    applyFormat(bare, 'link');
    expect(bare.value).toBe('[text](https://)');

    const quote = box('one\ntwo', 1, 6);
    applyFormat(quote, 'quote');
    expect(quote.value).toBe('> one\n> two');
    const list = box('a', 0);
    applyFormat(list, 'list');
    expect(list.value).toBe('- a');
    expect(FORMATS).toContain('list');
  });

  it('maps Ctrl/⌘+B, I and K, nothing else', () => {
    expect(shortcutFormat(new KeyboardEvent('keydown', { key: 'b', ctrlKey: true }))).toBe('bold');
    expect(shortcutFormat(new KeyboardEvent('keydown', { key: 'I', metaKey: true }))).toBe('italic');
    expect(shortcutFormat(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }))).toBe('link');
    expect(shortcutFormat(new KeyboardEvent('keydown', { key: 'x', ctrlKey: true }))).toBeNull();
    expect(shortcutFormat(new KeyboardEvent('keydown', { key: 'b' }))).toBeNull();
    expect(shortcutFormat(new KeyboardEvent('keydown', { key: 'b', ctrlKey: true, shiftKey: true }))).toBeNull();
  });

  it('lists the formatting syntax', () => {
    const help = formatHelp(i18n);
    expect(help.querySelectorAll('dt')).toHaveLength(9);
    expect(help.querySelector('dd')?.textContent).toBe('bold');
  });

  it('inserts, adds, edits and deletes quick replies', async () => {
    const api = {
      quickReplies: vi.fn().mockRejectedValueOnce(new Error('x')).mockResolvedValue([{ id: 1, title: 'Thanks', body: 'Thanks a lot' }]),
      createQuickReply: vi.fn().mockResolvedValueOnce({ id: 2, title: 'Address', body: 'Main St.' }).mockRejectedValueOnce(new Error('422')),
      updateQuickReply: vi.fn().mockResolvedValue({ id: 1, title: 'Thanks!', body: 'Thanks' }),
      deleteQuickReply: vi.fn().mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('x')),
    };
    const insert = vi.fn();
    const notice = vi.fn();
    const toggle = document.createElement('button');
    document.body.append(toggle);
    const menu = new QuickRepliesMenu({ api: api as unknown as ChatApi, i18n, insert, notice }, toggle);
    document.body.append(menu.element);

    toggle.click();
    await flush();
    expect(notice).toHaveBeenCalledWith('Error');
    expect(menu.element.textContent).toContain('None yet');
    menu.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(menu.element.hidden).toBe(true);
    menu.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));

    toggle.click();
    await flush();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    menu.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__use')?.click();
    expect(insert).toHaveBeenCalledWith('Thanks a lot');
    expect(menu.element.hidden).toBe(true);

    toggle.click();
    await flush();
    toggle.click();
    expect(menu.element.hidden).toBe(true);
    toggle.click();
    await flush();
    // Add.
    menu.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__add')?.click();
    let form = menu.element.querySelector('form') as HTMLFormElement;
    (form.querySelector('input') as HTMLInputElement).value = 'Address';
    (form.querySelector('textarea') as HTMLTextAreaElement).value = 'Main St.';
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(api.createQuickReply).toHaveBeenCalledWith('Address', 'Main St.');
    expect([...menu.element.querySelectorAll('.nowo-chat-quick__title')].map((e) => e.textContent)).toEqual(['Address', 'Thanks']);
    // A refused one: the form stays, with a notice.
    menu.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__add')?.click();
    menu.element.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(notice).toHaveBeenLastCalledWith('Invalid');
    menu.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__cancel')?.click();
    // Edit.
    (menu.element.querySelectorAll<HTMLButtonElement>('.nowo-chat-quick__edit')[1] as HTMLButtonElement).click();
    form = menu.element.querySelector('form') as HTMLFormElement;
    expect((form.querySelector('input') as HTMLInputElement).value).toBe('Thanks');
    form.dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(api.updateQuickReply).toHaveBeenCalledWith(1, 'Thanks', 'Thanks a lot');
    // Delete, then a failed delete.
    menu.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__delete')?.click();
    await flush();
    expect(api.deleteQuickReply).toHaveBeenCalledWith(2);
    menu.element.querySelector<HTMLButtonElement>('.nowo-chat-quick__delete')?.click();
    await flush();
    expect(notice).toHaveBeenLastCalledWith('Error');
  });
});
