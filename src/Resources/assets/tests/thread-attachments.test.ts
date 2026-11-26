import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatApiError, type ChatApi } from '../src/api';
import { BEFORE_SEND, bindInsertCard } from '../src/slots';
import { SECRET_HANDOFF, SECRET_HANDOFF_PROBE } from '../src/secret-handoff';
import { ThreadView, type ThreadHost } from '../src/thread';
import type { ChatConfig, ChatMessage, ConversationSlots, ConversationSummary } from '../src/types';
import { Templates } from '../src/templates';
import { templates, templatesRoot } from './support';

const i18n: Record<string, string> = { card_secret: '🔑 Shared a secret', quote_wrote: '%name% wrote:', text_copied: 'Copied', error: 'Error', card_not_allowed: 'Not allowed here', composer_actions: 'Actions', composer_cards: 'Cards', card_remove: 'Remove', card_unavailable: 'Gone', sending: 'Sending', retry: 'Retry', failed: 'Failed' };
const summary = (over: Partial<ConversationSummary> = {}): ConversationSummary => ({
  uuid: 'c1', kind: 'direct', title: 'Luis', subtitle: '', initials: 'LU', peerUuid: 'p', channelKey: null, supportStatus: null,
  canSend: true, peerOnline: false, unread: 0, lastMessageAt: null, lastMessage: null, ...over,
});
let nextId = 1;
const msg = (over: Partial<ChatMessage> = {}): ChatMessage => ({
  id: nextId++, uuid: `m${nextId}`, authorUuid: 'p', authorName: 'Luis', authorInitials: 'LU', mine: false, kind: 'text', html: '<p>hola</p>',
  createdAt: '2026-11-26T10:00:00Z', edited: false, deleted: false, moderated: false, ...over,
});
const cardRef = { type: 'vault_item', id: 'v1', title: 'Prod root DB', url: '', subtitle: '', badge: '', tone: 'neutral', card: { state: 'locked' as const, facts: [], actions: [] } };
const slots: ConversationSlots = {
  cardTypes: [{ key: 'vault_item', label: 'Vault item', icon: 'key', secret: true }],
  composerActions: [{ key: 'secret', label: 'One-time secret', icon: 'lock', slash: 'secret', dialogUrl: '/host/secret' }, { key: 'vault', label: 'Vault', icon: 'key', slash: null, dialogUrl: '/host/vault' }],
  menuSlots: [
    { key: 'report', label: 'Report', icon: 'key', target: 'message', cardTypes: [], ownOnly: false, dialogUrl: '/host/report' },
    { key: 'save_vault', label: 'Save to vault', icon: 'key', target: 'selection', cardTypes: [], ownOnly: false, dialogUrl: '/host/save' },
    { key: 'card_info', label: 'Card info', icon: 'key', target: 'card', cardTypes: ['vault_item'], ownOnly: false, dialogUrl: '/host/card' },
  ],
};
const menu = { order: ['reply', 'forward', 'delete'], items: { reply: true, forward: true, delete: true }, quick: [], picker: false, quoteMaxLength: 40, saveDockEntry: false };

