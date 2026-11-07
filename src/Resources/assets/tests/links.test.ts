import { afterEach, beforeEach, describe, expect, it, type Mock, vi } from 'vitest';
import { ChatApiError, type ChatApi } from '../src/api';
import {
  checkLinkUrl,
  cleanLinkTitle,
  copyText,
  decodeLinkTokens,
  detectProvider,
  insertLinkToken,
  linkErrorKey,
  linksSettings,
  linkToken,
} from '../src/links';
import { plain, ThreadView, type ThreadHost } from '../src/thread';
import type { ChatConfig, ChatMessage, ConversationSummary, LinkProvider, LinksPage, LinksSettings, PinnedLink, SharedLink } from '../src/types';
import { templates } from './support';

const providers: LinkProvider[] = [
  { key: 'teams', label: 'Microsoft Teams', hosts: ['teams.microsoft.com', 'teams.live.com'], icon: 'provider_teams', fallbackIcon: 'video' },
  { key: 'zoom', label: 'Zoom', hosts: ['zoom.us'], icon: 'provider_zoom', fallbackIcon: 'video' },
];
const settings: LinksSettings = { enabled: true, allowHttp: false, maxPinned: 3, titleMaxLength: 20, urlMaxLength: 60, providers };

const i18n: Record<string, string> = {
  close: 'Close', cancel: 'Cancel', save: 'Save', error: 'Error', deleted_user: 'Deleted user', rate_limited: 'Slow down', conversation_label: '%name%',
  links_attach: 'Attach link', links_panel: 'Links', links_bar: 'Pinned links', links_pinned: 'Pinned', links_shared: 'Shared in messages',
  links_add: 'Add link', links_add_button: 'Add', links_edit: 'Edit link', links_edit_named: 'Edit “%label%”', links_remove: 'Remove', links_url: 'URL',
  links_title: 'Title (optional)', links_detected: 'Detected: %provider%', links_insert: 'Insert', links_copy: 'Copy link', links_copied: 'Link copied',
  links_open: 'Open', links_goto: 'Go to message', links_search: 'Search links', links_filter: 'Provider', links_filter_all: 'All', links_filter_other: 'Other',
  links_empty: 'No links yet.', links_no_results: 'No links match.', links_load_more: 'Load more', links_invalid_url: 'Invalid URL',
  links_http_forbidden: 'https only', links_url_too_long: 'Too long (%max%)', links_invalid_title: 'Title too long (%max%)', links_too_many: 'Max %max% links',
  links_forbidden: 'Forbidden', links_not_found: 'Gone', links_shared_by: '%name% · %date%', sending: 'Sending',
};

const teamsRef = { key: 'teams', label: 'Microsoft Teams', icon: 'provider_teams', fallbackIcon: 'video' };
const pinned = (over: Partial<PinnedLink> = {}): PinnedLink => ({
  uuid: 'l1', url: 'https://teams.microsoft.com/l/meet', title: 'Daily', label: 'Daily', host: 'teams.microsoft.com', provider: teamsRef,
  createdBy: { uuid: 'p', name: 'Ana' }, createdAt: '2026-11-05T09:00:00Z', updatedAt: null, ...over,
});
const shared = (over: Partial<SharedLink> = {}): SharedLink => ({
  id: 7, url: 'https://example.com/doc', title: null, label: 'example.com', host: 'example.com', provider: null, kind: 'link',
  messageId: 3, messageUuid: 'm3', author: { uuid: 'p', name: 'Luis' }, createdAt: '2026-11-05T09:00:00Z', ...over,
});
const page = (over: Partial<LinksPage> = {}): LinksPage => ({ pinned: [pinned()], links: [shared()], hasMore: false, next: null, providers: [teamsRef], canManage: false, ...over });

const summary: ConversationSummary = {
  uuid: 'c1', kind: 'channel', title: 'Team', subtitle: '', initials: 'TE', peerUuid: null, channelKey: 'team', supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: null, lastMessage: null,
};
const message = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: 3, uuid: 'm3', authorUuid: 'p', authorName: 'Luis', authorInitials: 'LU', mine: false, kind: 'text', html: '<p>hola</p>',
  createdAt: '2026-11-05T09:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});

type Api = Record<'messages' | 'send' | 'typing' | 'members' | 'pinnedLinks' | 'addPinnedLink' | 'updatePinnedLink' | 'removePinnedLink' | 'links', Mock>;

const ENDPOINTS = { page: '/chat/__uuid__', pinnedLinks: '/l/__uuid__', pinnedLink: '/l/__uuid__/__link__', links: '/links/__uuid__' };

