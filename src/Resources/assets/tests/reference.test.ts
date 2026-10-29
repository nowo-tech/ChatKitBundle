import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { decodeReferences, encodeReferences, ReferenceAutocomplete } from '../src/reference';
import type { Reference, ReferenceType } from '../src/types';

const types: ReferenceType[] = [
  { key: 'task', label: 'Task', icon: 'task' },
  { key: 'issue', label: 'Issue', icon: 'issue' },
];
const deploy: Reference = { type: 'task', id: '42', title: 'Deploy the API', url: '/tasks/42', subtitle: 'Doing', badge: 'High', tone: 'warning' };
const crash: Reference = { type: 'issue', id: 'i-1', title: 'Crash', url: '/issues/1', subtitle: '', badge: '', tone: 'neutral' };
const orphan: Reference = { type: 'wiki', id: 'w', title: 'Wiki page', url: '/w', subtitle: '', badge: '', tone: 'neutral' };

describe('reference helpers', () => {
  it('decodes tokens to titles and encodes picked titles back, longest first and whole titles only', () => {
    expect(decodeReferences('see #[Deploy the API](task:42) and #[Crash](issue:i-1)')).toEqual({
      text: 'see #Deploy the API and #Crash',
      references: [
        { type: 'task', id: '42', title: 'Deploy the API' },
        { type: 'issue', id: 'i-1', title: 'Crash' },
      ],
    });
    const refs = [
      { type: 'task', id: '1', title: 'Deploy' },
      { type: 'task', id: '2', title: 'Deploy the API' },
      { type: 'issue', id: '3', title: 'C++ (x)' },
    ];
    expect(encodeReferences('#Deploy the API, #Deploy, #Deployment, x#Deploy, #C++ (x)!', refs)).toBe(
      '#[Deploy the API](task:2), #[Deploy](task:1), #Deployment, x#Deploy, #[C++ (x)](issue:3)!',
    );
  });
});

