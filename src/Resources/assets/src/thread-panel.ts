/**
 * 1.36: the thread panel of a message ("Reply in thread", or the "N replies" button under a root) — the root, its
 * replies oldest first ("Earlier replies" loads older ones), and a composer whose replies go through the send pipeline
 * on the server. A native modal <dialog>: a side panel on wide screens, a full-height sheet on phones (CSS); focus is
 * trapped, Escape closes it and focus returns to the message. Bodies are the server's sanitised render; everything
 * else is text (`textContent`), no inline style (CSP). A reply keeps its `Idempotency-Key` until it is sent, so a
 * retry after a lost answer posts it once.
 */
import { ChatApiError } from './api';
import { h } from './dom';
import { exactTimeLabel, t, timeLabel } from './format';
import { presentDialog, closeDialog } from './message-info';
import { stripReservedAttributes } from './message-menu';
import { newIdempotencyKey } from './forward-dialog';
import type { ChatMessage, ThreadPage } from './types';

/** What the panel needs from the API client. */
export interface ThreadApi {
  thread(uuid: string, messageUuid: string, cursor?: { before?: number; after?: number }): Promise<ThreadPage>;
  threadReply(uuid: string, messageUuid: string, body: string, key: string): Promise<{ message: ChatMessage; root: ChatMessage }>;
  threadRead(uuid: string, messageUuid: string, messageId: number): Promise<void>;
}

/** Options of a panel. */
export interface ThreadPanelOptions {
  api: ThreadApi;
  i18n: Record<string, string>;
  locale: string;
  conversationUuid: string;
  /** The root (or a reply: its root's thread opens). */
  message: ChatMessage;
  /** The viewer may write in the conversation. */
  canSend: boolean;
  /** Longest reply (characters). */
  maxLength: number;
  /** Element focused again when the panel closes. */
  returnTo: HTMLElement | null;
  /** The root changed (its reply count): the timeline repaints it. */
  rootChanged?: (root: ChatMessage) => void;
  /** The panel closed. */
  closed?: () => void;
  /** 2.6 (audit ZCI-L4): asks the host before a reply (`nowo-chat:before-send`); true when the host cancelled it. */
  beforeSend?: (text: string, sendAnyway: () => void) => boolean;
}

/** The open thread panel of a conversation window. */
export class ThreadPanel {
  readonly dialog: HTMLDialogElement;
  rootUuid: string;
  private replies: ChatMessage[] = [];
  private readUpTo = 0;
  private readonly log: HTMLOListElement;
  private readonly rootHolder: HTMLElement;
  private readonly older: HTMLButtonElement;
  private readonly input: HTMLTextAreaElement;
  private readonly send: HTMLButtonElement;
  private readonly status: HTMLElement;
  private key: string | null = null;
  private keyText = '';

