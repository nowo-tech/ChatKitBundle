/**
 * The floating chat dock (LinkedIn-style), built from the Twig <template> markup. The "Messaging" panel
 * bottom-right is the launcher and the conversation list; open conversations dock to its left as windows that
 * survive every navigation (DockStore) and stay in step across browser tabs (BroadcastChannel). On phones the
 * launcher opens a full-screen sheet. Everything happens in place: no request reloads or navigates the page.
 */
import { ChatApi } from './api';
import { h, setBadge } from './dom';
import { listTimeLabel, t } from './format';
import { ChatRealtime } from './realtime';
import { PREFS_EVENT } from './prefs';
import { playOnce } from './sounds';
import { ActivityTracker, currentStatus, customStatus, StatusMenu, statusLabel } from './status';
import { clearChatStorage, DockStore, DraftStore, type SyncEvent, ThreadCache } from './state';
import { part, Templates, text } from './templates';
import { plain, ThreadView, type ThreadHost } from './thread';
import type { ChatConfig, ChatMessage, ChatPrefs, Contact, ConversationSummary, LinkPreview, PeerStatus, RealtimeEvent, SoundName, SupportContext } from './types';

type Filter = 'all' | 'unread' | 'channel' | 'support' | 'archived';

/** Options of a dock (1.13). */
export interface ChatDockOptions {
  /**
   * Page mode: the full-page chat element (`[data-nowo-chat-page-mode]` of page.html.twig). The dock's list and one
   * conversation are rendered inline in its two columns instead of floating; null / omitted = the floating dock.
   */
  page?: HTMLElement | null;
}

/** Page mode (1.13): the full-page chat element, its two columns and the conversation shown. */
interface PageMode {
  /** The page element (`[data-nowo-chat-page-mode]`); its server-rendered children are hidden while mounted. */
  element: HTMLElement;
  /** The page-mode container appended to it (list column + thread column). */
  app: HTMLElement;
  thread: HTMLElement;
  /** "Pick a conversation." placeholder of the thread column. */
  empty: HTMLElement;
  /** Server-rendered children hidden by page mode (shown again on destroy()). */
  hidden: HTMLElement[];
  /** Uuid of the conversation shown, or null. */
  active: string | null;
}

/** History state key of the page mode: the conversation of each history entry ("" = none). */
const PAGE_STATE_KEY = 'nowoChatPage';

const MOBILE_QUERY = '(max-width: 768px)';
const WINDOW_WIDTH = 336;
const MINIMISED_WIDTH = 232;
const LIST_WIDTH = 312;
const GAP = 8;

/**
 * The floating dock of one page: launcher, conversation list, people picker and the conversation windows.
 * Hosts the ThreadView windows (ThreadHost) and keeps them in step with the server, realtime and other tabs.
 */
export class ChatDock implements ThreadHost {
  readonly api: ChatApi;
  readonly config: ChatConfig;
  readonly templates: Templates;
  private readonly store: DockStore;
  private readonly cache: ThreadCache;
  private readonly drafts: DraftStore;
  /** Removes every document / window listener on destroy(). */
  private readonly abort = new AbortController();
  /** One tab per browser owns the heartbeat and the list polling (Web Locks; every tab when unsupported). */
  private leader = typeof navigator === 'undefined' || !('locks' in navigator);
  private presenceTimer: number | null = null;
  private readonly conversations = new Map<string, ConversationSummary>();
  /** 1.10: the conversations the user closed (loaded when the "Closed" filter is picked). */
  private readonly archived = new Map<string, ConversationSummary>();
  /** 1.10: "Conversation closed — Undo" notice. */
  private readonly toast: HTMLElement;
  private toastTimer: number | undefined;
  private readonly windows = new Map<string, ThreadView>();
  private filter: Filter = 'all';
  private query = '';
  private mode: 'list' | 'picker' = 'list';
  private contactsQuery: string | null = null;
  private listLoaded = false;
  private pollTimer: number | null = null;
  private tick = 0;
  private mobileActive: string | null = null;
  /** Windows the user just opened (entry motion); restored ones appear without animation. */
  private readonly entering = new Set<string>();
  private realtime: ChatRealtime | null = null;
  private live = false;
  private wasLive = false;
  private statusTimer: number | undefined;
  /** Link previews already asked for (null: nothing to show), shared by every window. */
  private readonly previews = new Map<string, Promise<LinkPreview | null>>();
  private previewBatch: { urls: string[]; resolve: (map: Record<string, LinkPreview>) => void } | null = null;
  /** Conversation uuid → "typing…" timer for the list rows. */
  private readonly listTyping = new Map<string, number>();
  /** 1.10: input in any tab of the browser (auto-away). */
  private readonly activity: ActivityTracker;
  /** 1.10: the user's own status menu; null when the application's templates or API predate it. */
  private readonly statusMenu: StatusMenu | null;

  private readonly root: HTMLElement;
  private readonly dock: HTMLElement;
  private readonly panel: HTMLElement;
  private readonly status: HTMLElement;
  private readonly launcher: HTMLButtonElement;
  private readonly launcherBadge: HTMLElement;
  private readonly launcherChevron: HTMLElement;
  private readonly compose: HTMLButtonElement;
  private readonly panelBody: HTMLElement;
  private readonly listEl: HTMLElement;
  private readonly searchInput: HTMLInputElement;
  private readonly filters: HTMLElement;
  private readonly picker: HTMLElement;
  private readonly pickerInput: HTMLInputElement;
  private readonly pickerResults: HTMLElement;
  private readonly overflow: HTMLElement;
  private readonly overflowBtn: HTMLButtonElement;
  private readonly overflowMenu: HTMLElement;
  private readonly edge: HTMLButtonElement;
  private readonly edgeBadge: HTMLElement;
  private readonly mobileQuery: MediaQueryList;
  /** 1.13: page mode (the full-page chat), or null for the floating dock. */
  private readonly page: PageMode | null;

  /**
   * Builds the dock markup from the templates and binds its events (call start() to load and connect).
   *
   * @param root Dock root holding the `<template>` elements; the dock is appended to it.
   * @param config Dock configuration rendered by Twig.
   * @param options Page mode (1.13): `{ page }` renders the list and the conversation inside the full-page chat.
   */
  constructor(root: HTMLElement, config: ChatConfig, options: ChatDockOptions = {}) {
    this.root = root;
    this.config = config;
    this.templates = new Templates(root);
    this.api = new ChatApi(config);
    this.store = new DockStore(config.userUuid);
    this.cache = new ThreadCache(config.userUuid, config.locale);
    this.drafts = new DraftStore(config.userUuid);
    this.mobileQuery = window.matchMedia(MOBILE_QUERY);

    this.dock = this.templates.clone('dock');
    this.panel = part(this.dock, 'panel');
    this.status = part(this.dock, 'status');
    this.launcher = part<HTMLButtonElement>(this.dock, 'launcher');
    this.launcherBadge = part(this.dock, 'launcher-badge');
    this.launcherChevron = part(this.dock, 'launcher-chevron');
    this.compose = part<HTMLButtonElement>(this.dock, 'compose');
    this.panelBody = part(this.dock, 'panel-body');
    this.listEl = part(this.dock, 'list');
    this.searchInput = part<HTMLInputElement>(this.dock, 'search');
    this.filters = part(this.dock, 'filters');
    this.picker = part(this.dock, 'picker');
    this.pickerInput = part<HTMLInputElement>(this.dock, 'picker-input');
    this.pickerResults = part(this.dock, 'picker-results');
    this.overflow = part(this.dock, 'overflow');
    this.overflowBtn = part<HTMLButtonElement>(this.dock, 'overflow-btn');
    this.overflowMenu = part(this.dock, 'overflow-menu');
    this.edge = part<HTMLButtonElement>(this.templates.clone('edge'), 'edge');
    this.edgeBadge = part(this.edge, 'edge-badge');
    // The templates stay in the root (cloned again for every row / window); the dock and its edge tab go after them.
    root.append(this.dock, this.edge);

    this.toast = h('div', { class: 'nowo-chat-toast', role: 'status', 'data-testid': 'nowo-chat-toast', hidden: true });
    (this.dock.querySelector('[data-part="panel-body"]') ?? this.panel).before(this.toast);
    this.activity = new ActivityTracker(config.userUuid, this.abort.signal);
    this.statusMenu = this.buildStatusMenu();
    this.page = options.page === undefined || options.page === null ? null : this.attachPage(options.page);
    this.bind();
    this.store.subscribe((event) => this.onSync(event));
  }

