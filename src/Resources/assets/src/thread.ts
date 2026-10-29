/**
 * One conversation window (template `window`): header, thread and composer. Sending and receiving never leave the
 * page: optimistic append, server confirmation, retry on failure, and the text is never lost. Own messages can be
 * edited or deleted during the edit window; everyone who can write can react.
 */
import { ChatApiError, type ChatApi } from './api';
import { h, prefersReducedMotion, setBadge } from './dom';
import { emojiPicker, insertAtCaret } from './emoji';
import { dayLabel, isNewDay, paintMessageTime, startsGroup, t, timeLabel } from './format';
import { decodeMentions, markOwnMentions, MentionAutocomplete, mentionsViewer } from './mention';
import { decodeReferences, ReferenceAutocomplete } from './reference';
import { SupportPanel } from './support';
import { part, text, type Templates } from './templates';
import type { ChatConfig, ChatMessage, ConversationSummary, LinkPreview, PendingMessage, Reader } from './types';

/** What a conversation window needs from the dock that hosts it (ChatDock). */
export interface ThreadHost {
  config: ChatConfig;
  api: ChatApi;
  templates: Templates;
  /**
   * Minimise or expand a window.
   *
   * @param uuid Conversation uuid.
   * @param minimised True to minimise.
   */
  minimise(uuid: string, minimised: boolean): void;
  /**
   * Close a window.
   *
   * @param uuid Conversation uuid.
   */
  close(uuid: string): void;
  /**
   * Store the composer draft.
   *
   * @param uuid Conversation uuid.
   * @param text Composer text.
   */
  saveDraft(uuid: string, text: string): void;
  /**
   * Stored composer draft.
   *
   * @param uuid Conversation uuid.
   * @returns The draft ("" when none).
   */
  draft(uuid: string): string;
  /**
   * A message arrived or was sent here: keep the list row, other tabs and the cache in step.
   *
   * @param uuid Conversation uuid.
   * @param messages Every message of the thread, oldest first.
   * @param hasMore Whether older messages exist on the server.
   * @param fresh Messages that were not there before.
   */
  messagesChanged(uuid: string, messages: ChatMessage[], hasMore: boolean, fresh: ChatMessage[]): void;
  /**
   * The user has seen the thread up to a message.
   *
   * @param uuid Conversation uuid.
   * @param messageId Newest message id seen.
   */
  read(uuid: string, messageId: number): void;
  /**
   * Whether the phone layout applies.
   *
   * @returns True on phones.
   */
  isMobile(): boolean;
  /**
   * An agent resolved a support thread: refresh its summary everywhere.
   *
   * @param summary Updated summary.
   */
  conversationChanged(summary: ConversationSummary): void;
  /**
   * Show another conversation instead of this window (an earlier support ticket the requester continues or opens).
   *
   * @param from Uuid of the window to replace.
   * @param summary Conversation to open.
   */
  replaceWith(from: string, summary: ConversationSummary): void;
  /**
   * Card for a link, as the viewer may see it (null: none).
   *
   * @param url Link found in a message.
   * @returns The preview, or null.
   */
  linkPreview(url: string): Promise<LinkPreview | null>;
}

const TYPING_SEND_EVERY_MS = 3_000;
const TYPING_SHOW_MS = 5_000;
const NEAR_BOTTOM_PX = 48;

/** One conversation window: header, thread (messages, receipts, reactions, previews) and composer. */
export class ThreadView {
  readonly element: HTMLElement;
  private summary: ConversationSummary;
  private messages: ChatMessage[] = [];
  private pending: PendingMessage[] = [];
  private hasMore = false;
  private loaded = false;
  private loadingOlder = false;
  private minimised = false;
  private minimisedApplied = false;
  private readUpTo = 0;
  /** Id of the first message that arrived while the user was scrolled up ("New messages" divider). */
  private firstUnseenId: number | null = null;
  private unseenBelow = 0;
  private readers = new Map<string, Reader>();
  private typists = new Map<string, { name: string; timer: number }>();
  private lastTypingSent = 0;
  private emojiPanel: HTMLElement | null = null;
  /** Outside-click listener of the open popover (emoji or reactions), removed on every close. */
  private popoverOutside: ((event: MouseEvent) => void) | null = null;
  private reactionPanel: HTMLElement | null = null;
  /** Message being edited (its bubble shows the inline editor). */
  private editing: number | null = null;
  /** Newest message id painted so far: only lines newer than it get an entry animation. */
  private paintedUpTo = 0;
  private counterBand: string | null = null;

  private readonly identity: HTMLButtonElement;
  private readonly badge: HTMLElement;
  private readonly minimiseBtn: HTMLButtonElement;
  private readonly body: HTMLElement;
  private readonly log: HTMLElement;
  private readonly jumpBtn: HTMLButtonElement;
  private readonly live: HTMLElement;
  private readonly composer: HTMLFormElement;
  private readonly input: HTMLTextAreaElement;
  private readonly sendBtn: HTMLButtonElement;
  private readonly counter: HTMLElement;
  private readonly counterAnnounce: HTMLElement;
  private readonly notice: HTMLElement;
  private readonly typingEl: HTMLElement;
  private readonly buzzBtn: HTMLButtonElement;
  private readonly resolveBtn: HTMLButtonElement;
  private readonly emojiBtn: HTMLButtonElement;
  private readonly mention: MentionAutocomplete;
  private readonly reference: ReferenceAutocomplete | null;
  private readonly support: SupportPanel;

  private readonly host: ThreadHost;