function setup(configOver: Partial<ChatConfig> = {}, apiOver: Partial<Api> = {}) {
  const api: Api = {
    messages: vi.fn().mockResolvedValue({ messages: [message()], hasMore: false, readUpTo: null, readers: [] }),
    send: vi.fn().mockReturnValue(new Promise(() => undefined)),
    typing: vi.fn().mockResolvedValue(undefined),
    members: vi.fn().mockResolvedValue([]),
    pinnedLinks: vi.fn().mockResolvedValue({ links: [pinned()], canManage: true, max: 3 }),
    addPinnedLink: vi.fn(),
    updatePinnedLink: vi.fn(),
    removePinnedLink: vi.fn(),
    links: vi.fn().mockResolvedValue(page()),
    ...apiOver,
  };
  const host: ThreadHost = {
    config: { i18n, endpoints: ENDPOINTS, userUuid: 'me', locale: 'en', maxLength: 500, editWindowSeconds: 900, reactions: [], isAgent: false, prefs: { sound: 'pop', buzz: true, presence: true }, links: settings, ...configOver } as ChatConfig,
    api: api as unknown as ChatApi,
    templates: templates(),
    minimise: vi.fn(), close: vi.fn(), saveDraft: vi.fn(), draft: vi.fn().mockReturnValue(''),
    messagesChanged: vi.fn(), read: vi.fn(), isMobile: vi.fn().mockReturnValue(false), conversationChanged: vi.fn(),
    replaceWith: vi.fn(), linkPreview: vi.fn().mockResolvedValue(null),
  };
  const view = new ThreadView(host, summary);
  document.body.append(view.element);
  const q = <T extends Element = HTMLElement>(selector: string) => view.element.querySelector<T>(selector) as T;
  const all = (selector: string) => [...view.element.querySelectorAll<HTMLElement>(selector)];
  return { view, host, api, q, all };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i += 1) {
    await Promise.resolve();
  }
}

