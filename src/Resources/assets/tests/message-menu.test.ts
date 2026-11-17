import { afterEach, describe, expect, it } from 'vitest';
import { loadContextMenuRuntime, QUOTE_MAX, quoteOf, resetContextMenuRuntime, stripReservedAttributes, takeMessageFromUrl, visibleItems, type MessageMenuRules } from '../src/message-menu';

const rules = (over: Partial<MessageMenuRules> = {}): MessageMenuRules => ({ usable: true, mine: false, inWindow: true, canSend: true, canPin: false, pinned: false, canLink: true, ...over });

describe('message menu (1.33)', () => {
  afterEach(() => {
    resetContextMenuRuntime();
    document.head.replaceChildren();
    delete (window as unknown as Record<symbol, unknown>)[Symbol.for('nowo-ui.context-menu.bound')];
    window.history.replaceState(null, '', '/');
  });

  it('shows the items by the bundle rules', () => {
    expect(visibleItems(rules({ mine: true, canPin: true }))).toEqual({ reply: true, edit: true, 'copy-link': true, pin: true, delete: true });
    expect(visibleItems(rules({ mine: true, inWindow: false }))).toEqual({ reply: true, edit: false, 'copy-link': true, pin: false, delete: false });
    expect(visibleItems(rules({ mine: false }))).toEqual({ reply: true, edit: false, 'copy-link': true, pin: false, delete: false });
    // Read-only: no reply nor edit; the author still deletes within the window.
    expect(visibleItems(rules({ mine: true, canSend: false }))).toEqual({ reply: false, edit: false, 'copy-link': true, pin: false, delete: true });
    expect(visibleItems(rules({ usable: false, mine: true, canPin: true }))).toEqual({ reply: false, edit: false, 'copy-link': false, pin: false, delete: false });
    expect(visibleItems(rules({ canLink: false }))['copy-link']).toBe(false);
  });

  it('strips UiKit context-menu and card attributes, keeping the others', () => {
    const root = document.createElement('div');
    root.setAttribute('data-nowo-ui-card', '');
    root.innerHTML = '<p data-nowo-ui-context-menu="admin" data-NOWO-ui-context-menu-label="x" data-nowo-ui-cards="1" data-nowo-ui-menu class="a">t<span data-nowo-ui-context-menu-scope></span></p>';
    stripReservedAttributes(root);
    expect(root.outerHTML).toBe('<div><p data-nowo-ui-cards="1" data-nowo-ui-menu="" class="a">t<span></span></p></div>');
  });

  it('quotes a message for a reply', () => {
    expect(quoteOf('Luis', '  hola\n  Ana ')).toBe('> Luis: hola Ana\n\n');
    expect(quoteOf(' ', 'x')).toBe('> x\n\n');
    expect(quoteOf('Luis', '   ')).toBe('');
    const long = quoteOf('L', 'a'.repeat(QUOTE_MAX + 50));
    expect(long).toBe(`> L: ${'a'.repeat(QUOTE_MAX - 1)}…\n\n`);
  });

  it('loads UiKit\'s runtime once, same origin only, never twice', () => {
    const nonce = document.createElement('script');
    nonce.nonce = 'n0nce';
    document.head.append(nonce);
    expect(loadContextMenuRuntime('https://evil.example/nowo-ui-context-menu.js')).toBe(false);
    expect(loadContextMenuRuntime('/bundles/nowouikit/js/nowo-ui-context-menu.js')).toBe(true);
    const added = document.head.querySelector<HTMLScriptElement>('script[data-nowo-chat-context-menu-runtime]') as HTMLScriptElement;
    expect(added.getAttribute('src')).toBe('/bundles/nowouikit/js/nowo-ui-context-menu.js');
    expect(added.nonce).toBe('n0nce');
    expect(loadContextMenuRuntime('/bundles/nowouikit/js/nowo-ui-context-menu.js')).toBe(false);
    // The page has its own tag, or the runtime runs already.
    resetContextMenuRuntime();
    expect(loadContextMenuRuntime('/other/nowo-ui-context-menu.js')).toBe(false);
    document.head.replaceChildren();
    (window as unknown as Record<symbol, unknown>)[Symbol.for('nowo-ui.context-menu.bound')] = true;
    expect(loadContextMenuRuntime('/bundles/nowouikit/js/nowo-ui-context-menu.js')).toBe(false);
  });

  it('reads and drops ?message= from the address', () => {
    expect(takeMessageFromUrl()).toBeNull();
    window.history.replaceState(null, '', '/chat/c1?message=0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b&x=1');
    expect(takeMessageFromUrl()).toBe('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');
    expect(window.location.search).toBe('?x=1');
    window.history.replaceState(null, '', '/chat/c1?message=<script>');
    expect(takeMessageFromUrl()).toBeNull();
    expect(window.location.search).toBe('');
  });
});