describe('ReferenceAutocomplete', () => {
  let input: HTMLTextAreaElement;
  let load: Mock<(type: string, query: string) => Promise<{ types: ReferenceType[]; references: Reference[] }>>;
  let later: Mock<(event: KeyboardEvent) => void>;
  let auto: ReferenceAutocomplete;

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
  const list = (): HTMLUListElement => document.querySelector('.nowo-chat-ref-list') as HTMLUListElement;
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
    load = vi.fn<(type: string, query: string) => Promise<{ types: ReferenceType[]; references: Reference[] }>>().mockImplementation(async (kind) =>
      kind === '' ? { types, references: [] } : { types: [], references: kind === 'issue' ? [crash] : [deploy, crash, orphan] },
    );
    later = vi.fn<(event: KeyboardEvent) => void>();
    auto = new ReferenceAutocomplete(input, {
      load,
      icon: (name) => {
        const icon = document.createElement('i');
        icon.dataset.name = name;
        return icon;
      },
      i18n: { reference_list: 'Insert a reference', reference_commands: 'Commands' },
      idPrefix: 'c1',
    });
    input.addEventListener('keydown', later);
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.replaceChildren();
  });

  it('opens the command menu on / at the start, filters it, and a command searches its items', async () => {
    expect(input.parentElement?.classList.contains('nowo-chat-mention-host')).toBe(true);
    expect(auto.isOpen).toBe(false);

    type('/');
    await settle();
    expect(auto.isOpen).toBe(true);
    expect(list().getAttribute('aria-label')).toBe('Commands');
    expect(input.getAttribute('aria-controls')).toBe('c1-references');
    expect(input.hasAttribute('aria-expanded')).toBe(false);
    // Its own class, distinct from the @mention list.
    expect(list().classList.contains('nowo-chat-mention-list')).toBe(false);
    expect(options().map((option) => option.dataset.command)).toEqual(['task', 'issue']);
    expect(options()[0]?.querySelector('i')?.dataset.name).toBe('task');
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-references-0');

    type('/is');
    await settle();
    expect(options().map((option) => option.dataset.command)).toEqual(['issue']);
    // The commands are loaded once per box.
    expect(load.mock.calls.filter(([kind]) => kind === '')).toHaveLength(1);

    key('Enter');
    expect(input.value).toBe('/issue ');
    await settle();
    expect(load).toHaveBeenLastCalledWith('issue', '');
    expect(list().getAttribute('aria-label')).toBe('Insert a reference');
    expect(options().map((option) => option.dataset.refType)).toEqual(['issue']);
    // No subtitle: the type's label; no badge: none.
    expect(options()[0]?.textContent).toBe('CrashIssue');

    key('Tab');
    expect(input.value).toBe('#Crash ');
    expect(auto.isOpen).toBe(false);
    expect(input.hasAttribute('aria-controls')).toBe(false);
    expect(input.hasAttribute('aria-activedescendant')).toBe(false);
    expect(auto.encode(`${input.value}!`)).toBe('#[Crash](issue:i-1) !');
  });

  it('searches every type on # anywhere, shows subtitle, badge and unknown types, and picks with the mouse', async () => {
    type('ship #dep');
    await settle();
    expect(load).toHaveBeenLastCalledWith('*', 'dep');
    expect(options().map((option) => option.dataset.refType)).toEqual(['task', 'issue', 'wiki']);
    expect(options()[0]?.textContent).toBe('Deploy the APIDoingHigh');
    expect(options()[0]?.querySelector('.nowo-chat-ref__badge--warning')).not.toBeNull();
    expect(options()[2]?.querySelector('i')?.dataset.name).toBe('link');
    expect(options()[2]?.textContent).toBe('Wiki page');

    key('ArrowDown');
    key('ArrowDown');
    key('ArrowDown');
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-references-0');
    key('ArrowUp');
    expect(input.getAttribute('aria-activedescendant')).toBe('c1-references-2');

    const down = new MouseEvent('mousedown', { cancelable: true });
    options()[0]?.dispatchEvent(down);
    expect(down.defaultPrevented).toBe(true);
    expect(input.value).toBe('ship #Deploy the API ');
    expect(auto.references).toEqual([{ type: 'task', id: '42', title: 'Deploy the API' }]);
  });

  it('asks nothing for a command the conversation does not offer, and closes on no match, Escape, blur or failure', async () => {
    type('/monitor up');
    await settle();
    expect(load.mock.calls.map(([kind]) => kind)).toEqual(['']);
    expect(auto.isOpen).toBe(false);

    type('/zz');
    await settle();
    expect(auto.isOpen).toBe(false);

    type('#x');
    await settle();
    expect(auto.isOpen).toBe(true);
    const escape = key('Escape');
    expect(escape.defaultPrevented).toBe(true);
    expect(later).not.toHaveBeenCalled();
    expect(auto.isOpen).toBe(false);

    // Keys go through while closed (and while composing).
    key('Enter');
    expect(later).toHaveBeenCalledTimes(1);
    type('#x');
    await settle();
    key('Enter', { isComposing: true });
    key('a');
    expect(later).toHaveBeenCalledTimes(3);

    input.dispatchEvent(new Event('blur'));
    expect(auto.isOpen).toBe(false);

    type('plain text');
    await settle();
    expect(auto.isOpen).toBe(false);

    load.mockRejectedValueOnce(new Error('offline'));
    type('#y');
    await settle();
    expect(auto.isOpen).toBe(false);
  });

  it('drops a stale answer, seeds and decodes stored references, and forgets them on reset', async () => {
    let release: (value: { types: ReferenceType[]; references: Reference[] }) => void = () => undefined;
    load.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));
    type('#a');
    await settle();
    type('nothing');
    release({ types, references: [deploy] });
    await settle();
    expect(auto.isOpen).toBe(false);

    expect(auto.decode('see #[Old title](task:7)')).toBe('see #Old title');
    auto.seed([{ ...deploy }]);
    expect(auto.encode('#Old title #Deploy the API')).toBe('#[Old title](task:7) #[Deploy the API](task:42)');
    auto.reset();
    expect(auto.references).toEqual([]);
  });

  it('uses the caret position when the box has no selection API value', async () => {
    Object.defineProperty(input, 'selectionStart', { configurable: true, get: () => null });
    input.value = '#q';
    input.dispatchEvent(new Event('input'));
    await settle();
    expect(load).toHaveBeenLastCalledWith('*', 'q');
  });
});