  /**
   * The user's own status button and its menu (1.10), when the configuration carries a status and the templates
   * have the `me` button and the `status-menu` template.
   *
   * @returns The menu, or null.
   */
  private buildStatusMenu(): StatusMenu | null {
    const trigger = this.dock.querySelector<HTMLButtonElement>('[data-part="me"]');
    if (trigger === null || this.config.status === undefined || this.config.endpoints.status === undefined) {
      return null;
    }
    let popover: HTMLElement;
    try {
      popover = this.templates.clone('status-menu');
    } catch {
      return null;
    }
    trigger.querySelector('[data-part="me-avatar"]')?.replaceChildren(this.templates.avatar(this.config.me?.initials ?? '', 'direct', 'sm'));
    // In the panel's flow under its header (the panel clips anything outside it).
    (trigger.parentElement ?? this.panel).after(popover);
    return new StatusMenu(this, trigger, popover, (status) => (this.config.status = status), this.abort.signal);
  }

  /**
   * Page mode (1.13): moves the list panel into the left column of the full-page chat, keeps one conversation in the
   * right column and hides the server-rendered list and thread (they stay the fallback without JavaScript). The
   * floating dock and its edge tab stay hidden while the page is mounted: one chat on the page, never two.
   *
   * @param element The full-page chat element (`[data-nowo-chat-page-mode]`).
   * @returns The page-mode state.
   */
  private attachPage(element: HTMLElement): PageMode {
    const i18n = this.config.i18n;
    const list = h('div', { class: 'nowo-chat-pagemode__list' });
    const thread = h('div', { class: 'nowo-chat-pagemode__thread' });
    const app = h('div', { class: 'nowo-chat-pagemode', 'data-testid': 'nowo-chat-page-mode' }, list, thread);
    const empty = h('p', { class: 'nowo-chat-pagemode__empty', text: t(i18n, 'page_select') });
    thread.append(empty);
    // The panel keeps its header (status, new message), search, filters, list and people picker; the launcher, the
    // tuck and close buttons belong to the floating dock.
    const title = h('h2', { class: 'nowo-chat-pagemode__title', text: t(i18n, 'launcher') });
    this.launcher.before(title);
    this.launcher.hidden = true;
    this.panel.classList.add('is-open', 'nowo-chat-panel--page');
    list.append(this.panel);
    const hidden = [...element.children].filter((child): child is HTMLElement => child instanceof HTMLElement && !child.hidden);
    hidden.forEach((child) => (child.hidden = true));
    element.classList.add('is-page-mode');
    element.append(app);
    this.root.classList.add('has-page-mode');
    this.dock.hidden = true;
    this.edge.hidden = true;
    const current = element.dataset.nowoChatCurrent ?? '';
    if (element.dataset.nowoChatArchived === '1') {
      this.setFilter('archived');
    }
    // Every history entry of the page knows its conversation: Back / Forward show it again (popstate).
    window.history.replaceState({ ...(window.history.state as object | null), [PAGE_STATE_KEY]: current }, '');
    return { element, app, thread, empty, hidden, active: current === '' ? null : current };
  }

  /**
   * Address of the full-page chat for a conversation (null: the list), with `?archived=1` under the "Closed" filter.
   *
   * @param uuid Conversation uuid, or null.
   * @returns The URL path (and query).
   */
  private pageUrl(uuid: string | null): string {
    const path = this.config.endpoints.page.replace('__uuid__', uuid === null ? '' : encodeURIComponent(uuid));
    return this.filter === 'archived' ? `${path}?archived=1` : path;
  }

  /**
   * Page mode: shows a conversation (or none) and records it in the browser history.
   *
   * @param uuid Conversation uuid, or null for the list alone.
   * @param history "push" adds a history entry, "replace" rewrites the current one, "none" leaves it (popstate).
   */
  private selectPage(uuid: string | null, history: 'push' | 'replace' | 'none'): void {
    const page = this.page as PageMode;
    page.active = uuid;
    if (history !== 'none') {
      const url = this.pageUrl(uuid);
      const state = { ...(window.history.state as object | null), [PAGE_STATE_KEY]: uuid ?? '' };
      if (history === 'replace' || `${window.location.pathname}${window.location.search}` === url) {
        window.history.replaceState(state, '', url);
      } else {
        window.history.pushState(state, '', url);
      }
    }
    this.renderList();
  }

  /**
   * Page mode, Back / Forward: shows the conversation of the history entry.
   *
   * @param event The popstate event.
   */
  private onPopState(event: PopStateEvent): void {
    const uuid = (event.state as Record<string, unknown> | null)?.[PAGE_STATE_KEY];
    if (typeof uuid !== 'string') {
      return;
    }
    this.selectPage(uuid === '' ? null : uuid, 'none');
    this.applyState();
  }

  /** Page mode: the conversation (or the placeholder) in the right column; one column on phones (`has-thread`). */
  private layoutPage(): void {
    const page = this.page as PageMode;
    const view = page.active === null ? undefined : this.windows.get(page.active);
    const shown = view?.element ?? page.empty;
    // A conversation on its way (deep link before the list loads): "Loading", not "Pick a conversation".
    page.empty.textContent = t(this.config.i18n, page.active === null ? 'page_select' : 'loading');
    if (page.thread.firstElementChild !== shown) {
      page.thread.replaceChildren(shown);
    }
    page.app.classList.toggle('has-thread', view !== undefined);
  }

  /**
   * Page mode: a new conversation view is a region of the page, not a floating dialog (no minimise / close / pop-out).
   *
   * @param view The view just created.
   */
  private decoratePageView(view: ThreadView): void {
    view.element.setAttribute('role', 'region');
    view.element.classList.add('nowo-chat-window--page');
    part(view.element, 'identity').removeAttribute('aria-expanded');
  }

  /**
   * Applies preferences changed elsewhere (the application's settings, another tab): sounds, buzz, opening, send key.
   *
   * @param prefs Every preference (ChatUserSettings::preferences()).
   */
  applyPrefs(prefs: ChatPrefs): void {
    Object.assign(this.config.prefs, prefs);
    this.windows.forEach((view) => view.applySendKey());
  }

  /**
   * Whether the user is in "do not disturb" now: no sounds, no buzz opening or shaking, no opening on a message.
   *
   * @returns True in do not disturb.
   */
  isDoNotDisturb(): boolean {
    const status = this.statusMenu?.current ?? (this.config.status === undefined ? null : currentStatus(this.config.status));
    return status?.status === 'dnd';
  }

  /**
   * Opens the user's own status menu (the chat page's status button), bringing the dock back when it is tucked away.
   */
  openStatusMenu(): void {
    if (this.statusMenu === null) {
      return;
    }
    if (this.store.get().tucked) {
      this.setTucked(false, false);
    }
    this.statusMenu.open();
  }

  /** Starts the dock: leader election, restored layout, first list load, `?chat=` link, polling, realtime and presence. */
  async start(): Promise<void> {
    if (!this.leader) {
      // Held until the tab closes; the next tab in line takes over automatically.
      void navigator.locks.request(`nowo-chat-leader:${this.config.userUuid}`, () => {
        this.leader = true;
        return new Promise<void>(() => undefined);
      });
    }
    this.applyState();
    await this.refreshList();
    if (this.filter === 'archived') {
      // Page mode, `/chat/?archived=1`: the "Closed" filter is on from the start.
      await this.loadArchived();
    }
    this.openFromUrl();
    this.schedulePoll();
    this.realtime = new ChatRealtime(
      this.api,
      (event) => this.onRealtime(event),
      (connected) => {
        const reconnected = connected && !this.live;
        const dropped = !connected && this.live;
        // The first connection follows the list start() just loaded: only a real reconnection has anything to catch up.
        const catchUp = reconnected && this.wasLive;
        this.live = connected;
        this.root.classList.toggle('is-live', connected);
        this.showStatus(dropped ? 'reconnecting' : catchUp ? 'reconnected' : null);
        this.wasLive ||= connected;
        if (reconnected || dropped) {
          // Polling cadence follows the connection: the slow safety net while live, pollMs as soon as it drops.
          this.schedulePoll();
        }
        if (catchUp) {
          // Catch up whatever happened while the connection was down.
          void this.poll(true);
        }
      },
    );
    void this.realtime.connect();
    this.startPresence();
  }

  /**
   * Keeps the dock flush with the bottom of the viewport, or just above whatever covers it there: elements the
   * application marks with `data-nowo-chat-avoid` (e.g. its footer — fixed, or static and scrolled into view) and the
   * Symfony web debug toolbar (collapsed, open, wrapped on two rows). Their visible height goes to
   * `--nowo-chat-toolbar-offset`, recomputed when they appear or change, on scroll and on resize.
   *
   * @param signal Stops the observers and listeners when the dock is destroyed.
   */
  private trackBottomBars(signal: AbortSignal): void {
    const measure = (): void => {
      let offset = 0;
      document
        .querySelectorAll<HTMLElement>('[data-nowo-chat-avoid], .sf-toolbar .sf-toolbarreset, .sf-toolbar .sf-minitoolbar')
        .forEach((bar) => {
          const rect = bar.getBoundingClientRect();
          if (rect.height > 0 && rect.width > 0 && rect.top < window.innerHeight && rect.bottom > 0) {
            offset = Math.max(offset, Math.round(window.innerHeight - rect.top));
          }
        });
      this.root.style.setProperty('--nowo-chat-toolbar-offset', `${Math.max(0, offset)}px`);
    };
    let frame = 0;
    const schedule = (): void => {
      window.cancelAnimationFrame(frame);
      frame = window.requestAnimationFrame(measure);
    };
    const bars = new MutationObserver(measure);
    const watch = (): void => {
      bars.disconnect();
      document.querySelectorAll('.sf-toolbar, [data-nowo-chat-avoid]').forEach((element) =>
        bars.observe(element, { attributes: true, attributeFilter: ['class', 'style'], childList: true, subtree: true }),
      );
      measure();
    };
    // The toolbar is injected after the page loads (AJAX): watch the body's direct children for it.
    const body = new MutationObserver(watch);
    body.observe(document.body, { childList: true });
    window.addEventListener('resize', measure, { signal });
    window.addEventListener('scroll', schedule, { signal, passive: true });
    signal.addEventListener('abort', () => {
      window.cancelAnimationFrame(frame);
      bars.disconnect();
      body.disconnect();
    });
    watch();
  }

