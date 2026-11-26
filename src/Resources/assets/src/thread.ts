/**
 * One conversation window (template `window`): header, thread and composer. Sending and receiving never leave the
 * page: optimistic append, server confirmation, retry on failure, and the text is never lost. Own messages can be
 * edited or deleted during the edit window; everyone who can write can react.
 */
import { ChatApiError, type ChatApi } from './api';
import { authorBadges } from './badges';
import { h, prefersReducedMotion, setBadge } from './dom';
import { applyFormat, type Format, formatHelp, QuickRepliesMenu, shortcutFormat } from './composer-tools';
import { emojiPicker, insertAtCaret } from './emoji';
import { dayLabel, isNewDay, paintMessageTime, startsGroup, t, timeLabel } from './format';
import { decodeMentions, markOwnMentions, MentionAutocomplete, mentionsViewer } from './mention';
import { decodeReferences, ReferenceAutocomplete } from './reference';
import { ConversationLinks, copyText, decodeLinkTokens, linksSettings, linkToken } from './links';
import { cardText, plainText, quoteBlock, quoteOf, SECRET_CARD_FALLBACK, stripReservedAttributes, tidySeparators, visibleItems, type MessageMenuItem } from './message-menu';
import { openMessageInfo } from './message-info';
import { openForwardDialog } from './forward-dialog';
import { openSearchDialog } from './search-dialog';
import { ThreadPanel } from './thread-panel';
import { SupportPanel } from './support';
import { loadDialogRuntime } from './sidebar';
import { AttachmentTray, downloadFile, renderFiles } from './attachments';
import { handOffSecret, secretHandoffAvailable, showSecretWarning } from './secret-handoff';
import { beforeSend, type BeforeSendExtras, CardTray, type CardRef, type SendKind, dialogUrl, openSlotDialog, selectionIn, sendSlotPayload, setCardTarget, slashAction, slotFor, withCards } from './slots';
import { customStatus, statusLabel } from './status';
import { part, text, type Templates } from './templates';
import type { AttachmentState, ChatConfig, ChatMessage, ComposerAction, ConversationSlots, ConversationSummary, MenuSlot, LinkPreview, PendingMessage, Reader, ThreadSummary } from './types';

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
   * 1.35: the read marker of a conversation moved back ("Mark unread from here"): the list shows its unread badge again.
   *
   * @param uuid Conversation uuid.
   */
  unreadChanged?(uuid: string): void;
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
/** How long an action's notice shows (ms). */
const FLASH_MS = 3000;
const NEAR_BOTTOM_PX = 48;

/** localStorage key: the formatting toolbar stays open (1.11). */
const TOOLBAR_KEY = 'nowo-chat-format-toolbar';

/** 1.26.1: newer pages one catch-up fetches at most (30 messages each); a longer gap goes on at the next tick. */
const MAX_CATCH_UP_PAGES = 10;

/** 1.26.2 (audit LP-I3): shortest time between two re-reads of the lines shown (a flapping connection reopens often). */
const REREAD_COOLDOWN_MS = 30_000;

/**
 * 1.26.1: whether two copies of a message show the same thing (`mine` aside; keys compared in a fixed order, since a
 * realtime payload and a page may list them differently).
 *
 * @param a Copy shown.
 * @param b Copy received.
 * @returns True when nothing visible differs.
 */
function sameMessage(a: ChatMessage, b: ChatMessage): boolean {
  const canonical = (message: ChatMessage): string =>
    JSON.stringify(Object.entries(message).filter(([key]) => key !== 'mine').sort(([x], [y]) => (x < y ? -1 : x > y ? 1 : 0)));
  return canonical(a) === canonical(b);
}