function setup(messages: ChatMessage[] = [], slotsAnswer: Promise<ConversationSlots> = Promise.resolve(slots), withSlots = true, bare = false, menuConfig: Record<string, unknown> = menu, extra: Record<string, unknown> = {}) {
  const api = {
    messages: vi.fn().mockResolvedValue({ messages, hasMore: false, readUpTo: null, readers: [] }),
    send: vi.fn().mockImplementation(async (_uuid: string, body: string) => msg({ mine: true, html: `<p>${body}</p>` })),
    typing: vi.fn().mockResolvedValue(undefined),
    members: vi.fn().mockResolvedValue([]),
    pins: vi.fn().mockResolvedValue({ pins: [], canPin: false }),
    slots: vi.fn().mockReturnValue(slotsAnswer),
    card: vi.fn().mockResolvedValue(cardRef),
    uploadAttachment: vi.fn().mockImplementation(async (_c: string, f: File) => ({ uuid: `u-${f.name}`, name: f.name, extension: 'md', mediaType: 'text/markdown', kind: 'markdown', size: f.size, state: 'uploaded' })),
    attachmentDrafts: vi.fn().mockResolvedValue([]),
    attachment: vi.fn().mockResolvedValue({ attachment: {}, url: '/chat/files/a/original?e=1&s=x' }),
    removeAttachment: vi.fn().mockResolvedValue(undefined),
  };
  const root = document.createElement('div');
  root.setAttribute('data-nowo-chat-config', '{}');
  document.body.append(root);
  const dialog = document.createElement('dialog');
  dialog.id = 'slot-dialog';
  root.append(dialog);
  const host: ThreadHost = {
    config: {
      i18n, endpoints: { page: '/chat/__uuid__', forward: '/f/__uuid__/__message__', thread: '/t/__uuid__/__message__' }, userUuid: 'me', locale: 'en', maxLength: 200, editWindowSeconds: 900, reactions: [], isAgent: false,
      prefs: { sound: 'pop', buzz: true, presence: true }, menu: menuConfig,
      ...extra,
      ...(withSlots ? { slots: { endpoint: '/s/__uuid__', card: '/c/__uuid__/__type__/__id__', dialog: 'slot-dialog', dialogRuntime: '/ui/js/nowo-ui-dialog.js' } } : {}),
    } as unknown as ChatConfig,
    api: api as unknown as ChatApi,
    templates: bare ? bareTemplates() : templates(),
    minimise: vi.fn(), close: vi.fn(), saveDraft: vi.fn(), draft: vi.fn().mockReturnValue(''),
    messagesChanged: vi.fn(), read: vi.fn(), isMobile: vi.fn().mockReturnValue(false), conversationChanged: vi.fn(),
    replaceWith: vi.fn(), linkPreview: vi.fn().mockResolvedValue(null),
  };
  const view = new ThreadView(host, summary());
  root.append(view.element);
  const stop = bindInsertCard(root);
  const opened: { src: string | null; title: string | null }[] = [];
  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;
    if (target.hasAttribute('data-nowo-ui-dialog-open')) {
      opened.push({ src: target.getAttribute('data-nowo-ui-dialog-src'), title: target.getAttribute('data-nowo-ui-dialog-title') });
    }
  });
  const input = view.element.querySelector('[data-part="input"]') as HTMLTextAreaElement;
  const submit = () => (view.element.querySelector('[data-part="composer"]') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
  return { view, api, root, dialog, input, submit, opened, stop };
}

/** A host override of the window and message templates without the composer tools, field and slot model item. */
function bareTemplates(): Templates {
  const root = templatesRoot();
  for (const template of root.querySelectorAll<HTMLTemplateElement>('template')) {
    template.content.querySelectorAll('[data-part="composer-tools"], [data-part="menu-slot"][data-slot-key=""]').forEach((node) => node.remove());
    template.content.querySelectorAll('.nowo-chat-composer__field').forEach((node) => node.classList.remove('nowo-chat-composer__field'));
  }
  return new Templates(root);
}

const flush = async () => {
  for (let i = 0; i < 5; i += 1) {
    await Promise.resolve();
  }
};


const attachments = { attachments: { upload: '/u/__uuid__', drafts: '/d/__uuid__', attachment: '/a/__uuid__', maxBytes: 1000, maxPerMessage: 3, extensions: ['md'] } };
const i18nAll: Record<string, string> = { ...i18n, 'attachment.attach': 'Attach', 'attachment.blocked': 'Blocked', 'attachment.failed': 'Failed', 'attachment.download': 'Download', 'secret_handoff.warning': 'Secret?' };
Object.assign(i18n, i18nAll);