  /** Stop everything (page swap): connection, timers and listeners. */
  destroy(): void {
    this.abort.abort();
    this.store.close();
    this.realtime?.stop();
    window.clearTimeout(this.pollTimer ?? undefined);
    window.clearInterval(this.presenceTimer ?? undefined);
    window.clearTimeout(this.statusTimer);
    window.clearTimeout(this.toastTimer);
    this.dock.remove();
    this.edge.remove();
    if (this.page !== null) {
      // The server-rendered page comes back (a host that unmounts without leaving the page).
      this.page.app.remove();
      this.page.hidden.forEach((child) => (child.hidden = false));
      this.page.element.classList.remove('is-page-mode');
      this.root.classList.remove('has-page-mode');
    }
  }

  /**
   * A conversation summary changed (resolved, renamed…): update the window and the list.
   *
   * @param summary Fresh summary from the server.
   */
  conversationChanged(summary: ConversationSummary): void {
    this.conversations.set(summary.uuid, summary);
    this.windows.get(summary.uuid)?.applySummary(summary);
    this.renderList();
  }

  /**
   * Preview of a link for this viewer, batched (one request per tick, at most 10 links) and cached.
   *
   * @param url Link found in a message.
   * @returns The preview, or null when the application shows none (or the request failed).
   */
  linkPreview(url: string): Promise<LinkPreview | null> {
    let pending = this.previews.get(url);
    if (pending === undefined) {
      const batch = this.previewBatch ?? this.startPreviewBatch();
      batch.urls.push(url);
      pending = new Promise<Record<string, LinkPreview>>((resolve) => {
        const previous = batch.resolve;
        batch.resolve = (map) => {
          previous(map);
          resolve(map);
        };
      }).then((map) => map[url] ?? null);
      this.previews.set(url, pending);
    }
    return pending;
  }

  /**
   * Opens the batch of link previews of this tick; it is sent in chunks of ten on the next microtask.
   *
   * @returns The batch (its URLs and the resolver that delivers the merged answers).
   */
  private startPreviewBatch(): { urls: string[]; resolve: (map: Record<string, LinkPreview>) => void } {
    const batch = { urls: [] as string[], resolve: (_map: Record<string, LinkPreview>): void => undefined };
    this.previewBatch = batch;
    queueMicrotask(() => {
      this.previewBatch = null;
      const chunks: string[][] = [];
      for (let i = 0; i < batch.urls.length; i += 10) {
        chunks.push(batch.urls.slice(i, i + 10));
      }
      void Promise.all(chunks.map((urls) => this.api.linkPreviews(urls).catch(() => ({}))))
        .then((maps) => batch.resolve(Object.assign({}, ...maps)));
    });
    return batch;
  }

  /**
   * Whether the phone layout (full-screen sheet) applies.
   *
   * @returns True below the mobile breakpoint.
   */
  isMobile(): boolean {
    return this.mobileQuery.matches;
  }

  /**
   * Stored draft of a conversation.
   *
   * @param uuid Conversation uuid.
   * @returns The draft text ("" when none).
   */
  draft(uuid: string): string {
    return this.drafts.get(uuid);
  }

  /**
   * Stores the draft of a conversation (a blank value removes it).
   *
   * @param uuid Conversation uuid.
   * @param value Composer text.
   */
  saveDraft(uuid: string, value: string): void {
    this.drafts.set(uuid, value);
  }

  /**
   * Minimises or expands a window (shared with the other tabs); on phones, leaves or shows the full-screen thread.
   *
   * @param uuid Conversation uuid.
   * @param minimised True to minimise, false to expand.
   */
  minimise(uuid: string, minimised: boolean): void {
    if (this.page !== null) {
      // Page mode: the conversation is part of the page, it does not fold.
      return;
    }
    if (this.isMobile()) {
      this.mobileActive = minimised ? null : uuid;
      this.applyState();
      return;
    }
    this.store.update((state) => {
      const tab = state.tabs.find((item) => item.uuid === uuid);
      if (tab !== undefined) {
        tab.minimised = minimised;
      }
    });
    this.applyState();
  }

  /**
   * Closes a window (in every tab); focus moves to the next window or the launcher when it had it.
   *
   * @param uuid Conversation uuid.
   */
  close(uuid: string): void {
    if (this.page !== null) {
      this.closePage(uuid);
      return;
    }
    if (this.isMobile()) {
      this.mobileActive = null;
      this.applyState();
      return;
    }
    const hadFocus = this.windows.get(uuid)?.element.contains(document.activeElement) === true;
    this.store.update((state) => {
      state.tabs = state.tabs.filter((item) => item.uuid !== uuid);
    });
    this.applyState();
    if (hadFocus) {
      // The window is gone: hand focus to the next window, else to the launcher.
      const next = [...this.windows.values()].find((view) => !view.element.hidden);
      if (next !== undefined) {
        next.focusHeader();
      } else {
        this.launcher.focus();
      }
    }
  }

  /**
   * Page mode: back to the list alone (the phone layout's back button, a closed or unreachable conversation); focus
   * goes to that conversation's row, else to the search, when the conversation had it.
   *
   * @param uuid Conversation uuid.
   */
  private closePage(uuid: string): void {
    const page = this.page as PageMode;
    if (page.active !== uuid) {
      return;
    }
    const hadFocus = this.windows.get(uuid)?.element.contains(document.activeElement) === true;
    this.selectPage(null, 'push');
    this.applyState();
    if (hadFocus) {
      const row = this.listEl.querySelector<HTMLElement>(`[data-uuid="${CSS.escape(uuid)}"]`);
      (row ?? this.searchInput).focus({ preventScroll: true });
    }
  }

  /**
   * Opens another conversation in place of a window (a support draft replaced by the ticket it continues). The
   * server moved the draft's messages into the ticket: both cached threads are dropped, an open ticket window
   * reloads its messages, the draft row leaves the list and the list is fetched again.
   *
   * @param from Uuid of the window to replace.
   * @param summary Conversation to open.
   */
  replaceWith(from: string, summary: ConversationSummary): void {
    this.cache.remove(from);
    this.cache.remove(summary.uuid);
    this.conversations.delete(from);
    void this.windows.get(summary.uuid)?.reload();
    void this.refreshList();
    if (this.page === null && !this.isMobile()) {
      this.store.update((state) => {
        state.tabs = state.tabs.filter((item) => item.uuid !== from);
      });
    }
    // Page mode: the draft's address is gone, the ticket takes its history entry.
    void this.open(summary, true, true);
  }

  /**
   * A window's messages changed: update the cache and the list row, count unread on a minimised window and share
   * the new messages with the other tabs.
   *
   * @param uuid Conversation uuid.
   * @param messages Every message of the thread, oldest first.
   * @param hasMore Whether older messages exist on the server.
   * @param fresh Messages that were not there before (sent to the other tabs).
   */
  messagesChanged(uuid: string, messages: ChatMessage[], hasMore: boolean, fresh: ChatMessage[]): void {
    this.cache.write(uuid, messages, hasMore);
    const last = messages.at(-1);
    const summary = this.conversations.get(uuid);
    if (summary !== undefined && last !== undefined) {
      summary.lastMessageAt = last.createdAt;
      summary.lastMessage = { preview: plain(last.html).slice(0, 90), deleted: last.deleted, buzz: last.kind === 'buzz', pin: last.kind === 'pin', mine: last.mine, authorName: last.authorName };
      const view = this.windows.get(uuid);
      if (view !== undefined && view.isMinimised) {
        summary.unread += fresh.filter((message) => !message.mine).length;
        view.applySummary(summary);
      }
      this.renderList();
    }
    if (fresh.length > 0) {
      this.store.broadcast({ type: 'messages', uuid, messages: fresh });
    }
  }