  /**
   * Builds the window from the `window` template, restores the draft and binds its controls.
   *
   * @param host Dock hosting the window.
   * @param summary Conversation shown.
   */
  constructor(host: ThreadHost, summary: ConversationSummary) {
    this.host = host;
    this.summary = summary;
    const i18n = host.config.i18n;

    this.element = host.templates.clone('window');
    this.element.dataset.uuid = summary.uuid;
    part<HTMLAnchorElement>(this.element, 'popout').href = host.config.endpoints.page.replace('__uuid__', encodeURIComponent(summary.uuid));
    this.identity = part<HTMLButtonElement>(this.element, 'identity');
    this.badge = part(this.element, 'badge');
    this.minimiseBtn = part<HTMLButtonElement>(this.element, 'minimise');
    this.buzzBtn = part<HTMLButtonElement>(this.element, 'buzz');
    this.resolveBtn = part<HTMLButtonElement>(this.element, 'resolve');
    this.body = part(this.element, 'body');
    this.log = part(this.element, 'log');
    this.jumpBtn = part<HTMLButtonElement>(this.element, 'jump');
    this.live = part(this.element, 'live');
    this.notice = part(this.element, 'notice');
    this.typingEl = part(this.element, 'typing');
    this.composer = part<HTMLFormElement>(this.element, 'composer');
    this.input = part<HTMLTextAreaElement>(this.element, 'input');
    this.input.maxLength = host.config.maxLength;
    this.sendBtn = part<HTMLButtonElement>(this.element, 'send');
    this.counter = part(this.element, 'counter');
    this.counterAnnounce = part(this.element, 'counter-announce');
    this.emojiBtn = part<HTMLButtonElement>(this.element, 'emoji-toggle');

    this.identity.addEventListener('click', () => this.host.minimise(this.summary.uuid, !this.minimised));
    this.minimiseBtn.addEventListener('click', () => this.host.minimise(this.summary.uuid, !this.minimised));
    part(this.element, 'close').addEventListener('click', () => this.host.close(this.summary.uuid));
    part(this.element, 'back').addEventListener('click', () => this.host.close(this.summary.uuid));
    this.element.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        this.host.minimise(this.summary.uuid, true);
        // The composer is hidden now: keep focus on the visible header button.
        this.identity.focus({ preventScroll: true });
      }
    });
    this.body.addEventListener('scroll', () => this.onScroll(), { passive: true });
    this.jumpBtn.addEventListener('click', () => this.scrollToBottom(true));
    this.composer.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit();
    });
    // Before the composer's own keydown: Enter / Escape pick or close the @mention list first.
    this.mention = new MentionAutocomplete(this.input, {
      load: (query) => this.host.api.members(this.summary.uuid, query),
      i18n,
      idPrefix: `nowo-chat-${summary.uuid}`,
    });
    // `/` commands and the `#` picker, when the application registers reference providers.
    this.reference = this.referencePicker(this.input, `nowo-chat-${summary.uuid}`);
    this.input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void this.submit();
      }
    });
    let draftTimer: number | undefined;
    this.input.addEventListener('input', () => {
      this.autoGrow();
      this.updateComposerState();
      // Drafts are stored at most twice a second (each save is a storage write).
      window.clearTimeout(draftTimer);
      draftTimer = window.setTimeout(() => this.host.saveDraft(this.summary.uuid, this.encodeComposer()), 500);
      this.pingTyping();
    });
    this.buzzBtn.addEventListener('click', () => void this.sendBuzz());
    this.resolveBtn.addEventListener('click', () => {
      this.resolveBtn.disabled = true;
      void this.host.api
        .resolve(this.summary.uuid)
        .then((resolved) => this.host.conversationChanged(resolved))
        .catch(() => this.flashNotice(t(i18n, 'error')))
        .finally(() => {
          this.resolveBtn.disabled = false;
        });
    });
    this.emojiBtn.addEventListener('click', () => this.toggleEmoji());
    this.support = new SupportPanel(
      {
        config: host.config,
        api: host.api,
        conversationChanged: (changed) => host.conversationChanged(changed),
        openConversation: (other) => host.replaceWith(this.summary.uuid, other),
        appendMessages: (messages) => this.merge(messages, false),
        notice: (message) => this.flashNotice(message),
      },
      part(this.element, 'support'),
    );

    const draft = host.draft(summary.uuid);
    this.mention.load(this.reference?.decode(draft) ?? draft);
    this.applySummary(summary);
  }

  /**
   * Uuid of the conversation shown.
   *
   * @returns The conversation uuid.
   */
  get uuid(): string {
    return this.summary.uuid;
  }

  /**
   * Whether the window is minimised.
   *
   * @returns True when minimised.
   */
  get isMinimised(): boolean {
    return this.minimised;
  }

  /**
   * Id of the newest message loaded.
   *
   * @returns The id, or 0 without messages.
   */
  lastId(): number {
    return this.messages.at(-1)?.id ?? 0;
  }

  /**
   * Applies a conversation summary: header (title, subtitle, avatar, context), buttons, unread badge and the
   * read-only state of the composer.
   *
   * @param summary Current summary.
   */
  applySummary(summary: ConversationSummary): void {
    this.summary = summary;
    const i18n = this.host.config.i18n;
    part(this.element, 'title').textContent = summary.title;
    part(this.element, 'subtitle').textContent =
      summary.kind === 'support' && summary.supportStatus === 'resolved'
        ? t(i18n, 'support_resolved')
        : summary.kind === 'direct' && summary.peerOnline
          ? t(i18n, 'online')
          : summary.subtitle;
    this.element.setAttribute('aria-label', t(i18n, 'conversation_label', { name: summary.title }));
    part(this.element, 'avatar').replaceChildren(this.host.templates.avatar(summary.initials, summary.kind, 'md', summary.peerOnline));
    this.applyContext(summary);
    // A buzz is a person-to-person nudge: direct conversations only.
    this.buzzBtn.hidden = !summary.canSend || summary.kind !== 'direct';
    this.resolveBtn.hidden = !(this.host.config.isAgent && summary.kind === 'support' && (summary.supportStatus === 'open' || summary.supportStatus === 'waiting'));
    this.setUnread(this.minimised ? summary.unread : 0);
    this.input.disabled = !summary.canSend;
    this.notice.hidden = summary.canSend;
    this.notice.textContent = summary.canSend ? '' : t(i18n, 'read_only');
    this.composer.hidden = !summary.canSend;
    this.updateComposerState();
    this.support.render(summary);
  }

  /**
   * Support threads: "Reported from …" chip, a link when the page is known.
   *
   * @param summary Current summary.
   */
  private applyContext(summary: ConversationSummary): void {
    const chip = part(this.element, 'context');
    const context = summary.kind === 'support' ? summary.supportContext ?? null : null;
    const label = context?.label ?? context?.url ?? '';
    chip.hidden = label === '';
    part(chip, 'context-label').textContent = label;
    const link = chip instanceof HTMLAnchorElement ? chip : null;
    if (link !== null) {
      if (context?.url !== undefined) {
        link.href = context.url;
      } else {
        link.removeAttribute('href');
      }
    }
  }

  /**
   * Shows the window minimised or expanded; expanding clears the badge, scrolls to the latest and marks it read.
   * Applying the same state again does nothing.
   *
   * @param minimised True to minimise.
   */
  setMinimised(minimised: boolean): void {
    if (this.minimisedApplied && this.minimised === minimised) {
      // Same state (another tab synced the layout): never move the reader's scroll position.
      return;
    }
    this.minimisedApplied = true;
    this.minimised = minimised;
    this.identity.setAttribute('aria-expanded', minimised ? 'false' : 'true');
    this.element.classList.toggle('is-minimised', minimised);
    const label = t(this.host.config.i18n, minimised ? 'expand' : 'minimise');
    this.minimiseBtn.setAttribute('aria-label', label);
    this.minimiseBtn.title = label;
    this.minimiseBtn.replaceChildren(this.host.templates.icon(minimised ? 'chevronUp' : 'chevronDown'));
    if (!minimised) {
      this.setUnread(0);
      this.autoGrow();
      requestAnimationFrame(() => this.scrollToBottom(false));
      this.maybeMarkRead();
    }
  }

  /** Called once the window is in the document: a detached element cannot scroll, so land on the latest here. */
  scrollToLatest(): void {
    this.scrollToBottom(false);
    this.maybeMarkRead();
  }

  /** Moves focus to the header button (keyboard navigation between windows). */
  focusHeader(): void {
    this.identity.focus({ preventScroll: true });
  }

  /** Called when the tab becomes visible again: read what arrived meanwhile, if the user can see it. */
  markVisibleRead(): void {
    this.maybeMarkRead();
  }

  /** Moves focus to the composer, unless the thread is read-only. */
  focusComposer(): void {
    if (!this.input.disabled) {
      this.input.focus({ preventScroll: true });
    }
  }

  /**
   * First paint from the per-tab cache (instant after navigation), then the server catches up.
   *
   * @param cached Cached messages, or null (shows the loading state).
   */
  hydrate(cached: { messages: ChatMessage[]; hasMore: boolean } | null): void {
    if (cached !== null && cached.messages.length > 0) {
      this.messages = cached.messages;
      this.hasMore = cached.hasMore;
      this.render();
      this.scrollToBottom(false);
    } else {
      this.logState('loading');
    }
  }

  /** Loads the newest page from the server (or catches up after a cache paint); failures show the error state. */
  async load(): Promise<void> {
    try {
      if (this.messages.length > 0) {
        await this.catchUp();
        this.loaded = true;
        return;
      }
      const page = await this.host.api.messages(this.summary.uuid);
      this.messages = page.messages;
      this.hasMore = page.hasMore;
      this.readUpTo = page.readUpTo ?? 0;
      this.setReaders(page.readers);
      this.loaded = true;
      this.render();
      this.scrollToBottom(false);
      this.host.messagesChanged(this.summary.uuid, this.messages, this.hasMore, []);
      this.maybeMarkRead();
    } catch {
      this.logState('error');
    }
  }

  /** Drops the messages shown and loads the newest page again (messages moved in from elsewhere). */
  async reload(): Promise<void> {
    this.messages = [];
    this.hasMore = false;
    this.loaded = false;
    await this.load();
  }

  /** Poll / reconnect: fetch only what arrived after the newest message we have. */
  async catchUp(): Promise<void> {
    if (!this.loaded && this.messages.length === 0) {
      return;
    }
    const page = await this.host.api.messages(this.summary.uuid, { after: this.lastId() });
    if (page.readUpTo !== null) {
      this.readUpTo = Math.max(this.readUpTo, page.readUpTo);
    }
    this.setReaders(page.readers);
    this.merge(page.messages, true);
  }

  /**
   * Messages from another browser tab, a poll or our own send.
   *
   * @param incoming Messages to add or replace (by id).
   * @param notify Whether to report the new ones to the host (false when they came from another tab).
   */
  merge(incoming: ChatMessage[], notify: boolean): void {
    const known = new Map(this.messages.map((message) => [message.id, message]));
    const fresh: ChatMessage[] = [];
    for (const message of incoming) {
      if (!known.has(message.id)) {
        fresh.push(message);
      }
      known.set(message.id, message);
    }
    if (incoming.length === 0) {
      return;
    }
    const wasAtBottom = this.isNearBottom();
    const previousLast = this.messages.at(-1);
    const onlyAppended = incoming.every((message) => fresh.includes(message));
    this.messages = [...known.values()].sort((a, b) => a.id - b.id);
    const fromOthers = fresh.filter((message) => !message.mine);
    for (const message of fromOthers) {
      const typist = message.authorUuid !== null ? this.typists.get(message.authorUuid) : undefined;
      if (typist !== undefined && message.authorUuid !== null) {
        window.clearTimeout(typist.timer);
        this.typists.delete(message.authorUuid);
        this.renderTyping();
      }
    }
    const dividerBefore = this.firstUnseenId;
    if (fromOthers.length > 0 && !wasAtBottom && !this.minimised) {
      this.firstUnseenId ??= (fromOthers[0] as ChatMessage).id;
      this.unseenBelow += fromOthers.length;
    }
    // A new "New messages" divider for an already painted id needs a full render; otherwise append.
    const dividerOk = dividerBefore === this.firstUnseenId || fresh.some((message) => message.id === this.firstUnseenId);
    if (!(onlyAppended && dividerOk && this.appendFresh(fresh, previousLast))) {
      this.render();
    }
    if (fresh.some((message) => message.mine)) {
      // Own message: land on it immediately (no half-finished smooth scroll).
      this.scrollToBottom(false);
    } else if (wasAtBottom) {
      this.scrollToBottom(true);
    }
    this.updateJump();
    if (notify && fresh.length > 0) {
      this.host.messagesChanged(this.summary.uuid, this.messages, this.hasMore, fresh);
    }
    const last = fromOthers.at(-1);
    if (last !== undefined && !this.minimised) {
      this.live.textContent = `${last.authorName}: ${plain(last.html)}`;
    }
    this.maybeMarkRead();
  }

  /**
   * Edit, delete or reaction change (here, in another tab or by someone else): replace the line in place.
   *
   * @param message Updated message (unknown ids are ignored; `mine` is kept from the loaded one).
   */
  update(message: ChatMessage): void {
    const index = this.messages.findIndex((item) => item.id === message.id);
    if (index < 0) {
      return;
    }
    const merged = { ...message, mine: (this.messages[index] as ChatMessage).mine };
    this.messages[index] = merged;
    if (this.editing === message.id && message.deleted) {
      this.editing = null;
    }
    const node = this.log.querySelector<HTMLElement>(`[data-id="${message.id}"]`);
    if (node !== null && this.editing !== message.id) {
      node.replaceWith(this.renderMessage(merged, node.classList.contains('nowo-chat-msg--group-start')));
    }
    this.host.messagesChanged(this.summary.uuid, this.messages, this.hasMore, []);
  }

  /**
   * The thread was read elsewhere (another tab or device): move the read cursor and clear the badge.
   *
   * @param messageId Newest message id read.
   */
  markReadLocally(messageId: number): void {
    this.readUpTo = Math.max(this.readUpTo, messageId);
    this.setUnread(0);
  }

  /**
   * Realtime: another participant moved their read cursor.
   *
   * @param userUuid Reader uuid.
   * @param name Reader display name.
   * @param messageId Newest message id they have read (older cursors are ignored).
   */
  readBy(userUuid: string, name: string, messageId: number): void {
    const current = this.readers.get(userUuid);
    if (current === undefined || current.messageId < messageId) {
      this.readers.set(userUuid, { userUuid, name, messageId });
      this.patchReceipts();
    }
  }

  /**
   * Realtime: someone is typing (hidden after 5 s without a new ping, or when their message arrives).
   *
   * @param userUuid Typist uuid.
   * @param name Typist display name.
   */
  typingBy(userUuid: string, name: string): void {
    const previous = this.typists.get(userUuid);
    if (previous !== undefined) {
      window.clearTimeout(previous.timer);
    }
    const timer = window.setTimeout(() => {
      this.typists.delete(userUuid);
      this.renderTyping();
    }, TYPING_SHOW_MS);
    this.typists.set(userUuid, { name, timer });
    this.renderTyping();
  }

  /**
   * "Ana is typing", "Ana and Luis are typing" or "Several people are typing".
   *
   * @returns The label, or null when nobody is typing.
   */
  typingLabel(): string | null {
    const names = [...this.typists.values()].map((typist) => typist.name);
    const i18n = this.host.config.i18n;
    if (names.length === 0) {
      return null;
    }
    if (names.length === 1) {
      return t(i18n, 'typing_one', { name: names[0] as string });
    }
    if (names.length === 2) {
      return t(i18n, 'typing_two', { first: names[0] as string, second: names[1] as string });
    }
    return t(i18n, 'typing_many');
  }

  /**
   * Received buzz: a short, soft shake (≈4 px, 450 ms), a warm halo that fades out and a pulse on the header bolt.
   * Web Animations API, so it never fights the CSS entry animation. Reduced motion: halo only.
   */
  shake(): void {
    const reduced = prefersReducedMotion();
    this.element.dataset.buzzedAt = String(Date.now());
    if (!reduced && typeof this.element.animate === 'function') {
      this.element.animate(
        [
          { transform: 'translateX(0)' },
          { transform: 'translateX(-4px) rotate(-0.4deg)' },
          { transform: 'translateX(4px) rotate(0.4deg)' },
          { transform: 'translateX(-3px)' },
          { transform: 'translateX(2px)' },
          { transform: 'translateX(0)' },
        ],
        { duration: 450, easing: 'cubic-bezier(0.36, 0.07, 0.19, 0.97)' },
      );
    }
    this.halo(reduced ? 1600 : 1200);
    this.pulse(this.buzzBtn, reduced);
  }

  /** Receipts and "Seen by" only — no full thread rebuild for a read cursor (big channels send many). */
  private patchReceipts(): void {
    this.log.querySelectorAll<HTMLElement>('[data-part="receipt"]').forEach((receipt) => {
      this.paintReceipt(receipt, Number(receipt.dataset.id));
    });
    const seen = this.seenLine();
    const existing = this.log.querySelector('.nowo-chat-seen');
    if (seen === null) {
      existing?.remove();
    } else if (existing !== null) {
      existing.replaceWith(seen);
    } else {
      this.log.insertBefore(seen, this.log.querySelector('.nowo-chat-msg--pending'));
    }
  }

  /**
   * Warm halo around the window (keeps its own box shadow); skipped without Web Animations.
   *
   * @param duration Animation length in milliseconds.
   */
  private halo(duration: number): void {
    if (typeof this.element.animate !== 'function') {
      return;
    }
    // Literal colours: var() inside Web Animations keyframes is not resolved by every engine.
    const style = window.getComputedStyle(this.element);
    const warn = style.getPropertyValue('--nowo-chat-warn').trim() || '#e0a100';
    const base = style.boxShadow === '' || style.boxShadow === 'none' ? '' : `, ${style.boxShadow}`;
    this.element.animate(
      [
        { boxShadow: `0 0 0 0 transparent${base}` },
        { boxShadow: `0 0 0 4px color-mix(in srgb, ${warn} 55%, transparent)${base}`, offset: 0.2 },
        { boxShadow: `0 0 0 8px transparent${base}` },
      ],
      { duration, easing: 'ease-out' },
    );
  }

  /**
   * Pulse on the buzz bolt; skipped without Web Animations.
   *
   * @param element Element to animate.
   * @param reduced Reduced motion: an opacity fade instead of the scale / rotate.
   */
  private pulse(element: HTMLElement, reduced: boolean): void {
    if (typeof element.animate !== 'function') {
      return;
    }
    element.animate(
      reduced
        ? [{ opacity: 0.4 }, { opacity: 1 }]
        : [{ transform: 'scale(1) rotate(0)' }, { transform: 'scale(1.25) rotate(-12deg)', offset: 0.35 }, { transform: 'scale(1) rotate(0)' }],
      { duration: 500, easing: 'ease-out' },
    );
  }

  /**
   * Replaces the read cursors of the other participants.
   *
   * @param readers Read cursors from the server.
   */
  private setReaders(readers: Reader[]): void {
    this.readers = new Map(readers.map((reader) => [reader.userUuid, reader]));
  }

  /** Updates the "typing…" line (the live region only changes when its text does). */
  private renderTyping(): void {
    const label = this.typingLabel() ?? '';
    // Rewriting the same text would make the live region repeat it on every ping.
    if (this.typingEl.textContent !== label) {
      this.typingEl.textContent = label;
    }
    this.typingEl.hidden = label === '';
  }

  /** Tells the server the user is typing, at most every 3 s and only with text in the composer. */
  private pingTyping(): void {
    const now = Date.now();
    if (this.input.value.trim() === '' || now - this.lastTypingSent < TYPING_SEND_EVERY_MS) {
      return;
    }
    this.lastTypingSent = now;
    void this.host.api.typing(this.summary.uuid).catch(() => undefined);
  }

  /** Sends a buzz; "too soon" shows a notice; the button stays disabled for 1.5 s. */
  private async sendBuzz(): Promise<void> {
    this.buzzBtn.disabled = true;
    // The bolt reacts at once so the sender knows it left.
    this.pulse(this.buzzBtn, prefersReducedMotion());
    try {
      this.merge([await this.host.api.buzz(this.summary.uuid)], true);
    } catch (error) {
      if (error instanceof ChatApiError && error.code === 'buzz_too_soon') {
        this.flashNotice(t(this.host.config.i18n, 'buzz_too_soon'));
      }
    } finally {
      window.setTimeout(() => {
        this.buzzBtn.disabled = false;
      }, 1500);
    }
  }

  /**
   * Shows a notice for 3 s, then restores the read-only notice (or hides it).
   *
   * @param message Text to show.
   */
  private flashNotice(message: string): void {
    this.notice.hidden = false;
    this.notice.textContent = message;
    window.setTimeout(() => {
      this.notice.hidden = this.summary.canSend;
      this.notice.textContent = this.summary.canSend ? '' : t(this.host.config.i18n, 'read_only');
    }, 3000);
  }

  /** One popover at a time (emoji picker or reaction bar); an outside click closes it. */
  private closePopovers(): void {
    this.emojiPanel?.remove();
    this.emojiPanel = null;
    this.reactionPanel?.remove();
    this.reactionPanel = null;
    this.emojiBtn.setAttribute('aria-expanded', 'false');
    if (this.popoverOutside !== null) {
      document.removeEventListener('click', this.popoverOutside, true);
      this.popoverOutside = null;
    }
  }

  /**
   * Closes the open popover on a click outside it and its opener.
   *
   * @param panel Popover element.
   * @param opener Button that opened it.
   */
  private watchOutside(panel: HTMLElement, opener: HTMLElement): void {
    this.popoverOutside = (event: MouseEvent): void => {
      if (!panel.contains(event.target as Node) && !opener.contains(event.target as Node)) {
        this.closePopovers();
      }
    };
    document.addEventListener('click', this.popoverOutside, true);
  }

  /** Opens or closes the emoji picker of the composer. */
  private toggleEmoji(): void {
    const open = this.emojiPanel === null;
    this.closePopovers();
    if (!open) {
      return;
    }
    this.emojiBtn.setAttribute('aria-expanded', 'true');
    const panel = emojiPicker(
      this.host.config.i18n,
      (emoji) => {
        insertAtCaret(this.input, emoji);
        this.closePopovers();
      },
      () => {
        this.closePopovers();
        this.emojiBtn.focus();
      },
    );
    this.emojiPanel = panel;
    this.composer.append(panel);
    panel.querySelector<HTMLButtonElement>('.nowo-chat-emoji__item')?.focus();
    this.watchOutside(panel, this.emojiBtn);
  }

  /**
   * Quick reaction bar (the configured emojis) next to a message.
   *
   * @param message Message to react to.
   * @param opener React button (a second click on it closes the bar).
   */
  private openReactions(message: ChatMessage, opener: HTMLButtonElement): void {
    const wasOpenHere = this.reactionPanel !== null && opener.parentElement?.contains(this.reactionPanel) === true;
    this.closePopovers();
    if (wasOpenHere) {
      return;
    }
    const i18n = this.host.config.i18n;
    const panel = h('div', { class: 'nowo-chat-reaction-bar', role: 'group', 'aria-label': t(i18n, 'react') });
    for (const emoji of this.host.config.reactions) {
      const choice = h('button', { type: 'button', class: 'nowo-chat-reaction-bar__item', 'aria-label': emoji, text: emoji });
      choice.addEventListener('click', () => {
        this.closePopovers();
        void this.toggleReaction(message, emoji);
      });
      panel.append(choice);
    }
    panel.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.closePopovers();
        opener.focus();
      }
    });
    this.reactionPanel = panel;
    opener.after(panel);
    panel.querySelector<HTMLButtonElement>('button')?.focus();
    this.watchOutside(panel, opener);
  }

  /**
   * Toggles the user's reaction on a message; failures show a notice.
   *
   * @param message Message reacted to.
   * @param emoji Reaction emoji.
   */
  private async toggleReaction(message: ChatMessage, emoji: string): Promise<void> {
    try {
      const reactions = await this.host.api.react(this.summary.uuid, message.uuid, emoji);
      this.update({ ...message, reactions });
    } catch {
      this.flashNotice(t(this.host.config.i18n, 'error'));
    }
  }

  /**
   * Inline editor in place of the bubble; Enter saves, Escape cancels.
   *
   * @param message Own message to edit.
   * @param article Rendered line of the message.
   */
  private startEdit(message: ChatMessage, article: HTMLElement): void {
    this.closePopovers();
    this.editing = message.id;
    const form = this.host.templates.clone<HTMLFormElement>('edit-form');
    const input = part<HTMLTextAreaElement>(form, 'input');
    input.value = plain(message.html, true);
    input.maxLength = this.host.config.maxLength;
    // Chips read as `@Name` in the editor; the message's mentions turn back into tokens on save.
    const editMention = new MentionAutocomplete(input, {
      load: (query) => this.host.api.members(this.summary.uuid, query),
      i18n: this.host.config.i18n,
      idPrefix: `nowo-chat-edit-${message.uuid}`,
    });
    editMention.seed(message.mentions ?? []);
    // Cards read as `#Title` in the editor; the references the author may still open turn back into tokens on save.
    const editReference = this.referencePicker(input, `nowo-chat-edit-${message.uuid}`);
    editReference?.seed(message.references ?? []);
    /** Leaves the editor and repaints the line from the current message. */
    const stop = (): void => {
      this.editing = null;
      const current = this.messages.find((item) => item.id === message.id) ?? message;
      article.replaceWith(this.renderMessage(current, article.classList.contains('nowo-chat-msg--group-start')));
    };
    /** Saves a non-blank edit; on failure, leaves the editor with an error notice. */
    const save = async (): Promise<void> => {
      const mentioned = editMention.encode();
      const body = (editReference?.encode(mentioned) ?? mentioned).trim();
      if (body === '') {
        return;
      }
      part<HTMLButtonElement>(form, 'save').disabled = true;
      try {
        const edited = await this.host.api.edit(this.summary.uuid, message.uuid, body);
        this.editing = null;
        this.update(edited);
      } catch {
        stop();
        this.flashNotice(t(this.host.config.i18n, 'error'));
      }
    };
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void save();
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void save();
      } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        stop();
      }
    });
    part(form, 'cancel').addEventListener('click', stop);
    part(article, 'bubble').replaceWith(form);
    part(article, 'actions').hidden = true;
    // Full thread width while editing (the bubble's 82 % cap squeezed the editor).
    article.classList.add('is-editing');
    input.focus();
    // The taller editor and its buttons may end under the fold: bring the whole form into view.
    form.scrollIntoView?.({ block: 'nearest' });
  }

  /**
   * "Delete this message?" in place of the actions.
   *
   * @param message Message to delete.
   * @param article Rendered line of the message.
   */
  private confirmDelete(message: ChatMessage, article: HTMLElement): void {
    this.closePopovers();
    const actions = part(article, 'actions');
    const confirm = this.host.templates.clone('delete-confirm');
    /** Removes the confirmation and shows the actions again. */
    const restore = (): void => {
      confirm.remove();
      article.classList.remove('is-confirming');
      actions.hidden = false;
    };
    part(confirm, 'cancel').addEventListener('click', restore);
    part(confirm, 'confirm').addEventListener('click', () => {
      void this.host.api
        .remove(this.summary.uuid, message.uuid)
        .then((deleted) => this.update(deleted))
        .catch(() => {
          restore();
          this.flashNotice(t(this.host.config.i18n, 'error'));
        });
    });
    actions.hidden = true;
    // Its own full-width strip under the message (inline it squeezed the bubble).
    article.classList.add('is-confirming');
    article.append(confirm);
    part(confirm, 'cancel').focus();
  }

  /**
   * The `/` command menu and `#` picker of a text box, or null when the application registers no reference provider.
   *
   * @param input Composer or editor text box.
   * @param idPrefix Unique id prefix of its list.
   * @returns The picker, or null.
   */
  private referencePicker(input: HTMLTextAreaElement, idPrefix: string): ReferenceAutocomplete | null {
    if (this.host.config.references !== true) {
      return null;
    }
    return new ReferenceAutocomplete(input, {
      load: (type, query) => this.host.api.references(this.summary.uuid, type, query),
      icon: (name) => this.host.templates.icon(name, 16),
      i18n: this.host.config.i18n,
      idPrefix,
    });
  }

  /**
   * The composer text with its @mentions and #references as tokens (what is sent and kept as a draft).
   *
   * @returns The encoded text.
   */
  private encodeComposer(): string {
    const mentioned = this.mention.encode();
    return this.reference?.encode(mentioned) ?? mentioned;
  }

  /**
   * Sends the composer text: appended at once as pending, the composer and draft cleared, then delivered.
   * Blank, read-only or too long texts are not sent.
   */
  private async submit(): Promise<void> {
    const body = this.encodeComposer().trim();
    if (body === '' || !this.summary.canSend) {
      return;
    }
    if (body.length > this.host.config.maxLength) {
      this.updateComposerState();
      return;
    }
    const pending: PendingMessage = {
      clientId: `c${Date.now()}${Math.random().toString(36).slice(2, 7)}`,
      body,
      createdAt: new Date().toISOString(),
      status: 'sending',
    };
    this.pending.push(pending);
    this.input.value = '';
    this.mention.reset();
    this.reference?.reset();
    this.host.saveDraft(this.summary.uuid, '');
    this.autoGrow();
    this.updateComposerState();
    this.render();
    this.scrollToBottom(false);
    await this.deliver(pending);
  }

  /**
   * Sends a pending message; on failure it stays in the thread with the reason and a retry button.
   *
   * @param pending Message waiting for the server.
   */
  private async deliver(pending: PendingMessage): Promise<void> {
    pending.status = 'sending';
    this.render();
    try {
      const message = await this.host.api.send(this.summary.uuid, pending.body);
      this.pending = this.pending.filter((item) => item !== pending);
      this.merge([message], true);
      this.markReadLocally(message.id);
    } catch (error) {
      pending.status = 'failed';
      const i18n = this.host.config.i18n;
      const code = error instanceof ChatApiError ? error.code : '';
      if (code === 'rate_limited') {
        pending.error = t(i18n, 'rate_limited');
      } else if (code === 'too_long') {
        pending.error = t(i18n, 'too_long', { max: this.host.config.maxLength });
      } else if (code === 'read_only') {
        pending.error = t(i18n, 'read_only');
        this.applySummary({ ...this.summary, canSend: false });
      } else {
        pending.error = t(i18n, 'error');
      }
      this.render();
    }
  }

  /**
   * Replaces the thread with a loading / error state.
   *
   * @param key Message key.
   */
  private logState(key: 'loading' | 'error'): void {
    const state = this.host.templates.clone('thread-state');
    text(state, 'text', t(this.host.config.i18n, key));
    this.log.replaceChildren(state);
  }

  /** Full render of the thread: "load more" row, empty state, lines, "Seen by" and pending messages. */
  private render(): void {
    const i18n = this.host.config.i18n;
    const fragment = document.createDocumentFragment();
    if (this.hasMore) {
      fragment.append(h('p', { class: 'nowo-chat-thread__more', text: this.loadingOlder ? t(i18n, 'loading') : '' }));
    }
    if (this.messages.length === 0 && this.pending.length === 0) {
      const empty = this.host.templates.clone('thread-empty');
      part(empty, 'avatar').replaceWith(this.host.templates.avatar(this.summary.initials, this.summary.kind));
      text(empty, 'title', this.summary.title);
      text(empty, 'hint', this.summary.kind === 'support' ? t(i18n, 'support_hint') : t(i18n, 'start_chat'));
      fragment.append(empty);
    }
    let previous: ChatMessage | undefined;
    let previousAny: ChatMessage | undefined;
    for (const message of this.messages) {
      fragment.append(...this.messageNodes(message, previous, previousAny));
      previous = message.kind === 'buzz' ? undefined : message;
      previousAny = message;
    }
    const seen = this.seenLine();
    if (seen !== null) {
      fragment.append(seen);
    }
    for (const pending of this.pending) {
      fragment.append(this.renderPending(pending));
    }
    this.log.replaceChildren(fragment);
    this.paintedUpTo = Math.max(this.paintedUpTo, this.lastId());
  }

  /**
   * Nodes for one line: an optional day separator and "New messages" divider, then the bubble or the buzz line.
   * `previous` is the last text message of the current author group (undefined after a buzz or a new day).
   *
   * @param message Message to render.
   * @param previous Previous text message of the group, if any.
   * @param previousAny Previous message of any kind, if any (day separators).
   * @returns The nodes to append, in order.
   */
  private messageNodes(message: ChatMessage, previous: ChatMessage | undefined, previousAny: ChatMessage | undefined): HTMLElement[] {
    const i18n = this.host.config.i18n;
    const locale = this.host.config.locale;
    const templates = this.host.templates;
    const nodes: HTMLElement[] = [];
    const newDay = isNewDay(previousAny, message);
    if (newDay) {
      const day = templates.clone('day');
      text(day, 'text', dayLabel(message.createdAt, i18n, locale));
      nodes.push(day);
    }
    if (this.firstUnseenId === message.id) {
      nodes.push(templates.clone('unseen'));
    }
    if (message.kind === 'buzz') {
      const line = templates.clone('buzz-line');
      line.classList.toggle('is-new', this.paintedUpTo > 0 && message.id > this.paintedUpTo);
      text(line, 'text', `${t(i18n, 'buzz_sent', { name: message.mine ? t(i18n, 'you') : message.authorName })} · ${timeLabel(message.createdAt, locale)}`);
      nodes.push(line);
      return nodes;
    }
    nodes.push(this.renderMessage(message, newDay ? true : startsGroup(previous, message)));
    return nodes;
  }

  /**
   * Fast path: new messages that only extend the thread are appended — no rebuild of the whole log, so long
   * threads stay cheap. Returns false when a full render is needed.
   *
   * @param fresh New messages, oldest first.
   * @param previousLast Newest message before the merge, if any.
   * @returns True when appended, false when a full render is needed.
   */
  private appendFresh(fresh: ChatMessage[], previousLast: ChatMessage | undefined): boolean {
    if (
      this.pending.length > 0 ||
      // A delivered message has already left `pending`, but its "Sending…" node is still painted: repaint.
      this.log.querySelector('.nowo-chat-msg--pending') !== null ||
      fresh.length === 0 ||
      previousLast === undefined ||
      fresh.some((message) => message.id <= previousLast.id) ||
      this.log.querySelector('.nowo-chat-thread__empty') !== null
    ) {
      return false;
    }
    this.log.querySelector('.nowo-chat-seen')?.remove();
    let previousAny: ChatMessage = previousLast;
    let previous: ChatMessage | undefined = previousLast.kind === 'buzz' ? undefined : previousLast;
    for (const message of fresh) {
      this.log.append(...this.messageNodes(message, isNewDay(previousAny, message) ? undefined : previous, previousAny));
      previous = message.kind === 'buzz' ? undefined : message;
      previousAny = message;
    }
    const seen = this.seenLine();
    if (seen !== null) {
      this.log.append(seen);
    }
    this.paintedUpTo = Math.max(this.paintedUpTo, this.lastId());
    return true;
  }

  /**
   * One message line: avatar, author, bubble (or tombstone), time, edited mark, receipt, reactions, actions and
   * link previews.
   *
   * @param message Message to render.
   * @param groupStart Whether it starts an author group (avatar and name shown).
   * @returns The message element.
   */
  private renderMessage(message: ChatMessage, groupStart: boolean): HTMLElement {
    const i18n = this.host.config.i18n;
    const templates = this.host.templates;
    const article = templates.clone('message');
    article.dataset.id = String(message.id);
    article.classList.toggle('nowo-chat-msg--mine', message.mine);
    article.classList.toggle('nowo-chat-msg--group-start', groupStart);
    article.classList.toggle('nowo-chat-msg--mentioned', !message.mine && !message.deleted && mentionsViewer(message, this.host.config.userUuid));

    const avatarSlot = part(article, 'avatar');
    if (message.mine) {
      avatarSlot.remove();
    } else if (groupStart) {
      avatarSlot.replaceWith(templates.avatar(message.authorInitials, 'direct', 'sm'));
    } else {
      avatarSlot.replaceWith(h('span', { class: 'nowo-chat-avatar-spacer' }));
    }
    text(article, 'author', groupStart && !message.mine && this.summary.kind !== 'direct' ? message.authorName : null);

    const bubble = part(article, 'bubble');
    if (message.deleted) {
      // WhatsApp-style tombstone: a ban icon and "You deleted this message" / "This message was deleted".
      bubble.classList.add('nowo-chat-bubble--deleted');
      const key = message.moderated ? 'moderated_message' : message.mine ? 'deleted_message_mine' : 'deleted_message';
      bubble.replaceChildren(this.host.templates.icon('ban', 14), h('span', { text: t(i18n, key) }));
    } else {
      // Server-sanitised Markdown render (ChatMarkdownRenderer): escape-first, closed tag set.
      // eslint-disable-next-line no-restricted-syntax -- sanitised on the server, see ChatMarkdownRenderer
      bubble.innerHTML = message.html;
      markOwnMentions(bubble, this.host.config.userUuid);
      // Icons of the #reference cards (data-icon slots of the server render).
      this.host.templates.fillIcons(bubble);
    }

    const time = part(article, 'time');
    paintMessageTime(time, message.createdAt, i18n, this.host.config.locale);
    part(article, 'edited').hidden = !message.edited || message.deleted;
    const receipt = part(article, 'receipt');
    if (message.mine && this.summary.kind === 'direct') {
      receipt.hidden = false;
      receipt.dataset.id = String(message.id);
      this.paintReceipt(receipt, message.id);
    } else {
      receipt.remove();
    }

    this.renderReactions(article, message);
    this.wireActions(article, message);
    this.loadPreviews(article, message);
    return article;
  }

  /**
   * Cards for the links of a message (the application decides what the viewer may see).
   *
   * @param article Rendered line of the message.
   * @param message The message (deleted ones get none; at most three distinct links).
   */
  private loadPreviews(article: HTMLElement, message: ChatMessage): void {
    const holder = part(article, 'previews');
    holder.hidden = true;
    if (!this.host.config.linkPreviews || message.deleted) {
      return;
    }
    const urls = [...new Set([...part(article, 'bubble').querySelectorAll<HTMLAnchorElement>('a[href]')].map((link) => link.getAttribute('href') ?? ''))].filter((url) => url !== '').slice(0, 3);
    for (const url of urls) {
      void this.host.linkPreview(url).then((preview) => {
        if (preview !== null) {
          holder.append(this.previewCard(url, preview));
          holder.hidden = false;
        }
      });
    }
  }

  /**
   * Card of a link preview.
   *
   * @param url Link target.
   * @param preview Title, subtitle and badge of the card.
   * @returns The card link.
   */
  private previewCard(url: string, preview: LinkPreview): HTMLElement {
    const card = this.host.templates.clone<HTMLAnchorElement>('link-preview');
    card.href = url;
    text(card, 'title', preview.title);
    text(card, 'subtitle', preview.subtitle);
    text(card, 'badge', preview.badge);
    return card;
  }

  /**
   * Reaction chips under a message (count, pressed when the user reacted; disabled when read-only).
   *
   * @param article Rendered line of the message.
   * @param message The message.
   */
  private renderReactions(article: HTMLElement, message: ChatMessage): void {
    const holder = part(article, 'reactions');
    const entries = Object.entries(message.reactions ?? {}).filter(([, people]) => people.length > 0);
    holder.hidden = entries.length === 0 || message.deleted;
    holder.replaceChildren(
      ...entries.map(([emoji, people]) => {
        const mine = people.includes(this.host.config.userUuid);
        const chip = h(
          'button',
          { type: 'button', class: `nowo-chat-reaction${mine ? ' is-mine' : ''}`, 'aria-pressed': mine ? 'true' : 'false', 'aria-label': `${emoji} ${people.length}` },
          h('span', { 'aria-hidden': 'true', text: emoji }),
          h('span', { class: 'nowo-chat-reaction__count', 'aria-hidden': 'true', text: String(people.length) }),
        );
        chip.disabled = !this.summary.canSend;
        chip.addEventListener('click', () => void this.toggleReaction(message, emoji));
        return chip;
      }),
    );
  }

  /**
   * Shows the actions that apply (react, edit and delete within the edit window, moderation for agents) and binds
   * them, plus tap-to-show on touch screens.
   *
   * @param article Rendered line of the message.
   * @param message The message.
   */
  private wireActions(article: HTMLElement, message: ChatMessage): void {
    const config = this.host.config;
    const actions = part(article, 'actions');
    const react = part<HTMLButtonElement>(actions, 'react');
    const edit = part<HTMLButtonElement>(actions, 'edit');
    const remove = part<HTMLButtonElement>(actions, 'delete');
    const inWindow = Date.now() - new Date(message.createdAt).getTime() <= config.editWindowSeconds * 1000;
    const usable = !message.deleted && message.kind === 'text';
    react.hidden = !(usable && this.summary.canSend && config.reactions.length > 0);
    edit.hidden = !(usable && message.mine && inWindow && this.summary.canSend);
    // Only the author, within the edit window (others' messages are never deletable).
    remove.hidden = !(usable && message.mine && inWindow);
    actions.hidden = react.hidden && edit.hidden && remove.hidden;
    // Touch screens have no hover: tapping a message shows its actions (one message at a time).
    article.addEventListener('click', (event) => {
      if ((event.target as Element).closest('a, button, form') !== null) {
        return;
      }
      const active = !article.classList.contains('is-active');
      this.log.querySelectorAll('.nowo-chat-msg.is-active').forEach((other) => other.classList.remove('is-active'));
      article.classList.toggle('is-active', active);
    });
    react.addEventListener('click', () => this.openReactions(message, react));
    edit.addEventListener('click', () => this.startEdit(message, article));
    remove.addEventListener('click', () => this.confirmDelete(message, article));
  }

  /**
   * In a DM: ✓ sent, ✓✓ (accent) once the other person has read it.
   *
   * @param receipt Receipt element of the line.
   * @param messageId Id of the message.
   */
  private paintReceipt(receipt: HTMLElement, messageId: number): void {
    const read = [...this.readers.values()].some((reader) => reader.messageId >= messageId);
    receipt.classList.toggle('is-read', read);
    receipt.textContent = read ? ' ✓✓' : ' ✓';
    receipt.title = t(this.host.config.i18n, read ? 'seen' : 'sent');
  }

  /**
   * In channels / support: "Seen by Ana, Luis" under the newest own message.
   *
   * @returns The line, or null in DMs, when the newest message is not the user's own or nobody has seen it.
   */
  private seenLine(): HTMLElement | null {
    if (this.summary.kind === 'direct') {
      return null;
    }
    const lastMine = [...this.messages].reverse().find((message) => message.mine && message.kind === 'text');
    if (lastMine === undefined || this.messages.at(-1)?.id !== lastMine.id) {
      return null;
    }
    const names = [...this.readers.values()].filter((reader) => reader.messageId >= lastMine.id).map((reader) => reader.name);
    if (names.length === 0) {
      return null;
    }
    const shown = names.length > 3 ? `${names.slice(0, 3).join(', ')} +${names.length - 3}` : names.join(', ');
    const seen = this.host.templates.clone('seen');
    text(seen, 'text', t(this.host.config.i18n, 'seen_by', { names: shown }));
    return seen;
  }

  /**
   * A message not confirmed by the server yet: "Sending…", or the error and a retry button.
   *
   * @param pending Pending message.
   * @returns The line element.
   */
  private renderPending(pending: PendingMessage): HTMLElement {
    const i18n = this.host.config.i18n;
    const article = this.host.templates.clone('pending');
    article.classList.toggle('nowo-chat-msg--failed', pending.status === 'failed');
    part(article, 'bubble').textContent = decodeMentions(decodeReferences(pending.body).text).text;
    const status = part(article, 'status');
    if (pending.status === 'sending') {
      status.textContent = t(i18n, 'sending');
    } else {
      const retry = h('button', { type: 'button', class: 'nowo-chat-retry' }, this.host.templates.icon('retry', 14), ` ${t(i18n, 'retry')}`);
      retry.addEventListener('click', () => void this.deliver(pending));
      status.replaceChildren(h('span', { class: 'nowo-chat-msg__error', text: `${pending.error ?? t(i18n, 'failed')} · ` }), retry);
    }
    return article;
  }

  /** Near the top: load older messages; near the bottom: clear the unseen count and mark read. */
  private onScroll(): void {
    if (this.body.scrollTop < 80 && this.hasMore && !this.loadingOlder) {
      void this.loadOlder();
    }
    if (this.isNearBottom()) {
      this.unseenBelow = 0;
      this.updateJump();
      this.maybeMarkRead();
    }
  }

  /** Prepends the previous page, keeping the reader's position; failures are retried on the next scroll. */
  private async loadOlder(): Promise<void> {
    const first = this.messages[0];
    if (first === undefined) {
      return;
    }
    this.loadingOlder = true;
    const previousHeight = this.body.scrollHeight;
    try {
      const page = await this.host.api.messages(this.summary.uuid, { before: first.id });
      this.messages = [...page.messages, ...this.messages];
      this.hasMore = page.hasMore;
    } catch {
      // Keep what we have; the next scroll retries.
    } finally {
      this.loadingOlder = false;
    }
    this.render();
    // Keep the message under the reader's eyes where it was (no jump).
    this.body.scrollTop += this.body.scrollHeight - previousHeight;
  }

  /** Marks the thread read when the user can see its newest message (expanded, visible tab, scrolled to the bottom). */
  private maybeMarkRead(): void {
    const last = this.messages.at(-1);
    if (last === undefined || this.minimised || document.visibilityState !== 'visible' || !this.isNearBottom() || last.id <= this.readUpTo) {
      return;
    }
    this.readUpTo = last.id;
    this.firstUnseenId = null;
    this.host.read(this.summary.uuid, last.id);
  }

  /**
   * Whether the thread is scrolled (almost) to the bottom.
   *
   * @returns True within 48 px of the bottom.
   */
  private isNearBottom(): boolean {
    return this.body.scrollHeight - this.body.scrollTop - this.body.clientHeight < NEAR_BOTTOM_PX;
  }

  /**
   * Scrolls to the newest message and clears the unseen count.
   *
   * @param smooth Smooth scrolling (unless the user prefers reduced motion).
   */
  private scrollToBottom(smooth: boolean): void {
    this.body.scrollTo({ top: this.body.scrollHeight, behavior: smooth && !prefersReducedMotion() ? 'smooth' : 'auto' });
    this.unseenBelow = 0;
    this.updateJump();
  }

  /** Shows the jump button ("New messages" / "Latest") when there is unseen content or the reader is far up. */
  private updateJump(): void {
    const i18n = this.host.config.i18n;
    const show = this.unseenBelow > 0 || (!this.isNearBottom() && this.body.scrollHeight > this.body.clientHeight * 2);
    this.jumpBtn.hidden = !show;
    this.jumpBtn.replaceChildren(this.host.templates.icon('arrowDown', 14), ` ${this.unseenBelow > 0 ? t(i18n, 'new_messages') : t(i18n, 'jump_latest')}`);
  }

  /**
   * Sets the unread badge of the window header.
   *
   * @param count Unread count (0 hides it).
   */
  private setUnread(count: number): void {
    setBadge(this.badge, count, t(this.host.config.i18n, 'unread', { count }));
  }

  /**
   * Grow with the text up to 6 lines. scrollHeight excludes the border, so it is added back — otherwise the box is
   * 2 px short and the browser paints a scrollbar on a one-line message.
   */
  private autoGrow(): void {
    const max = 144;
    const style = window.getComputedStyle(this.input);
    const border = (parseFloat(style.borderTopWidth) || 0) + (parseFloat(style.borderBottomWidth) || 0);
    this.input.style.height = 'auto';
    const wanted = this.input.scrollHeight + border;
    this.input.style.height = `${Math.min(wanted, max)}px`;
    this.input.style.overflowY = wanted > max ? 'auto' : 'hidden';
  }

  /** Send button state and remaining-characters counter (announced only when crossing the 100 / 20 / 0 bands). */
  private updateComposerState(): void {
    const length = this.input.value.trim().length;
    const max = this.host.config.maxLength;
    this.sendBtn.disabled = length === 0 || length > max || !this.summary.canSend;
    const remaining = max - this.input.value.length;
    this.counter.hidden = remaining > 200;
    this.counter.textContent = String(remaining);
    this.counter.classList.toggle('is-over', remaining < 0);
    // Screen readers hear the counter only when it crosses a band, not on every keystroke.
    const band = remaining < 0 ? 'over' : remaining <= 20 ? '20' : remaining <= 100 ? '100' : '';
    if (band !== this.counterBand) {
      // The first evaluation (restored draft) is silent.
      this.counterAnnounce.textContent = band === '' || this.counterBand === null ? '' : String(remaining);
      this.counterBand = band;
    }
  }
}