/** One conversation window: header, thread (messages, receipts, reactions, previews) and composer. */
export class ThreadView {
  readonly element: HTMLElement;
  private summary: ConversationSummary;
  private messages: ChatMessage[] = [];
  private pending: PendingMessage[] = [];
  /**
   * 1.34.1: own messages that arrived live (the realtime echo, a catch-up or another tab) while a send was on its way
   * and that no send response has claimed yet. A send whose response is lost after the server saved it can still be
   * matched with its echo (see echoOf()).
   */
  private readonly ownEchoes = new Set<number>();
  private hasMore = false;
  /** 1.10: pinned messages (latest pin first), the bar above the thread and whether the viewer may pin. */
  private pins: ChatMessage[] = [];
  private canPin = false;
  private readonly pinsBar: HTMLElement;
  /** 1.19: pinned links (bar under the header), the "Attach link" composer button and the links panel. */
  private readonly links: ConversationLinks | null;
  private loaded = false;
  /** 1.33: a message to show once the first page is in (`?message=<uuid>`, a copied message link). */
  private pendingJump: string | null = null;
  /** 1.26.1: the first load (or a reload) is on its way; a resync waits for it instead of sending another. */
  private loading = false;
  /** 1.26.1: the catch-up on its way (one request chain per window) and whether another one was asked for meanwhile. */
  private catchingUp: Promise<void> | null = null;
  private catchUpAgain = false;
  /** Whether the last fetchNewer() chain has ended (it clears `catchingUp` in the same step as its last check). */
  private chainDone = true;
  /**
   * 1.26.1 (audit LN-M1): newest message id up to which this window is known to hold every message (the newest page,
   * then each catch-up page). Live messages never move it: one can arrive while older ones are still missing (during a
   * reconnection, or between two catch-up pages), so the catch-up asks `?after=` this id, never the newest one shown.
   */
  private syncedUpTo = 0;
  /** 1.26.1 (LN-I2): after a 429 the catch-up waits until then (epoch ms; Retry-After, else 30 s). */
  private catchUpPausedUntil = 0;
  /** 1.26.2 (audit LN-I1): the re-read of the lines shown after a resync on its way (one per window). */
  private refreshingShown: Promise<void> | null = null;
  /** 1.26.2 (audit LP-I3): when the last re-read ended without an error (epoch ms). */
  private rereadAt = 0;
  /**
   * 1.26.2 (audit LP-L1): ids changed live (update(), merge()) while a re-read is on its way: its pages were read
   * before those changes, so they are not applied over them. Null when no re-read runs.
   */
  private touchedDuringReread: Set<number> | null = null;
  private loadingOlder = false;
  private minimised = false;
  private minimisedApplied = false;
  private readUpTo = 0;
  /** 1.25: nothing is marked read until the viewer interacts (deferRead()). */
  private readHeld = false;
  /** Listeners of the held read (deferRead()), removed when it is released. */
  private heldRead: AbortController | null = null;
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
  /** 1.35: until when a flashed notice (an action's feedback) is kept by applySummary() (epoch ms). */
  private flashUntil = 0;
  /** 1.35: uuids of the messages the viewer saved for later (from the pages and the `saved` events). */
  private readonly savedUuids = new Set<string>();
  /** 1.36: the viewer's view of the threads on the shown pages (unread replies, the newest repliers). */
  private readonly threadInfo = new Map<string, ThreadSummary>();
  /** 1.36: the open thread panel. */
  private threadPanel: ThreadPanel | null = null;
  /** 1.35: "Mark unread from here" holds the automatic read until the viewer scrolls, types or sends again. */
  private unreadHold: AbortController | null = null;
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
  /** 1.24: mute / unmute (absent from templates that predate it). */
  private readonly muteBtn: HTMLButtonElement | null;
  private readonly emojiBtn: HTMLButtonElement;
  private readonly mention: MentionAutocomplete;
  private readonly reference: ReferenceAutocomplete | null;
  private readonly support: SupportPanel;
  /** 2.6 (spec 259): the cards waiting in the composer (null without `slots` settings). */
  private readonly cardTray: CardTray | null = null;
  /** 2.7 (spec 259): the composer's files (null while attachments are off). */
  private readonly attachmentTray: AttachmentTray | null = null;
  /** 2.6: the conversation's card types, composer actions and message menu slots. */
  private slots: ConversationSlots = { cardTypes: [], composerActions: [], menuSlots: [] };

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
    this.bindMessageSearch();
    this.identity = part<HTMLButtonElement>(this.element, 'identity');
    this.badge = part(this.element, 'badge');
    this.minimiseBtn = part<HTMLButtonElement>(this.element, 'minimise');
    this.buzzBtn = part<HTMLButtonElement>(this.element, 'buzz');
    this.resolveBtn = part<HTMLButtonElement>(this.element, 'resolve');
    this.muteBtn = this.element.querySelector<HTMLButtonElement>('[data-part="mute"]');
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
      // 1.11: Ctrl/⌘+B, I and K format the selection.
      const shortcut = shortcutFormat(event);
      if (shortcut !== null) {
        event.preventDefault();
        applyFormat(this.input, shortcut);
        return;
      }
      if (event.key !== 'Enter' || event.isComposing) {
        return;
      }
      // 1.10 send key: Enter (Shift+Enter breaks the line) or Ctrl/Cmd+Enter (Enter breaks the line).
      const sends = this.host.config.prefs.sendKey === 'ctrl_enter' ? event.ctrlKey || event.metaKey : !event.shiftKey;
      if (sends) {
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
    const muteBtn = this.muteBtn;
    muteBtn?.addEventListener('click', () => void this.toggleMute(muteBtn));
    this.emojiBtn.addEventListener('click', () => this.toggleEmoji());
    this.bindComposerTools(i18n);
    // 2.6 (spec 259): the host's cards and slots.
    const slotSettings = host.config.slots;
    if (slotSettings !== undefined) {
      this.cardTray = new CardTray({
        i18n,
        icon: (name) => host.templates.icon(name, 14),
        load: (card) => host.api.card(this.summary.uuid, card.type, card.id),
        changed: () => this.updateComposerState(),
        focusAfterRemove: () => this.input.focus(),
      });
      const field = this.composer.querySelector('.nowo-chat-composer__field');
      if (field !== null) {
        field.before(this.cardTray.element);
      } else {
        this.composer.prepend(this.cardTray.element);
      }
      if (slotSettings.dialogRuntime !== undefined) {
        loadDialogRuntime(slotSettings.dialogRuntime);
      }
      void this.loadSlots();
    }
    // 2.7 (spec 259): attachments (absent while they are off).
    const attachmentSettings = host.config.attachments;
    if (attachmentSettings !== undefined) {
      this.attachmentTray = new AttachmentTray({
        i18n,
        settings: attachmentSettings,
        api: host.api,
        conversationUuid: () => this.summary.uuid,
        icon: (name, size) => host.templates.icon(name, size ?? 16),
        changed: () => this.updateComposerState(),
        focusAfterRemove: () => this.input.focus(),
      });
      const field = this.composer.querySelector('.nowo-chat-composer__field');
      if (field !== null) {
        field.before(this.attachmentTray.element);
      } else {
        this.composer.prepend(this.attachmentTray.element);
      }
      const tools = this.composer.querySelector('[data-part="composer-tools"]') ?? this.composer;
      tools.prepend(this.attachmentTray.button, this.attachmentTray.input);
    }
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

    this.canPin = summary.canPin === true;
    this.pinsBar = h('div', { class: 'nowo-chat-pins', 'data-testid': 'nowo-chat-pins', hidden: true });
    part(this.element, 'context').after(this.pinsBar);
    const links = linksSettings(host.config);
    this.links =
      links === null
        ? null
        : new ConversationLinks(
            {
              config: host.config,
              api: host.api,
              templates: host.templates,
              element: this.element,
              input: this.input,
              uuid: () => this.summary.uuid,
              announce: (message) => {
                this.live.textContent = message;
              },
              notice: (message) => this.flashNotice(message),
              jumpTo: (id) => this.jumpTo({ id }),
            },
            links,
          );
    // Pinned links first, then the pinned messages.
    if (this.links !== null) {
      part(this.element, 'context').after(this.links.bar);
    }

    const draft = host.draft(summary.uuid);
    this.mention.load(this.reference?.decode(draft) ?? draft);
    this.applySummary(summary);
    void this.loadPins();
    void this.loadLinks();
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
   * 1.26.1: newest message id up to which the window holds every message (live messages may sit beyond it).
   *
   * @returns The id, or 0 before the first load.
   */
  syncedId(): number {
    return this.syncedUpTo;
  }

  /**
   * Loads the pinned messages (1.10; the dock calls it again on a realtime `pins` event).
   */
  async loadPins(): Promise<void> {
    if (this.host.config.endpoints.pins === undefined) {
      return;
    }
    try {
      this.applyPins(await this.host.api.pins(this.summary.uuid));
    } catch {
      // The bar stays as it was.
    }
  }

  /**
   * Loads the pinned links and refreshes the open links panel (1.19; the dock calls it again on a realtime `links` event).
   */
  async loadLinks(): Promise<void> {
    await this.links?.load();
  }

  /**
   * Shows the pinned bar: the latest pin (a click shows it in the thread) and "All pinned (n)" with each pin and Unpin.
   *
   * @param state Pins and whether the viewer may pin.
   */
  private applyPins(state: { pins: ChatMessage[]; canPin: boolean }): void {
    const i18n = this.host.config.i18n;
    const changedRight = state.canPin !== this.canPin;
    this.pins = state.pins;
    this.canPin = state.canPin;
    const latest = this.pins[0];
    this.pinsBar.hidden = latest === undefined;
    if (latest === undefined) {
      this.pinsBar.replaceChildren();
    } else {
      const excerpt = (message: ChatMessage): string => `${message.authorName}: ${plain(message.html, false, t(i18n, 'card_secret')).slice(0, 80)}`;
      // The visible excerpt is the accessible name (WCAG 2.5.3); the hint goes in the title.
      const show = h('button', { type: 'button', class: 'nowo-chat-pins__latest', title: t(i18n, 'pinned_show', { text: excerpt(latest) }) });
      show.append(this.host.templates.icon('pin', 14), h('span', { class: 'nowo-chat-pins__text', text: excerpt(latest) }));
      show.addEventListener('click', () => void this.jumpTo(latest));
      const list = h('ul', { class: 'nowo-chat-pins__list', id: `nowo-chat-pins-${this.summary.uuid}`, hidden: true });
      const all = h('button', { type: 'button', class: 'nowo-chat-pins__all', 'aria-expanded': 'false', 'aria-controls': list.id, text: t(i18n, 'pinned_all', { count: this.pins.length }) });
      all.addEventListener('click', () => {
        list.hidden = !list.hidden;
        all.setAttribute('aria-expanded', list.hidden ? 'false' : 'true');
      });
      list.append(
        ...this.pins.map((pin) => {
          const item = h('li', { class: 'nowo-chat-pins__item' });
          const jump = h('button', { type: 'button', class: 'nowo-chat-pins__jump', text: excerpt(pin) });
          jump.addEventListener('click', () => void this.jumpTo(pin));
          item.append(jump);
          if (this.canPin) {
            const unpin = h('button', { type: 'button', class: 'nowo-chat-pins__unpin', text: t(i18n, 'unpin') });
            unpin.addEventListener('click', () => void this.setPinned(pin, false));
            item.append(unpin);
          }
          return item;
        }),
      );
      this.pinsBar.replaceChildren(show, all, list);
    }
    if (changedRight) {
      this.render();
    }
  }

  /**
   * Pins or unpins a message; the bar and the thread follow (the server posts the "pinned" line).
   *
   * @param message The message.
   * @param pinned True to pin, false to unpin.
   */
  private async setPinned(message: ChatMessage, pinned: boolean): Promise<void> {
    try {
      this.applyPins(await this.host.api.pin(this.summary.uuid, message.uuid, pinned));
      this.update({ ...message, pinned });
    } catch (error) {
      this.flashNotice(t(this.host.config.i18n, error instanceof ChatApiError && error.code === 'too_many_pins' ? 'too_many_pins' : 'error'));
    }
  }

  /**
   * Shows a message in the thread (loading older pages until it is there), briefly highlighted.
   *
   * @param message The message to show (only its id is needed).
   */
  async jumpTo(message: { id: number }): Promise<void> {
    const find = (): HTMLElement | null => this.log.querySelector<HTMLElement>(`[data-id="${message.id}"]`);
    let guard = 0;
    while (find() === null && this.hasMore && guard < 20) {
      guard += 1;
      await this.loadOlder();
    }
    const target = find();
    if (target === null) {
      this.flashNotice(t(this.host.config.i18n, 'pinned_gone'));
      return;
    }
    target.scrollIntoView({ block: 'center' });
    target.classList.add('is-highlighted');
    window.setTimeout(() => target.classList.remove('is-highlighted'), 2_000);
  }

  /**
   * The composer tools (1.11), when the template has them: the Aa button shows the formatting toolbar (remembered in
   * this browser), whose buttons format the selection or show the formatting help; the quick replies menu.
   */
  private bindComposerTools(i18n: Record<string, string>): void {
    const toggle = this.element.querySelector<HTMLButtonElement>('[data-part="format-toggle"]');
    const toolbar = this.element.querySelector<HTMLElement>('[data-part="format-toolbar"]');
    if (toggle !== null && toolbar !== null) {
      const show = (open: boolean): void => {
        toolbar.hidden = !open;
        toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
        try {
          window.localStorage.setItem(TOOLBAR_KEY, open ? '1' : '0');
        } catch {
          // No storage: just this window.
        }
      };
      const remembered = ((): boolean => {
        try {
          return window.localStorage.getItem(TOOLBAR_KEY) === '1';
        } catch {
          return false;
        }
      })();
      toolbar.hidden = !remembered;
      toggle.setAttribute('aria-pressed', remembered ? 'true' : 'false');
      toggle.addEventListener('click', () => show(toolbar.hidden === true));
      toolbar.querySelectorAll<HTMLButtonElement>('[data-format]').forEach((button) => {
        button.addEventListener('click', () => {
          const format = button.dataset.format ?? '';
          if (format === 'help') {
            const open = this.element.querySelector('[data-part="format-help"]');
            if (open === null) {
              toolbar.after(formatHelp(i18n));
            } else {
              open.remove();
            }
            button.setAttribute('aria-expanded', open === null ? 'true' : 'false');
            return;
          }
          applyFormat(this.input, format as Format);
        });
      });
    }
    const quick = this.element.querySelector<HTMLButtonElement>('[data-part="quick-replies"]');
    if (quick !== null) {
      if (this.host.config.endpoints.quickReplies === undefined) {
        quick.remove();
        return;
      }
      quick.setAttribute('aria-expanded', 'false');
      const menu = new QuickRepliesMenu(
        { api: this.host.api, i18n, insert: (value) => insertAtCaret(this.input, value), notice: (message) => this.flashNotice(message) },
        quick,
      );
      this.composer.append(menu.element);
    }
  }

  /** The composer hint follows the send key (Enter, or Ctrl/Cmd+Enter). */
  applySendKey(): void {
    const hint = this.element.querySelector<HTMLElement>('.nowo-chat-composer__hint');
    if (hint === null) {
      return;
    }
    // The template's own hint (Enter) is kept for when the user switches back.
    hint.dataset.enterHint ??= hint.textContent;
    hint.textContent = this.host.config.prefs.sendKey === 'ctrl_enter' ? t(this.host.config.i18n, 'composer_hint_ctrl') : hint.dataset.enterHint;
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
    // 1.10: the other person's status ("Online", "Busy" …) and custom status in a direct conversation.
    const status = summary.peerStatus ?? (summary.peerOnline ? 'available' : 'offline');
    const presence = status === 'offline' ? '' : status === 'available' ? t(i18n, 'online') : statusLabel(i18n, status);
    const peerLine = [presence, customStatus(summary.peerStatusText, summary.peerStatusEmoji)].filter((value) => value !== '').join(' · ');
    const subtitle = part(this.element, 'subtitle');
    subtitle.title = summary.kind === 'direct' ? peerLine : '';
    subtitle.textContent =
      summary.kind === 'support' && summary.supportStatus === 'resolved'
        ? t(i18n, 'support_resolved')
        : summary.kind === 'direct' && peerLine !== ''
          ? peerLine
          : summary.subtitle;
    if (summary.kind === 'direct') {
      this.element.dataset.peerStatus = status;
    }
    // 1.24: hooks for the host's header actions (nowo_chat_window_header_actions).
    this.element.dataset.kind = summary.kind;
    if (summary.channelKey !== null) {
      this.element.dataset.channelKey = summary.channelKey;
    }
    this.element.setAttribute('aria-label', t(i18n, 'conversation_label', { name: summary.title }));
    if (summary.canPin !== undefined) {
      this.canPin = summary.canPin;
    }
    part(this.element, 'avatar').replaceChildren(this.host.templates.avatar(summary.initials, summary.kind, 'md', summary.peerOnline, summary.kind === 'direct' ? status : undefined, summary.avatarUrl));
    this.applySendKey();
    this.applyContext(summary);
    // A buzz is a person-to-person nudge: direct conversations only.
    this.buzzBtn.hidden = !summary.canSend || summary.kind !== 'direct';
    this.resolveBtn.hidden = !(this.host.config.isAgent && summary.kind === 'support' && (summary.supportStatus === 'open' || summary.supportStatus === 'waiting'));
    this.setUnread(this.minimised ? summary.unread : 0);
    this.input.disabled = !summary.canSend;
    // 1.35: a notice flashing right now (an action's feedback) stays until its own timer resets it.
    if (Date.now() >= this.flashUntil) {
      this.notice.hidden = summary.canSend;
      // 1.24: a read-only channel says so (hosts that override the strings without the new key keep the old text).
      this.notice.textContent = summary.canSend ? '' : t(i18n, summary.kind === 'channel' && i18n.channel_read_only !== undefined ? 'channel_read_only' : 'read_only');
    }
    this.applyMute();
    this.composer.hidden = !summary.canSend;
    this.updateComposerState();
    this.support.render(summary);
  }

  /**
   * 1.24: whether the conversation is muted for the viewer now.
   *
   * @returns True while muted.
   */
  get isMuted(): boolean {
    return isMuted(this.summary);
  }

  /** 1.24: the mute button's icon, label and pressed state (hidden without the mute endpoint). */
  private applyMute(): void {
    const button = this.muteBtn;
    if (button === null) {
      return;
    }
    button.hidden = this.host.config.endpoints.mute === undefined;
    const muted = this.isMuted;
    const label = t(this.host.config.i18n, muted ? 'unmute' : 'mute');
    button.setAttribute('aria-label', label);
    button.setAttribute('aria-pressed', muted ? 'true' : 'false');
    button.title = label;
    button.replaceChildren(this.host.templates.icon(muted ? 'bellOff' : 'bell'));
    this.element.classList.toggle('is-muted', muted);
  }

  /**
   * 1.24: mutes the conversation until the viewer unmutes it, or unmutes it.
   *
   * @param button The mute button (disabled while the request runs).
   */
  private async toggleMute(button: HTMLButtonElement): Promise<void> {
    button.disabled = true;
    try {
      const mutedUntil = await this.host.api.mute(this.summary.uuid, !this.isMuted);
      this.host.conversationChanged({ ...this.summary, mutedUntil });
    } catch {
      this.flashNotice(t(this.host.config.i18n, 'error'));
    } finally {
      button.disabled = false;
    }
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

  /**
   * 1.25 (`web_ui.page.open_recent`, audit KJ-L2): the conversation was opened for the viewer, not by them — nothing is
   * marked read until they interact with it: a pointer, touch, wheel or key inside the view, or a Tab that moves the
   * focus into it. 1.25.1 (audit KJ-I11): focus alone does not count — a host script's programmatic `.focus()` into
   * the thread fires a trusted `focusin` too, so a `focusin` releases the read only right after a Tab keydown (which
   * arrives on the document while the focus is still outside the view). The bundle's own open() releases it when the
   * viewer picks the conversation (releaseRead()).
   */
  deferRead(): void {
    this.readHeld = true;
    this.heldRead?.abort();
    const controller = new AbortController();
    const { signal } = controller;
    this.heldRead = controller;
    let tabbed = false;
    const release = (): void => this.releaseRead();
    ['pointerdown', 'touchstart', 'wheel', 'keydown'].forEach((type) => this.element.addEventListener(type, release, { passive: true, signal }));
    document.addEventListener(
      'keydown',
      (event) => {
        tabbed = event.key === 'Tab';
      },
      { capture: true, signal },
    );
    // Runs before the document's listener below: the focus that follows the Tab is the viewer's.
    this.element.addEventListener(
      'focusin',
      () => {
        if (tabbed) {
          release();
        }
      },
      { signal },
    );
    document.addEventListener(
      'focusin',
      () => {
        tabbed = false;
      },
      { signal },
    );
  }

  /** The viewer interacted with (or picked) the conversation: a read held by deferRead() goes ahead. */
  releaseRead(): void {
    if (this.heldRead === null) {
      return;
    }
    this.discardHeldRead();
    this.readHeld = false;
    this.maybeMarkRead();
  }

  /** The window goes away: the listeners of a held read are removed, and nothing is marked read. */
  discardHeldRead(): void {
    this.heldRead?.abort();
    this.heldRead = null;
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
      // Audit LN-I5: the cache may hold a live message beyond a gap; load() starts from the server's newest page.
      this.syncedUpTo = 0;
      this.render();
      this.scrollToBottom(false);
    } else {
      this.logState('loading');
    }
  }

  /**
   * 1.40: the header's search button (`search.conversation_entry`): a link to /chat/search for this conversation that,
   * with the script, opens the search modal instead. Without the endpoints (search off) the button goes away.
   */
  private bindMessageSearch(): void {
    const link = this.element.querySelector<HTMLAnchorElement>('[data-part="message-search"]');
    if (link === null) {
      return;
    }
    const { searchResults, searchPage } = this.host.config.endpoints;
    if (searchResults === undefined || searchPage === undefined) {
      link.remove();
      return;
    }
    link.href = `${searchPage}?conversation=${encodeURIComponent(this.summary.uuid)}`;
    link.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
        return;
      }
      event.preventDefault();
      try {
        openSearchDialog({
          templates: this.host.templates,
          endpoint: searchResults,
          conversationUuid: this.summary.uuid,
          returnTo: link,
          pick: (conversationUuid, messageUuid, href) => {
            if (conversationUuid === this.summary.uuid) {
              this.jumpWhenLoaded(messageUuid);
            } else {
              window.location.assign(href);
            }
          },
        });
      } catch {
        // A host template without the modal: the search page.
        window.location.assign(link.href);
      }
    });
  }

  /**
   * 1.33: shows a message (by uuid) once the thread has its first page — at once when it has.
   *
   * @param uuid The message uuid (`?message=` of a copied link).
   */
  jumpWhenLoaded(uuid: string): void {
    this.pendingJump = uuid;
    if (this.loaded) {
      void this.runPendingJump();
    }
  }

  /** 1.33: the pending `?message=` jump: the loaded message, else the server's copy (it checks the viewer sees it). */
  private async runPendingJump(): Promise<void> {
    const uuid = this.pendingJump;
    this.pendingJump = null;
    if (uuid === null) {
      return;
    }
    const known = this.messages.find((message) => message.uuid === uuid);
    try {
      await this.jumpTo(known ?? (await this.host.api.message(this.summary.uuid, uuid)));
    } catch {
      this.flashNotice(t(this.host.config.i18n, 'pinned_gone'));
    }
  }

  /** Loads the newest page from the server (or catches up after a cache paint); failures show the error state. */
  async load(): Promise<void> {
    this.loading = true;
    try {
      if (this.messages.length > 0) {
        await this.refreshCached();
        this.loaded = true;
        void this.runPendingJump();
        return;
      }
      const page = await this.host.api.messages(this.summary.uuid);
      this.messages = page.messages;
      this.hasMore = page.hasMore;
      // The newest page is complete up to its last message.
      this.syncedUpTo = this.lastId();
      this.readUpTo = page.readUpTo ?? 0;
      this.setReaders(page.readers);
      this.noteSaved(page.saved);
      this.noteThreads(page.threads);
      this.loaded = true;
      this.render();
      this.scrollToBottom(false);
      this.host.messagesChanged(this.summary.uuid, this.messages, this.hasMore, []);
      this.maybeMarkRead();
      void this.runPendingJump();
    } catch {
      this.logState('error');
    } finally {
      this.loading = false;
    }
  }

  /**
   * 1.26.1 (audit LN-I5): after a cache paint, the server's newest page is the cursor's boundary. A page that overlaps
   * the cache is merged (only new or changed lines repaint); one that does not replaces it (a gap could hide between).
   * Then the catch-up fetches anything newer than that page.
   *
   * 1.26.2 (audit LN-D2-I1): the page overlaps only when its first message is already in the cache. Comparing with the
   * cache's newest id was not enough: a cache holding a live message beyond a gap longer than one page (`[1, 2, 3, 50]`
   * against the page `[48, 49, 50]`) kept the hole in the middle for good.
   */
  private async refreshCached(): Promise<void> {
    const page = await this.host.api.messages(this.summary.uuid);
    const first = page.messages[0];
    if (first !== undefined && !this.messages.some((message) => message.id === first.id)) {
      this.messages = [];
      this.hasMore = page.hasMore;
      this.render();
    }
    if (page.readUpTo !== null) {
      this.readUpTo = Math.max(this.readUpTo, page.readUpTo);
    }
    this.setReaders(page.readers);
    this.noteSaved(page.saved);
    this.noteThreads(page.threads);
    this.syncedUpTo = Math.max(this.syncedUpTo, page.messages.at(-1)?.id ?? 0);
    this.merge(page.messages, true);
    if (page.messages.length === 0) {
      // The conversation is empty on the server (e.g. messages moved elsewhere): the cache goes too.
      this.messages = [];
      this.render();
    }
  }

  /** Drops the messages shown and loads the newest page again (messages moved in from elsewhere). */
  async reload(): Promise<void> {
    this.messages = [];
    this.hasMore = false;
    this.loaded = false;
    this.syncedUpTo = 0;
    await this.load();
  }

  /**
   * 1.26.1: the live connection came back (or was replaced): fetch every message after the newest one held in full.
   * A thread whose first load failed loads again; one still loading is left alone.
   *
   * 1.26.2 (audit LN-I1): `?after=` only returns newer messages, so an edit, deletion or reaction made during the drop
   * to a line already shown stayed stale until the window was reopened. After the catch-up, the lines shown are read
   * again (see refreshShown()).
   */
  async resync(): Promise<void> {
    if (this.loading) {
      return;
    }
    if (!this.loaded && this.messages.length === 0) {
      await this.load();
      return;
    }
    await this.catchUp();
    await this.refreshShown();
  }

  /**
   * 1.26.2 (audit LN-I1): reads again the pages the window shows (the newest page, then `?before=` pages back to the
   * oldest line shown, at most MAX_CATCH_UP_PAGES) and applies what changed on the lines already shown: edits,
   * deletions, reactions. Messages the window does not hold are left out (the catch-up and "older" pages add those), so
   * no hole can open. One re-read per window: a call made meanwhile waits for it. A 429 pauses it like the catch-up.
   * Audit LP-I3: after a re-read that ended without an error, the next one waits REREAD_COOLDOWN_MS.
   */
  private async refreshShown(): Promise<void> {
    if (this.refreshingShown !== null) {
      return this.refreshingShown;
    }
    const now = Date.now();
    if (this.messages.length === 0 || now < this.catchUpPausedUntil || now - this.rereadAt < REREAD_COOLDOWN_MS) {
      return;
    }
    const touched = new Set<number>();
    this.touchedDuringReread = touched;
    this.refreshingShown = this.rereadShown(touched).finally(() => {
      this.refreshingShown = null;
      this.touchedDuringReread = null;
    });
    return this.refreshingShown;
  }

  /**
   * The re-read requests of refreshShown(), newest page first. Audit LP-L1: a page copy never undoes a newer state
   * shown — a deletion stays (the page may predate it), and a line changed live during the re-read (an edit, a
   * deletion, a reaction or a pin, the viewer's own included) is left as it is.
   *
   * @param touched Ids changed live since the re-read started (filled by update() and merge()).
   */
  private async rereadShown(touched: Set<number>): Promise<void> {
    const oldest = (this.messages[0] as ChatMessage).id;
    try {
      let page = await this.host.api.messages(this.summary.uuid);
      for (let pages = 1; ; pages += 1) {
        const shown = new Map(this.messages.map((message) => [message.id, message]));
        this.apply(
          page.messages.filter((message) => {
            const current = shown.get(message.id);
            return current !== undefined && !touched.has(message.id) && (message.deleted || !current.deleted);
          }),
          true,
        );
        const first = page.messages[0];
        if (first === undefined || !page.hasMore || first.id <= oldest || pages >= MAX_CATCH_UP_PAGES) {
          this.rereadAt = Date.now();
          return;
        }
        page = await this.host.api.messages(this.summary.uuid, { before: first.id });
      }
    } catch (error) {
      this.pauseOnRateLimit(error);
      throw error;
    }
  }

  /**
   * After a 429, the catch-up and the re-read wait for the server's Retry-After (30 s without one, 300 s at most).
   *
   * @param error Error of the failed request.
   */
  private pauseOnRateLimit(error: unknown): void {
    if (error instanceof ChatApiError && error.status === 429) {
      this.catchUpPausedUntil = Date.now() + Math.min(300, error.retryAfter ?? 30) * 1_000;
    }
  }

  /**
   * Poll / reconnect: fetch what arrived after the newest message the window is known to hold in full (`?after=`
   * pages until the server has nothing newer; syncedId(), not the newest one shown, since a live message may have
   * arrived beyond a gap). Since 1.26.1 one chain of requests runs per window: a call made while one is on its way runs
   * one more request after it (the first one may have left before the newest message existed) and waits for it. After
   * a 429 nothing is sent until the server's Retry-After has passed.
   */
  async catchUp(): Promise<void> {
    if ((!this.loaded && this.messages.length === 0) || Date.now() < this.catchUpPausedUntil) {
      return;
    }
    if (this.catchingUp !== null) {
      this.catchUpAgain = true;
      return this.catchingUp;
    }
    const chain = this.fetchNewer();
    // fetchNewer() clears it itself, right after its last check (LN-I3); a chain that failed at once is done already.
    this.catchingUp = this.chainDone ? null : chain;
    return chain;
  }

  /** The catch-up requests: newer pages (at most MAX_CATCH_UP_PAGES; the next tick goes on) merged as they come. */
  private async fetchNewer(): Promise<void> {
    this.chainDone = false;
    let pages = 0;
    let more: boolean;
    try {
      do {
        this.catchUpAgain = false;
        const page = await this.host.api.messages(this.summary.uuid, { after: this.syncedUpTo });
        if (page.readUpTo !== null) {
          this.readUpTo = Math.max(this.readUpTo, page.readUpTo);
        }
        this.setReaders(page.readers);
        this.noteSaved(page.saved);
        this.noteThreads(page.threads);
        // Everything after the cursor up to this page's newest message is now held (pages are contiguous).
        const newest = page.messages.at(-1)?.id ?? 0;
        this.syncedUpTo = Math.max(this.syncedUpTo, newest);
        this.merge(page.messages, true);
        pages += 1;
        // A catch-up page's hasMore means newer messages wait beyond it.
        more = (page.hasMore && page.messages.length > 0) || this.catchUpAgain;
      } while (more && pages < MAX_CATCH_UP_PAGES);
    } catch (error) {
      this.pauseOnRateLimit(error);
      throw error;
    } finally {
      // Same synchronous step as the last check of catchUpAgain: a later call starts a new chain (LN-I3).
      this.chainDone = true;
      this.catchingUp = null;
    }
  }

  /**
   * Messages from another browser tab, a poll or our own send.
   *
   * @param incoming Messages to add or replace (by id).
   * @param notify Whether to report the new ones to the host (false when they came from another tab).
   */
  merge(incoming: ChatMessage[], notify: boolean): void {
    // 1.36: thread replies never join the main timeline (an open thread panel shows them).
    incoming = incoming.filter((message) => {
      if (message.threadRootUuid === undefined) {
        return true;
      }
      this.threadPanel?.receive(message);
      return false;
    });
    // Audit LP-L1: a re-read on its way must not apply its older copies over these.
    incoming.forEach((message) => this.touchedDuringReread?.add(message.id));
    this.apply(incoming, notify);
  }

  /**
   * Adds or replaces messages by id (merge() without the re-read bookkeeping).
   *
   * @param incoming Messages to add or replace.
   * @param notify Whether to report the new ones to the host.
   */
  private apply(incoming: ChatMessage[], notify: boolean): void {
    const known = new Map(this.messages.map((message) => [message.id, message]));
    const fresh: ChatMessage[] = [];
    // 1.26.1 (audit LN-DL1): a message already shown is applied only when its content changed, and repainted in place.
    const changed: ChatMessage[] = [];
    const sending = this.pending.some((item) => item.status === 'sending');
    for (const message of incoming) {
      const current = known.get(message.id);
      if (current === undefined) {
        fresh.push(message);
        known.set(message.id, message);
        if (sending && message.mine) {
          this.ownEchoes.add(message.id);
        }
      } else if (!sameMessage(current, message)) {
        const merged = { ...message, mine: current.mine };
        changed.push(merged);
        known.set(message.id, merged);
      }
    }
    if (fresh.length === 0 && changed.length === 0) {
      // Nothing new (e.g. a catch-up page of messages that came live): no repaint, no lost editor.
      return;
    }
    this.links?.messagesChanged();
    const wasAtBottom = this.isNearBottom();
    const previousLast = this.messages.at(-1);
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
    if (fresh.length === 0 || (dividerOk && this.appendFresh(fresh, previousLast))) {
      this.repaint(changed);
    } else {
      this.render();
    }
    if (fresh.some((message) => message.mine)) {
      // Own message: land on it immediately (no half-finished smooth scroll).
      this.scrollToBottom(false);
    } else if (wasAtBottom) {
      this.scrollToBottom(true);
    }
    this.updateJump();
    if (notify && (fresh.length > 0 || changed.length > 0)) {
      this.host.messagesChanged(this.summary.uuid, this.messages, this.hasMore, fresh);
    }
    const last = fromOthers.at(-1);
    if (last !== undefined && !this.minimised) {
      this.live.textContent = `${last.authorName}: ${plain(last.html, false, t(this.host.config.i18n, 'card_secret'))}`;
    }
    this.maybeMarkRead();
  }

  /**
   * 1.26.1: repaints changed lines in place. A line being edited keeps its editor: leaving it repaints the line from
   * the current message (a deletion ends the edit, as in update()).
   *
   * @param changed Messages already shown whose content changed.
   */
  private repaint(changed: ChatMessage[]): void {
    for (const message of changed) {
      if (this.editing === message.id && message.deleted) {
        this.editing = null;
      }
      const node = this.log.querySelector<HTMLElement>(`[data-id="${message.id}"]`);
      if (node !== null && this.editing !== message.id) {
        node.replaceWith(this.renderMessage(message, node.classList.contains('nowo-chat-msg--group-start')));
      }
    }
  }

  /**
   * Edit, delete or reaction change (here, in another tab or by someone else): replace the line in place.
   *
   * @param message Updated message (unknown ids are ignored; `mine` is kept from the loaded one).
   */
  update(message: ChatMessage): void {
    if (message.threadRootUuid !== undefined) {
      // 1.36: a reply changed (edit, delete, reactions): the open thread panel repaints it.
      this.threadPanel?.receive(message);
      return;
    }
    this.threadPanel?.updateRoot(message);
    const index = this.messages.findIndex((item) => item.id === message.id);
    if (index < 0) {
      return;
    }
    const merged = { ...message, mine: (this.messages[index] as ChatMessage).mine };
    // Audit LP-L1: a re-read on its way must not apply its older copy over this one.
    this.touchedDuringReread?.add(message.id);
    this.links?.messagesChanged();
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
   * 2.4 (`realtime_events: ids`, events without names): the name of a person this window already knows — a reader of
   * the conversation, the author of a loaded message, the other person of a direct conversation; empty when unknown.
   *
   * @param userUuid The person's public id.
   * @returns The display name, or an empty string.
   */
  nameOf(userUuid: string): string {
    return this.readers.get(userUuid)?.name
      ?? this.messages.find((message) => message.authorUuid === userUuid)?.authorName
      ?? (this.summary.peerUuid === userUuid ? this.summary.title : '');
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
    this.flashUntil = Date.now() + FLASH_MS;
    this.notice.hidden = false;
    this.notice.textContent = message;
    window.setTimeout(() => {
      this.notice.hidden = this.summary.canSend;
      this.notice.textContent = this.summary.canSend ? '' : t(this.host.config.i18n, 'read_only');
    }, FLASH_MS);
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
    editReference?.seed((message.references ?? []).filter((reference) => reference.card === undefined));
    /** Leaves the editor and repaints the line from the current message. */
    const stop = (): void => {
      this.editing = null;
      const current = this.messages.find((item) => item.id === message.id) ?? message;
      article.replaceWith(this.renderMessage(current, article.classList.contains('nowo-chat-msg--group-start')));
    };
    /** Saves a non-blank edit; on failure, leaves the editor with an error notice. */
    const save = async (force = false): Promise<void> => {
      const mentioned = editMention.encode();
      const body = (editReference?.encode(mentioned) ?? mentioned).trim();
      if (body === '') {
        return;
      }
      // 2.6 (audit ZCI-L4): the host's before-send check runs for edits too; sendAnyway() saves as is.
      const saveAnyway = (): void => void save(true);
      if (!force && beforeSend(this.dockRoot(), this.summary.uuid, body, saveAnyway, 'edit', this.secretExtras(body, 'edit', stop, form))) {
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
  private async submit(force = false): Promise<void> {
    const text = this.encodeComposer().trim();
    const cards = this.cardTray?.items ?? [];
    // 2.6: a composer action's slash alias typed alone (`/secret`) opens its dialog instead of sending.
    const action = force || cards.length > 0 ? null : slashAction(text, this.slots.composerActions);
    if (action !== null) {
      this.input.value = '';
      this.updateComposerState();
      this.openAction(action);
      return;
    }
    const body = withCards(text, cards);
    const tray = this.attachmentTray;
    if ((body === '' && tray?.hasFiles !== true) || !this.summary.canSend) {
      return;
    }
    if (body.length > this.host.config.maxLength) {
      this.updateComposerState();
      return;
    }
    // 2.6: the host may warn first (`nowo-chat:before-send`, e.g. a secret in the text); sendAnyway() sends as is.
    // 2.7: it may show the dock's warning, and offer "Send as a secret" when the host opted in to the hand-off.
    const sendAnyway = (): void => void this.submit(true);
    if (!force && text !== '' && beforeSend(this.dockRoot(), this.summary.uuid, text, sendAnyway, 'send', this.secretExtras(text, 'send', () => this.clearComposer(), this.composer))) {
      return;
    }
    // 2.7: send when ready — every file clean first; a blocked or failed one is removed by the person.
    if (tray?.hasFiles === true && !tray.ready) {
      if (tray.rejected || !(await tray.waitUntilReady())) {
        this.flashNotice(t(this.host.config.i18n, 'attachment.blocked'));
        return;
      }
    }
    const files = tray?.uuids ?? [];
    const pending: PendingMessage = {
      clientId: `c${Date.now()}${Math.random().toString(36).slice(2, 7)}`,
      body,
      createdAt: new Date().toISOString(),
      status: 'sending',
      ...(files.length > 0 ? { attachments: files } : {}),
    };
    this.pending.push(pending);
    this.input.value = '';
    this.mention.reset();
    this.reference?.reset();
    this.cardTray?.reset();
    tray?.reset();
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
      this.settle(pending, await (pending.attachments === undefined ? this.host.api.send(this.summary.uuid, pending.body) : this.host.api.send(this.summary.uuid, pending.body, pending.attachments)));
    } catch (error) {
      const echoed = this.echoOf(pending, error);
      if (echoed !== undefined) {
        // The server saved it (its echo is shown) but the response was lost: delivered, not failed.
        this.settle(pending, echoed);
        return;
      }
      pending.status = 'failed';
      const i18n = this.host.config.i18n;
      const code = error instanceof ChatApiError ? error.code : '';
      if (code === 'rate_limited') {
        pending.error = t(i18n, 'rate_limited');
      } else if (code === 'too_long') {
        pending.error = t(i18n, 'too_long', { max: this.host.config.maxLength });
      } else if (code === 'card_not_allowed') {
        pending.error = t(i18n, 'card_not_allowed');
      } else if (code === 'attachment_not_ready' || code === 'attachment_invalid' || code === 'too_many_attachments') {
        // 2.7: a file was removed, blocked or is still being checked.
        pending.error = t(i18n, 'attachment.failed');
      } else if (code === 'read_only') {
        pending.error = t(i18n, 'read_only');
        this.applySummary({ ...this.summary, canSend: false });
      } else {
        pending.error = t(i18n, 'error');
      }
      if (!this.pending.some((item) => item.status === 'sending')) {
        this.ownEchoes.clear();
      }
      this.render();
    }
  }

  /**
   * 1.34.1: a pending message was delivered. Its "Sending…" line leaves and the server's copy is shown once, whether
   * the realtime echo painted it before the response (merge() then has nothing new to paint: the line is dropped by a
   * render) or comes after it (merge() ignores a known id).
   *
   * @param pending The delivered pending message.
   * @param message The server's copy.
   */
  private settle(pending: PendingMessage, message: ChatMessage): void {
    this.pending = this.pending.filter((item) => item !== pending);
    this.merge([message], true);
    // After merge(): our own response is not an unclaimed echo for another send still on its way.
    this.ownEchoes.delete(message.id);
    if (!this.pending.some((item) => item.status === 'sending')) {
      this.ownEchoes.clear();
    }
    if (this.log.querySelectorAll('.nowo-chat-msg--pending').length !== this.pending.length) {
      this.render();
    }
    this.markReadLocally(message.id);
  }

  /**
   * 1.34.1: a send failed without a refusal from the server (network error, 5xx): if exactly one unclaimed own message
   * with the same text arrived live while it was on its way (and no other send is on its way), the server saved it and
   * only the response was lost. A refusal (4xx) saved nothing. Anything ambiguous stays failed, with its retry button
   * (never silently dropped).
   *
   * @param pending The pending message whose send failed.
   * @param error The error of the send.
   * @returns The echoed copy, or undefined.
   */
  private echoOf(pending: PendingMessage, error: unknown): ChatMessage | undefined {
    // Another send on its way could own that echo: ambiguous.
    if ((error instanceof ChatApiError && error.status >= 400 && error.status < 500) || this.pending.some((item) => item !== pending && item.status === 'sending')) {
      return undefined;
    }
    // The composer text is Markdown, the echo is HTML: compare the words, without emphasis / code marks.
    const normalise = (value: string): string => value.replace(/[*_~`]/g, '').replace(/\s+/g, ' ').trim();
    const sent = normalise(decodeMentions(decodeReferences(decodeLinkTokens(pending.body)).text).text);
    const matches = this.messages.filter(
      (message) => this.ownEchoes.has(message.id) && message.kind === 'text' && !message.deleted && normalise(plain(message.html)) === sent,
    );
    return matches.length === 1 ? matches[0] : undefined;
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
      part(empty, 'avatar').replaceWith(this.host.templates.avatar(this.summary.initials, this.summary.kind, 'md', false, undefined, this.summary.avatarUrl));
      text(empty, 'title', this.summary.title);
      text(empty, 'hint', this.summary.kind === 'support' ? t(i18n, 'support_hint') : t(i18n, 'start_chat'));
      fragment.append(empty);
    }
    let previous: ChatMessage | undefined;
    let previousAny: ChatMessage | undefined;
    for (const message of this.messages) {
      fragment.append(...this.messageNodes(message, previous, previousAny));
      previous = isLine(message) ? undefined : message;
      previousAny = message;
    }
    const seen = this.seenLine();
    if (seen !== null) {
      fragment.append(seen);
    }
    for (const pending of this.pending) {
      fragment.append(this.renderPending(pending));
    }
    // 1.26.2 (audit LN-D2-I2): a deletion ends the edit, as in repaint() and update(): the line shows its tombstone.
    if (this.editing !== null && this.messages.some((message) => message.id === this.editing && message.deleted)) {
      this.editing = null;
    }
    // 1.26.1 (audit LN-DL1): a line being edited keeps its node, so the typed text and the editor survive a repaint.
    const edited = this.editing === null ? null : this.log.querySelector<HTMLElement>(`[data-id="${this.editing}"]`);
    const replacement = edited === null ? null : fragment.querySelector<HTMLElement>(`[data-id="${this.editing}"]`);
    if (edited !== null && replacement !== null) {
      edited.classList.toggle('nowo-chat-msg--group-start', replacement.classList.contains('nowo-chat-msg--group-start'));
      replacement.replaceWith(edited);
    } else if (edited !== null) {
      // The message left the thread: so does its editor.
      this.editing = null;
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
    if (message.kind === 'pin') {
      // 1.10: "X pinned a message" in the reader's language; a click shows the message.
      const line = templates.clone('buzz-line');
      line.classList.add('nowo-chat-pin-line');
      line.querySelector('[data-icon], svg')?.replaceWith(templates.icon('pin', 14));
      text(line, 'text', `${t(i18n, 'pinned_line', { name: message.mine ? t(i18n, 'you') : message.authorName })} · ${timeLabel(message.createdAt, locale)}`);
      const target = this.pins.find((pin) => pin.uuid === message.pinnedMessage);
      if (target !== undefined) {
        line.addEventListener('click', () => void this.jumpTo(target));
      }
      nodes.push(line);
      return nodes;
    }
    if (message.kind === 'system') {
      // 1.24: a line the application posted (e.g. "Ana renamed the channel"), always as text.
      const line = templates.clone('buzz-line');
      line.classList.add('nowo-chat-system-line');
      line.querySelector('[data-icon], svg')?.remove();
      text(line, 'text', `${plain(message.html)} · ${timeLabel(message.createdAt, locale)}`);
      nodes.push(line);
      return nodes;
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
    let previous: ChatMessage | undefined = isLine(previousLast) ? undefined : previousLast;
    for (const message of fresh) {
      this.log.append(...this.messageNodes(message, isNewDay(previousAny, message) ? undefined : previous, previousAny));
      previous = isLine(message) ? undefined : message;
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
      avatarSlot.replaceWith(templates.avatar(message.authorInitials, 'direct', 'sm', false, undefined, message.authorAvatarUrl));
    } else {
      avatarSlot.replaceWith(h('span', { class: 'nowo-chat-avatar-spacer' }));
    }
    // 1.29: the author's badges (checked again, textContent only); in a direct thread they bring the name line with them.
    const badges = message.mine || message.kind === 'system' ? [] : authorBadges(message.authorBadges);
    const author = text(article, 'author', groupStart && !message.mine && (this.summary.kind !== 'direct' || badges.length > 0) ? message.authorName : null);
    if (!author.hidden && badges.length > 0) {
      templates.authorBadges(author, badges);
    }

    const bubble = part(article, 'bubble');
    if (message.deleted) {
      // Tombstone: a ban icon and "You deleted this message" / "This message was deleted".
      bubble.classList.add('nowo-chat-bubble--deleted');
      const key = message.moderated ? 'moderated_message' : message.mine ? 'deleted_message_mine' : 'deleted_message';
      bubble.replaceChildren(this.host.templates.icon('ban', 14), h('span', { text: t(i18n, key) }));
    } else {
      // Server-sanitised Markdown render (ChatMarkdownRenderer): escape-first, closed tag set.
      // eslint-disable-next-line no-restricted-syntax -- sanitised on the server, see ChatMarkdownRenderer
      bubble.innerHTML = message.html;
      // 1.33: never UiKit's context-menu / card attributes inside a message (the server strips them too).
      stripReservedAttributes(bubble);
      markOwnMentions(bubble, this.host.config.userUuid);
      // Icons of the #reference cards (data-icon slots of the server render).
      this.host.templates.fillIcons(bubble);
      // 2.6: card actions marked for the dialog open the host page in the dock's dialog.
      this.wireCardActions(bubble);
      if (message.kind === 'auto') {
        // 1.11: an automatic reply says so (the icon is decorative; the label is read).
        article.classList.add('nowo-chat-msg--auto');
        article.dataset.auto = '';
        bubble.before(h('span', { class: 'nowo-chat-msg__auto' }, this.host.templates.icon('auto', 12), h('span', { text: t(i18n, 'auto_reply_marker') })));
      }
    }

    // 2.7 (spec 259): the message's files (name and size as text; Download asks for a URL signed for this reader).
    const files = message.deleted ? [] : (message.attachments ?? []);
    if (files.length > 0) {
      bubble.hidden = message.html === '';
      bubble.after(renderFiles(files, i18n, (name, size) => templates.icon(name, size ?? 16), (uuid) => {
        downloadFile(this.host.api, uuid).catch(() => this.flashNotice(t(i18n, 'error')));
      }));
    }
    if (!message.deleted && message.forwarded !== undefined) {
      // 1.36: a forward says so; its source only when the viewer may read it (the server decides).
      const from = message.forwarded.from === 'channel' && message.forwarded.label !== null ? t(i18n, 'forwarded_from_channel', { name: message.forwarded.label }) : message.forwarded.from === 'direct' ? t(i18n, 'forwarded_from_direct') : t(i18n, 'forwarded');
      bubble.before(h('p', { class: 'nowo-chat-msg__forwarded', 'data-part': 'forwarded', text: from }));
    }
    this.renderThreadSummary(article, message);
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
    const urls = [...new Set([...part(article, 'bubble').querySelectorAll<HTMLAnchorElement>('a[href]:not(.nowo-chat-linkcard)')].map((link) => link.getAttribute('href') ?? ''))].filter((url) => url !== '').slice(0, 3);
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
    // 1.10: pin / unpin for who may pin (the server checks again).
    const pin = actions.querySelector<HTMLButtonElement>('[data-part="pin"]');
    if (pin !== null) {
      pin.hidden = !(usable && this.canPin);
      const label = t(config.i18n, message.pinned === true ? 'unpin' : 'pin');
      pin.setAttribute('aria-label', label);
      pin.title = label;
      pin.setAttribute('aria-pressed', message.pinned === true ? 'true' : 'false');
      pin.addEventListener('click', () => void this.setPinned(message, message.pinned !== true));
    }
    edit.hidden = !(usable && message.mine && inWindow && this.summary.canSend);
    // Only the author, within the edit window (others' messages are never deletable).
    remove.hidden = !(usable && message.mine && inWindow);
    const menu = this.wireMenu(article, message, { usable, mine: message.mine, inWindow });
    actions.hidden = react.hidden && edit.hidden && remove.hidden && (pin === null || pin.hidden) && (menu === null || menu.hidden);
    // Touch screens have no hover: tapping a message shows its actions (one message at a time).
    article.addEventListener('click', (event) => {
      if ((event.target as Element).closest('a, button, form, summary, details') !== null) {
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
   * 1.33: the "More actions" kebab of a message (template block `nowo_chat_message_menu`): shows the items the viewer may
   * use and binds them. The same items open as the message's context menu when UiKit's runtime runs (it forwards each
   * click to the kebab item). The server checks every action again: send (Reply), edit, link, pin and delete endpoints.
   *
   * @param article Rendered line of the message.
   * @param message The message.
   * @param state Whether it is usable (shown, text), the viewer's own and within the edit window.
   * @returns The menu holder, or null when the template has none (a host override of the message template).
   */
  private wireMenu(article: HTMLElement, message: ChatMessage, state: { usable: boolean; mine: boolean; inWindow: boolean }): HTMLElement | null {
    const menu = article.querySelector<HTMLElement>('[data-part="menu"]');
    if (menu === null) {
      return null;
    }
    const config = this.host.config;
    const endpoints = config.endpoints;
    const offered = config.menu?.items ?? {};
    const saved = this.savedUuids.has(message.uuid);
    const shown = visibleItems({
      ...state,
      canSend: this.summary.canSend,
      canPin: this.canPin,
      pinned: message.pinned === true,
      canLink: (endpoints.messageLink ?? '') !== '',
      // 1.35: an item is offered only when the configuration says so and its endpoint exists.
      offered: {
        quote: offered.quote === true,
        copy_text: offered.copy_text === true,
        save: offered.save === true && (endpoints.save ?? '') !== '',
        mark_unread: offered.mark_unread === true && (endpoints.unread ?? '') !== '',
        // Own messages; moderators (support agents) outside direct conversations (the server decides again).
        info: offered.info === true && (endpoints.info ?? '') !== '' && (message.mine || (config.isAgent && this.summary.kind !== 'direct')),
        // 1.36
        thread: offered.thread === true && (endpoints.thread ?? '') !== '',
        // 2.6: a message with typed cards is not forwarded in v1 (the server refuses it too).
        // 2.7 (Q-259-8 A): nor one with files.
        forward: offered.forward === true && (endpoints.forward ?? '') !== '' && message.hasCards !== true && (message.attachments?.length ?? 0) === 0,
      },
      saved,
      reply: message.threadRootUuid !== undefined,
    });
    const details = menu.querySelector('details');
    const closeKebab = (): void => {
      if (details !== null) {
        details.open = false;
      }
    };
    const actions: Record<MessageMenuItem, () => void> = {
      reply: () => this.replyTo(message, article),
      edit: () => this.startEdit(message, article),
      'copy-link': () => void this.copyLink(message),
      pin: () => void this.setPinned(message, message.pinned !== true),
      delete: () => this.confirmDelete(message, article),
      quote: () => this.quote(message, article),
      'copy-text': () => void this.copyPlainText(article),
      save: () => void this.toggleSaved(message),
      'mark-unread': () => void this.markUnreadFrom(message),
      info: () => void this.showInfo(message, article),
      thread: () => this.openThread(message, article),
      forward: () => this.forward(message, article),
    };
    let any = false;
    for (const [name, run] of Object.entries(actions) as [MessageMenuItem, () => void][]) {
      const item = menu.querySelector<HTMLElement>(`[data-part="menu-${name}"]`);
      if (item === null) {
        continue;
      }
      item.hidden = !shown[name];
      any ||= shown[name];
      if (name === 'pin') {
        item.textContent = t(config.i18n, message.pinned === true ? 'unpin' : 'pin');
      }
      if (name === 'save') {
        item.textContent = t(config.i18n, saved ? 'unsave_message' : 'save_message');
      }
      item.addEventListener('click', (event) => {
        event.preventDefault();
        closeKebab();
        run();
      });
    }
    any = this.wireSlots(menu, article, message, state.usable, closeKebab) || any;
    any = this.wireQuickReactions(menu, message, state.usable, closeKebab) || any;
    const panel = menu.querySelector('.nowo-ui-kebab__menu');
    if (panel !== null) {
      tidySeparators(panel);
    }
    menu.hidden = !any;
    // Escape closes the open kebab and gives focus back to its toggle.
    details?.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && details.open) {
        event.stopPropagation();
        details.open = false;
        details.querySelector<HTMLElement>('summary')?.focus();
      }
    });
    return menu;
  }

  /**
   * 1.35: the quick reactions row of the menu (each configured emoji toggles the viewer's reaction through the usual
   * endpoint; "More…" opens the emoji picker). Shown to whoever may react.
   *
   * @param menu The menu holder of the message.
   * @param message The message.
   * @param usable Whether the message takes menu actions (a shown text message).
   * @param closeKebab Closes the kebab before running an item.
   * @returns Whether the row shows anything.
   */
  private wireQuickReactions(menu: HTMLElement, message: ChatMessage, usable: boolean, closeKebab: () => void): boolean {
    const row = menu.querySelector<HTMLElement>('[data-part="menu-reactions"]');
    if (row === null) {
      return false;
    }
    const config = this.host.config;
    const canReact = usable && this.summary.canSend;
    const mine = config.userUuid;
    let any = false;
    row.querySelectorAll<HTMLElement>('[data-part="menu-react"]').forEach((item) => {
      const emoji = item.dataset.emoji ?? '';
      item.hidden = !canReact || emoji === '';
      any ||= !item.hidden;
      const reacted = (message.reactions?.[emoji] ?? []).includes(mine);
      item.classList.toggle('is-current', reacted);
      item.addEventListener('click', (event) => {
        event.preventDefault();
        closeKebab();
        void this.toggleReaction(message, emoji);
      });
    });
    const more = row.querySelector<HTMLElement>('[data-part="menu-react-more"]');
    if (more !== null) {
      more.hidden = !canReact || config.menu?.picker !== true;
      any ||= !more.hidden;
      more.addEventListener('click', (event) => {
        event.preventDefault();
        closeKebab();
        this.openReactionPicker(message, menu);
      });
    }
    row.hidden = !any;
    return any;
  }

  /**
   * 1.35: "More…" of the quick reactions: the emoji picker next to the message; the pick toggles that reaction.
   *
   * @param message The message.
   * @param anchor Element the picker opens after (the message's menu holder).
   */
  private openReactionPicker(message: ChatMessage, anchor: HTMLElement): void {
    this.closePopovers();
    const panel = emojiPicker(
      this.host.config.i18n,
      (emoji) => {
        this.closePopovers();
        void this.toggleReaction(message, emoji);
      },
      () => {
        this.closePopovers();
        anchor.querySelector<HTMLElement>('summary')?.focus();
      },
    );
    panel.classList.add('nowo-chat-emoji--reactions');
    this.reactionPanel = panel;
    anchor.after(panel);
    panel.querySelector<HTMLButtonElement>('.nowo-chat-emoji__item')?.focus();
    this.watchOutside(panel, anchor);
  }

  /**
   * 1.35: Quote — the message as a multi-line quote at the start of the composer (mentions as names: nobody is notified).
   *
   * @param message The message quoted.
   * @param article Its rendered line.
   */
  private quote(message: ChatMessage, article: HTMLElement): void {
    const config = this.host.config;
    const block = quoteBlock(message.authorName, plainText(part(article, 'bubble'), t(config.i18n, 'card_secret')), t(config.i18n, 'quote_wrote'), config.menu?.quoteMaxLength ?? 1000);
    if (block === '' || this.input.disabled) {
      return;
    }
    this.input.value = `${block}${this.input.value.replace(/^\n+/, '')}`;
    this.input.dispatchEvent(new Event('input', { bubbles: true }));
    this.input.focus({ preventScroll: true });
    this.input.setSelectionRange(this.input.value.length, this.input.value.length);
  }

  /**
   * 1.35: Copy text — the plain text the viewer sees (Markdown stripped, mentions as names).
   *
   * @param article The rendered line of the message.
   */
  private async copyPlainText(article: HTMLElement): Promise<void> {
    const i18n = this.host.config.i18n;
    this.flashNotice(t(i18n, (await copyText(plainText(part(article, 'bubble'), t(i18n, 'card_secret')))) ? 'text_copied' : 'error'));
  }

  /**
   * 1.35: Save for later / Remove from saved (the server checks the viewer may read the message).
   *
   * @param message The message.
   */
  private async toggleSaved(message: ChatMessage): Promise<void> {
    const i18n = this.host.config.i18n;
    try {
      const saved = await this.host.api.setSaved(this.summary.uuid, message.uuid, !this.savedUuids.has(message.uuid));
      this.savedChanged(message.uuid, saved);
      this.flashNotice(t(i18n, saved ? 'saved_done' : 'unsaved_done'));
    } catch (error) {
      this.flashNotice(t(i18n, error instanceof ChatApiError && (error.code === 'too_many_saved' || error.code === 'not_savable') ? error.code : 'error'));
    }
  }

  /**
   * 1.35: a message was saved or unsaved (here, in another tab or on another device): its menu follows.
   *
   * @param messageUuid The message.
   * @param saved Whether it is saved now.
   */
  savedChanged(messageUuid: string, saved: boolean): void {
    if (saved === this.savedUuids.has(messageUuid)) {
      return;
    }
    if (saved) {
      this.savedUuids.add(messageUuid);
    } else {
      this.savedUuids.delete(messageUuid);
    }
    const message = this.messages.find((candidate) => candidate.uuid === messageUuid);
    if (message !== undefined) {
      this.update(message);
    }
  }

  /**
   * 1.35: the saved messages of a page the server sent (`saved`; absent while the item is off).
   *
   * @param uuids Uuids of the saved messages of that page.
   */
  private noteSaved(uuids: string[] | undefined): void {
    for (const uuid of uuids ?? []) {
      this.savedUuids.add(uuid);
    }
  }

  /**
   * 1.35: Mark unread from here — the read marker moves to just before the message; the window stops marking the
   * conversation read until the viewer scrolls, types or sends again.
   *
   * @param message The first message unread again.
   */
  private async markUnreadFrom(message: ChatMessage): Promise<void> {
    const i18n = this.host.config.i18n;
    try {
      const readUpTo = await this.host.api.markUnread(this.summary.uuid, message.uuid);
      this.unreadFrom(readUpTo);
      this.host.unreadChanged?.(this.summary.uuid);
      this.flashNotice(t(i18n, 'marked_unread'));
    } catch {
      this.flashNotice(t(i18n, 'error'));
    }
  }

  /**
   * 1.35: the read marker moved back (here or in another tab): no automatic read until the viewer acts again.
   *
   * @param readUpTo The read marker now (null: nothing read).
   */
  unreadFrom(readUpTo: number | null): void {
    this.readUpTo = readUpTo ?? 0;
    this.unreadHold?.abort();
    const hold = new AbortController();
    this.unreadHold = hold;
    const release = (): void => {
      if (this.unreadHold === hold) {
        hold.abort();
        this.unreadHold = null;
        this.maybeMarkRead();
      }
    };
    for (const type of ['wheel', 'touchmove', 'keydown']) {
      this.body.addEventListener(type, release, { signal: hold.signal, passive: true });
    }
    this.input.addEventListener('input', release, { signal: hold.signal });
  }

  /**
   * 1.35: Message info — readers and read times (reciprocal) in a dialog.
   *
   * @param message The message.
   * @param article Its rendered line (focused again when the dialog closes).
   */
  private async showInfo(message: ChatMessage, article: HTMLElement): Promise<void> {
    const config = this.host.config;
    try {
      // 1.36: the previous texts for the author and moderators (the server decides again: 403 for anyone else).
      const history = config.menu?.editHistory === true && (config.endpoints.revisions ?? '') !== '' && (message.mine || (config.isAgent && this.summary.kind !== 'direct'));
      openMessageInfo(await this.host.api.messageInfo(this.summary.uuid, message.uuid), config.i18n, config.locale, article, history ? () => this.host.api.revisions(this.summary.uuid, message.uuid) : null);
    } catch {
      this.flashNotice(t(config.i18n, 'error'));
    }
  }

  /**
   * 1.36: the "N replies" button under a thread root (unread replies of a followed thread are marked), which opens the
   * thread panel.
   *
   * @param article Rendered line of the message.
   * @param message The message.
   */
  private renderThreadSummary(article: HTMLElement, message: ChatMessage): void {
    const count = message.thread?.count ?? 0;
    if (count === 0 || this.host.config.menu?.items.thread !== true) {
      return;
    }
    const i18n = this.host.config.i18n;
    const info = this.threadInfo.get(message.uuid);
    const label = t(i18n, count === 1 ? 'thread_one_reply' : 'thread_replies_count', { count });
    const last = message.thread?.lastReplyAt ?? null;
    const button = h(
      'button',
      { type: 'button', class: `nowo-chat-msg__thread${info?.unread === true ? ' is-unread' : ''}`, 'data-part': 'thread-summary', 'aria-label': info?.unread === true ? `${label}, ${t(i18n, 'thread_new_replies')}` : label },
      h('span', { class: 'nowo-chat-msg__thread-avatars', 'aria-hidden': 'true' }, ...(info?.repliers ?? []).map((person) => this.host.templates.avatar(person.initials, 'direct', 'sm', false, undefined, person.avatarUrl))),
      h('span', { class: 'nowo-chat-msg__thread-count', text: label }),
      last === null ? null : h('time', { class: 'nowo-chat-msg__thread-last', datetime: last, text: t(i18n, 'thread_last_reply', { time: timeLabel(last, this.host.config.locale) }) }),
    );
    button.addEventListener('click', () => this.openThread(message, article));
    const anchor = article.querySelector('[data-part="reactions"]') ?? part(article, 'bubble');
    anchor.after(button);
  }

  /**
   * 1.36: the thread summaries of a page the server sent (`threads`; absent while threads are off).
   *
   * @param threads By root uuid.
   */
  private noteThreads(threads: Record<string, ThreadSummary> | undefined): void {
    for (const [uuid, summary] of Object.entries(threads ?? {})) {
      this.threadInfo.set(uuid, summary);
    }
  }

  /**
   * 1.36: a thread got a reply (realtime `thread`, for followers): the root's count and its "new replies" mark follow.
   *
   * @param rootUuid The root.
   * @param replyCount Its replies now.
   * @param lastReplyAt Its newest reply time.
   */
  threadChanged(rootUuid: string, replyCount: number, lastReplyAt: string | null): void {
    const root = this.messages.find((message) => message.uuid === rootUuid);
    if (root === undefined) {
      return;
    }
    const open = this.threadPanel?.rootUuid === rootUuid;
    const known = this.threadInfo.get(rootUuid);
    this.threadInfo.set(rootUuid, { count: replyCount, lastReplyAt, unread: !open, following: true, repliers: known?.repliers ?? [] });
    this.update({ ...root, thread: { count: replyCount, lastReplyAt } });
  }

  /**
   * 1.36: "Reply in thread" or the "N replies" button: the thread panel of the message.
   *
   * @param message The root (or a reply).
   * @param article Its rendered line (focused again when the panel closes).
   */
  private openThread(message: ChatMessage, article: HTMLElement): void {
    const config = this.host.config;
    this.threadPanel?.close();
    const rootUuid = message.threadRootUuid ?? message.uuid;
    const known = this.threadInfo.get(rootUuid);
    if (known !== undefined) {
      this.threadInfo.set(rootUuid, { ...known, unread: false });
    }
    const panel = new ThreadPanel({
      api: this.host.api,
      i18n: config.i18n,
      locale: config.locale,
      conversationUuid: this.summary.uuid,
      message,
      canSend: this.summary.canSend,
      maxLength: config.maxLength,
      returnTo: article,
      rootChanged: (root) => this.update(root),
      // 2.6 (audit ZCI-L4): the host's before-send check runs for thread replies too.
      beforeSend: (text, sendAnyway, clear, anchor) => beforeSend(this.dockRoot(), this.summary.uuid, text, sendAnyway, 'thread', this.secretExtras(text, 'thread', clear ?? ((): void => undefined), anchor ?? this.composer)),
      closed: () => {
        if (this.threadPanel === panel) {
          this.threadPanel = null;
        }
      },
    });
    this.threadPanel = panel;
    const root = this.messages.find((candidate) => candidate.uuid === rootUuid);
    if (root !== undefined && known?.unread === true) {
      this.update(root);
    }
  }

  /**
   * 1.36: Forward — the picker dialog; the notice says how many conversations it went to.
   *
   * @param message The message.
   * @param article Its rendered line (focused again when the dialog closes).
   */
  private forward(message: ChatMessage, article: HTMLElement): void {
    const config = this.host.config;
    openForwardDialog(this.host.api, config.i18n, this.summary.uuid, message.uuid, config.menu?.forwardMaxTargets ?? 5, article, (count) => {
      this.flashNotice(t(config.i18n, count === 1 ? 'forwarded_one' : 'forwarded_many', { count }));
    });
  }

  /**
   * 1.33: Reply — starts the composer with a quote of the message (`> Name: excerpt`) and focuses it; sending goes
   * through the usual send endpoint (the server checks the viewer may write).
   *
   * @param message The message replied to.
   * @param article Its rendered line (the plain text of the bubble).
   */
  private replyTo(message: ChatMessage, article: HTMLElement): void {
    // Audit ZCI-M2: the same walker as Quote and Copy text (a card is its generic line or `#Title`, never its facts).
    const quote = quoteOf(message.authorName, plainText(part(article, 'bubble'), t(this.host.config.i18n, 'card_secret')));
    if (quote === '' || this.input.disabled) {
      return;
    }
    this.input.value = `${quote}${this.input.value.replace(/^\n+/, '')}`;
    this.input.dispatchEvent(new Event('input', { bubbles: true }));
    this.input.focus({ preventScroll: true });
    this.input.setSelectionRange(this.input.value.length, this.input.value.length);
  }

  /**
   * 1.33: Copy link — asks the server for the message's address (it checks the viewer may see it) and copies it.
   *
   * @param message The message.
   */
  private async copyLink(message: ChatMessage): Promise<void> {
    const i18n = this.host.config.i18n;
    try {
      const url = await this.host.api.messageLink(this.summary.uuid, message.uuid);
      this.flashNotice(t(i18n, (await copyText(url)) ? 'link_copied' : 'error'));
    } catch {
      this.flashNotice(t(i18n, 'error'));
    }
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
    part(article, 'bubble').textContent = decodeMentions(decodeReferences(decodeLinkTokens(pending.body)).text).text;
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
    if (last === undefined || this.readHeld || this.unreadHold !== null || this.minimised || document.visibilityState !== 'visible' || !this.isNearBottom() || last.id <= this.readUpTo) {
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
  /**
   * 2.6 (spec 259): loads the conversation's card types, composer actions and menu slots, and shows the actions.
   */
  private async loadSlots(): Promise<void> {
    try {
      this.slots = await this.host.api.slots(this.summary.uuid);
    } catch {
      return;
    }
    this.cardTray?.setTypes(this.slots.cardTypes);
    this.renderActions();
    if (this.slots.menuSlots.length > 0) {
      this.render();
    }
  }

  /**
   * 2.6: the composer action buttons (in the composer tools, a labelled group).
   */
  private renderActions(): void {
    this.composer.querySelector('[data-part="composer-actions"]')?.remove();
    if (this.slots.composerActions.length === 0) {
      return;
    }
    const group = h('span', { class: 'nowo-chat-composer__actions', role: 'group', 'aria-label': t(this.host.config.i18n, 'composer_actions'), 'data-part': 'composer-actions' });
    for (const action of this.slots.composerActions) {
      const label = action.slash === null ? action.label : `${action.label} (/${action.slash})`;
      const button = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-composer__tool nowo-chat-composer__action', 'aria-label': label, title: label, 'aria-haspopup': 'dialog', 'data-action-key': action.key }, this.host.templates.icon(action.icon, 16));
      button.addEventListener('click', () => this.openAction(action));
      group.append(button);
    }
    const tools = this.composer.querySelector('[data-part="composer-tools"]');
    if (tools !== null) {
      tools.append(group);
    } else {
      this.composer.prepend(group);
    }
  }

  /**
   * 2.6: opens a composer action's host dialog; a card it inserts goes to this composer.
   *
   * @param action The action.
   */
  private openAction(action: ComposerAction): void {
    const settings = this.host.config.slots;
    const url = dialogUrl(action.dialogUrl, { conversation: this.summary.uuid });
    const dialog = settings === undefined || url === null ? null : openSlotDialog(settings, url, action.label, this.element);
    if (dialog === null) {
      setCardTarget(null);
      this.flashNotice(t(this.host.config.i18n, 'error'));
      return;
    }
    // Audit ZCI-I1: only while this dialog is open (cleared when it closes).
    setCardTarget((card: CardRef) => {
      if (this.cardTray?.add(card) === true) {
        this.input.focus();
      }
    }, dialog);
  }

  /**
   * 2.6: the host's message menu slots of a message: the placed items of `message_menu.order` (`slot:<key>`), then a
   * copy of the model item for each slot not placed. Shown on the messages each one fits; a selection is read when the
   * menu opens and handed to the dialog in the browser only.
   *
   * @returns Whether a slot is shown.
   */
  private wireSlots(menu: HTMLElement, article: HTMLElement, message: ChatMessage, usable: boolean, closeKebab: () => void): boolean {
    const slots = this.slots.menuSlots;
    const items = [...menu.querySelectorAll<HTMLElement>('[data-part="menu-slot"]')];
    const model = items.find((item) => (item.dataset.slotKey ?? '') === '');
    if (slots.length === 0 || items.length === 0 || this.host.config.slots === undefined) {
      return false;
    }
    const bubble = article.querySelector('[data-part="bubble"]') ?? article;
    let selection = '';
    const selectionItems: HTMLElement[] = [];
    // A selection slot shows only while text of this message is selected (read when the menu opens).
    const capture = (): void => {
      selection = selectionIn(bubble);
      selectionItems.forEach((item) => {
        item.hidden = !usable || selection.trim() === '';
      });
    };
    article.addEventListener('contextmenu', capture);
    menu.querySelector('summary')?.addEventListener('pointerdown', capture);
    let any = false;
    for (const slot of slots) {
      let item = items.find((candidate) => candidate.dataset.slotKey === slot.key) ?? null;
      if (item === null && model !== undefined) {
        item = model.cloneNode(true) as HTMLElement;
        item.dataset.slotKey = slot.key;
        model.before(item);
      }
      if (item === null) {
        continue;
      }
      const fit = slotFor(slot, message, '');
      item.textContent = slot.label;
      if (slot.target === 'selection') {
        item.hidden = true;
        // Offered when the message fits (own-only); its visibility follows the selection.
        if (usable && (!slot.ownOnly || message.mine)) {
          selectionItems.push(item);
          any = true;
        }
      } else {
        item.hidden = !usable || !fit.shown;
        any ||= !item.hidden;
      }
      item.addEventListener('click', (event) => {
        event.preventDefault();
        closeKebab();
        this.openSlot(slot, message, fit.card === null ? null : `${fit.card.type}:${fit.card.id}`, selection);
      });
    }
    return any;
  }

  /**
   * 2.6: opens a message menu slot's host dialog.
   *
   * @param slot The slot.
   * @param message The message.
   * @param card `type:id` of the card it acts on, or null.
   * @param selection The selected text ('' for none).
   */
  private openSlot(slot: MenuSlot, message: ChatMessage, card: string | null, selection: string): void {
    const settings = this.host.config.slots;
    const url = dialogUrl(slot.dialogUrl, { conversation: this.summary.uuid, message: message.uuid, ...(card === null ? {} : { card }) });
    const dialog = settings === undefined || url === null ? null : openSlotDialog(settings, url, slot.label, this.element);
    if (dialog === null) {
      this.flashNotice(t(this.host.config.i18n, 'error'));
      return;
    }
    if (slot.target === 'selection') {
      sendSlotPayload(dialog, selection);
    }
  }

  /**
   * 2.6: card actions the server marked `data-card-dialog` open their same-origin page in the dock's dialog.
   *
   * @param bubble The rendered message.
   */
  private wireCardActions(bubble: HTMLElement): void {
    const settings = this.host.config.slots;
    if (settings === undefined) {
      return;
    }
    bubble.querySelectorAll<HTMLAnchorElement>('.nowo-chat-card a[data-card-dialog]').forEach((link) => {
      link.setAttribute('aria-haspopup', 'dialog');
      link.addEventListener('click', (event) => {
        if (openSlotDialog(settings, link.getAttribute('href') ?? '', link.textContent ?? '', this.element) !== null) {
          event.preventDefault();
        }
      });
    });
  }

  /**
   * 2.6: the dock root (where host events are dispatched and listened to).
   *
   * @returns The root element.
   */
  /**
   * 2.7 (spec 259): a state of one of the viewer's own files (realtime `attachment` event).
   *
   * @param uuid Attachment uuid.
   * @param state New state.
   */
  attachmentState(uuid: string, state: AttachmentState): void {
    this.attachmentTray?.setState(uuid, state);
  }

  /**
   * 2.7 (D-20261126-11): the extras of a `nowo-chat:before-send`: the dock's inline warning, and "Send as a secret"
   * when the host opted in (configuration flag and the probe answered). The hand-off clears the draft (and its stored
   * copy) unless the host cancels the event; nothing is sent.
   *
   * @param text The text about to be sent.
   * @param kind Where it comes from.
   * @param clear Empties the draft after a hand-off.
   * @param anchor Where the warning goes.
   * @returns The extras.
   */
  private secretExtras(text: string, kind: SendKind, clear: () => void, anchor: HTMLElement): BeforeSendExtras {
    const root = this.dockRoot();
    const handOff = secretHandoffAvailable(root, this.host.config.dock?.secretHandoff === true)
      ? (): void => {
          if (handOffSecret(root, this.summary.uuid, text, kind)) {
            clear();
          }
        }
      : undefined;
    return {
      ...(handOff === undefined ? {} : { handOff }),
      warn: (message, actions): void => {
        showSecretWarning(anchor, this.host.templates, { i18n: this.host.config.i18n, message, handOff: actions.handOff, sendAnyway: actions.sendAnyway, done: () => this.input.focus() });
      },
    };
  }

  /** 2.7: empties the composer and purges its stored draft (a secret handed off is never kept or sent). */
  private clearComposer(): void {
    this.input.value = '';
    this.mention.reset();
    this.reference?.reset();
    this.cardTray?.reset();
    this.host.saveDraft(this.summary.uuid, '');
    this.autoGrow();
    this.updateComposerState();
  }

  private dockRoot(): HTMLElement {
    return this.element.closest<HTMLElement>('[data-nowo-chat-config]') ?? document.querySelector<HTMLElement>('[data-nowo-chat-config]') ?? this.element;
  }

  private updateComposerState(): void {
    const length = this.input.value.trim().length;
    const max = this.host.config.maxLength;
    const cards = this.cardTray?.items.length ?? 0;
    const files = this.attachmentTray?.hasFiles === true ? 1 : 0;
    this.sendBtn.disabled = (length === 0 && cards === 0 && files === 0) || length > max || !this.summary.canSend;
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
 * 1.24: whether the viewer muted the conversation (and the mute has not ended).
 *
 * @param summary The conversation.
 * @returns True while muted.
 */
export function isMuted(summary: ConversationSummary): boolean {
  const until = summary.mutedUntil ?? null;
  return until !== null && Date.parse(until) > Date.now();
}

/**
 * 1.24: whether the message is shown as a centered line (buzz, pin line, system line), outside author groups.
 *
 * @param message The message.
 * @returns True for a line.
 */
export function isLine(message: ChatMessage): boolean {
  return message.kind === 'buzz' || message.kind === 'pin' || message.kind === 'system';
}

/**
 * Text of a server-rendered message: for previews and live announcements, or — with `lines` — for the editor
 * (paragraphs and line breaks become newlines; the Markdown markers are not recovered).
 *
 * @param html Message HTML from the server.
 * @param lines Keep line breaks (editor) instead of one line (previews).
 * @param secretLine 2.6: the translated generic line of a secret card (audit ZCI-L2).
 * @returns The plain text, trimmed.
 */
export function plain(html: string, lines = false, secretLine: string = SECRET_CARD_FALLBACK): string {
  const doc = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/g, '\n').replace(/<\/(p|li|pre)>/g, '\n'), 'text/html');
  // Decorative emojis (support assistant lines) are aria-hidden: previews and the live region read only the text.
  doc.body.querySelectorAll('.nowo-chat-deco').forEach((emoji) => emoji.remove());
  // 2.6: a typed card leaves the editor text (an edit keeps the message's cards on the server); elsewhere (previews,
  // the pins bar, the live region) the generic secret line or `#Title` (audit ZCI-L2).
  doc.body.querySelectorAll('.nowo-chat-card').forEach((card) => {
    card.replaceWith(lines ? '' : cardText(card, secretLine));
  });
  // A #reference card reads `#Title`; the neutral chip of an item the viewer may not open, `[Content not visible]`.
  doc.body.querySelectorAll('.nowo-chat-ref').forEach((chip) => {
    const title = chip.querySelector('.nowo-chat-ref__title')?.textContent ?? '';
    chip.replaceWith(chip.classList.contains('nowo-chat-ref--restricted') ? `[${title}]` : `#${title}`);
  });
  // 1.19: a link card reads its title; the editor gets the `!link[Title](url)` token back, so an edit keeps the card.
  doc.body.querySelectorAll('.nowo-chat-linkcard').forEach((card) => {
    const shown = card.querySelector('.nowo-chat-linkcard__title')?.textContent ?? '';
    const href = card.getAttribute('href') ?? '';
    card.replaceWith(lines ? linkToken(card.getAttribute('data-title') ?? shown, href) : shown !== '' ? shown : href);
  });
  const text = doc.body.textContent ?? '';
  // One line for previews: block boundaries become spaces ("supports:\n- dms" → "supports: dms").
  return (lines ? text.replace(/\n{3,}/g, '\n\n') : text.replace(/\s+/g, ' ')).trim();
}
