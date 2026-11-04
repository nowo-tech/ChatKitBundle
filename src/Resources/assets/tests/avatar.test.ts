/// <reference types="vite/client" />
import { afterEach, describe, expect, it, vi } from 'vitest';
import { attachAvatarImage, forgetFailedAvatars, isSafeAvatarUrl } from '../src/avatar';
import { MentionAutocomplete } from '../src/mention';
import { Templates } from '../src/templates';
import type { Member } from '../src/types';
import TABLE from './fixtures/avatar-urls.json?raw';
import { templatesRoot } from './support';

/** An entry of the shared URL table (also run by AvatarUrlPolicyTest). */
interface Entry {
  name: string;
  url?: string | null;
  prefix?: string;
  repeat?: string;
  count?: number;
  suffix?: string;
}

const table = JSON.parse(TABLE) as { accepted: Entry[]; rejected: Entry[] };

const urlOf = (entry: Entry): string | null =>
  entry.repeat === undefined ? entry.url ?? null : `${entry.prefix ?? ''}${entry.repeat.repeat(entry.count ?? 0)}${entry.suffix ?? ''}`;

describe('avatar pictures (1.16)', () => {
  afterEach(() => {
    forgetFailedAvatars();
    document.body.replaceChildren();
  });

  it.each(table.accepted.map((entry) => [entry.name, urlOf(entry)] as const))('accepts %s', (_name, url) => {
    expect(isSafeAvatarUrl(url)).toBe(true);
  });

  it.each(table.rejected.map((entry) => [entry.name, urlOf(entry)] as const))('rejects %s', (_name, url) => {
    expect(isSafeAvatarUrl(url)).toBe(false);
  });

  it('keeps the initials bubble byte-identical without a picture', () => {
    const templates = new Templates(templatesRoot());
    const plain = templates.avatar('AN', 'direct', 'sm', true);
    for (const empty of [undefined, null, '', 'javascript:alert(1)', '//evil.example/a.webp']) {
      expect(templates.avatar('AN', 'direct', 'sm', true, undefined, empty).outerHTML).toBe(plain.outerHTML);
    }
    expect(plain.outerHTML).toBe('<span class="nowo-chat-avatar nowo-chat-avatar--direct nowo-chat-avatar--sm is-online" aria-hidden="true">AN</span>');
    // Channels and the support inbox never get a picture.
    expect(templates.avatar('', 'channel', 'md', false, undefined, '/files/x').querySelector('img')).toBeNull();
    expect(templates.avatar('', 'support', 'md', false, undefined, '/files/x').querySelector('img')).toBeNull();
  });

  it('puts the picture over the initials and falls back on error, once per page', () => {
    const templates = new Templates(templatesRoot());
    const bubble = templates.avatar('AN', 'direct', 'md', false, 'busy', '/files/ana/s128');
    expect(bubble.className).toBe('nowo-chat-avatar nowo-chat-avatar--direct nowo-chat-avatar--md is-online is-status-busy has-image');
    expect(bubble.textContent).toBe('AN');
    const image = bubble.querySelector('img');
    expect(image).not.toBeNull();
    expect(image?.className).toBe('nowo-chat-avatar__image');
    expect(image?.getAttribute('src')).toBe('/files/ana/s128');
    expect(image?.getAttribute('alt')).toBe('');
    expect(image?.getAttribute('width')).toBe('40');
    expect(image?.getAttribute('height')).toBe('40');
    expect(image?.getAttribute('loading')).toBe('lazy');
    expect(image?.getAttribute('decoding')).toBe('async');
    expect(image?.getAttribute('referrerpolicy')).toBe('same-origin');
    expect(image?.hasAttribute('style')).toBe(false);
    expect([...(image?.attributes ?? [])].some((attribute) => attribute.name.startsWith('on'))).toBe(false);
    expect(templates.avatar('AN', 'direct', 'sm', false, undefined, '/files/ana/s128').querySelector('img')?.getAttribute('width')).toBe('28');

    image?.dispatchEvent(new Event('error'));
    expect(bubble.querySelector('img')).toBeNull();
    expect(bubble.classList.contains('has-image')).toBe(false);
    expect(bubble.textContent).toBe('AN');
    // The failed URL is not requested again on this page.
    expect(templates.avatar('AN', 'direct', 'md', false, undefined, '/files/ana/s128').querySelector('img')).toBeNull();
    expect(templates.avatar('LU', 'direct', 'md', false, undefined, 'https://cdn.example.com/luis.webp').querySelector('img')).not.toBeNull();
  });

  it('is never stored: a new page (or a forget) tries the URL again', () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem');
    const holder = document.createElement('span');
    attachAvatarImage(holder, '/files/x', 24);
    holder.querySelector('img')?.dispatchEvent(new Event('error'));
    expect(attachAvatarImage(document.createElement('span'), '/files/x', 24)).toBe(false);
    expect(setItem).not.toHaveBeenCalled();
    forgetFailedAvatars();
    expect(attachAvatarImage(document.createElement('span'), '/files/x', 24)).toBe(true);
    setItem.mockRestore();
  });

  it('gives mention options the same picture and fallback', async () => {
    vi.useFakeTimers();
    const members: Member[] = [
      { uuid: 'u-ana', name: 'Ana', initials: 'AN', avatarUrl: '/files/ana/s128', status: 'busy' },
      { uuid: 'u-luis', name: 'Luis', initials: 'LU', avatarUrl: 'javascript:alert(1)' },
      { uuid: 'u-bea', name: 'Bea', initials: 'BE' },
    ];
    const form = document.createElement('form');
    const input = document.createElement('textarea');
    form.append(input);
    document.body.append(form);
    const auto = new MentionAutocomplete(input, { load: () => Promise.resolve(members), i18n: {}, idPrefix: 'm1', debounceMs: 0 });
    input.value = '@';
    input.setSelectionRange(1, 1);
    input.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(200);
    expect(auto.isOpen).toBe(true);
    const initials = [...document.querySelectorAll<HTMLElement>('.nowo-chat-mention-option__initials')];
    expect(initials).toHaveLength(3);
    const [ana, luis, bea] = initials;
    expect(ana?.classList.contains('has-image')).toBe(true);
    expect(ana?.querySelector('img')?.getAttribute('width')).toBe('24');
    expect(ana?.lastElementChild?.classList.contains('nowo-chat-status-dot')).toBe(true);
    expect(luis?.querySelector('img')).toBeNull();
    expect(bea?.outerHTML).toBe('<span class="nowo-chat-mention-option__initials" aria-hidden="true">BE</span>');
    ana?.querySelector('img')?.dispatchEvent(new Event('error'));
    expect(ana?.querySelector('img')).toBeNull();
    expect(ana?.classList.contains('has-image')).toBe(false);
    vi.useRealTimers();
  });
});