describe('ThreadView attachments and the secret hand-off (2.7)', () => {
  beforeEach(() => {
    Element.prototype.scrollTo = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
      cb(0);
      return 1;
    });
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
  });

  it('attaches files, sends when ready and renders them as cards (no forward)', async () => {
    const sent = msg({ mine: true, html: '', attachments: [{ uuid: 'u-a.md', name: 'a.md', extension: 'md', mediaType: 'text/markdown', kind: 'markdown', size: 3, state: 'clean' }] });
    const ctx = setup([], Promise.resolve(slots), false, false, { ...menu, order: ['forward'], items: { forward: true } }, attachments);
    ctx.api.send.mockResolvedValue(sent);
    await ctx.view.load();
    const button = ctx.view.element.querySelector('[data-part="attach-file"]') as HTMLButtonElement;
    expect(button.getAttribute('aria-label')).toBe('Attach');
    const input = ctx.view.element.querySelector('[data-part="attach-input"]') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [new File(['abc'], 'a.md')], configurable: true });
    input.dispatchEvent(new Event('change'));
    await vi.waitFor(() => expect(ctx.api.uploadAttachment).toHaveBeenCalled());
    await flush();
    const sendBtn = ctx.view.element.querySelector('[data-part="send"]') as HTMLButtonElement;
    expect(sendBtn.disabled).toBe(false);
    // The scan ends (realtime): ready, then the empty-text send carries the file.
    ctx.view.attachmentState('u-a.md', 'clean');
    ctx.submit();
    await vi.waitFor(() => expect(ctx.api.send).toHaveBeenCalledWith('c1', '', ['u-a.md']));
    await flush();
    const card = ctx.view.element.querySelector('[data-testid="nowo-chat-files"]') as HTMLElement;
    expect(card.querySelector('.nowo-chat-file__name')?.textContent).toBe('a.md');
    const article = card.closest('[data-id]') as HTMLElement;
    expect((article.querySelector('[data-part="bubble"]') as HTMLElement).hidden).toBe(true);
    expect((article.querySelector('[data-part="menu-forward"]') as HTMLElement).hidden).toBe(true);
    (card.querySelector('.nowo-chat-file__download') as HTMLButtonElement).click();
    await vi.waitFor(() => expect(ctx.api.attachment).toHaveBeenCalledWith('u-a.md'));
  });

  it('refuses to send a blocked file and reports a refused send', async () => {
    const ctx = setup([], Promise.resolve(slots), false, false, menu, attachments);
    await ctx.view.load();
    const input = ctx.view.element.querySelector('[data-part="attach-input"]') as HTMLInputElement;
    Object.defineProperty(input, 'files', { value: [new File(['abc'], 'a.md')], configurable: true });
    input.dispatchEvent(new Event('change'));
    await vi.waitFor(() => expect(ctx.api.uploadAttachment).toHaveBeenCalled());
    await flush();
    ctx.view.attachmentState('u-a.md', 'blocked');
    ctx.submit();
    await flush();
    expect(ctx.api.send).not.toHaveBeenCalled();
    ctx.view.attachmentState('u-a.md', 'clean');
    ctx.api.send.mockRejectedValueOnce(new ChatApiError(409, 'attachment_not_ready'));
    ctx.submit();
    await vi.waitFor(() => expect(ctx.view.element.querySelector('.nowo-chat-msg--pending')?.textContent).toContain('Failed'));
  });

  it('hands a flagged draft to the host only with both opt-ins: cleared, never sent, never stored', async () => {
    const ctx = setup([], Promise.resolve(slots), false, false, menu, { dock: { secretHandoff: true } });
    await ctx.view.load();
    const handed: unknown[] = [];
    ctx.root.addEventListener(SECRET_HANDOFF_PROBE, (event) => event.preventDefault());
    ctx.root.addEventListener(SECRET_HANDOFF, (event) => handed.push((event as CustomEvent).detail));
    ctx.root.addEventListener(BEFORE_SEND, (event) => {
      event.preventDefault();
      (event as CustomEvent<{ warn: (message?: string) => void }>).detail.warn();
    });
    ctx.input.value = 'password: hunter2';
    ctx.input.dispatchEvent(new Event('input', { bubbles: true }));
    ctx.submit();
    await flush();
    const warning = ctx.view.element.querySelector('[data-testid="nowo-chat-secret-warning"]') as HTMLElement;
    expect([...warning.querySelectorAll('button')].map((b) => b.dataset.part)).toEqual(['handoff', 'send', 'cancel']);
    (warning.querySelector('[data-part="handoff"]') as HTMLButtonElement).click();
    expect(handed).toEqual([{ text: 'password: hunter2', conversation: 'c1', kind: 'send' }]);
    expect(ctx.input.value).toBe('');
    expect(ctx.api.send).not.toHaveBeenCalled();
    expect((ctx.view as unknown as { host: ThreadHost }).host.saveDraft).toHaveBeenLastCalledWith('c1', '');
  });

  it('falls back to 2.6 without the opt-in: no hand-off, Send anyway sends', async () => {
    for (const extra of [{}, { dock: { secretHandoff: true } }]) {
      const ctx = setup([], Promise.resolve(slots), false, false, menu, extra);
      await ctx.view.load();
      const details: Record<string, unknown>[] = [];
      ctx.root.addEventListener(BEFORE_SEND, (event) => {
        details.push((event as CustomEvent).detail);
        event.preventDefault();
        (event as CustomEvent<{ warn: () => void }>).detail.warn();
      });
      ctx.input.value = 'token abc';
      ctx.submit();
      await flush();
      expect('handOff' in (details[0] ?? {})).toBe(false);
      const warning = ctx.view.element.querySelector('[data-testid="nowo-chat-secret-warning"]') as HTMLElement;
      expect(warning.querySelector('[data-part="handoff"]')).toBeNull();
      (warning.querySelector('[data-part="send"]') as HTMLButtonElement).click();
      await vi.waitFor(() => expect(ctx.api.send).toHaveBeenCalledWith('c1', 'token abc'));
      document.body.replaceChildren();
    }
  });

  it('hands off from an edit and a thread reply too', async () => {
    const own = msg({ uuid: 'own', mine: true, authorUuid: 'me', html: '<p>old</p>' });
    const full = { ...menu, order: ['thread', 'edit'], items: { thread: true, edit: true }, threads: true };
    const ctx = setup([own], Promise.resolve(slots), false, false, full, { dock: { secretHandoff: true } });
    const edit = vi.fn();
    const threadReply = vi.fn();
    Object.assign(ctx.api, { edit, threadReply, thread: vi.fn().mockResolvedValue({ root: own, replies: [], hasMore: false, replyCount: 0, readUpTo: null, following: true }), threadRead: vi.fn().mockResolvedValue(undefined) });
    await ctx.view.load();
    await flush();
    const handed: { kind: string; text: string }[] = [];
    ctx.root.addEventListener(SECRET_HANDOFF_PROBE, (event) => event.preventDefault());
    ctx.root.addEventListener(SECRET_HANDOFF, (event) => handed.push((event as CustomEvent).detail));
    ctx.root.addEventListener(BEFORE_SEND, (event) => {
      event.preventDefault();
      (event as CustomEvent<{ handOff: () => void }>).detail.handOff();
    });
    const article = ctx.view.element.querySelector(`[data-id="${own.id}"]`) as HTMLElement;
    (article.querySelector('[data-part="menu-edit"]') as HTMLElement).click();
    const editor = ctx.view.element.querySelector('form [data-part="input"]') as HTMLTextAreaElement;
    editor.value = 'password hunter2';
    editor.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    await flush();
    expect(edit).not.toHaveBeenCalled();
    // The editor closed without saving: the message reads as before.
    expect(ctx.view.element.querySelector(`[data-id="${own.id}"] [data-part="bubble"]`)?.textContent).toBe('old');
    const current = ctx.view.element.querySelector(`[data-id="${own.id}"]`) as HTMLElement;
    (current.querySelector('[data-part="menu-thread"]') as HTMLElement).click();
    await vi.waitFor(() => expect(document.querySelector('[data-part="thread-input"]')).not.toBeNull());
    const reply = document.querySelector('[data-part="thread-input"]') as HTMLTextAreaElement;
    reply.value = 'token abc';
    (document.querySelector('[data-part="thread-send"]') as HTMLButtonElement).click();
    await flush();
    expect(threadReply).not.toHaveBeenCalled();
    expect(reply.value).toBe('');
    expect(handed.map((detail) => [detail.kind, detail.text])).toEqual([['edit', 'password hunter2'], ['thread', 'token abc']]);
  });
});
