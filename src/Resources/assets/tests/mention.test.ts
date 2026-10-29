import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { decodeMentions, encodeMentions, markOwnMentions, MentionAutocomplete, mentionsViewer } from '../src/mention';
import type { ChatMessage, Member } from '../src/types';

const members: Member[] = [
  { uuid: 'u-ana', name: 'Ana López', initials: 'AL' },
  { uuid: 'u-luis', name: 'Luis', initials: 'LU' },
];

describe('mention helpers', () => {
  it('decodes tokens to names and encodes picked names back, longest first and whole names only', () => {
    expect(decodeMentions('hi @[Ana López](u-ana) and @[Luis](u-luis)')).toEqual({
      text: 'hi @Ana López and @Luis',
      mentions: [{ uuid: 'u-ana', name: 'Ana López' }, { uuid: 'u-luis', name: 'Luis' }],
    });
    const refs = [{ uuid: 'u-a', name: 'Ana' }, { uuid: 'u-al', name: 'Ana López' }, { uuid: 'u-x', name: 'C++ (dev)' }];
    expect(encodeMentions('@Ana López, @Ana, @Anabel, x@Ana, @C++ (dev)!', refs)).toBe('@[Ana López](u-al), @[Ana](u-a), @Anabel, x@Ana, @[C++ (dev)](u-x)!');
  });

  it('tells whether a message mentions the viewer and marks the viewer chips', () => {
    const message = { mentions: [{ uuid: 'me', name: 'Me' }] } as ChatMessage;
    expect(mentionsViewer(message, 'me')).toBe(true);
    expect(mentionsViewer(message, 'other')).toBe(false);
    expect(mentionsViewer({} as ChatMessage, 'me')).toBe(false);

    const bubble = document.createElement('div');
    bubble.innerHTML = '<span class="nowo-chat-mention" data-mention="me">@Me</span><span class="nowo-chat-mention" data-mention="x">@X</span>';
    markOwnMentions(bubble, 'me');
    expect([...bubble.querySelectorAll('.is-me')].map((chip) => chip.textContent)).toEqual(['@Me']);
  });
});

