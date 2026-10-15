import { afterEach, describe, expect, it, vi } from 'vitest';
import { builtinIcon, h, prefersReducedMotion, setBadge } from '../src/dom';

describe('chat dom helpers', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('builds elements with attributes, text and children', () => {
    const element = h('button', { class: 'a b', text: 'Hi', type: 'button', disabled: true, hidden: false, title: null, 'data-x': 3 }, 'tail', null, h('span'));
    expect(element.className).toBe('a b');
    expect(element.getAttribute('type')).toBe('button');
    expect(element.getAttribute('disabled')).toBe('');
    expect(element.hasAttribute('hidden')).toBe(false);
    expect(element.hasAttribute('title')).toBe(false);
    expect(element.dataset.x).toBe('3');
    expect(element.textContent).toBe('Hitail');
    expect(element.lastElementChild?.tagName).toBe('SPAN');
  });

  it('draws the built-in icons as decorative SVG', () => {
    const svg = builtinIcon('send', 12);
    expect(svg.getAttribute('aria-hidden')).toBe('true');
    expect(svg.getAttribute('width')).toBe('12');
    expect(svg.querySelector('path')).not.toBeNull();
    expect(builtinIcon('nope').childElementCount).toBe(0);
    expect(builtinIcon('chat').getAttribute('width')).toBe('18');
  });

  it('caps the badge and gives screen readers a sentence', () => {
    const badge = document.createElement('span');
    setBadge(badge, 120, '120 unread');
    expect(badge.hidden).toBe(false);
    expect(badge.querySelector('[aria-hidden]')?.textContent).toBe('99+');
    expect(badge.querySelector('.nowo-chat-sr-only')?.textContent).toBe('120 unread');
    setBadge(badge, 0, '');
    expect(badge.hidden).toBe(true);
  });

  it('reads the reduced-motion preference', () => {
    expect(prefersReducedMotion()).toBe(false);
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    expect(prefersReducedMotion()).toBe(true);
    vi.stubGlobal('matchMedia', undefined);
    expect(prefersReducedMotion()).toBe(false);
  });
});