  /**
   * A window was read: clear its unread count, tell the other tabs and the server.
   *
   * @param uuid Conversation uuid.
   * @param messageId Newest message id seen.
   */
  read(uuid: string, messageId: number): void {
    const summary = this.conversations.get(uuid);
    if (summary !== undefined) {
      summary.unread = 0;
      this.renderList();
    }
    this.store.broadcast({ type: 'read', uuid, messageId });
    void this.api.markRead(uuid, messageId).catch(() => undefined);
  }

  /**
   * Open (or focus) a conversation window; from the list, the picker or another browser tab.
   *
   * @param summary Conversation to open (merged into the known summary).
   * @param focus Whether to focus the composer of the window.
   * @param replace Page mode: rewrite the current history entry instead of adding one.
   */
  async open(summary: ConversationSummary, focus = true, replace = false): Promise<void> {
    this.conversations.set(summary.uuid, { ...this.conversations.get(summary.uuid), ...summary });
    // Opening a conversation (list, link, push) brings a tucked dock back.
    this.setTucked(false, false);
    if (this.page !== null) {
      // Page mode: the conversation takes the right column and the address (`/chat/{uuid}`).
      this.selectPage(summary.uuid, replace ? 'replace' : 'push');
    } else {
      if (!this.windows.has(summary.uuid)) {
        this.entering.add(summary.uuid);
      }
      if (this.isMobile()) {
        this.mobileActive = summary.uuid;
      } else {
        this.store.update((state) => {
          state.tabs = [{ uuid: summary.uuid, minimised: false }, ...state.tabs.filter((item) => item.uuid !== summary.uuid)];
        });
      }
    }
    this.closePicker();
    this.applyState();
    if (focus) {
      this.windows.get(summary.uuid)?.focusComposer();
    }
  }