/** Types into an input (value + input event). */
function type(input: HTMLInputElement, value: string): void {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

const dialog = () => document.querySelector<HTMLDialogElement>('[data-testid="nowo-chat-link-dialog"]');
const inDialog = <T extends HTMLElement = HTMLElement>(name: string) => dialog()?.querySelector<T>(`[data-part="${name}"]`) as T;
const submitDialog = () => dialog()?.querySelector('form')?.dispatchEvent(new Event('submit', { cancelable: true }));

describe('links helpers', () => {
  it('reads the settings and detects providers by host or subdomain', () => {
    expect(linksSettings({ links: settings } as ChatConfig)).toBe(settings);
    expect(linksSettings({ links: { ...settings, enabled: false } } as ChatConfig)).toBeNull();
    expect(linksSettings({} as ChatConfig)).toBeNull();
    expect(detectProvider('teams.microsoft.com', providers)?.key).toBe('teams');
    expect(detectProvider('Teams.Live.com.', providers)?.key).toBe('teams');
    expect(detectProvider('us02web.zoom.us', providers)?.key).toBe('zoom');
    expect(detectProvider('notzoom.us', providers)).toBeNull();
    expect(detectProvider('', providers)).toBeNull();
  });

  it('validates URLs like the server', () => {
    expect(checkLinkUrl('https://teams.microsoft.com/l/x', settings)).toEqual({ url: 'https://teams.microsoft.com/l/x', host: 'teams.microsoft.com' });
    expect(checkLinkUrl(' zoom.us/j/1 ', settings)).toEqual({ url: 'https://zoom.us/j/1', host: 'zoom.us' });
    expect(checkLinkUrl('meet.jit.si:8443/room', settings)).toEqual({ url: 'https://meet.jit.si:8443/room', host: 'meet.jit.si' });
    expect(checkLinkUrl('', settings)).toEqual({ error: 'links_invalid_url' });
    expect(checkLinkUrl('javascript:alert(1)', settings)).toEqual({ error: 'links_invalid_url' });
    expect(checkLinkUrl('data:text/html,hi', settings)).toEqual({ error: 'links_invalid_url' });
    expect(checkLinkUrl('ftp://files.example.com', settings)).toEqual({ error: 'links_invalid_url' });
    expect(checkLinkUrl('https://user:pw@example.com', settings)).toEqual({ error: 'links_invalid_url' });
    expect(checkLinkUrl('https://exa mple.com', settings)).toEqual({ error: 'links_invalid_url' });
    expect(checkLinkUrl('http://example.com', settings)).toEqual({ error: 'links_http_forbidden' });
    expect(checkLinkUrl('http://example.com', { ...settings, allowHttp: true })).toEqual({ url: 'http://example.com/', host: 'example.com' });
    expect(checkLinkUrl(`https://example.com/${'a'.repeat(60)}`, settings)).toEqual({ error: 'links_url_too_long', params: { max: 60 } });
    // Within the limit as typed, over it once normalised (encoded spaces).
    expect(checkLinkUrl(`https://example.com/${'a b'.repeat(13)}`, settings)).toEqual({ error: 'links_url_too_long', params: { max: 60 } });
  });

  it('builds, decodes and inserts link tokens', () => {
    expect(cleanLinkTitle(' [Daily]\nstand-up ')).toBe('Daily stand-up');
    expect(cleanLinkTitle('abcdefghij', 4)).toBe('abcd');
    expect(linkToken('Doc [v2]', 'https://x.com/a_(b) c')).toBe('!link[Doc v2](https://x.com/a_%28b%29%20c)');
    expect(linkToken('', 'https://x.com/')).toBe('!link[](https://x.com/)');
    expect(decodeLinkTokens('See !link[Daily](https://teams.microsoft.com/l) and !link[](https://zoom.us/j/1) or !link[ ](nope)')).toBe('See Daily and zoom.us or nope');

    const box = document.createElement('textarea');
    box.value = 'hola mundo';
    box.setSelectionRange(4, 4);
    insertLinkToken(box, '!link[](https://x.com/)');
    expect(box.value).toBe('hola\n!link[](https://x.com/)\n mundo');
    box.value = '';
    insertLinkToken(box, 'T');
    expect(box.value).toBe('T');
    box.value = 'a\n';
    box.setSelectionRange(2, 2);
    insertLinkToken(box, 'T');
    expect(box.value).toBe('a\nT');
  });

  it('maps the API error codes to messages', () => {
    expect(linkErrorKey(new ChatApiError(422, 'invalid_link_url'))).toBe('links_invalid_url');
    expect(linkErrorKey(new ChatApiError(422, 'invalid_link_title'))).toBe('links_invalid_title');
    expect(linkErrorKey(new ChatApiError(422, 'invalid_link'))).toBe('links_invalid_url');
    expect(linkErrorKey(new ChatApiError(422, 'too_many_links'))).toBe('links_too_many');
    expect(linkErrorKey(new ChatApiError(403, 'links_forbidden'))).toBe('links_forbidden');
    expect(linkErrorKey(new ChatApiError(404, 'not_found'))).toBe('links_not_found');
    expect(linkErrorKey(new ChatApiError(429, 'rate_limited'))).toBe('rate_limited');
    expect(linkErrorKey(new ChatApiError(500, 'boom'))).toBe('error');
    expect(linkErrorKey(new Error('x'))).toBe('error');
  });

  describe('copyText', () => {
    afterEach(() => {
      vi.unstubAllGlobals();
      // @ts-expect-error test cleanup
      delete document.execCommand;
    });

    it('uses the Clipboard API, else execCommand', async () => {
      const writeText = vi.fn().mockResolvedValue(undefined);
      vi.stubGlobal('navigator', { clipboard: { writeText } });
      expect(await copyText('https://x')).toBe(true);
      expect(writeText).toHaveBeenCalledWith('https://x');

      writeText.mockRejectedValue(new Error('denied'));
      Object.defineProperty(document, 'execCommand', { configurable: true, value: vi.fn().mockReturnValue(true) });
      expect(await copyText('https://y')).toBe(true);
      expect(document.querySelector('textarea')).toBeNull();

      vi.stubGlobal('navigator', {});
      Object.defineProperty(document, 'execCommand', { configurable: true, value: vi.fn().mockImplementation(() => { throw new Error('no'); }) });
      expect(await copyText('https://z')).toBe(false);
      // @ts-expect-error test cleanup
      delete document.execCommand;
      expect(await copyText('https://z')).toBe(false);
    });
  });
});

describe('conversation links', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    Element.prototype.scrollTo = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
      cb(0);
      return 1;
    });
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.replaceChildren();
  });

  it('shows no links UI when the feature is off', async () => {
    const { q, api } = setup({ links: undefined });
    await flush();
    expect(q('[data-testid="nowo-chat-links-bar"]')).toBeNull();
    expect(q('[data-testid="nowo-chat-attach-link"]')).toBeNull();
    expect(q('[data-testid="nowo-chat-links-open"]')).toBeNull();
    expect(api.pinnedLinks).not.toHaveBeenCalled();
  });

  it('shows the pinned links read-only, hidden when there are none', async () => {
    const { q, all, view, api } = setup({}, { pinnedLinks: vi.fn().mockResolvedValue({ links: [pinned(), pinned({ uuid: 'l2', url: 'https://example.com/', title: null, label: 'example.com', host: 'example.com', provider: null })], canManage: false, max: 3 }) });
    await flush();
    const bar = q('[data-testid="nowo-chat-links-bar"]');
    expect(bar.hidden).toBe(false);
    // Under the header, before the pinned messages.
    expect(bar.previousElementSibling?.getAttribute('data-part')).toBe('context');
    expect(bar.nextElementSibling?.classList.contains('nowo-chat-pins')).toBe(true);
    const chips = all('[data-testid="nowo-chat-link-chip"]') as HTMLAnchorElement[];
    expect(chips.map((chip) => chip.textContent)).toEqual(['Daily', 'example.com']);
    expect(chips[0]?.getAttribute('href')).toBe('https://teams.microsoft.com/l/meet');
    expect(chips[0]?.target).toBe('_blank');
    expect(chips[0]?.rel).toBe('noopener noreferrer');
    expect(chips[0]?.title).toBe('https://teams.microsoft.com/l/meet');
    expect(chips[0]?.dataset.provider).toBe('teams');
    expect(chips[1]?.hasAttribute('data-provider')).toBe(false);
    expect(q('[data-part="link-edit"]')).toBeNull();
    expect(q('[data-testid="nowo-chat-links-add"]')).toBeNull();

    api.pinnedLinks.mockResolvedValue({ links: [], canManage: false, max: 3 });
    await view.loadLinks();
    expect(bar.hidden).toBe(true);
    // A failed reload keeps the bar as it was.
    api.pinnedLinks.mockRejectedValue(new Error('x'));
    await view.loadLinks();
    expect(bar.hidden).toBe(true);
  });

  it('works without the pinned links endpoint (composer and panel only)', async () => {
    const { q, api } = setup({ endpoints: { page: '/chat/__uuid__' } as ChatConfig['endpoints'] });
    await flush();
    expect(api.pinnedLinks).not.toHaveBeenCalled();
    expect(q('[data-testid="nowo-chat-links-bar"]').hidden).toBe(true);
    expect(q('[data-testid="nowo-chat-attach-link"]')).not.toBeNull();
    expect(q('[data-testid="nowo-chat-links-open"]')).toBeNull();
  });

  it('adds a pinned link through the dialog (validation, provider, server errors)', async () => {
    const { q, api, host } = setup({}, { pinnedLinks: vi.fn().mockResolvedValue({ links: [], canManage: true, max: 3 }) });
    await flush();
    const bar = q('[data-testid="nowo-chat-links-bar"]');
    expect(bar.hidden).toBe(false);
    const add = q<HTMLButtonElement>('[data-testid="nowo-chat-links-add"]');
    expect(add.querySelector('.nowo-chat-linkbar__add-text')?.textContent).toBe('Add link');
    add.click();
    const element = dialog() as HTMLDialogElement;
    expect(element.hasAttribute('open')).toBe(true);
    expect(element.dataset.mode).toBe('add');
    // 1.20 (dialog rule v2): the medium size; header and footer outside the scrolling body.
    expect(element.classList.contains('nowo-chat-dialog--md')).toBe(true);
    expect(element.querySelector('.nowo-chat-linkdialog__body footer, .nowo-chat-linkdialog__body header')).toBeNull();
    const heading = element.querySelector('h2') as HTMLElement;
    expect(heading.textContent).toBe('Add link');
    expect(element.getAttribute('aria-labelledby')).toBe(heading.id);
    const url = inDialog<HTMLInputElement>('link-url');
    expect(url.inputMode).toBe('url');
    expect(url.required).toBe(true);
    expect(document.activeElement).toBe(url);
    expect(element.querySelector(`label[for="${url.id}"]`)?.textContent).toBe('URL');
    const footer = [...element.querySelectorAll('footer button')].map((button) => button.textContent);
    expect(footer).toEqual(['Cancel', 'Add']);

    // Validation mirrors the server.
    submitDialog();
    await flush();
    expect(inDialog('link-error').textContent).toBe('Invalid URL');
    expect(inDialog('link-error').getAttribute('role')).toBe('alert');
    expect(url.getAttribute('aria-invalid')).toBe('true');
    type(url, 'javascript:alert(1)');
    expect(inDialog('link-error').hidden).toBe(true);
    expect(url.hasAttribute('aria-invalid')).toBe(false);
    submitDialog();
    await flush();
    expect(inDialog('link-error').textContent).toBe('Invalid URL');
    type(url, 'http://example.com');
    submitDialog();
    await flush();
    expect(inDialog('link-error').textContent).toBe('https only');
    expect(api.addPinnedLink).not.toHaveBeenCalled();

    // Provider detection, including subdomains.
    type(url, 'https://teams.microsoft.com/l/meetup-join/1');
    expect(inDialog('link-detected').hidden).toBe(false);
    expect(inDialog('link-detected').textContent).toBe('Detected: Microsoft Teams');
    expect(inDialog('link-detected').querySelector('svg')).not.toBeNull();
    type(url, 'https://example.com');
    expect(inDialog('link-detected').hidden).toBe(true);
    type(url, 'https://teams.microsoft.com/l/1');

    // A server error stays in the dialog.
    api.addPinnedLink.mockRejectedValueOnce(new ChatApiError(422, 'too_many_links'));
    type(inDialog<HTMLInputElement>('link-title'), ' Daily [x] ');
    submitDialog();
    await flush();
    expect(api.addPinnedLink).toHaveBeenLastCalledWith('c1', 'https://teams.microsoft.com/l/1', 'Daily x');
    expect(inDialog('link-error').textContent).toBe('Max 3 links');
    expect(inDialog<HTMLButtonElement>('link-save').disabled).toBe(false);

    api.addPinnedLink.mockResolvedValueOnce({ link: pinned(), links: [pinned()], canManage: true, max: 3 });
    submitDialog();
    await flush();
    expect(dialog()).toBeNull();
    expect(bar.querySelectorAll('[data-testid="nowo-chat-link-chip"]')).toHaveLength(1);
    // The Add button was re-rendered: focus lands on the new one; with links, its text is for screen readers only.
    const again = q<HTMLButtonElement>('[data-testid="nowo-chat-links-add"]');
    expect(document.activeElement).toBe(again);
    expect(again.querySelector('.nowo-chat-sr-only')?.textContent).toBe('Add link');
    expect(host.minimise).not.toHaveBeenCalled();
  });

  it('disables Add at the limit with a hint', async () => {
    const { q, view } = setup({}, { pinnedLinks: vi.fn().mockResolvedValue({ links: [pinned()], canManage: true, max: 1 }) });
    await flush();
    const add = q<HTMLButtonElement>('[data-testid="nowo-chat-links-add"]');
    expect(add.getAttribute('aria-disabled')).toBe('true');
    expect(add.title).toBe('Max 1 links');
    add.click();
    expect(dialog()).toBeNull();
    expect(q('[data-part="notice"]').textContent).toBe('Max 1 links');
    expect(view).toBeDefined();
  });

  it('edits and removes a pinned link; Escape, Cancel and × close the dialog without minimising the window', async () => {
    const { q, api, host } = setup();
    await flush();
    const edit = q<HTMLButtonElement>('[data-part="link-edit"]');
    expect(edit.getAttribute('aria-label')).toBe('Edit “Daily”');

    edit.click();
    expect(inDialog<HTMLInputElement>('link-url').value).toBe('https://teams.microsoft.com/l/meet');
    expect(inDialog<HTMLInputElement>('link-title').value).toBe('Daily');
    expect([...(dialog() as HTMLElement).querySelectorAll('footer button')].map((button) => button.textContent)).toEqual(['Remove', 'Cancel', 'Save']);
    (dialog() as HTMLElement).dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(dialog()).toBeNull();
    expect(host.minimise).not.toHaveBeenCalled();
    expect(document.activeElement).toBe(edit);

    edit.click();
    (dialog() as HTMLElement).dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true }));
    inDialog('link-cancel').click();
    expect(dialog()).toBeNull();
    edit.click();
    (dialog()?.querySelector('.nowo-chat-linkdialog__close') as HTMLButtonElement).click();
    expect(dialog()).toBeNull();
    edit.click();
    (dialog() as HTMLElement).dispatchEvent(new Event('cancel', { cancelable: true }));
    expect(dialog()).toBeNull();

    edit.click();
    type(inDialog<HTMLInputElement>('link-title'), 'Weekly');
    api.updatePinnedLink.mockResolvedValue({ link: pinned({ title: 'Weekly', label: 'Weekly' }), links: [pinned({ title: 'Weekly', label: 'Weekly' })], canManage: true, max: 3 });
    submitDialog();
    await flush();
    expect(api.updatePinnedLink).toHaveBeenCalledWith('c1', 'l1', 'https://teams.microsoft.com/l/meet', 'Weekly');
    expect(q('[data-testid="nowo-chat-link-chip"]').textContent).toBe('Weekly');
    // The edit button was re-rendered: focus goes to the chip of the link.
    expect(document.activeElement).toBe(q('[data-testid="nowo-chat-link-chip"]'));

    q<HTMLButtonElement>('[data-part="link-edit"]').click();
    api.removePinnedLink.mockRejectedValueOnce(new ChatApiError(404, 'not_found'));
    inDialog('link-remove').click();
    await flush();
    expect(inDialog('link-error').textContent).toBe('Gone');
    api.removePinnedLink.mockResolvedValueOnce({ links: [], canManage: true, max: 3 });
    inDialog('link-remove').click();
    await flush();
    expect(api.removePinnedLink).toHaveBeenLastCalledWith('c1', 'l1');
    expect(dialog()).toBeNull();
    expect(q('[data-testid="nowo-chat-link-chip"]')).toBeNull();
    expect(document.activeElement).toBe(q('[data-testid="nowo-chat-links-add"]'));
  });

  it('uses the native modal dialog when the browser has one', async () => {
    const showModal = vi.fn(function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    });
    const close = vi.fn(function (this: HTMLDialogElement) {
      this.removeAttribute('open');
      this.dispatchEvent(new Event('close'));
    });
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: showModal });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value: close });
    Object.defineProperty(HTMLDialogElement.prototype, 'open', { configurable: true, get(this: HTMLDialogElement) { return this.hasAttribute('open'); } });
    try {
      const { q } = setup();
      await flush();
      q<HTMLButtonElement>('[data-part="link-edit"]').click();
      expect(showModal).toHaveBeenCalled();
      inDialog('link-cancel').click();
      expect(close).toHaveBeenCalled();
      expect(dialog()).toBeNull();
    } finally {
      // @ts-expect-error test cleanup
      delete HTMLDialogElement.prototype.showModal;
      // @ts-expect-error test cleanup
      delete HTMLDialogElement.prototype.close;
      // @ts-expect-error test cleanup
      delete HTMLDialogElement.prototype.open;
    }
  });

  it('attaches a link card to the message being written', async () => {
    const { q, api } = setup({ endpoints: { ...ENDPOINTS, quickReplies: '/qr' } as ChatConfig['endpoints'] });
    await flush();
    const attach = q<HTMLButtonElement>('[data-testid="nowo-chat-attach-link"]');
    expect(attach.dataset.part).toBe('attach-link');
    expect(attach.getAttribute('aria-label')).toBe('Attach link');
    expect(attach.previousElementSibling?.getAttribute('data-part')).toBe('quick-replies');
    const input = q<HTMLTextAreaElement>('[data-part="input"]');
    input.value = 'Join here';
    input.setSelectionRange(9, 9);

    attach.click();
    expect(dialog()?.dataset.mode).toBe('message');
    expect(inDialog('link-save').textContent).toBe('Insert');
    expect(dialog()?.querySelector('[data-part="link-remove"]')).toBeNull();
    type(inDialog<HTMLInputElement>('link-url'), 'teams.microsoft.com/l/x');
    type(inDialog<HTMLInputElement>('link-title'), 'Standup [daily]');
    submitDialog();
    await flush();
    expect(dialog()).toBeNull();
    expect(input.value).toBe('Join here\n!link[Standup daily](https://teams.microsoft.com/l/x)');
    expect(document.activeElement).toBe(input);

    // The pending bubble shows the title, not the token.
    q('form[data-part="composer"]').dispatchEvent(new Event('submit', { cancelable: true }));
    await flush();
    expect(api.send).toHaveBeenCalledWith('c1', 'Join here\n!link[Standup daily](https://teams.microsoft.com/l/x)');
    expect(q('.nowo-chat-msg--pending [data-part="bubble"]').textContent).toBe('Join here\nStandup daily');
  });

  it('fades the dialog before closing it, and moves focus to the composer only after that', async () => {
    const { q } = setup();
    await flush();
    const input = q<HTMLTextAreaElement>('[data-part="input"]');
    q('[data-testid="nowo-chat-attach-link"]').click();
    const opened = dialog() as HTMLDialogElement;
    type(inDialog<HTMLInputElement>('link-url'), 'teams.microsoft.com/l/x');
    const style = vi.spyOn(window, 'getComputedStyle').mockReturnValue({ transitionDuration: '150ms', transitionDelay: '0s' } as CSSStyleDeclaration);
    submitDialog();
    await flush();
    expect(dialog()).toBe(opened);
    expect(opened.hasAttribute('data-nowo-chat-closing')).toBe(true);
    expect(input.value).toBe('');
    opened.dispatchEvent(new Event('transitionend'));
    await flush();
    style.mockRestore();
    expect(dialog()).toBeNull();
    expect(input.value).toContain('!link[');
    expect(document.activeElement).toBe(input);
  });

  it('places the attach button after the formatting toggle without quick replies', async () => {
    const { q } = setup();
    await flush();
    expect(q('[data-testid="nowo-chat-attach-link"]').previousElementSibling?.getAttribute('data-part')).toBe('format-toggle');
  });

  it('opens the links panel: sections, paging, search, filter, copy, go to message', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const { q, all, api, view, host } = setup();
    await view.load();
    await flush();
    const toggle = q<HTMLButtonElement>('[data-testid="nowo-chat-links-open"]');
    expect(toggle.dataset.part).toBe('links');
    expect(toggle.getAttribute('aria-label')).toBe('Links');
    expect(toggle.nextElementSibling?.getAttribute('data-part')).toBe('popout');
    const panel = q('[data-testid="nowo-chat-links-panel"]');
    expect(toggle.getAttribute('aria-controls')).toBe(panel.id);
    expect(panel.hidden).toBe(true);

    api.links.mockResolvedValueOnce(page({ links: [shared(), shared({ id: 8, messageId: 4, author: null, provider: teamsRef, label: 'Standup', title: 'Standup', host: 'teams.microsoft.com' })], hasMore: true, next: 8 }));
    toggle.click();
    await flush();
    expect(panel.hidden).toBe(false);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(panel.getAttribute('aria-labelledby')).toBe(panel.querySelector('h2')?.id);
    expect(panel.querySelector('h2')?.textContent).toBe('Links');
    expect(document.activeElement).toBe(panel.querySelector('[data-part="links-search"]'));
    expect(api.links).toHaveBeenLastCalledWith('c1', { q: '', provider: '' });
    const select = panel.querySelector<HTMLSelectElement>('[data-part="links-provider"]') as HTMLSelectElement;
    expect([...select.options].map((option) => option.textContent)).toEqual(['All', 'Microsoft Teams', 'Other']);
    expect(all('[data-part="links-pinned"] li')).toHaveLength(1);
    const items = all('[data-part="links-shared"] li');
    expect(items).toHaveLength(2);
    expect(items[0]?.querySelector('.nowo-chat-links__label')?.textContent).toBe('example.com');
    expect(items[0]?.querySelector('.nowo-chat-links__meta')?.textContent).toMatch(/^Luis · /);
    expect(items[1]?.querySelector('.nowo-chat-links__meta')?.textContent).toMatch(/^Deleted user · /);
    expect(items[1]?.dataset.provider).toBe('teams');
    const open = items[0]?.querySelector<HTMLAnchorElement>('[data-part="link-open"]') as HTMLAnchorElement;
    expect(open.href).toBe('https://example.com/doc');
    expect(open.rel).toBe('noopener noreferrer');
    expect(open.getAttribute('aria-label')).toBe('Open — example.com');
    // Pinned links have no "Go to message"; without the right to manage, no Edit either.
    expect(all('[data-part="links-pinned"] [data-part="link-goto"]')).toHaveLength(0);
    expect(all('[data-part="links-pinned"] [data-part="link-edit"]')).toHaveLength(0);

    // Load more: the next page is appended.
    const more = q<HTMLButtonElement>('[data-part="links-more"]');
    expect(more.hidden).toBe(false);
    api.links.mockResolvedValueOnce(page({ pinned: undefined, links: [shared({ id: 9, messageId: 1 })], hasMore: false, next: null }));
    more.click();
    await flush();
    expect(api.links).toHaveBeenLastCalledWith('c1', { q: '', provider: '', before: 8 });
    expect(all('[data-part="links-shared"] li')).toHaveLength(3);
    expect(more.hidden).toBe(true);
    // Nothing more to load: a stray click does nothing.
    api.links.mockClear();
    more.click();
    expect(api.links).not.toHaveBeenCalled();

    // Copy link: announced in the live region.
    all('[data-part="links-shared"] [data-part="link-copy"]')[0]?.click();
    await flush();
    expect(writeText).toHaveBeenCalledWith('https://example.com/doc');
    expect(q('[data-part="live"]').textContent).toBe('Link copied');
    writeText.mockRejectedValueOnce(new Error('denied'));
    all('[data-part="links-shared"] [data-part="link-copy"]')[0]?.click();
    await flush();
    expect(q('[data-part="notice"]').textContent).toBe('Error');

    // Search (debounced) and filter.
    api.links.mockResolvedValue(page({ pinned: [], links: [] }));
    const search = panel.querySelector<HTMLInputElement>('[data-part="links-search"]') as HTMLInputElement;
    type(search, 'st');
    type(search, 'sta');
    expect(api.links).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(250);
    expect(api.links).toHaveBeenCalledTimes(1);
    expect(api.links).toHaveBeenLastCalledWith('c1', { q: 'sta', provider: '' });
    expect(q('[data-part="links-state"]').textContent).toBe('No links match.');
    select.value = 'teams';
    select.dispatchEvent(new Event('change'));
    await flush();
    expect(api.links).toHaveBeenLastCalledWith('c1', { q: 'sta', provider: 'teams' });
    // The filter keeps its selection when the providers are refreshed.
    expect(select.value).toBe('teams');
    type(search, '');
    select.value = '';
    select.dispatchEvent(new Event('change'));
    await flush();
    expect(q('[data-part="links-state"]').textContent).toBe('No links yet.');

    // Go to message: the panel closes and the thread shows the message.
    api.links.mockResolvedValue(page({ pinned: [], links: [shared()] }));
    await vi.advanceTimersByTimeAsync(250);
    all('[data-part="links-shared"] [data-part="link-goto"]')[0]?.click();
    await flush();
    expect(panel.hidden).toBe(true);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(q('[data-id="3"]').classList.contains('is-highlighted')).toBe(true);

    // Escape closes the panel (not the window) and focus returns to the header button.
    toggle.click();
    await flush();
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    expect(panel.hidden).toBe(false);
    panel.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(panel.hidden).toBe(true);
    expect(document.activeElement).toBe(toggle);
    expect(host.minimise).not.toHaveBeenCalled();
    toggle.click();
    await flush();
    panel.querySelector<HTMLButtonElement>('[data-part="links-close"]')?.click();
    expect(panel.hidden).toBe(true);
  });

  it('refreshes the open panel on messages (debounced) and on a links event; errors and stale answers', async () => {
    const { q, api, view } = setup();
    await view.load();
    await flush();
    const toggle = q<HTMLButtonElement>('[data-testid="nowo-chat-links-open"]');
    // Closed panel: messages change nothing.
    view.merge([message({ id: 10 })], true);
    await vi.advanceTimersByTimeAsync(1_000);
    expect(api.links).not.toHaveBeenCalled();

    toggle.click();
    await flush();
    api.links.mockClear();
    view.merge([message({ id: 11 })], true);
    view.update(message({ id: 11, html: '<p>edited</p>' }));
    await vi.advanceTimersByTimeAsync(999);
    expect(api.links).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(api.links).toHaveBeenCalledTimes(1);

    api.links.mockClear();
    await view.loadLinks();
    await flush();
    expect(api.links).toHaveBeenCalledTimes(1);

    // An error replaces the lists.
    api.links.mockRejectedValueOnce(new Error('down'));
    await view.loadLinks();
    await flush();
    expect(q('[data-part="links-state"]').textContent).toBe('Error');

    // A slower, older answer never overwrites a newer one.
    let resolveOld: (value: LinksPage) => void = () => undefined;
    api.links.mockReturnValueOnce(new Promise<LinksPage>((resolve) => (resolveOld = resolve)));
    api.links.mockRejectedValueOnce(new Error('later'));
    const select = q<HTMLSelectElement>('[data-part="links-provider"]');
    select.dispatchEvent(new Event('change'));
    select.dispatchEvent(new Event('change'));
    await flush();
    resolveOld(page());
    await flush();
    expect(q('[data-part="links-state"]').textContent).toBe('Error');
    expect((q('[data-part="links-pinned"]').parentElement as HTMLElement).hidden).toBe(true);

    // A failed "Load more" shows a notice.
    api.links.mockResolvedValueOnce(page({ hasMore: true, next: 5 }));
    select.dispatchEvent(new Event('change'));
    await flush();
    api.links.mockRejectedValueOnce(new Error('x'));
    q<HTMLButtonElement>('[data-part="links-more"]').click();
    await flush();
    expect(q('[data-part="notice"]').textContent).toBe('Error');
    expect(q<HTMLButtonElement>('[data-part="links-more"]').disabled).toBe(false);
    // A page that arrives after a newer request started is dropped.
    let resolveMore: (value: LinksPage) => void = () => undefined;
    api.links.mockReturnValueOnce(new Promise<LinksPage>((resolve) => (resolveMore = resolve)));
    q<HTMLButtonElement>('[data-part="links-more"]').click();
    api.links.mockResolvedValueOnce(page({ links: [] }));
    select.dispatchEvent(new Event('change'));
    await flush();
    resolveMore(page({ pinned: undefined, links: [shared({ id: 99 })] }));
    await flush();
    expect(q('[data-part="links-shared"]').childElementCount).toBe(0);
  });

  it('edits a pinned link from the panel when the viewer may manage them', async () => {
    const { q, api, all } = setup({}, { links: vi.fn().mockResolvedValue(page({ canManage: true, providers: [] })) });
    await flush();
    q<HTMLButtonElement>('[data-testid="nowo-chat-links-open"]').click();
    await flush();
    const select = q<HTMLSelectElement>('[data-part="links-provider"]');
    // Without providers in the answer the configured ones stay.
    expect([...select.options].map((option) => option.value)).toEqual(['', 'teams', 'zoom', 'other']);
    const edit = all('[data-part="links-pinned"] [data-part="link-edit"]')[0] as HTMLButtonElement;
    expect(edit.getAttribute('aria-label')).toBe('Edit “Daily”');
    edit.click();
    expect(dialog()?.dataset.mode).toBe('edit');
    api.updatePinnedLink.mockResolvedValue({ link: pinned(), links: [pinned()], canManage: true, max: 3 });
    submitDialog();
    await flush();
    expect(api.updatePinnedLink).toHaveBeenCalled();
    expect(api.links.mock.calls.length).toBeGreaterThanOrEqual(2);
  });

  it('validates the title length in the dialog', async () => {
    const { q } = setup();
    await flush();
    q<HTMLButtonElement>('[data-part="link-edit"]').click();
    const title = inDialog<HTMLInputElement>('link-title');
    title.removeAttribute('maxlength');
    type(title, 'x'.repeat(30));
    submitDialog();
    await flush();
    expect(inDialog('link-error').textContent).toBe('Title too long (20)');
    expect(document.activeElement).toBe(title);
  });
});

