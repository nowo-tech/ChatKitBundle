/**
 * 1.25: the grouped, collapsible conversation list (Slack-style sidebar) of the full-page chat and, with
 * `web_ui.dock.sidebar_sections`, of the dock. Sections: "Recent" / "Unread" (a toggle), the host sections of
 * ChatSidebarSectionProviderInterface (`GET …/api/sidebar`) and "Direct messages and groups" (every listed
 * conversation no host section shows), ordered by position.
 *
 * Every section is a disclosure (a button with aria-expanded over its rows) and its rows a listbox (role="option",
 * aria-selected = the conversation shown on the page). The whole sidebar is one tab stop: ↑ / ↓ move between section
 * headers, rows and actions, ← folds a section (or goes up to its header), → unfolds it (or goes into its rows),
 * Home / End go to the first / last, Enter or Space opens; Delete closes a conversation of the bundle's sections.
 * Folded sections and the Recent / Unread choice are remembered per viewer in localStorage (when it can be used).
 *
 * Texts always go through textContent. Host icons: a dock icon name, or a server-rendered UX Icons SVG, re-parsed here
 * and reduced to plain SVG shapes. Action dialogs are UiKit remote form dialogs (`data-nowo-ui-dialog-*` openers,
 * same-origin fragments only, checked again here); without UiKit's dialog script the action is a plain link.
 */
import { setBadge } from './dom';
import { t } from './format';
import { part, text, type Templates } from './templates';
import { isMuted } from './thread';
import type { ChatConfig, ConversationSummary, SidebarAction, SidebarItem, SidebarSection } from './types';

/** Position of the bundle's "Recent" / "Unread" section (ChatSidebarSection::POSITION_RECENT). */
export const POSITION_RECENT = -1000;
/** Position of the bundle's "Direct messages and groups" section (ChatSidebarSection::POSITION_CONVERSATIONS). */
export const POSITION_CONVERSATIONS = 1000;
/** Conversations the "Recent" view shows. */
export const RECENT_LIMIT = 5;

/** What the sidebar needs from the dock. */
export interface SidebarHost {
  readonly config: ChatConfig;
  readonly templates: Templates;
  /** The listed (not closed) conversations. */
  listed(): ConversationSummary[];
  /** The conversation shown on the page, or null (the floating dock shows none). */
  current(): string | null;
  /** Opens a conversation by uuid (a known summary, else fetched). */
  openConversation(uuid: string): void;
  /** Closes (archives) a conversation of the bundle's sections (Delete key). */
  archive(summary: ConversationSummary): void;
}

/** Remembered view of one viewer. */
interface SidebarPrefs {
  collapsed: Record<string, boolean>;
  mode: 'recent' | 'unread';
}

/** A section as it is drawn: the bundle's or the host's. */
interface DrawnSection {
  key: string;
  label: string;
  position: number;
  collapsible: boolean;
  collapsedByDefault: boolean;
  rows: HTMLElement[];
  actions: SidebarAction[];
  /** Unread count shown on the folded header (muted rows left out). */
  unread: number;
  /** Shown when the section has no rows. */
  empty: string | null;
  recent: boolean;
}

/** Follows a URL (separate so tests can observe it). */
export const navigation = {
  assign(url: string): void {
    window.location.assign(url);
  },
};