  /**
   * Builds the panel, opens it and loads the thread.
   *
   * @param options What the panel shows and does.
   */
  constructor(private readonly options: ThreadPanelOptions) {
    const { i18n } = options;
    this.rootUuid = options.message.threadRootUuid ?? options.message.uuid;
    const id = `nowo-chat-thread-${Date.now()}`;
    const title = h('h2', { class: 'nowo-chat-thread-panel__title', id: `${id}-title`, text: t(i18n, 'thread_title') });
    const close = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-thread-panel__close', 'data-part': 'thread-close', 'aria-label': t(i18n, 'close'), text: '×' });
    this.rootHolder = h('div', { class: 'nowo-chat-thread-panel__root', 'data-part': 'thread-root' });
    this.older = h('button', { type: 'button', class: 'nowo-chat-thread-panel__older', 'data-part': 'thread-older', text: t(i18n, 'thread_older'), hidden: true });
    this.log = h('ol', { class: 'nowo-chat-thread-panel__replies', 'data-part': 'thread-replies', 'aria-label': t(i18n, 'thread_replies') });
    this.status = h('p', { class: 'nowo-chat-thread-panel__status', role: 'status', 'data-part': 'thread-status' });
    this.input = h('textarea', { class: 'nowo-chat-thread-panel__input', id: `${id}-input`, rows: 2, maxlength: options.maxLength, 'data-part': 'thread-input', 'aria-label': t(i18n, 'thread_reply_label') });
    this.send = h('button', { type: 'button', class: 'nowo-chat-thread-panel__send', 'data-part': 'thread-send', text: t(i18n, 'send') });
    const composer = h('div', { class: 'nowo-chat-thread-panel__composer', hidden: !options.canSend }, this.input, this.send);
    this.dialog = h(
      'dialog',
      { class: 'nowo-chat-thread-panel', 'aria-labelledby': title.id, 'data-part': 'thread-panel' },
      h('header', { class: 'nowo-chat-thread-panel__header' }, title, close),
      h('div', { class: 'nowo-chat-thread-panel__body' }, this.rootHolder, this.older, this.log),
      this.status,
      composer,
    );
    this.older.addEventListener('click', () => void this.load(this.replies[0]?.id));
    this.send.addEventListener('click', () => void this.submit());
    this.input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        void this.submit();
      }
    });
    this.dialog.addEventListener('close', () => options.closed?.());
    this.paintRoot(options.message.threadRootUuid === undefined ? options.message : null);
    presentDialog(this.dialog, options.canSend ? this.input : close, options.returnTo);
    void this.load();
  }

  /**
   * Loads the newest page of the thread, or the page before a reply.
   *
   * @param before Older replies than this id; undefined for the newest page.
   */
  async load(before?: number): Promise<void> {
    const { api, conversationUuid, i18n } = this.options;
    try {
      const page = await api.thread(conversationUuid, this.rootUuid, before === undefined ? {} : { before });
      this.paintRoot(page.root);
      this.readUpTo = Math.max(this.readUpTo, page.readUpTo ?? 0);
      this.merge(page.replies);
      this.older.hidden = !page.hasMore;
      this.markRead();
    } catch {
      this.status.textContent = t(i18n, 'error');
    }
  }

  /**
   * A reply arrived or changed (realtime, or this panel's own): it is shown in place and read.
   *
   * @param reply The reply (other threads' replies are ignored).
   */
  receive(reply: ChatMessage): void {
    if (reply.threadRootUuid !== this.rootUuid) {
      return;
    }
    this.merge([reply]);
    this.markRead();
  }

  /**
   * The root changed (edit, reactions, count).
   *
   * @param root The root.
   */
  updateRoot(root: ChatMessage): void {
    if (root.uuid === this.rootUuid) {
      this.paintRoot(root);
    }
  }

  /** Closes the panel. */
  close(): void {
    closeDialog(this.dialog);
  }

  private merge(incoming: ChatMessage[]): void {
    const known = new Map(this.replies.map((reply) => [reply.id, reply]));
    for (const reply of incoming) {
      known.set(reply.id, reply);
    }
    this.replies = [...known.values()].sort((a, b) => a.id - b.id);
    this.log.replaceChildren(...this.replies.map((reply) => this.renderReply(reply)));
    this.log.lastElementChild?.scrollIntoView?.({ block: 'nearest' });
  }

  private paintRoot(root: ChatMessage | null): void {
    if (root === null) {
      return;
    }
    this.rootHolder.replaceChildren(this.renderReply(root, 'div'));
    const count = root.thread?.count ?? 0;
    this.rootHolder.append(h('p', { class: 'nowo-chat-thread-panel__count', 'data-part': 'thread-count', text: t(this.options.i18n, count === 1 ? 'thread_one_reply' : 'thread_replies_count', { count }) }));
  }

  private renderReply(message: ChatMessage, tag: 'li' | 'div' = 'li'): HTMLElement {
    const { i18n, locale } = this.options;
    const bubble = h('div', { class: 'nowo-chat-bubble', 'data-part': 'bubble' });
    if (message.deleted) {
      bubble.classList.add('nowo-chat-bubble--deleted');
      bubble.textContent = t(i18n, message.mine ? 'deleted_message_mine' : 'deleted_message');
    } else {
      // Server-sanitised Markdown render (ChatMarkdownRenderer): escape-first, closed tag set.
      // eslint-disable-next-line no-restricted-syntax -- sanitised on the server, see ChatMarkdownRenderer
      bubble.innerHTML = message.html;
      stripReservedAttributes(bubble);
    }
    const time = h('time', { datetime: message.createdAt, title: exactTimeLabel(message.createdAt, locale), text: timeLabel(message.createdAt, locale) });
    return h(
      tag,
      { class: `nowo-chat-thread-panel__reply${message.mine ? ' is-mine' : ''}`, 'data-id': message.id, 'data-part': 'thread-reply' },
      h('p', { class: 'nowo-chat-thread-panel__author' }, h('span', { text: message.authorName }), ' · ', time, message.edited && !message.deleted ? ` · ${t(i18n, 'edited')}` : ''),
      bubble,
    );
  }

  private markRead(): void {
    const newest = this.replies.at(-1)?.id ?? 0;
    if (newest > this.readUpTo) {
      this.readUpTo = newest;
      void this.options.api.threadRead(this.options.conversationUuid, this.rootUuid, newest).catch(() => undefined);
    }
  }

  private async submit(force = false): Promise<void> {
    const { api, conversationUuid, i18n } = this.options;
    const body = this.input.value.trim();
    if (body === '' || this.send.disabled) {
      return;
    }
    // 2.6 (audit ZCI-L4): the host may warn first; sendAnyway() sends the text as it is now.
    if (!force && this.options.beforeSend?.(body, () => void this.submit(true)) === true) {
      return;
    }
    // The same text keeps its key: a retry after a lost answer is one reply.
    if (this.key === null || this.keyText !== body) {
      this.key = newIdempotencyKey();
      this.keyText = body;
    }
    this.send.disabled = true;
    this.status.textContent = '';
    try {
      const { message, root } = await api.threadReply(conversationUuid, this.rootUuid, body, this.key);
      this.key = null;
      this.input.value = '';
      this.receive({ ...message, mine: true });
      this.paintRoot(root);
      this.options.rootChanged?.(root);
    } catch (error) {
      this.status.textContent = t(i18n, error instanceof ChatApiError && ['too_long', 'rate_limited', 'read_only'].includes(error.code) ? error.code : 'error');
    } finally {
      this.send.disabled = false;
      this.input.focus({ preventScroll: true });
    }
  }
}