/**
 * Text of a server-rendered message: for previews and live announcements, or — with `lines` — for the editor
 * (paragraphs and line breaks become newlines; the Markdown markers are not recovered).
 *
 * @param html Message HTML from the server.
 * @param lines Keep line breaks (editor) instead of one line (previews).
 * @returns The plain text, trimmed.
 */
export function plain(html: string, lines = false): string {
  const doc = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/g, '\n').replace(/<\/(p|li|pre)>/g, '\n'), 'text/html');
  // Decorative emojis (support assistant lines) are aria-hidden: previews and the live region read only the text.
  doc.body.querySelectorAll('.nowo-chat-deco').forEach((emoji) => emoji.remove());
  // A #reference card reads `#Title`; the neutral chip of an item the viewer may not open, `[Content not visible]`.
  doc.body.querySelectorAll('.nowo-chat-ref').forEach((chip) => {
    const title = chip.querySelector('.nowo-chat-ref__title')?.textContent ?? '';
    chip.replaceWith(chip.classList.contains('nowo-chat-ref--restricted') ? `[${title}]` : `#${title}`);
  });
  const text = doc.body.textContent ?? '';
  // One line for previews: block boundaries become spaces ("supports:\n- dms" → "supports: dms").
  return (lines ? text.replace(/\n{3,}/g, '\n\n') : text.replace(/\s+/g, ' ')).trim();
}