/** SVG elements a host icon may keep. */
const SVG_ELEMENTS = new Set(['svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'title', 'defs', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'stop']);

/**
 * A server-rendered UX Icons SVG reduced to plain shapes: one <svg> root, only drawing elements, no event handler,
 * link, style or script attribute.
 *
 * @param markup SVG markup.
 * @returns The SVG element (aria-hidden), or null when the markup is not one.
 */
export function sanitizeIcon(markup: string): SVGElement | null {
  const parsed = new DOMParser().parseFromString(markup, 'image/svg+xml').documentElement;
  if (parsed.localName !== 'svg' || parsed.namespaceURI !== 'http://www.w3.org/2000/svg') {
    return null;
  }
  const clean = (element: Element): void => {
    [...element.children].forEach((child) => {
      if (SVG_ELEMENTS.has(child.localName.toLowerCase())) {
        clean(child);
      } else {
        child.remove();
      }
    });
    [...element.attributes].forEach((attribute) => {
      const name = attribute.name.toLowerCase();
      if (name.startsWith('on') || name === 'style' || name.endsWith('href') || /^\s*(?:javascript|data|vbscript):/i.test(attribute.value)) {
        element.removeAttribute(attribute.name);
      }
    });
  };
  clean(parsed);
  const icon = document.importNode(parsed, true) as unknown as SVGElement;
  icon.setAttribute('aria-hidden', 'true');
  icon.setAttribute('focusable', 'false');
  return icon;
}

/**
 * A same-origin http(s) address (a dialog fragment), as a path; null for another origin or scheme.
 *
 * @param url A path or an absolute URL.
 * @returns Path, query and fragment on this origin, or null.
 */
export function sameOriginPath(url: string): string | null {
  if (url === '' || /[\\\s]/.test(url) || url.startsWith('//')) {
    return null;
  }
  let parsed: URL;
  try {
    parsed = new URL(url, window.location.href);
  } catch {
    return null;
  }
  if (parsed.origin !== window.location.origin || (parsed.protocol !== 'http:' && parsed.protocol !== 'https:')) {
    return null;
  }
  return `${parsed.pathname}${parsed.search}${parsed.hash}`;
}

/**
 * A link of a row or an action: a path or an http(s) URL; null otherwise (the server checked it already).
 *
 * @param url The address.
 * @returns The address, or null.
 */
export function safeLink(url: string | null): string | null {
  if (url === null || /[\\\s]/.test(url)) {
    return null;
  }
  return /^(?:\/(?!\/)|https?:\/\/)/.test(url) ? url : null;
}

/** The grouped sidebar of one dock. */
export class ChatSidebarView {
  private readonly host: SidebarHost;
  private readonly storageKey: string;
  private prefs: SidebarPrefs;
  private sections: SidebarSection[] = [];
  /** Element that keeps the sidebar's single tab stop (re-applied after each render). */
  private focusKey: string | null = null;

  /**
   * Creates the sidebar and reads the viewer's remembered view.
   *
   * @param host The dock.
   */
  constructor(host: SidebarHost) {
    this.host = host;
    this.storageKey = `nowo-chat-sidebar:${host.config.userUuid}`;
    this.prefs = this.readPrefs();
  }

  /**
   * Replaces the host sections (the answer of the sidebar endpoint).
   *
   * @param sections Sections in position order.
   */
  setSections(sections: SidebarSection[]): void {
    this.sections = sections;
  }

  /**
   * The conversation was read here: its host rows lose their unread and mention counts.
   *
   * @param uuid Conversation uuid.
   */
  markRead(uuid: string): void {
    this.hostItems(uuid).forEach((item) => {
      item.unread = 0;
      item.mentions = 0;
    });
  }

  /**
   * A message arrived in a conversation (realtime): its host rows count it, and a mention of the viewer too.
   *
   * @param uuid Conversation uuid.
   * @param mention Whether the message mentions the viewer.
   */
  received(uuid: string, mention: boolean): void {
    this.hostItems(uuid).forEach((item) => {
      item.unread += 1;
      item.mentions += mention ? 1 : 0;
    });
  }

  /**
   * Whether the sidebar has anything to show besides conversations (host rows or actions).
   *
   * @returns True with at least one host row or action.
   */
  hasHostContent(): boolean {
    return this.sections.some((section) => section.items.length > 0 || section.actions.length > 0);
  }

  /**
   * Draws every section.
   *
   * @returns The section elements (`<li>` of the list).
   */
  render(): HTMLElement[] {
    const i18n = this.host.config.i18n;
    const listed = this.host.listed().filter((summary) => summary.kind !== 'direct' || summary.lastMessage !== null);
    const recentFirst = (a: ConversationSummary, b: ConversationSummary): number => (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? '');
    listed.sort(recentFirst);
    const byUuid = new Map(this.host.listed().map((summary) => [summary.uuid, summary]));
    const covered = new Set(this.sections.flatMap((section) => section.items.map((item) => item.conversationUuid).filter((uuid): uuid is string => uuid !== null)));
    const unreadOf = (rows: ConversationSummary[]): number => rows.reduce((sum, summary) => sum + (isMuted(summary) ? 0 : summary.unread), 0);

    const drawn: DrawnSection[] = [];
    if (listed.length > 0) {
      const unreadOnly = this.prefs.mode === 'unread';
      const rows = unreadOnly ? listed.filter((summary) => summary.unread > 0 && !isMuted(summary)) : listed.slice(0, RECENT_LIMIT);
      drawn.push({ key: 'recent', label: t(i18n, 'sidebar_recent'), position: POSITION_RECENT, collapsible: true, collapsedByDefault: false, rows: rows.map((summary) => this.conversationRow('recent', summary)), actions: [], unread: unreadOf(rows), empty: unreadOnly ? t(i18n, 'sidebar_no_unread') : null, recent: true });
    }
    for (const section of this.sections) {
      let unread = 0;
      const rows = section.items.map((item) => {
        const summary = item.conversationUuid === null ? undefined : byUuid.get(item.conversationUuid);
        const muted = summary === undefined ? item.muted : isMuted(summary);
        const count = summary === undefined ? item.unread : summary.unread;
        unread += muted ? 0 : count;
        return this.hostRow(section.key, item, count, muted);
      });
      drawn.push({ key: section.key, label: section.label, position: section.position, collapsible: section.collapsible, collapsedByDefault: section.collapsedByDefault, rows, actions: section.actions, unread, empty: null, recent: false });
    }
    const others = listed.filter((summary) => !covered.has(summary.uuid));
    drawn.push({ key: 'conversations', label: t(i18n, 'sidebar_conversations'), position: POSITION_CONVERSATIONS, collapsible: true, collapsedByDefault: false, rows: others.map((summary) => this.conversationRow('conversations', summary)), actions: [], unread: unreadOf(others), empty: t(i18n, 'sidebar_empty'), recent: false });
    // Stable: the host order among equal positions.
    drawn.sort((a, b) => a.position - b.position);
    return drawn.map((section) => this.sectionElement(section));
  }

  /**
   * Keyboard of the sidebar (one tab stop, arrows inside). Call once on the list element.
   *
   * @param list The list element the sections are drawn into.
   * @param signal Removes the listeners.
   */
  bindKeys(list: HTMLElement, signal: AbortSignal): void {
    list.addEventListener(
      'keydown',
      (event) => {
        const target = event.target as HTMLElement;
        if (!list.classList.contains('is-sidebar')) {
          return;
        }
        const stops = this.stops(list);
        const index = stops.indexOf(target);
        if (index === -1) {
          return;
        }
        const toggle = target.dataset.part === 'toggle' ? target : null;
        let next: HTMLElement | undefined;
        switch (event.key) {
          case 'ArrowDown':
            next = stops[Math.min(index + 1, stops.length - 1)];
            break;
          case 'ArrowUp':
            next = stops[Math.max(index - 1, 0)];
            break;
          case 'Home':
            next = stops[0];
            break;
          case 'End':
            next = stops[stops.length - 1];
            break;
          case 'ArrowLeft':
            if (toggle !== null && toggle.getAttribute('aria-expanded') === 'true') {
              toggle.click();
              return event.preventDefault();
            }
            next = toggle === null ? (target.closest('[data-part="section"]')?.querySelector<HTMLElement>('[data-part="toggle"]:not([hidden])') ?? undefined) : undefined;
            break;
          case 'ArrowRight':
            if (toggle !== null && toggle.getAttribute('aria-expanded') === 'false') {
              toggle.click();
              return event.preventDefault();
            }
            next = toggle === null ? undefined : stops[index + 1];
            break;
          case 'Enter':
          case ' ':
            if (target.getAttribute('role') === 'option') {
              event.preventDefault();
              target.click();
            }
            return;
          default:
            return;
        }
        event.preventDefault();
        if (next !== undefined && next !== target) {
          this.focus(list, next);
        }
      },
      { signal },
    );
    list.addEventListener(
      'focusin',
      (event) => {
        const target = event.target as HTMLElement;
        if (list.classList.contains('is-sidebar') && this.stops(list).includes(target)) {
          this.focus(list, target, false);
        }
      },
      { signal },
    );
  }

  /**
   * Keeps the single tab stop after a render: the element focused last (same key), else the current conversation,
   * else the first header.
   *
   * @param list The list element.
   */
  restoreTabStop(list: HTMLElement): void {
    const stops = this.stops(list);
    const kept = stops.find((stop) => stop.dataset.navKey === this.focusKey) ?? stops.find((stop) => stop.getAttribute('aria-selected') === 'true') ?? stops[0];
    stops.forEach((stop) => (stop.tabIndex = stop === kept ? 0 : -1));
  }

  /**
   * Moves the tab stop (and the focus) to an element of the sidebar.
   *
   * @param list The list element.
   * @param element The new stop.
   * @param move Whether to focus it (false: it already has the focus).
   */
  private focus(list: HTMLElement, element: HTMLElement, move = true): void {
    this.stops(list).forEach((stop) => (stop.tabIndex = stop === element ? 0 : -1));
    this.focusKey = element.dataset.navKey ?? null;
    if (move) {
      element.focus();
    }
  }

  /**
   * The focusable elements of the sidebar, in order: section headers, mode buttons, rows of unfolded sections, actions.
   *
   * @param list The list element.
   * @returns The elements.
   */
  private stops(list: HTMLElement): HTMLElement[] {
    return [...list.querySelectorAll<HTMLElement>('[data-nav-key]')].filter((element) => element.closest('[hidden]') === null);
  }

  /**
   * One section element: header (fold toggle, Recent / Unread), rows, empty text, actions.
   *
   * @param section The section.
   * @returns The `<li>`.
   */
  private sectionElement(section: DrawnSection): HTMLElement {
    const i18n = this.host.config.i18n;
    const element = this.host.templates.clone('sidebar-section');
    element.dataset.section = section.key;
    element.setAttribute('data-testid', `chat-sidebar-section-${section.key}`);
    const collapsed = section.collapsible && (this.prefs.collapsed[section.key] ?? section.collapsedByDefault);
    const items = part(element, 'items');
    const listId = `nowo-chat-sidebar-${section.key.replace(/[^a-z0-9_-]/g, '-')}`;
    items.id = listId;
    items.setAttribute('aria-label', section.label);
    items.replaceChildren(...section.rows);
    items.hidden = collapsed || section.rows.length === 0;
    const empty = text(element, 'empty', section.rows.length === 0 && !collapsed ? section.empty : null);
    empty.hidden = empty.hidden || collapsed;

    const toggle = part<HTMLButtonElement>(element, 'toggle');
    const heading = text(element, 'heading', section.collapsible ? null : section.label);
    if (section.collapsible) {
      text(toggle, 'label', section.label);
      toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      toggle.setAttribute('aria-controls', listId);
      toggle.dataset.navKey = `section:${section.key}`;
      setBadge(part(toggle, 'badge'), collapsed ? section.unread : 0, t(i18n, 'unread', { count: section.unread }));
      toggle.addEventListener('click', () => this.setCollapsed(element, section, !collapsed));
    } else {
      toggle.hidden = true;
      heading.hidden = false;
    }
    element.classList.toggle('is-collapsed', collapsed);

    const modes = part(element, 'modes');
    modes.hidden = !section.recent;
    if (section.recent) {
      modes.querySelectorAll<HTMLButtonElement>('[data-mode]').forEach((button) => {
        const mode = button.dataset.mode === 'unread' ? 'unread' : 'recent';
        button.setAttribute('aria-pressed', mode === this.prefs.mode ? 'true' : 'false');
        button.dataset.navKey = `mode:${mode}`;
        button.addEventListener('click', () => {
          this.prefs.mode = mode;
          this.savePrefs();
          this.rerender(element);
        });
      });
    }

    const actions = part(element, 'actions');
    actions.replaceChildren(...section.actions.map((action) => this.actionElement(section.key, action)).filter((action): action is HTMLElement => action !== null));
    actions.hidden = collapsed || actions.childElementCount === 0;
    return element;
  }

  /**
   * Folds or unfolds a section (remembered) and draws the sidebar again in place.
   *
   * @param element The section element.
   * @param section The section.
   * @param collapsed True to fold.
   */
  private setCollapsed(element: HTMLElement, section: DrawnSection, collapsed: boolean): void {
    this.prefs.collapsed[section.key] = collapsed;
    this.savePrefs();
    this.focusKey = `section:${section.key}`;
    this.rerender(element);
  }

  /**
   * Draws the whole sidebar again inside the list that holds `element`, keeping the focus on the same control.
   *
   * @param element An element of the current drawing.
   */
  private rerender(element: HTMLElement): void {
    const list = element.parentElement;
    const hadFocus = element.contains(document.activeElement);
    const key = (document.activeElement as HTMLElement | null)?.dataset?.navKey ?? this.focusKey;
    if (list === null) {
      return;
    }
    list.replaceChildren(...this.render());
    this.focusKey = key ?? null;
    this.restoreTabStop(list);
    if (hadFocus) {
      list.querySelector<HTMLElement>('[tabindex="0"]')?.focus();
    }
  }

  /**
   * A row of a conversation of the bundle's sections (avatar, title, muted, unread).
   *
   * @param section Section key.
   * @param summary The conversation.
   * @returns The option.
   */
  private conversationRow(section: string, summary: ConversationSummary): HTMLElement {
    const row = this.itemElement(`${section}-${summary.uuid}`, summary.title, summary.unread, isMuted(summary), 0, summary.uuid);
    part(row, 'icon').replaceChildren(this.host.templates.avatar(summary.initials, summary.kind, 'sm', summary.peerOnline, summary.kind === 'direct' ? summary.peerStatus : undefined, summary.avatarUrl));
    row.title = summary.subtitle;
    row.addEventListener('click', () => this.host.openConversation(summary.uuid));
    row.addEventListener('keydown', (event) => {
      if (event.key === 'Delete') {
        event.preventDefault();
        this.host.archive(summary);
      }
    });
    return row;
  }

  /**
   * A row of a host section: a conversation (opened in the chat) or a link.
   *
   * @param section Section key.
   * @param item The row.
   * @param unread Unread count (the chat's for a conversation).
   * @param muted Muted look (the chat's for a conversation).
   * @returns The option.
   */
  private hostRow(section: string, item: SidebarItem, unread: number, muted: boolean): HTMLElement {
    const row = this.itemElement(`${section}-${item.key}`, item.label, unread, muted, item.mentions, item.conversationUuid);
    part(row, 'icon').replaceChildren(this.iconOf(item.icon ?? 'channel', item.iconSvg));
    if (item.subtitle !== null && item.subtitle !== '') {
      row.title = item.subtitle;
    }
    const url = safeLink(item.url);
    row.addEventListener('click', () => {
      if (item.conversationUuid !== null) {
        this.host.openConversation(item.conversationUuid);
      } else if (url !== null) {
        navigation.assign(url);
      }
    });
    return row;
  }

  /**
   * The shared part of every row.
   *
   * @param key Unique key in the sidebar (data-testid `chat-sidebar-item-<key>`).
   * @param label Row text.
   * @param unread Unread count.
   * @param muted Muted look.
   * @param mentions Unread mentions of the viewer.
   * @param uuid Conversation shown by the row (the page's current one is selected).
   * @returns The option.
   */
  private itemElement(key: string, label: string, unread: number, muted: boolean, mentions: number, uuid: string | null): HTMLElement {
    const i18n = this.host.config.i18n;
    const row = this.host.templates.clone('sidebar-item');
    row.setAttribute('data-testid', `chat-sidebar-item-${key}`);
    row.dataset.navKey = `item:${key}`;
    if (uuid !== null) {
      row.dataset.uuid = uuid;
    }
    text(row, 'label', label);
    const current = uuid !== null && uuid === this.host.current();
    row.setAttribute('aria-selected', current ? 'true' : 'false');
    row.classList.toggle('is-current', current);
    row.classList.toggle('is-unread', unread > 0 && !muted);
    row.classList.toggle('is-muted', muted);
    part(row, 'muted').hidden = !muted;
    part(row, 'muted').setAttribute('aria-label', t(i18n, 'sidebar_muted'));
    const at = text(row, 'mentions', mentions > 0 ? '@' : null);
    at.setAttribute('aria-label', t(i18n, 'sidebar_mentions', { count: mentions }));
    setBadge(part(row, 'badge'), unread, t(i18n, 'unread', { count: unread }));
    return row;
  }

  /**
   * An action: a UiKit remote form dialog opener (same-origin fragment) with its link fallback, or a plain link.
   *
   * @param section Section key.
   * @param action The action.
   * @returns The link, or null when neither of its URLs can be used.
   */
  private actionElement(section: string, action: SidebarAction): HTMLElement | null {
    const dialog = action.dialogUrl === null ? null : sameOriginPath(action.dialogUrl);
    const href = safeLink(action.url) ?? dialog;
    if (href === null) {
      return null;
    }
    const link = this.host.templates.clone<HTMLAnchorElement>('sidebar-action');
    link.href = href;
    link.setAttribute('data-testid', `chat-sidebar-action-${section}-${action.key}`);
    link.dataset.navKey = `action:${section}:${action.key}`;
    text(link, 'label', action.label);
    const icon = part(link, 'icon');
    if (action.icon === null && action.iconSvg === undefined) {
      icon.remove();
    } else {
      icon.replaceChildren(this.iconOf(action.icon ?? 'plus', action.iconSvg));
    }
    const dialogId = this.host.config.sidebar?.dialogs[action.dialogSize];
    if (dialog !== null && dialogId !== undefined && document.getElementById(dialogId) !== null) {
      link.setAttribute('data-nowo-ui-dialog-open', dialogId);
      link.setAttribute('data-nowo-ui-dialog-src', dialog);
      link.setAttribute('data-nowo-ui-dialog-form', '');
      link.setAttribute('data-nowo-ui-dialog-title', action.label);
      link.setAttribute('aria-haspopup', 'dialog');
    }
    return link;
  }

  /**
   * A row or action icon: the sanitised server SVG, else the dock icon (`channel` = #, `private` = a lock).
   *
   * @param name Dock icon name.
   * @param svg Server-rendered UX Icons SVG.
   * @returns The icon element.
   */
  private iconOf(name: string, svg?: string): Element {
    const rendered = svg === undefined ? null : sanitizeIcon(svg);
    if (rendered !== null) {
      rendered.setAttribute('width', '16');
      rendered.setAttribute('height', '16');
      return rendered;
    }
    const dock = name === 'channel' ? 'hash' : name === 'private' ? 'lock' : name;
    return this.host.templates.icon(dock, 16, 'hash');
  }

  /**
   * Host rows of a conversation.
   *
   * @param uuid Conversation uuid.
   * @returns The rows.
   */
  private hostItems(uuid: string): SidebarItem[] {
    return this.sections.flatMap((section) => section.items.filter((item) => item.conversationUuid === uuid));
  }

  /**
   * The remembered view (defaults when the storage cannot be read).
   *
   * @returns The preferences.
   */
  private readPrefs(): SidebarPrefs {
    const prefs: SidebarPrefs = { collapsed: {}, mode: 'recent' };
    try {
      const stored = JSON.parse(window.localStorage.getItem(this.storageKey) ?? 'null') as Partial<SidebarPrefs> | null;
      if (stored !== null && typeof stored === 'object') {
        if (stored.mode === 'unread') {
          prefs.mode = 'unread';
        }
        if (stored.collapsed !== null && typeof stored.collapsed === 'object') {
          Object.entries(stored.collapsed).forEach(([key, value]) => {
            if (typeof value === 'boolean') {
              prefs.collapsed[key] = value;
            }
          });
        }
      }
    } catch {
      // No storage (private mode, blocked site data) or a damaged value: the defaults.
    }
    return prefs;
  }

  /** Remembers the view (silently skipped when the storage cannot be written). */
  private savePrefs(): void {
    try {
      window.localStorage.setItem(this.storageKey, JSON.stringify(this.prefs));
    } catch {
      // No storage: the view lasts until the page is left.
    }
  }
}