  /**
   * Wires the panel controls and the page-level listeners (links, logout, keyboard, resize, visibility); the
   * document / window ones are removed by destroy().
   */
  private bind(): void {
    const signal = this.abort.signal;
    this.launcher.addEventListener('click', () => this.toggleList());
    part(this.panel, 'close-panel').addEventListener('click', () => {
      this.toggleList(false);
      this.launcher.focus();
    });
    this.compose.addEventListener('click', () => this.openPicker());
    part(this.panel, 'tuck').addEventListener('click', () => this.setTucked(true));
    this.edge.addEventListener('click', () => this.setTucked(false));
    part(this.panel, 'picker-back').addEventListener('click', () => {
      this.closePicker();
      this.compose.focus();
    });
    this.filters.querySelectorAll<HTMLButtonElement>('[data-filter]').forEach((chip) => {
      chip.addEventListener('click', () => {
        this.setFilter((chip.dataset.filter ?? 'all') as Filter);
        if (this.filter === 'archived') {
          void this.loadArchived();
        }
        if (this.page !== null) {
          // Page mode: the address follows the "Closed" filter (`?archived=1`) without a new history entry.
          this.selectPage(this.page.active, 'replace');
        }
        this.renderList();
      });
    });
    this.panel.querySelector('[data-part="archive-read"]')?.addEventListener('click', () => void this.archiveRead());
    this.searchInput.addEventListener('input', () => {
      this.query = this.searchInput.value.trim().toLowerCase();
      this.renderList();
    });
    let pickerTimer: number | undefined;
    this.pickerInput.addEventListener('input', () => {
      window.clearTimeout(pickerTimer);
      pickerTimer = window.setTimeout(() => void this.loadContacts(this.pickerInput.value.trim()), 200);
    });
    this.panel.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }
      event.preventDefault();
      if (this.mode === 'picker') {
        this.closePicker();
        this.compose.focus();
      } else if (this.page === null) {
        this.toggleList(false);
        this.launcher.focus();
      }
    });
    this.overflowBtn.addEventListener('click', () => this.toggleOverflow(this.overflowMenu.hasAttribute('hidden')));
    this.overflow.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !this.overflowMenu.hidden) {
        event.stopPropagation();
        this.toggleOverflow(false);
        this.overflowBtn.focus();
      }
    });
    document.addEventListener(
      'click',
      (event) => {
        if (!this.overflow.contains(event.target as Node)) {
          this.toggleOverflow(false);
        }
        // Signing out on a shared device: forget drafts and cached messages.
        if ((event.target as Element | null)?.closest?.('a[href*="/logout"]')) {
          clearChatStorage();
        }
      },
      { signal },
    );
    // 1.10: a status button elsewhere on the page (the chat page's header) opens the dock's status menu; the
    // application's settings form tells the dock about changed preferences with a `nowo-chat:prefs` event.
    document.addEventListener(
      'click',
      (event) => {
        if ((event.target as Element | null)?.closest?.('[data-nowo-chat-status-open]')) {
          event.preventDefault();
          // The same click must not close the menu it opens (document click outside the popover).
          event.stopPropagation();
          this.openStatusMenu();
        }
      },
      { signal, capture: true },
    );
    // 1.10: the full-page chat's Close / Reopen buttons (data-nowo-chat-archive / -unarchive="<uuid>").
    document.addEventListener(
      'click',
      (event) => {
        const button = (event.target as Element | null)?.closest?.<HTMLElement>('[data-nowo-chat-archive], [data-nowo-chat-unarchive]');
        if (button === null || button === undefined) {
          return;
        }
        const archive = button.hasAttribute('data-nowo-chat-archive');
        const uuid = (archive ? button.dataset.nowoChatArchive : button.dataset.nowoChatUnarchive) ?? '';
        void this.api
          .archive(uuid, archive)
          .then(() => button.closest('li')?.remove())
          .catch(() => this.showStatus('error'));
      },
      { signal },
    );
    document.addEventListener(PREFS_EVENT, (event) => this.applyPrefs((event as CustomEvent<ChatPrefs>).detail), { signal });
    // Links anywhere on the page: data-nowo-chat-open="support" (data-nowo-chat-context-url / -label),
    // "user:<public id>", "channel:<key>" or "conversation:<uuid>" open that conversation in the dock.
    document.addEventListener(
      'click',
      (event) => {
        const trigger = (event.target as Element | null)?.closest?.<HTMLElement>('[data-nowo-chat-open]');
        if (trigger === null || trigger === undefined) {
          return;
        }
        event.preventDefault();
        void this.openTarget(trigger).catch(() => this.showStatus('error'));
      },
      { signal },
    );
    document.addEventListener(
      'submit',
      (event) => {
        if ((event.target as HTMLFormElement).action.includes('/logout')) {
          clearChatStorage();
        }
      },
      { signal },
    );
    // Alt+↑ / Alt+↓ move between open windows; "/" (outside a text field) focuses the search. Page mode: the panel
    // and the conversation live in the page, outside the root.
    const onKey = (event: KeyboardEvent): void => {
      if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
        const views = [...this.windows.values()].filter((view) => !view.element.hidden);
        if (views.length === 0) {
          return;
        }
        event.preventDefault();
        const current = views.findIndex((view) => view.element.contains(document.activeElement));
        (views[(current + (event.key === 'ArrowUp' ? 1 : views.length - 1)) % views.length] as ThreadView).focusHeader();
        return;
      }
      const target = event.target as HTMLElement;
      if (event.key === '/' && !(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) && this.panel.contains(target)) {
        event.preventDefault();
        if (this.page === null && !this.store.get().listOpen) {
          this.toggleList(true);
        }
        this.searchInput.focus();
      }
    };
    this.root.addEventListener('keydown', onKey, { signal });
    if (this.page !== null) {
      this.page.app.addEventListener('keydown', onKey, { signal });
      window.addEventListener('popstate', (event) => this.onPopState(event), { signal });
    }
    window.addEventListener('resize', () => this.layout(), { signal });
    this.trackBottomBars(signal);
    // Mobile keyboard: size the full-screen conversation to the visible area so the composer stays above it.
    window.visualViewport?.addEventListener(
      'resize',
      () => this.root.style.setProperty('--nowo-chat-vvh', `${window.visualViewport?.height ?? window.innerHeight}px`),
      { signal },
    );
    this.mobileQuery.addEventListener('change', () => this.applyState(), { signal });
    document.addEventListener(
      'visibilitychange',
      () => {
        if (document.visibilityState === 'visible') {
          // Messages that arrived while the tab was hidden become read once the user is back.
          this.windows.forEach((view) => view.markVisibleRead());
          void this.poll(true);
        }
      },
      { signal },
    );
  }

  /**
   * Opens the conversation a `data-nowo-chat-open` link points to ("support", "user:<id>", "channel:<key>",
   * "conversation:<uuid>"); other values are ignored.
   *
   * @param trigger The clicked element.
   */
  private async openTarget(trigger: HTMLElement): Promise<void> {
    const target = trigger.dataset.nowoChatOpen ?? '';
    if (target === 'support') {
      const context: SupportContext = {};
      if (trigger.dataset.nowoChatContextUrl !== undefined) {
        context.url = trigger.dataset.nowoChatContextUrl;
      }
      if (trigger.dataset.nowoChatContextLabel !== undefined) {
        context.label = trigger.dataset.nowoChatContextLabel;
      }
      await this.open(await this.api.openSupport(Object.keys(context).length > 0 ? context : undefined));
    } else if (target.startsWith('user:')) {
      await this.open(await this.api.openDirect(target.slice(5)));
    } else if (target.startsWith('channel:')) {
      await this.open(await this.api.openChannel(target.slice(8)));
    } else if (target.startsWith('conversation:')) {
      await this.open(await this.api.conversation(target.slice(13)));
    }
  }

  /**
   * Picks a list filter and marks its chip pressed.
   *
   * @param filter The filter.
   */
  private setFilter(filter: Filter): void {
    this.filter = filter;
    this.filters.querySelectorAll('[data-filter]').forEach((element) => element.setAttribute('aria-pressed', element.getAttribute('data-filter') === filter ? 'true' : 'false'));
  }

  /**
   * Shows or hides the "+N" menu of windows that do not fit.
   *
   * @param open True to show it.
   */
  private toggleOverflow(open: boolean): void {
    this.overflowMenu.hidden = !open;
    this.overflowBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  /**
   * "Reconnecting…" while the live connection is down, a short "Back online" when it returns.
   *
   * @param key Status message key; null hides the status. Every key except "reconnecting" hides itself after a few seconds.
   */
  private showStatus(key: 'reconnecting' | 'reconnected' | 'error' | null): void {
    window.clearTimeout(this.statusTimer);
    if (key === null) {
      this.status.hidden = true;
      return;
    }
    this.status.hidden = false;
    this.status.textContent = t(this.config.i18n, key);
    this.status.classList.toggle('is-ok', key === 'reconnected');
    if (key !== 'reconnecting') {
      this.statusTimer = window.setTimeout(() => (this.status.hidden = true), key === 'error' ? 4_000 : 2_500);
    }
  }

  /** Push notification click: `?chat=<uuid>` opens that conversation, then the parameter is dropped. */
  private openFromUrl(): void {
    const url = new URL(window.location.href);
    const uuid = url.searchParams.get('chat');
    if (uuid === null || !/^[0-9a-f-]{36}$/.test(uuid)) {
      return;
    }
    url.searchParams.delete('chat');
    window.history.replaceState(window.history.state, '', url.toString());
    const known = this.conversations.get(uuid);
    if (known !== undefined) {
      void this.open(known, true, true);
      return;
    }
    void this.api.conversation(uuid).then((summary) => this.open(summary, true, true)).catch(() => undefined);
  }

  /**
   * Fetches a message as the viewer sees it (its #reference cards) and repaints it in its open window.
   *
   * @param conversationUuid Conversation of the message.
   * @param messageUuid The message.
   */
  private resolveReferences(conversationUuid: string, messageUuid: string): void {
    void this.api
      .message(conversationUuid, messageUuid)
      .then((resolved) => this.windows.get(conversationUuid)?.update(resolved))
      .catch(() => undefined);
  }

  /**
   * Applies a realtime event: new / updated messages, read cursors, typing, or a changed conversation.
   *
   * @param event Event from the user's private topic.
   */
  private onRealtime(event: RealtimeEvent): void {
    if (event.type === 'status') {
      // Reciprocal presence: someone who hides their own presence sees no dots, only the custom text.
      this.applyPeerStatuses({ [event.userUuid]: { status: this.config.prefs.presence ? event.status : 'offline', text: event.text, emoji: event.emoji } });
      return;
    }
    if (event.type === 'my_status') {
      this.config.status = event.status;
      this.statusMenu?.apply(event.status);
      return;
    }
    if (event.type === 'prefs') {
      this.applyPrefs(event.prefs);
      return;
    }
    if (event.type === 'pins') {
      void this.windows.get(event.conversationUuid)?.loadPins();
      return;
    }
    if (event.type === 'list') {
      // Closed or reopened in another tab.
      void this.refreshList(true);
      if (this.filter === 'archived') {
        void this.loadArchived();
      }
      return;
    }
    if (event.type === 'message' || event.type === 'message_updated') {
      const message: ChatMessage = { ...event.message, mine: event.message.authorUuid === this.config.userUuid };
      if (message.refsPending === true) {
        // One payload for every member: its #reference cards are resolved for this viewer by the server.
        this.resolveReferences(event.conversationUuid, message.uuid);
      }
      if (event.type === 'message_updated') {
        this.windows.get(event.conversationUuid)?.update(message);
        return;
      }
      this.listTyping.delete(event.conversationUuid);
      const view = this.windows.get(event.conversationUuid);
      const summary = this.conversations.get(event.conversationUuid);
      if (view !== undefined) {
        view.merge([message], true);
      } else if (summary === undefined) {
        void this.refreshList();
      } else {
        summary.lastMessageAt = message.createdAt;
        summary.lastMessage = { preview: plain(message.html).slice(0, 90), deleted: false, buzz: message.kind === 'buzz', pin: message.kind === 'pin', mine: message.mine, authorName: message.authorName };
        summary.unread += message.mine ? 0 : 1;
        this.renderList();
      }
      // A "pinned a message" line rings nothing.
      // An automatic reply rings nothing either (1.11).
      if (!message.mine && message.kind !== 'pin' && message.kind !== 'auto') {
        this.alert(event.conversationUuid, message);
      }
      return;
    }
    if (event.type === 'read') {
      if (event.userUuid === this.config.userUuid) {
        // Read on another device / tab.
        const summary = this.conversations.get(event.conversationUuid);
        if (summary !== undefined) {
          summary.unread = 0;
          this.renderList();
        }
        this.windows.get(event.conversationUuid)?.markReadLocally(event.messageId);
      } else {
        this.windows.get(event.conversationUuid)?.readBy(event.userUuid, event.userName, event.messageId);
      }
      return;
    }
    if (event.type === 'typing') {
      this.windows.get(event.conversationUuid)?.typingBy(event.userUuid, event.userName);
      window.clearTimeout(this.listTyping.get(event.conversationUuid));
      this.listTyping.set(
        event.conversationUuid,
        window.setTimeout(() => {
          this.listTyping.delete(event.conversationUuid);
          this.renderList();
        }, 5_000),
      );
      this.renderList();
      return;
    }
    void this.api.conversation(event.conversationUuid).then((summary) => this.conversationChanged(summary)).catch(() => undefined);
  }

  /**
   * Sound / buzz for an incoming message, once across tabs, following the user's preferences (1.10): the sound of
   * the event (message, @mention, support reply, buzz) at their volume, only in the background when they asked so,
   * the window opened on a message or a buzz when they asked so — and nothing but the badge in "do not disturb".
   *
   * @param uuid Conversation uuid.
   * @param message The incoming message (a buzz also opens and shakes its window).
   */
  private alert(uuid: string, message: ChatMessage): void {
    const prefs = this.config.prefs;
    const view = this.windows.get(uuid);
    const summary = this.conversations.get(uuid);
    const dnd = this.isDoNotDisturb();
    const background = document.visibilityState !== 'visible' || !document.hasFocus();
    const audible = !dnd && (prefs.backgroundOnly !== true || background);
    const volume = prefs.volume ?? 100;
    if (message.kind === 'buzz') {
      if (!prefs.buzz || dnd) {
        return;
      }
      // Page mode never switches the conversation the user is reading on its own.
      if (this.page === null && (view === undefined || view.isMinimised) && summary !== undefined && prefs.buzzOpenWindow !== false) {
        void this.open(summary, false);
      }
      window.setTimeout(() => this.windows.get(uuid)?.shake(), 120);
      if (audible) {
        playOnce(`buzz-${message.id}`, prefs.sounds?.buzz ?? 'buzz', volume);
      }
      return;
    }
    if (!dnd && this.page === null && prefs.openOnMessage === true && view === undefined && summary !== undefined) {
      void this.open(summary, false);
    }
    const inFront = !background && view !== undefined && !view.isMinimised;
    if (!inFront && audible) {
      playOnce(`msg-${message.id}`, this.soundOf(summary, message), volume);
    }
  }

  /**
   * The sound of an incoming message: @mention, support reply or plain message (one sound before 1.10).
   *
   * @param summary Its conversation, when known.
   * @param message The message.
   * @returns The sound name.
   */
  private soundOf(summary: ConversationSummary | undefined, message: ChatMessage): SoundName {
    const sounds = this.config.prefs.sounds;
    if (sounds === undefined) {
      return this.config.prefs.sound;
    }
    if (message.mentionsMe === true) {
      return sounds.mention;
    }
    return summary?.kind === 'support' ? sounds.support : sounds.message;
  }

  /**
   * Applies what the viewer sees of people's statuses to their direct conversations (list row, window header).
   *
   * @param statuses Status by public id.
   * @param online Public ids online (presence answer); undefined = derive from the statuses.
   */
  private applyPeerStatuses(statuses: Record<string, PeerStatus>, online?: Set<string>): void {
    let changed = false;
    for (const summary of this.conversations.values()) {
      if (summary.kind !== 'direct' || summary.peerUuid === null) {
        continue;
      }
      const status = statuses[summary.peerUuid];
      if (status === undefined && online === undefined) {
        continue;
      }
      const next = {
        peerOnline: online === undefined ? status?.status !== 'offline' : online.has(summary.peerUuid),
        peerStatus: status?.status ?? summary.peerStatus,
        peerStatusText: status === undefined ? summary.peerStatusText : status.text,
        peerStatusEmoji: status === undefined ? summary.peerStatusEmoji : status.emoji,
      };
      if (
        next.peerOnline !== summary.peerOnline ||
        next.peerStatus !== summary.peerStatus ||
        next.peerStatusText !== summary.peerStatusText ||
        next.peerStatusEmoji !== summary.peerStatusEmoji
      ) {
        Object.assign(summary, next);
        this.windows.get(summary.uuid)?.applySummary(summary);
        changed = true;
      }
    }
    if (changed) {
      this.renderList();
    }
  }

  /**
   * Heartbeat every 30 s (visible or not, idle or not); the answer lights the status dots of direct contacts and
   * refreshes the user's own status (an expired one is back to available).
   */
  private startPresence(): void {
    /** One heartbeat (followers skip it while hidden): updates the dots and statuses that changed. */
    const beat = (): void => {
      if (document.visibilityState !== 'visible' && !this.leader) {
        return;
      }
      void this.api
        .presence(document.visibilityState === 'visible', this.activity.isIdle(this.config.autoAwayMinutes ?? 0))
        .then(({ online, statuses, me }) => {
          this.applyPeerStatuses(statuses, new Set(online));
          if (me !== null && this.config.status !== undefined) {
            this.config.status = me;
            this.statusMenu?.apply(me);
          }
        })
        .catch(() => undefined);
    };
    beat();
    this.presenceTimer = window.setInterval(beat, 30_000);
    document.addEventListener(
      'visibilitychange',
      () => {
        if (document.visibilityState === 'visible') {
          beat();
        }
      },
      { signal: this.abort.signal },
    );
  }

  /**
   * Opens or closes the conversation list (shared layout); opening focuses the search.
   *
   * @param force True to open, false to close; toggles when omitted.
   */
  private toggleList(force?: boolean): void {
    if (this.page !== null) {
      // Page mode: the list is always shown.
      return;
    }
    const open = force ?? !this.store.get().listOpen;
    this.store.update((state) => {
      state.listOpen = open;
    });
    if (!open) {
      this.closePicker();
    }
    this.applyState();
    if (open) {
      this.playEntry(this.panel);
      this.searchInput.focus({ preventScroll: true });
    }
  }

  /**
   * Tucks the whole dock (list and windows) into a tab on the right edge, or brings it back as it was (shared
   * layout: every tab of the browser follows). Focus moves to the control that stays visible.
   *
   * @param tucked True to tuck the dock away, false to show it again.
   * @param moveFocus Whether to move focus (false when a conversation is being opened, which focuses its window).
   */
  setTucked(tucked: boolean, moveFocus = true): void {
    if (this.page !== null || this.store.get().tucked === tucked) {
      return;
    }
    this.store.update((state) => {
      state.tucked = tucked;
    });
    this.applyState();
    if (!moveFocus) {
      return;
    }
    if (tucked) {
      this.edge.focus({ preventScroll: true });
    } else {
      this.playEntry(this.panel);
      this.launcher.focus({ preventScroll: true });
    }
  }

  /**
   * Plays the entry animation (CSS `is-entering`) on a panel or window.
   *
   * @param element Element that just appeared.
   */
  private playEntry(element: HTMLElement): void {
    element.classList.add('is-entering');
    window.setTimeout(() => element.classList.remove('is-entering'), 260);
  }

  /** Switches the panel to the people picker (opening the list first) and loads the suggestions. */
  private openPicker(): void {
    if (!this.store.get().listOpen) {
      this.toggleList(true);
    }
    this.mode = 'picker';
    this.picker.hidden = false;
    this.panelBody.classList.add('is-picking');
    this.pickerInput.value = '';
    void this.loadContacts('');
    this.pickerInput.focus({ preventScroll: true });
  }

  /** Back from the people picker to the conversation list. */
  private closePicker(): void {
    if (this.mode !== 'picker') {
      return;
    }
    this.mode = 'list';
    this.picker.hidden = true;
    this.panelBody.classList.remove('is-picking');
  }

  /** Apply the shared layout: which windows exist, which are minimised, list open, mobile sheet. */
  private applyState(): void {
    const state = this.store.get();
    const mobile = this.isMobile();
    const page = this.page;
    this.root.classList.toggle('is-mobile', mobile);
    if (page === null) {
      // Page mode leaves the shared layout of the floating dock (other tabs, other pages) untouched.
      this.root.classList.toggle('is-tucked', state.tucked);
      this.dock.inert = state.tucked;
      this.edge.hidden = !state.tucked;
      this.panel.classList.toggle('is-open', state.listOpen);
      this.launcher.setAttribute('aria-expanded', state.listOpen ? 'true' : 'false');
      this.launcherChevron.replaceChildren(this.templates.icon(state.listOpen ? 'chevronDown' : 'chevronUp', 16));
    }

    const single = page === null ? this.mobileActive : page.active;
    const wanted = page !== null || mobile ? (single !== null ? [{ uuid: single, minimised: false }] : []) : state.tabs;
    const wantedIds = new Set(wanted.map((tab) => tab.uuid));
    const created: ThreadView[] = [];
    for (const [uuid, view] of this.windows) {
      if (!wantedIds.has(uuid)) {
        view.element.remove();
        this.windows.delete(uuid);
      }
    }
    for (const tab of wanted) {
      let view = this.windows.get(tab.uuid);
      if (view === undefined) {
        const summary = this.conversations.get(tab.uuid);
        if (summary === undefined) {
          if (this.listLoaded) {
            void this.api
              .conversation(tab.uuid)
              .then((loaded) => {
                this.conversations.set(loaded.uuid, loaded);
                this.applyState();
              })
              .catch(() => this.close(tab.uuid));
          }
          continue;
        }
        view = new ThreadView(this, summary);
        created.push(view);
        if (this.entering.delete(tab.uuid)) {
          this.playEntry(view.element);
        }
        this.windows.set(tab.uuid, view);
        view.hydrate(this.cache.read(tab.uuid));
        void view.load();
      }
      view.setMinimised(tab.minimised);
      if (page !== null && created.includes(view)) {
        this.decoratePageView(view);
      }
    }
    this.cache.prune([...wantedIds]);
    this.root.classList.toggle('has-mobile-thread', page === null && mobile && this.mobileActive !== null);
    this.layout();
    // New windows are attached now: show their latest messages.
    created.forEach((view) => view.scrollToLatest());
    this.renderBadge();
  }

  /** Order windows right-to-left next to the panel; whatever does not fit goes to the "+N" menu. */
  private layout(): void {
    if (this.page !== null) {
      this.layoutPage();
      return;
    }
    if (this.isMobile()) {
      for (const view of this.windows.values()) {
        view.element.hidden = false;
        if (view.element.parentElement !== this.dock) {
          this.dock.append(view.element);
        }
      }
      this.overflow.hidden = true;
      return;
    }
    let available = window.innerWidth - LIST_WIDTH - GAP * 3 - 48;
    const hidden: string[] = [];
    // Insert after the panel (DOM order = visual order in a row-reverse flex container).
    let anchor: Element = this.panel;
    for (const tab of this.store.get().tabs) {
      const view = this.windows.get(tab.uuid);
      if (view === undefined) {
        continue;
      }
      const width = (tab.minimised ? MINIMISED_WIDTH : WINDOW_WIDTH) + GAP;
      if (available - width < 0) {
        view.element.hidden = true;
        hidden.push(tab.uuid);
        continue;
      }
      available -= width;
      view.element.hidden = false;
      placeAfter(anchor, view.element);
      anchor = view.element;
    }
    this.overflow.hidden = hidden.length === 0;
    placeAfter(anchor, this.overflow);
    this.overflowBtn.textContent = t(this.config.i18n, 'more_tabs', { count: hidden.length });
    this.overflowMenu.replaceChildren(
      ...hidden.map((uuid) => {
        const summary = this.conversations.get(uuid) as ConversationSummary;
        const item = this.templates.clone('overflow-item');
        part(item, 'avatar').replaceWith(this.templates.avatar(summary.initials, summary.kind, 'sm'));
        text(item, 'title', summary.title);
        part(item, 'row').addEventListener('click', () => {
          this.toggleOverflow(false);
          void this.open(summary);
        });
        return item;
      }),
    );
  }

  /**
   * Reloads the conversation list: updates open windows (catching up the ones whose last message changed),
   * toggles the offline state, then renders the list and the layout.
   */
  private async refreshList(prune = false): Promise<void> {
    try {
      const { conversations } = await this.api.conversations();
      if (prune) {
        // Closed elsewhere: out of the list (a window still open keeps its summary).
        const listed = new Set(conversations.map((summary) => summary.uuid));
        [...this.conversations.keys()].filter((uuid) => !listed.has(uuid) && !this.windows.has(uuid)).forEach((uuid) => this.conversations.delete(uuid));
      }
      for (const summary of conversations) {
        const known = this.conversations.get(summary.uuid);
        this.conversations.set(summary.uuid, summary);
        const view = this.windows.get(summary.uuid);
        if (view !== undefined) {
          // The server count stays until this tab actually marks the window read (read()).
          view.applySummary(summary);
          if (known !== undefined && summary.lastMessageAt !== known.lastMessageAt) {
            void view.catchUp().catch(() => undefined);
          }
        }
      }
      this.listLoaded = true;
      this.root.classList.remove('is-offline');
    } catch {
      this.root.classList.add('is-offline');
    }
    this.renderList();
    this.applyState();
  }

  /**
   * Empty / loading / no-results block of the list.
   *
   * @param title Main text.
   * @param body Secondary text (null hides it).
   * @param start Whether to show the "start a conversation" button.
   * @returns The state element.
   */
  private listState(title: string, body: string | null = null, start = false): HTMLElement {
    const state = this.templates.clone('list-state');
    text(state, 'title', title);
    text(state, 'body', body);
    const startBtn = part(state, 'start');
    startBtn.hidden = !start;
    startBtn.addEventListener('click', () => this.openPicker());
    return state;
  }

  /**
   * Second line of a list row: who is typing, the buzz / deleted label, or the last message with its author.
   *
   * @param summary Conversation of the row.
   * @returns The preview text.
   */
  private previewOf(summary: ConversationSummary): string {
    const i18n = this.config.i18n;
    if (this.listTyping.has(summary.uuid)) {
      return this.windows.get(summary.uuid)?.typingLabel() ?? t(i18n, 'typing_many');
    }
    const last = summary.lastMessage;
    if (last === null) {
      return summary.subtitle;
    }
    if (last.pin === true) {
      return t(i18n, 'pinned_line', { name: last.mine ? t(i18n, 'you') : last.authorName ?? summary.title });
    }
    if (last.buzz === true) {
      return t(i18n, 'buzz_sent', { name: last.mine ? t(i18n, 'you') : last.authorName ?? summary.title });
    }
    if (last.deleted) {
      return t(i18n, 'deleted_message');
    }
    const author = last.mine
      ? `${t(i18n, 'you')}: `
      : summary.kind !== 'direct' && last.authorName !== null && last.authorName !== summary.title
        ? `${last.authorName}: `
        : '';
    return `${author}${last.preview}`;
  }

  /** Renders the conversation list (filter, search, newest first) and the launcher badge. */
  private renderList(): void {
    const i18n = this.config.i18n;
    const archivedFilter = this.filter === 'archived';
    const rows = [...(archivedFilter ? this.archived : this.conversations).values()]
      .filter((summary) => {
        // An empty direct conversation stays out of the list until someone writes (it lives in its window).
        if (summary.kind === 'direct' && summary.lastMessage === null) {
          return false;
        }
        if (!archivedFilter && ((this.filter === 'unread' && summary.unread === 0) || (this.filter !== 'all' && this.filter !== 'unread' && summary.kind !== this.filter))) {
          return false;
        }
        return this.query === '' || summary.title.toLowerCase().includes(this.query) || summary.subtitle.toLowerCase().includes(this.query);
      })
      .sort((a, b) => (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? ''));

    if (!this.listLoaded) {
      this.listEl.replaceChildren(this.listState(t(i18n, 'loading')));
      return;
    }
    if (rows.length === 0 && archivedFilter) {
      this.listEl.replaceChildren(this.listState(t(i18n, 'archived_empty')));
      return;
    }
    if (rows.length === 0) {
      const empty = this.conversations.size === 0;
      this.listEl.replaceChildren(empty ? this.listState(t(i18n, 'empty_title'), t(i18n, 'empty_body'), true) : this.listState(t(i18n, 'no_results')));
      return;
    }

    this.listEl.replaceChildren(
      ...rows.map((summary) => {
        const item = this.templates.clone('row');
        const row = part<HTMLButtonElement>(item, 'row');
        row.classList.toggle('is-unread', summary.unread > 0);
        row.classList.toggle('is-typing', this.listTyping.has(summary.uuid));
        row.dataset.uuid = summary.uuid;
        if (this.page !== null && this.page.active === summary.uuid) {
          // Page mode: the conversation shown on the right.
          row.classList.add('is-current');
          row.setAttribute('aria-current', 'true');
        }
        part(item, 'avatar').replaceWith(this.templates.avatar(summary.initials, summary.kind, 'md', summary.peerOnline, summary.kind === 'direct' ? summary.peerStatus : undefined));
        const title = text(item, 'title', summary.title);
        if (summary.kind === 'direct') {
          this.decoratePeer(row, title, summary);
        }
        part(item, 'tag').hidden = summary.kind !== 'support';
        const time = text(item, 'time', listTimeLabel(summary.lastMessageAt, this.config.locale));
        time.setAttribute('datetime', summary.lastMessageAt ?? '');
        text(item, 'preview', this.previewOf(summary));
        setBadge(part(item, 'badge'), summary.unread, t(i18n, 'unread', { count: summary.unread }));
        row.addEventListener('click', () => void this.open(summary));
        this.bindArchive(item, row, summary, archivedFilter);
        return item;
      }),
    );
    this.renderBadge();
  }

  /**
   * The row's Close (archive) button — Reopen under the "Closed" filter — and the Delete key on the row (1.10).
   *
   * @param item The list item.
   * @param row The row button.
   * @param summary The conversation.
   * @param archived Whether the row is listed under "Closed".
   */
  private bindArchive(item: HTMLElement, row: HTMLElement, summary: ConversationSummary, archived: boolean): void {
    const button = item.querySelector<HTMLButtonElement>('[data-part="row-archive"]');
    if (button === null || this.config.endpoints.archive === undefined) {
      button?.remove();
      return;
    }
    const label = t(this.config.i18n, archived ? 'reopen_conversation' : 'close_conversation', { name: summary.title });
    button.setAttribute('aria-label', label);
    button.title = label;
    if (archived) {
      button.querySelector('[data-icon]')?.replaceChildren(this.templates.icon('unarchive', 16));
    }
    button.addEventListener('click', () => void this.setArchived(summary, !archived));
    row.addEventListener('keydown', (event) => {
      if (event.key === 'Delete' && !archived) {
        event.preventDefault();
        void this.setArchived(summary, true);
      }
    });
  }

  /**
   * Closes a conversation in the user's list (with Undo), or reopens it.
   *
   * @param summary The conversation.
   * @param archived True to close, false to reopen.
   */
  private async setArchived(summary: ConversationSummary, archived: boolean): Promise<void> {
    const i18n = this.config.i18n;
    try {
      await this.api.archive(summary.uuid, archived);
    } catch {
      this.showStatus('error');
      return;
    }
    if (archived) {
      this.conversations.delete(summary.uuid);
      if (this.windows.has(summary.uuid)) {
        this.close(summary.uuid);
      }
      this.showToast(t(i18n, 'archived_one'), () => void this.setArchived(summary, false));
    } else {
      this.archived.delete(summary.uuid);
      this.conversations.set(summary.uuid, { ...summary, archived: false });
      this.showToast(t(i18n, 'unarchived_one'));
    }
    this.renderList();
  }

  /** "Close all read": every conversation without unread messages leaves the list. */
  private async archiveRead(): Promise<void> {
    try {
      const count = await this.api.archiveRead();
      this.showToast(t(this.config.i18n, 'archived_many', { count }));
      await this.refreshList(true);
    } catch {
      this.showStatus('error');
    }
  }

  /** Loads the conversations the user closed (the "Closed" filter). */
  private async loadArchived(): Promise<void> {
    try {
      const { conversations } = await this.api.conversations(true);
      this.archived.clear();
      conversations.forEach((summary) => this.archived.set(summary.uuid, summary));
    } catch {
      this.showStatus('error');
    }
    this.renderList();
  }

  /**
   * A short notice under the panel header, with an optional Undo, gone after 6 s.
   *
   * @param message The notice.
   * @param undo What Undo does; no button without it.
   */
  private showToast(message: string, undo?: () => void): void {
    window.clearTimeout(this.toastTimer);
    this.toast.replaceChildren(h('span', { text: message }));
    if (undo !== undefined) {
      const button = h('button', { type: 'button', class: 'nowo-chat-toast__undo', text: t(this.config.i18n, 'undo') });
      button.addEventListener('click', () => {
        this.toast.hidden = true;
        undo();
      });
      this.toast.append(button);
    }
    this.toast.hidden = false;
    this.toastTimer = window.setTimeout(() => {
      this.toast.hidden = true;
    }, 6_000);
  }

  /**
   * A direct row: its peer's status (data attribute, a hidden label for screen readers) and custom status after the name.
   *
   * @param row The row button.
   * @param title The name element.
   * @param summary The conversation.
   */
  private decoratePeer(row: HTMLElement, title: HTMLElement, summary: ConversationSummary): void {
    const status = summary.peerStatus ?? (summary.peerOnline ? 'available' : 'offline');
    row.dataset.peerStatus = status;
    const extras: HTMLElement[] = [];
    if (status !== 'offline') {
      extras.push(h('span', { class: 'nowo-chat-sr-only', text: `, ${statusLabel(this.config.i18n, status)}` }));
    }
    const custom = customStatus(summary.peerStatusText, summary.peerStatusEmoji);
    if (custom !== '') {
      const line = h('span', { class: 'nowo-chat-row__status', title: custom, text: custom });
      if ((summary.peerStatusText ?? '') === '') {
        // Only an emoji: say what it is.
        line.setAttribute('aria-label', t(this.config.i18n, 'status_custom_emoji', { emoji: custom }));
      }
      extras.push(line);
    }
    title.after(...extras);
  }

  /** Total unread count on the launcher badge (and the `has-unread` root class). */
  private renderBadge(): void {
    const total = [...this.conversations.values()].reduce((sum, summary) => sum + summary.unread, 0);
    setBadge(this.launcherBadge, total, t(this.config.i18n, 'unread', { count: total }));
    setBadge(this.edgeBadge, total, t(this.config.i18n, 'unread', { count: total }));
    this.root.classList.toggle('has-unread', total > 0);
  }

  /**
   * A section of the people picker.
   *
   * @param heading Section heading (null hides it).
   * @param rows Picker rows.
   * @returns The section element.
   */
  private section(heading: string | null, rows: HTMLElement[]): HTMLElement {
    const section = this.templates.clone('picker-section');
    text(section, 'heading', heading);
    part(section, 'items').replaceChildren(...rows);
    return section;
  }

  /**
   * Message block of the people picker (nobody found, error).
   *
   * @param message Text to show.
   * @param alert Whether it is announced as an alert.
   * @returns The state element.
   */
  private pickerState(message: string, alert = false): HTMLElement {
    const state = this.templates.clone('picker-state');
    text(state, 'text', message);
    if (alert) {
      state.setAttribute('role', 'alert');
    }
    return state;
  }

  /**
   * Fills the people picker: pinned support / context channel, recent people (empty query) and one section per
   * directory source; answers for an outdated query are dropped.
   *
   * @param query Search text.
   */
  private async loadContacts(query: string): Promise<void> {
    const i18n = this.config.i18n;
    this.contactsQuery = query;
    let contacts: Contact[];
    try {
      contacts = await this.api.contacts(query);
    } catch {
      this.pickerResults.replaceChildren(this.pickerState(t(i18n, 'error'), true));
      return;
    }
    if (this.contactsQuery !== query) {
      return;
    }
    const sections: HTMLElement[] = [];
    const lowered = query.toLowerCase();
    const pinned: HTMLElement[] = [];
    if (lowered === '' || t(i18n, 'support_title').toLowerCase().includes(lowered)) {
      pinned.push(this.pickerRow(t(i18n, 'support_title'), t(i18n, 'support_hint'), this.templates.avatar('', 'support'), async () => this.open(await this.api.openSupport())));
    }
    const channel = this.config.contextChannel;
    if (channel !== null && (lowered === '' || channel.label.toLowerCase().includes(lowered))) {
      pinned.push(this.pickerRow(`#${channel.label}`, t(i18n, 'channel'), this.templates.avatar('', 'channel'), async () => this.open(await this.api.openChannel(channel.key))));
    }
    if (pinned.length > 0) {
      sections.push(this.section(null, pinned));
    }
    // The last people you talked to, first.
    const recent =
      lowered === ''
        ? [...this.conversations.values()]
            .filter((summary) => summary.kind === 'direct' && summary.lastMessage !== null)
            .sort((a, b) => (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? ''))
            .slice(0, 5)
        : [];
    if (recent.length > 0) {
      sections.push(
        this.section(
          t(i18n, 'people_recent'),
          recent.map((summary) => this.pickerRow(summary.title, summary.subtitle, this.templates.avatar(summary.initials, 'direct', 'md', summary.peerOnline, summary.peerStatus), async () => this.open(summary))),
        ),
      );
    }
    // One section per directory source, in the order the application returned them.
    const sources = [...new Set(contacts.map((contact) => contact.source))];
    for (const source of sources) {
      const headingKey = `people_${source}`;
      sections.push(
        this.section(
          headingKey in i18n ? t(i18n, headingKey) : t(i18n, 'people_other'),
          contacts
            .filter((contact) => contact.source === source)
            .map((contact) => {
              const viaKey = `via_${source}`;
              const subtitle = contact.via !== '' && viaKey in i18n ? t(i18n, viaKey, { name: contact.via }) : contact.email ?? contact.via;
              return this.pickerRow(contact.name, subtitle, this.templates.avatar(contact.initials), async () => this.open(await this.api.openDirect(contact.uuid)));
            }),
        ),
      );
    }
    if (contacts.length === 0) {
      sections.push(this.pickerState(t(i18n, 'no_people')));
    }
    this.pickerResults.replaceChildren(...sections);
  }

  /**
   * A clickable row of the people picker; a failing pick marks the row and shows an error.
   *
   * @param title Row title.
   * @param subtitle Second line.
   * @param picture Avatar element.
   * @param onPick Opens the conversation.
   * @returns The row button.
   */
  private pickerRow(title: string, subtitle: string, picture: HTMLElement, onPick: () => Promise<void>): HTMLElement {
    const row = this.templates.clone<HTMLButtonElement>('picker-row');
    part(row, 'avatar').replaceWith(picture);
    text(row, 'title', title);
    text(row, 'preview', subtitle);
    row.addEventListener('click', () => {
      row.disabled = true;
      onPick()
        .catch(() => {
          row.classList.add('is-error');
          row.setAttribute('aria-describedby', 'nowo-chat-picker-error');
          this.pickerResults.querySelector('#nowo-chat-picker-error')?.remove();
          const error = this.pickerState(t(this.config.i18n, 'error'), true);
          error.id = 'nowo-chat-picker-error';
          error.classList.add('nowo-chat-list__error');
          this.pickerResults.prepend(error);
        })
        .finally(() => {
          row.disabled = false;
        });
    });
    return row;
  }

  /**
   * Applies an event from another tab: a new layout, messages for a conversation, or a read cursor.
   *
   * @param event Sync event.
   */
  private onSync(event: SyncEvent): void {
    if (event.type === 'state') {
      this.applyState();
      return;
    }
    const summary = this.conversations.get(event.uuid);
    if (event.type === 'messages') {
      const view = this.windows.get(event.uuid);
      view?.merge(event.messages, false);
      const last = event.messages.at(-1);
      if (summary === undefined) {
        void this.refreshList();
      } else if (last !== undefined) {
        summary.lastMessageAt = last.createdAt;
        if (view === undefined || view.isMinimised) {
          summary.unread += event.messages.filter((message) => !message.mine).length;
        }
        this.renderList();
      }
      return;
    }
    if (summary !== undefined) {
      summary.unread = 0;
      this.renderList();
    }
    this.windows.get(event.uuid)?.markReadLocally(event.messageId);
  }

  /** Catch up open windows every `pollMs` (a slow safety net when live), refresh the list every 4th tick. */
  private schedulePoll(): void {
    window.clearTimeout(this.pollTimer ?? undefined);
    const base = this.live ? 60_000 : document.visibilityState === 'visible' ? this.config.pollMs : this.config.pollMs * 6;
    // Other tabs receive the leader's messages over BroadcastChannel: they only need a slow safety net.
    const delay = this.leader ? base : base * 4;
    this.pollTimer = window.setTimeout(() => {
      void this.poll(false).finally(() => this.schedulePoll());
    }, delay);
  }

  /**
   * One poll tick: refresh the list (forced, live, every 4th tick or hidden tab), else catch up the open windows.
   *
   * @param force Refresh the list now (reconnect, tab visible again).
   */
  private async poll(force: boolean): Promise<void> {
    this.tick += 1;
    if (force || this.live || this.tick % 4 === 0 || document.visibilityState !== 'visible') {
      await this.refreshList();
      return;
    }
    await Promise.all(
      [...this.windows.values()].map((view) =>
        view.catchUp().then(
          () => this.root.classList.remove('is-offline'),
          () => this.root.classList.add('is-offline'),
        ),
      ),
    );
  }
}

/**
 * Moves `element` right after `anchor` only when it is not there already: re-inserting a node resets the scroll
 * position of everything inside it (an open thread would jump back to its first message).
 *
 * @param anchor Element to follow.
 * @param element Element to place.
 */
function placeAfter(anchor: Element, element: Element): void {
  if (anchor.nextElementSibling !== element) {
    anchor.after(element);
  }
}
