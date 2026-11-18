import { afterEach, describe, expect, it } from 'vitest';
import { loadContextMenuRuntime, plainText, QUOTE_MAX, quoteBlock, quoteOf, resetContextMenuRuntime, stripReservedAttributes, takeMessageFromUrl, tidySeparators, visibleItems, type MessageMenuRules } from '../src/message-menu';

const rules = (over: Partial<MessageMenuRules> = {}): MessageMenuRules => ({ usable: true, mine: false, inWindow: true, canSend: true, canPin: false, pinned: false, canLink: true, ...over });

describe('message menu (1.33)', () => {
  afterEach(() => {
    resetContextMenuRuntime();
    document.head.replaceChildren();
    delete (window as unknown as Record<symbol, unknown>)[Symbol.for('nowo-ui.context-menu.bound')];
    window.history.replaceState(null, '', '/');
  });

  it('shows the items by the bundle rules', () => {
    expect(visibleItems(rules({ mine: true, canPin: true }))).toEqual({ reply: true, edit: true, 'copy-link': true, pin: true, delete: true, quote: false, 'copy-text': false, save: false, 'mark-unread': false, info: false });
    expect(visibleItems(rules({ mine: true, inWindow: false }))).toEqual({ reply: true, edit: false, 'copy-link': true, pin: false, delete: false, quote: false, 'copy-text': false, save: false, 'mark-unread': false, info: false });
    expect(visibleItems(rules({ mine: false }))).toEqual({ reply: true, edit: false, 'copy-link': true, pin: false, delete: false, quote: false, 'copy-text': false, save: false, 'mark-unread': false, info: false });
    // Read-only: no reply nor edit; the author still deletes within the window.
    expect(visibleItems(rules({ mine: true, canSend: false }))).toEqual({ reply: false, edit: false, 'copy-link': true, pin: false, delete: true, quote: false, 'copy-text': false, save: false, 'mark-unread': false, info: false });
    expect(visibleItems(rules({ usable: false, mine: true, canPin: true }))).toEqual({ reply: false, edit: false, 'copy-link': false, pin: false, delete: false, quote: false, 'copy-text': false, save: false, 'mark-unread': false, info: false });
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

  it('1.35: offers the new items by configuration and the viewer', () => {
    const offered = { quote: true, copy_text: true, save: true, mark_unread: true, info: true };
    expect(visibleItems(rules({ offered }))).toMatchObject({ quote: true, 'copy-text': true, save: true, 'mark-unread': true, info: true });
    expect(visibleItems(rules({ offered, mine: true }))['mark-unread']).toBe(false);
    expect(visibleItems(rules({ offered, canSend: false })).quote).toBe(false);
    expect(visibleItems(rules({ offered, usable: false }))).toMatchObject({ quote: false, 'copy-text': false, save: false, 'mark-unread': false, info: false });
  });

  it('1.35: the plain text of a rendered message', () => {
    const root = document.createElement('div');
    root.innerHTML =
      '<p>Hola <strong>Ana</strong>   y <a class="nowo-chat-mention" data-uuid="x">@Luis</a></p>' +
      '<ul><li>uno</li><li>dos <a href="https://example.test/x">enlace</a></li></ul>' +
      '<blockquote><p>citado<br>dos líneas</p></blockquote>' +
      '<p><a href="https://example.test">https://example.test</a> <a href="mailto:a@b.c">a@b.c</a> <a href="/rel">rel</a></p>' +
      '<pre><code>x = 1</code></pre><p><img alt="🙂"><span hidden>oculto</span><span aria-hidden="true">x</span></p><p> </p><p></p>';
    expect(plainText(root)).toBe('Hola Ana y @Luis\n- uno\n- dos enlace (https://example.test/x)\n> citado\n> dos líneas\nhttps://example.test a@b.c rel\nx = 1\n🙂');
    const empty = document.createElement('div');
    expect(plainText(empty)).toBe('');
    const nested = document.createElement('div');
    nested.innerHTML = 'text <div>block</div> after';
    expect(plainText(nested)).toBe('text\nblock\nafter');
  });

  it('1.35: the quote block', () => {
    expect(quoteBlock('Luis *x*', 'uno\ndos', '%name% wrote:', 100)).toBe('> **Luis \\*x\\*** wrote:\n> uno\n> dos\n\n');
    expect(quoteBlock(' ', 'uno', '%name% wrote:', 100)).toBe('> uno\n\n');
    expect(quoteBlock('L', '  ', '%name% wrote:', 100)).toBe('');
    expect(quoteBlock('L', 'abcdefghij', '%name%:', 5)).toBe('> **L**:\n> abcd…\n\n');
    expect(quoteBlock('L', 'a\n\nb', '%name%:', 50)).toBe('> **L**:\n> a\n>\n> b\n\n');
  });

  it('1.35: tidies the separators of a kebab', () => {
    const menu = document.createElement('div');
    menu.innerHTML =
      '<hr data-part="menu-separator"><a>a</a><hr data-part="menu-separator"><a hidden>b</a><hr data-part="menu-separator"><a>c</a><hr data-part="menu-separator"><a hidden>d</a>';
    tidySeparators(menu);
    expect([...menu.children].map((el) => `${el.tagName}${(el as HTMLElement).hidden ? '-' : ''}`)).toEqual(['HR-', 'A', 'HR', 'A-', 'HR-', 'A', 'HR-', 'A-']);
  });
});
