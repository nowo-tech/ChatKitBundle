import { describe, expect, it } from 'vitest';
import { part, Templates, text } from '../src/templates';
import { templatesRoot } from './support';

describe('Templates', () => {
  it('clones the Twig templates and fills the empty icon slots', () => {
    const templates = new Templates(templatesRoot());
    const dock = templates.clone('dock');
    expect(dock.classList.contains('nowo-chat-dock')).toBe(true);
    expect(part(dock, 'compose').querySelector('svg')).not.toBeNull();
    expect(templates.clone('dock')).not.toBe(dock);
    expect(() => templates.clone('nope')).toThrow('missing <template data-nowo-chat-template="nope">');
  });

  it('uses the application icons (UX Icons) when the page provides them', () => {
    const root = templatesRoot();
    const custom = document.createElement('template');
    custom.dataset.nowoChatIcon = 'buzz';
    custom.innerHTML = '<svg class="ux" width="1em" height="1em" viewBox="0 0 24 24"><path d="M1 1"/></svg>';
    root.append(custom);
    const templates = new Templates(root);
    const icon = templates.icon('buzz', 20);
    expect(icon.classList.contains('ux')).toBe(true);
    expect(icon.getAttribute('width')).toBe('20');
    expect(icon.getAttribute('aria-hidden')).toBe('true');
    expect(templates.icon('close').classList.contains('ux')).toBe(false);
    // A slot that already holds an SVG (custom markup) is left alone.
    const window = templates.clone('window');
    expect(part(window, 'buzz').querySelector('svg.ux')).not.toBeNull();

    // A template slot the application already filled (its own markup) keeps it.
    const prefilled = document.createElement('template');
    prefilled.dataset.nowoChatTemplate = 'prefilled';
    prefilled.innerHTML = '<div><span data-icon="close"><i class="own"></i></span></div>';
    root.append(prefilled);
    const slot = templates.clone('prefilled').querySelector('[data-icon]') as HTMLElement;
    expect(slot.querySelector('i.own')).not.toBeNull();
    expect(slot.querySelector('svg')).toBeNull();
  });

  it('builds avatars for people, channels and the support inbox', () => {
    const templates = new Templates(templatesRoot());
    const person = templates.avatar('AN', 'direct', 'sm', true);
    expect(person.textContent).toBe('AN');
    expect(person.className).toBe('nowo-chat-avatar nowo-chat-avatar--direct nowo-chat-avatar--sm is-online');
    expect(templates.avatar('', 'channel').querySelector('svg')?.getAttribute('width')).toBe('16');
    expect(templates.avatar('', 'support', 'sm').querySelector('svg')?.getAttribute('width')).toBe('14');
    expect(templates.avatar('X').className).toContain('nowo-chat-avatar--md');
  });

  it('finds parts and sets text', () => {
    const templates = new Templates(templatesRoot());
    const row = templates.clone('row');
    expect(text(row, 'title', 'Ana').hidden).toBe(false);
    expect(part(row, 'title').textContent).toBe('Ana');
    expect(text(row, 'preview', '').hidden).toBe(true);
    expect(text(row, 'preview', null).textContent).toBe('');
    const button = part(row, 'row');
    expect(part(button, 'row')).toBe(button);
    expect(() => part(row, 'missing')).toThrow('[data-part="missing"]');
  });
});