describe('plain() and link cards', () => {
  const card =
    '<p>Meeting: <a class="nowo-chat-linkcard" href="https://teams.microsoft.com/l/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer nofollow ugc" data-provider="teams"><span class="nowo-chat-linkcard__icon" data-icon="provider_teams" data-icon-fallback="video" data-icon-size="18" aria-hidden="true"></span><span class="nowo-chat-linkcard__text"><span class="nowo-chat-linkcard__title">Daily</span><span class="nowo-chat-linkcard__meta">Microsoft Teams · teams.microsoft.com</span></span></a></p>';

  it('reads the title in previews and gives the token back to the editor', () => {
    expect(plain(card)).toBe('Meeting: Daily');
    expect(plain(card, true)).toBe('Meeting: !link[Daily](https://teams.microsoft.com/l/x?a=1&b=2)');
    expect(plain('<p><a class="nowo-chat-linkcard" href="https://x.com/"></a></p>')).toBe('https://x.com/');
    expect(plain('<p><a class="nowo-chat-linkcard" data-title="" href="https://x.com/"><span class="nowo-chat-linkcard__title">x.com</span></a></p>', true)).toBe('!link[](https://x.com/)');
    expect(plain('<p><a class="nowo-chat-linkcard"></a></p>', true)).toBe('!link[]()');
  });

  it('renders a link card in a message with its fallback icon and no link preview request', async () => {
    const { view, q, host } = setup({ linkPreviews: true });
    (host.linkPreview as Mock).mockResolvedValue(null);
    view.merge([message({ id: 20, html: card })], false);
    const icon = q('[data-id="20"] .nowo-chat-linkcard__icon svg');
    expect(icon).not.toBeNull();
    expect(icon.innerHTML).toContain('rect');
    expect(host.linkPreview).not.toHaveBeenCalled();
    document.body.replaceChildren();
  });
});