describe('MentionAutocomplete', () => {
  let input: HTMLTextAreaElement;
  let load: Mock<(query: string) => Promise<Member[]>>;
  let later: Mock<(event: KeyboardEvent) => void>;
  let auto: MentionAutocomplete;

  const type = (value: string, caret = value.length): void => {
    input.value = value;
    input.setSelectionRange(caret, caret);
    input.dispatchEvent(new Event('input'));
  };
  const key = (name: string, init: KeyboardEventInit = {}): KeyboardEvent => {
    const event = new KeyboardEvent('keydown', { key: name, cancelable: true, ...init });
    input.dispatchEvent(event);
    return event;
  };
  const list = (): HTMLUListElement => document.querySelector('.nowo-chat-mention-list') as HTMLUListElement;
  const options = (): HTMLElement[] => [...list().querySelectorAll<HTMLElement>('[role="option"]')];
  const settle = async (): Promise<void> => {
    await vi.advanceTimersByTimeAsync(200);
  };

  beforeEach(() => {
    vi.useFakeTimers();
    const form = document.createElement('form');
    input = document.createElement('textarea');
    form.append(input);
    document.body.append(form);
    load = vi.fn<(query: string) => Promise<Member[]>>().mockResolvedValue(members);
    later = vi.fn<(event: KeyboardEvent) => void>();
    auto = new MentionAutocomplete(input, { load, i18n: { mention_list: 'Mention someone' }, idPrefix: 'c1' });
    // A host handler registered after the autocomplete (the composer's Enter-to-send).
    input.addEventListener('keydown', later);
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.replaceChildren();
  });

  it('binds ARIA to the text box and opens on @ with the first option selected', async () => {
    expect(input.parentElement?.classList.contains('nowo-chat-mention-host')).toBe(true);
    expect(list().getAttribute('aria-label')).toBe('Mention someone');
    expect(input.getAttribute('aria-autocomplete')).toBe('list');
    // A <textarea> keeps its textbox role: no aria-expanded / aria-haspopup (axe aria-allowed-attr), and it only
    // points at the list while it is open.
    expect(input.hasAttribute('aria-controls')).toBe(false);
    expect(auto.isOpen).toBe(false);

    type('hello @an');
    await settle();
    expect(load).toHaveBeenCalledWith('an');
    expect(auto.isOpen).toBe(true);
    expect(input.getAttribute('aria-controls')).toBe('c1-mentions');
    expect(input.hasAttribute('aria-expanded')).toBe(false);
    expect(input.hasAttribute('aria-haspopup')).toBe(false);
    expect(input.getAttribute('role')).toBeNull();
    expect(options().map((option) => option.textContent)).toEqual(['ALAna López', 'LULuis']);
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-mentions-0');
    expect(options()[0]?.getAttribute('aria-selected')).toBe('true');
  });

  it('moves with the arrows, picks with Enter and keeps Enter from the host', async () => {
    const changed = vi.fn();
    input.addEventListener('input', changed);
    type('hi @lu and more', 6);
    await settle();
    expect(key('ArrowDown').defaultPrevented).toBe(true);
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-mentions-1');
    key('ArrowDown');
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-mentions-0');
    key('ArrowUp');
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-mentions-1');
    key('Enter');
    expect(later).not.toHaveBeenCalled();
    expect(input.value).toBe('hi @Luis  and more');
    expect(input.selectionStart).toBe(9);
    expect(auto.isOpen).toBe(false);
    expect(changed).toHaveBeenCalled();
    expect(auto.mentions).toEqual([{ uuid: 'u-luis', name: 'Luis' }]);
    expect(auto.encode()).toBe('hi @[Luis](u-luis)  and more');

    // Closed: keys reach the host.
    key('Enter');
    expect(later).toHaveBeenCalledTimes(1);
  });

  it('picks with Tab or a click, closes with Escape or blur, and ignores other keys and IME composition', async () => {
    type('@a');
    await settle();
    key('Tab');
    expect(input.value).toBe('@Ana López ');

    type('@a');
    await settle();
    options()[1]?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
    expect(input.value).toBe('@Luis ');

    type('@a');
    await settle();
    expect(key('a').defaultPrevented).toBe(false);
    expect(key('Enter', { isComposing: true }).defaultPrevented).toBe(false);
    key('Escape');
    expect(auto.isOpen).toBe(false);
    expect(input.hasAttribute('aria-activedescendant')).toBe(false);

    type('@a');
    await settle();
    input.dispatchEvent(new Event('blur'));
    expect(auto.isOpen).toBe(false);
  });

  it('closes when nothing matches, on errors, without a trigger, and drops stale answers', async () => {
    load.mockResolvedValueOnce([]);
    type('@zz');
    await settle();
    expect(auto.isOpen).toBe(false);

    load.mockRejectedValueOnce(new Error('offline'));
    type('@zz');
    await settle();
    expect(auto.isOpen).toBe(false);

    type('mail@example');
    await settle();
    type('@Ana ');
    await settle();
    expect(load).toHaveBeenCalledTimes(2);

    // Two words are one query; an answer that arrives after the list was closed is ignored.
    let resolve: (value: Member[]) => void = () => undefined;
    load.mockReturnValueOnce(new Promise<Member[]>((done) => {
      resolve = done;
    }));
    type('@Ana Ló');
    await settle();
    expect(load).toHaveBeenLastCalledWith('Ana Ló');
    auto.close();
    resolve(members);
    await settle();
    expect(auto.isOpen).toBe(false);
  });

  it('loads stored text, seeds, resets and uses the caret end when there is no selection', async () => {
    auto.load('hi @[Ana López](u-ana)');
    expect(input.value).toBe('hi @Ana López');
    auto.seed([{ uuid: 'u-luis', name: 'Luis' }]);
    expect(auto.mentions.map((mention) => mention.uuid)).toEqual(['u-ana', 'u-luis']);
    input.value += ' @Luis';
    expect(auto.encode()).toBe('hi @[Ana López](u-ana) @[Luis](u-luis)');
    auto.reset();
    expect(auto.mentions).toEqual([]);

    Object.defineProperty(input, 'selectionStart', { configurable: true, get: () => null });
    input.value = 'x @l';
    input.dispatchEvent(new Event('input'));
    await settle();
    expect(load).toHaveBeenLastCalledWith('l');
  });

  it('scrolls the active option into view and honours a custom delay', async () => {
    const scroll = vi.fn();
    HTMLElement.prototype.scrollIntoView = scroll;
    const other = document.createElement('textarea');
    document.body.append(other);
    const quick = new MentionAutocomplete(other, { load, i18n: {}, idPrefix: 'c2', debounceMs: 0 });
    other.value = '@l';
    other.setSelectionRange(2, 2);
    other.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(0);
    expect(quick.isOpen).toBe(true);
    expect(scroll).toHaveBeenCalledWith({ block: 'nearest' });
    delete (HTMLElement.prototype as Partial<HTMLElement>).scrollIntoView;
  });
});
