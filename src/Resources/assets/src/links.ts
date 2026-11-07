/**
 * Links (1.19): links pinned to a conversation (a bar of chips under the header, like bookmarks — a Teams meeting, a
 * board, a document), the link dialog (add / edit a pinned link, or attach a link card to a message as
 * `!link[Title](https://…)`), and the "Links" panel listing the pinned links and every link shared in the messages.
 * Everything is built with DOM nodes (text through textContent); the server validates again and renders the cards.
 */
import { ChatApiError, type ChatApi } from './api';
import { h } from './dom';
import { closeWithEffect, isClosing } from './motion';
import { insertAtCaret } from './emoji';
import { listTimeLabel, t } from './format';
import type { Templates } from './templates';
import type { ChatConfig, LinkPerson, LinkProvider, LinkProviderRef, LinksPage, LinksSettings, PinnedLink, PinnedLinksState, SharedLink } from './types';

/** A link card token of a message body: `!link[Title](https://…)` (the title may be empty). */
const LINK_TOKEN = /!link\[([^[\]\n]*)\]\(([^\s()]+)\)/g;

/** Schemes kept as typed (and then refused unless http(s)); anything else without a scheme gets `https://`. */
const SCHEME = /^[a-z][a-z0-9+.-]*:/i;

/** `host:port/…` typed without a scheme (the port would read as a scheme). */
const HOST_PORT = /^[^/:\s]+\.[^/:\s]+:\d+(\/|$)/;

/** Delay of the panel search (keystrokes) and of its refresh after new messages. */
const SEARCH_DELAY_MS = 250;
const REFRESH_DELAY_MS = 1_000;

/** Serial suffix of the ids of dialogs and panels (several windows). */
let serial = 0;

/**
 * The links settings of the dock, when the feature is on.
 *
 * @param config Dock configuration.
 * @returns The settings, or null when `links` is absent or disabled.
 */
export function linksSettings(config: ChatConfig): LinksSettings | null {
  return config.links?.enabled === true ? config.links : null;
}

/**
 * The configured provider of a host: the host itself or one of its subdomains (us02web.zoom.us → zoom.us).
 *
 * @param host Host name of the link.
 * @param providers Configured providers.
 * @returns The provider, or null for a generic link.
 */
export function detectProvider(host: string, providers: LinkProvider[]): LinkProvider | null {
  const name = host.toLowerCase().replace(/\.$/, '');
  if (name === '') {
    return null;
  }
  return (
    providers.find((provider) =>
      provider.hosts.some((candidate) => {
        const known = candidate.toLowerCase();
        return name === known || name.endsWith(`.${known}`);
      }),
    ) ?? null
  );
}

/** Result of checking a typed URL: the normalised URL and its host, or a translation key. */
export type LinkCheck = { url: string; host: string } | { error: string; params?: Record<string, string | number> };

/**
 * Checks a URL the way the server does: it must parse, use `https:` (or `http:` when allowed), carry no user name or
 * password and fit the length limit. A URL typed without a scheme (`teams.microsoft.com/l/…`) gets `https://`.
 *
 * @param raw The typed URL.
 * @param settings Links settings.
 * @returns The normalised URL and host, or the error key (and its parameters).
 */
export function checkLinkUrl(raw: string, settings: LinksSettings): LinkCheck {
  let value = raw.trim();
  if (value === '') {
    return { error: 'links_invalid_url' };
  }
  if (!SCHEME.test(value) || HOST_PORT.test(value)) {
    value = `https://${value}`;
  }
  const tooLong = { error: 'links_url_too_long', params: { max: settings.urlMaxLength } };
  if (value.length > settings.urlMaxLength) {
    return tooLong;
  }
  let url: URL;
  try {
    url = new URL(value);
  } catch {
    return { error: 'links_invalid_url' };
  }
  if (url.protocol === 'http:' && !settings.allowHttp) {
    return { error: 'links_http_forbidden' };
  }
  if ((url.protocol !== 'https:' && url.protocol !== 'http:') || url.username !== '' || url.password !== '' || url.hostname === '') {
    return { error: 'links_invalid_url' };
  }
  if (url.href.length > settings.urlMaxLength) {
    return tooLong;
  }
  return { url: url.href, host: url.hostname };
}

/**
 * A title as a token may carry it: no `[`, `]` or line breaks, trimmed, at most `max` characters.
 *
 * @param raw The typed title.
 * @param max Maximum length (0: no limit).
 * @returns The clean title ("" when none).
 */
export function cleanLinkTitle(raw: string, max = 0): string {
  const title = raw.replace(/[\r\n]+/g, ' ').replace(/[[\]]/g, '').trim();
  return max > 0 ? title.slice(0, max).trim() : title;
}

/**
 * The link card token of a message body (`(` and `)` of the URL are percent-encoded so the token stays one piece).
 *
 * @param title Title ("" for none).
 * @param url Normalised URL.
 * @param max Maximum title length (0: no limit).
 * @returns `!link[Title](url)`.
 */
export function linkToken(title: string, url: string, max = 0): string {
  return `!link[${cleanLinkTitle(title, max)}](${url.replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/\s/g, '%20')})`;
}

/**
 * Text of a body with its link card tokens read as their title (or host): the optimistic bubble of a message.
 *
 * @param body Message body with tokens.
 * @returns The text.
 */
export function decodeLinkTokens(body: string): string {
  return body.replace(LINK_TOKEN, (_match, title: string, url: string) => {
    if (title.trim() !== '') {
      return title.trim();
    }
    try {
      return new URL(url).hostname;
    } catch {
      return url;
    }
  });
}

/**
 * Inserts a token at the caret of the composer, on its own line when the caret is within text.
 *
 * @param input The composer.
 * @param token The token.
 */
export function insertLinkToken(input: HTMLTextAreaElement, token: string): void {
  const before = input.value.slice(0, input.selectionStart);
  const after = input.value.slice(input.selectionEnd);
  const prefix = before !== '' && !before.endsWith('\n') ? '\n' : '';
  const suffix = after !== '' && !after.startsWith('\n') ? '\n' : '';
  insertAtCaret(input, `${prefix}${token}${suffix}`);
}

/**
 * The translation key of a failed links call.
 *
 * @param error The error thrown by the API client.
 * @returns The key (and its parameters).
 */
export function linkErrorKey(error: unknown): string {
  const code = error instanceof ChatApiError ? error.code : '';
  return (
    {
      invalid_link_url: 'links_invalid_url',
      invalid_link_title: 'links_invalid_title',
      invalid_link: 'links_invalid_url',
      too_many_links: 'links_too_many',
      links_forbidden: 'links_forbidden',
      not_found: 'links_not_found',
      rate_limited: 'rate_limited',
    } as Record<string, string>
  )[code] ?? 'error';
}

/**
 * Copies text to the clipboard (Clipboard API, else a hidden text box and `execCommand`).
 *
 * @param value Text to copy.
 * @returns Whether it was copied.
 */
export async function copyText(value: string): Promise<boolean> {
  try {
    if (navigator.clipboard !== undefined && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(value);
      return true;
    }
  } catch {
    // Denied (no permission, insecure context): try the fallback.
  }
  const area = h('textarea', { class: 'nowo-chat-sr-only', readonly: true, 'aria-hidden': 'true', tabindex: -1 });
  area.value = value;
  document.body.append(area);
  area.select();
  let copied: boolean;
  try {
    copied = typeof document.execCommand === 'function' && document.execCommand('copy');
  } catch {
    copied = false;
  }
  area.remove();
  return copied;
}

/** What the link dialog needs. */
export interface LinkDialogContext {
  i18n: Record<string, string>;
  settings: LinksSettings;
  templates: Templates;
}

/** One opening of the link dialog. */
export interface LinkDialogOptions {
  /** `add` / `edit` a pinned link, or attach a link card to the message being written. */
  mode: 'add' | 'edit' | 'message';
  /** The pinned link being edited. */
  link?: { url: string; title: string | null };
  /** Saves (or inserts) the link; a thrown API error is shown in the dialog. */
  submit(url: string, title: string): Promise<void> | void;
  /** Receives what the user typed during the close of a `message` dialog, once the token is in. */
  typedDuringClose?(text: string): void;
  /** Unpins the link (edit mode). */
  remove?(): Promise<void>;
  /** Where focus goes once the dialog closes (default: the opener, when it is still in the page). */
  returnFocus?(): HTMLElement | null;
}

/**
 * The link dialog: a modal `<dialog>` (UiKit 2.29 anatomy: header with the title and ×, a scrolling body with the URL,
 * the optional title, the detected provider and the error, and a footer — Remove first, Cancel, the primary last).
 * 1.20 (dialog rule v2): the medium dialog size (`nowo-chat-dialog--md`: `--nowo-dialog-width-md`), its height the
 * content's, capped at `--nowo-dialog-height-md` and the viewport minus 2rem.
 */
export class LinkDialog {
  readonly element: HTMLDialogElement;
  private readonly url: HTMLInputElement;
  private readonly title: HTMLInputElement;
  private readonly detected: HTMLElement;
  private readonly error: HTMLElement;
  private readonly primary: HTMLButtonElement;
  private focusBack = true;
  private finished = false;

  /**
   * Builds the dialog (closed).
   *
   * @param context Strings, settings and icons.
   * @param options Mode, link and actions.
   * @param opener The control that opened it (focus returns there).
   */
  constructor(
    private readonly context: LinkDialogContext,
    private readonly options: LinkDialogOptions,
    private readonly opener: HTMLElement | null,
  ) {
    const i18n = context.i18n;
    serial += 1;
    const id = `nowo-chat-link-dialog-${serial}`;
    const heading = { add: 'links_add', edit: 'links_edit', message: 'links_attach' }[options.mode];
    this.element = h('dialog', { class: 'nowo-chat-dialog nowo-chat-dialog--md nowo-chat-linkdialog', 'aria-labelledby': `${id}-title`, 'data-testid': 'nowo-chat-link-dialog', 'data-mode': options.mode });
    const close = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-linkdialog__close', 'aria-label': t(i18n, 'close'), title: t(i18n, 'close') }, h('span', { 'aria-hidden': 'true', text: '×' }));
    close.addEventListener('click', () => this.close());
    this.url = h('input', {
      type: 'url',
      id: `${id}-url`,
      class: 'nowo-chat-linkdialog__input',
      inputmode: 'url',
      autocomplete: 'off',
      spellcheck: 'false',
      required: true,
      'aria-required': 'true',
      'aria-describedby': `${id}-error`,
      maxlength: context.settings.urlMaxLength,
      placeholder: 'https://',
      'data-part': 'link-url',
    });
    this.url.value = options.link?.url ?? '';
    this.title = h('input', { type: 'text', id: `${id}-label`, class: 'nowo-chat-linkdialog__input', autocomplete: 'off', maxlength: context.settings.titleMaxLength, 'data-part': 'link-title' });
    this.title.value = options.link?.title ?? '';
    this.detected = h('p', { class: 'nowo-chat-linkdialog__detected', 'aria-live': 'polite', 'data-part': 'link-detected', hidden: true });
    this.error = h('p', { class: 'nowo-chat-linkdialog__error', id: `${id}-error`, role: 'alert', 'data-part': 'link-error', hidden: true });
    this.url.addEventListener('input', () => {
      this.showError(null);
      this.detect();
    });
    const cancel = h('button', { type: 'button', class: 'nowo-chat-linkdialog__button', 'data-part': 'link-cancel', text: t(i18n, 'cancel') });
    cancel.addEventListener('click', () => this.close());
    const primaryKey = { add: 'links_add_button', edit: 'save', message: 'links_insert' }[options.mode];
    this.primary = h('button', { type: 'submit', class: 'nowo-chat-linkdialog__button nowo-chat-linkdialog__button--primary', 'data-part': 'link-save', text: t(i18n, primaryKey) });
    const footer = h('footer', { class: 'nowo-chat-linkdialog__footer' });
    if (options.mode === 'edit' && options.remove !== undefined) {
      const remove = h('button', { type: 'button', class: 'nowo-chat-linkdialog__button nowo-chat-linkdialog__button--danger', 'data-part': 'link-remove', text: t(i18n, 'links_remove') });
      remove.addEventListener('click', () => void this.run(() => (options.remove as () => Promise<void>)()));
      footer.append(remove);
    }
    footer.append(cancel, this.primary);
    const form = h(
      'form',
      { class: 'nowo-chat-linkdialog__form', novalidate: true },
      h('header', { class: 'nowo-chat-linkdialog__header' }, h('h2', { class: 'nowo-chat-linkdialog__title', id: `${id}-title`, text: t(i18n, heading) }), close),
      h(
        'div',
        { class: 'nowo-chat-linkdialog__body' },
        h('label', { class: 'nowo-chat-linkdialog__label', for: this.url.id, text: t(i18n, 'links_url') }),
        this.url,
        h('label', { class: 'nowo-chat-linkdialog__label', for: this.title.id, text: t(i18n, 'links_title') }),
        this.title,
        this.detected,
        this.error,
      ),
      footer,
    );
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.save();
    });
    this.element.append(form);
    // Escape closes the dialog only: the window around it must not minimise.
    this.element.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.close();
      }
    });
    this.element.addEventListener('cancel', (event) => {
      event.preventDefault();
      this.close();
    });
    this.element.addEventListener('close', () => this.finish());
    this.detect();
  }

  /**
   * Opens the dialog as a modal (top layer, focus trapped) and focuses the URL.
   *
   * @param container Where the element lives (the conversation window).
   */
  open(container: HTMLElement): void {
    container.append(this.element);
    if (typeof this.element.showModal === 'function') {
      this.element.showModal();
    } else {
      this.element.setAttribute('open', '');
    }
    this.url.focus();
  }

  /**
   * Closes the dialog and gives focus back.
   *
   * @param focusBack Return focus to the opener (false when the caller moves it, e.g. to the composer).
   * @param after Runs when the dialog really closed, after its fade: the page behind a modal dialog cannot take focus before that.
   */
  close(focusBack = true, after?: () => void): void {
    this.focusBack = focusBack;
    closeWithEffect(this.element, () => {
      if (typeof this.element.close === 'function' && this.element.open) {
        this.element.close();
      }
      this.finish();
      after?.();
    });
  }

  /** Removes the closed dialog and returns focus (once). */
  private finish(): void {
    if (this.finished) {
      return;
    }
    this.finished = true;
    this.element.removeAttribute('open');
    this.element.remove();
    if (this.focusBack) {
      const target = this.options.returnFocus?.() ?? this.opener;
      if (target?.isConnected === true) {
        target.focus();
      }
    }
  }

  /** Shows the provider the URL belongs to ("Detected: Microsoft Teams"). */
  private detect(): void {
    const checked = checkLinkUrl(this.url.value, this.context.settings);
    const provider = 'url' in checked ? detectProvider(checked.host, this.context.settings.providers) : null;
    this.detected.hidden = provider === null;
    if (provider === null) {
      this.detected.replaceChildren();
      return;
    }
    this.detected.replaceChildren(this.context.templates.icon(provider.icon, 16, provider.fallbackIcon), h('span', { text: t(this.context.i18n, 'links_detected', { provider: provider.label }) }));
  }

  /**
   * Shows (or hides) the error line and marks the URL invalid.
   *
   * @param message The message, or null to hide it.
   */
  private showError(message: string | null): void {
    this.error.hidden = message === null;
    this.error.textContent = message ?? '';
    if (message === null) {
      this.url.removeAttribute('aria-invalid');
    }
  }

  /** Validates, then saves (or inserts) the link. */
  private async save(): Promise<void> {
    const i18n = this.context.i18n;
    const checked = checkLinkUrl(this.url.value, this.context.settings);
    if ('error' in checked) {
      this.showError(t(i18n, checked.error, checked.params));
      this.url.setAttribute('aria-invalid', 'true');
      this.url.focus();
      return;
    }
    const title = cleanLinkTitle(this.title.value);
    if (title.length > this.context.settings.titleMaxLength) {
      this.showError(t(i18n, 'links_invalid_title', { max: this.context.settings.titleMaxLength }));
      this.title.focus();
      return;
    }
    if (this.options.mode === 'message') {
      // Focus goes to the composer, where the token lands. It cannot before the fade ends: what is typed meanwhile follows the token.
      if (isClosing(this.element)) {
        return;
      }
      const typed: string[] = [];
      this.element.addEventListener('beforeinput', (event) => {
        event.preventDefault();
        if (event.inputType === 'insertText' && event.data !== null) {
          typed.push(event.data);
        }
      });
      await new Promise<void>((resolve) => {
        this.close(false, resolve);
      });
      await this.options.submit(checked.url, title);
      if (typed.length > 0) {
        this.options.typedDuringClose?.(typed.join(''));
      }
      return;
    }
    await this.run(() => this.options.submit(checked.url, title));
  }

  /**
   * Runs a server action with the buttons disabled; closes on success, shows the error otherwise.
   *
   * @param action The call.
   */
  private async run(action: () => Promise<void> | void): Promise<void> {
    const buttons = [...this.element.querySelectorAll<HTMLButtonElement>('.nowo-chat-linkdialog__footer button')];
    buttons.forEach((button) => {
      button.disabled = true;
    });
    try {
      await action();
      this.close();
    } catch (error) {
      const key = linkErrorKey(error);
      const max = { links_too_many: this.context.settings.maxPinned, links_invalid_title: this.context.settings.titleMaxLength, links_invalid_url: this.context.settings.urlMaxLength }[key] ?? 0;
      this.showError(t(this.context.i18n, key, { max }));
    } finally {
      buttons.forEach((button) => {
        button.disabled = false;
      });
    }
  }
}

/** What the links of a conversation window need from it (ThreadView). */
export interface LinksHost {
  config: ChatConfig;
  api: ChatApi;
  templates: Templates;
  /** The conversation window. */
  element: HTMLElement;
  /** The composer text box (link cards are inserted there). */
  input: HTMLTextAreaElement;
  /**
   * Uuid of the conversation shown.
   *
   * @returns The uuid.
   */
  uuid(): string;
  /**
   * Says something in the window's live region.
   *
   * @param message Text to announce.
   */
  announce(message: string): void;
  /**
   * Shows a short notice in the window.
   *
   * @param message Text to show.
   */
  notice(message: string): void;
  /**
   * Shows a message in the thread.
   *
   * @param messageId Message id.
   */
  jumpTo(messageId: number): Promise<void>;
}

/**
 * The links of one conversation window: the pinned-links bar (under the header), the composer's "Attach link" button,
 * the header's "Links" button and its panel.
 */
export class ConversationLinks {
  /** The bar of pinned links (the window inserts it under its header). */
  readonly bar: HTMLElement;
  private state: PinnedLinksState;
  private panel: LinksPanel | null = null;
  private refreshTimer: number | undefined;

  /**
   * Builds the bar and the buttons.
   *
   * @param host The window.
   * @param settings Links settings.
   */
  constructor(
    private readonly host: LinksHost,
    private readonly settings: LinksSettings,
  ) {
    const i18n = host.config.i18n;
    this.state = { links: [], canManage: false, max: settings.maxPinned };
    this.bar = h('div', { class: 'nowo-chat-linkbar', role: 'group', 'aria-label': t(i18n, 'links_bar'), 'data-testid': 'nowo-chat-links-bar', 'data-part': 'links-bar', hidden: true });
    this.addComposerButton();
    if (host.config.endpoints.links !== undefined) {
      this.addPanel();
    }
  }

  /**
   * Whether the panel is open.
   *
   * @returns True while it shows.
   */
  get panelOpen(): boolean {
    return this.panel?.isOpen === true;
  }

  /** Loads the pinned links (the window calls it on open and on a realtime `links` event) and refreshes the panel. */
  async load(): Promise<void> {
    if (this.host.config.endpoints.pinnedLinks === undefined) {
      this.refreshPanel();
      return;
    }
    try {
      // apply() refreshes the open panel too.
      this.apply(await this.host.api.pinnedLinks(this.host.uuid()));
    } catch {
      // The bar stays as it was; the panel still follows.
      this.refreshPanel();
    }
  }

  /** Reloads the panel when it is open. */
  private refreshPanel(): void {
    if (this.panelOpen) {
      void this.panel?.refresh();
    }
  }

  /** Messages arrived or changed: the open panel follows (debounced). */
  messagesChanged(): void {
    if (!this.panelOpen) {
      return;
    }
    window.clearTimeout(this.refreshTimer);
    this.refreshTimer = window.setTimeout(() => void this.panel?.refresh(), REFRESH_DELAY_MS);
  }

  /**
   * Shows the pinned links.
   *
   * @param state Links, permission and limit from the server.
   */
  apply(state: PinnedLinksState): void {
    this.state = state;
    this.renderBar();
    this.refreshPanel();
  }

  /** The bar: a chip per link (opens it in a new tab), Edit per chip and Add link for who may manage them. */
  private renderBar(): void {
    const i18n = this.host.config.i18n;
    const { links, canManage, max } = this.state;
    this.bar.hidden = links.length === 0 && !canManage;
    this.bar.classList.toggle('is-empty', links.length === 0);
    const list = h('ul', { class: 'nowo-chat-linkbar__list' });
    for (const link of links) {
      const item = h('li', { class: 'nowo-chat-linkbar__item', 'data-link': link.uuid });
      item.append(this.chip(link));
      if (canManage) {
        const label = t(i18n, 'links_edit_named', { label: link.label });
        const edit = h('button', { type: 'button', class: 'nowo-chat-linkbar__edit', 'aria-label': label, title: label, 'data-part': 'link-edit' }, this.host.templates.icon('edit', 12));
        edit.addEventListener('click', () => this.openPinnedDialog('edit', link, edit));
        item.append(edit);
      }
      list.append(item);
    }
    if (canManage) {
      const full = links.length >= max;
      const add = h(
        'button',
        {
          type: 'button',
          class: 'nowo-chat-linkbar__add',
          'data-testid': 'nowo-chat-links-add',
          'data-part': 'links-add',
          'aria-haspopup': 'dialog',
          'aria-disabled': full ? 'true' : null,
          title: full ? t(i18n, 'links_too_many', { max }) : t(i18n, 'links_add'),
        },
        this.host.templates.icon('plus', 14),
        h('span', { class: links.length === 0 ? 'nowo-chat-linkbar__add-text' : 'nowo-chat-sr-only', text: t(i18n, 'links_add') }),
      );
      add.addEventListener('click', () => {
        if (this.state.links.length >= this.state.max) {
          this.host.notice(t(i18n, 'links_too_many', { max: this.state.max }));
          return;
        }
        this.openPinnedDialog('add', null, add);
      });
      list.append(h('li', { class: 'nowo-chat-linkbar__item nowo-chat-linkbar__item--add' }, add));
    }
    this.bar.replaceChildren(list);
  }

  /**
   * A pinned link as a chip: provider icon and label, the URL as its title.
   *
   * @param link The link.
   * @returns The chip.
   */
  private chip(link: PinnedLink): HTMLAnchorElement {
    const chip = h('a', {
      class: 'nowo-chat-linkchip',
      href: link.url,
      target: '_blank',
      rel: 'noopener noreferrer',
      title: link.url,
      'data-testid': 'nowo-chat-link-chip',
      'data-provider': link.provider?.key ?? null,
    });
    chip.append(providerIcon(this.host.templates, link.provider, 14), h('span', { class: 'nowo-chat-linkchip__label', text: link.label }));
    return chip;
  }

  /**
   * Opens the dialog to add or edit a pinned link.
   *
   * @param mode `add` or `edit`.
   * @param link The link edited (edit mode).
   * @param opener The control that opened it.
   */
  openPinnedDialog(mode: 'add' | 'edit', link: PinnedLink | null, opener: HTMLElement): void {
    const uuid = this.host.uuid();
    const api = this.host.api;
    /** After the bar re-rendered: the chip of the link, else the Add button. */
    const returnFocus = (): HTMLElement | null => {
      if (opener.isConnected) {
        return opener;
      }
      return (
        (link === null ? null : this.bar.querySelector<HTMLElement>(`[data-link="${link.uuid}"] .nowo-chat-linkchip`)) ??
        this.bar.querySelector<HTMLElement>('.nowo-chat-linkbar__add') ??
        this.host.element.querySelector<HTMLElement>('[data-part="identity"]')
      );
    };
    const dialog = new LinkDialog(
      this.dialogContext(),
      {
        mode,
        link: link === null ? undefined : { url: link.url, title: link.title },
        submit: async (url, title) => {
          this.apply(link === null ? await api.addPinnedLink(uuid, url, title) : await api.updatePinnedLink(uuid, link.uuid, url, title));
        },
        remove:
          link === null
            ? undefined
            : async () => {
                this.apply(await api.removePinnedLink(uuid, link.uuid));
              },
        returnFocus,
      },
      opener,
    );
    dialog.open(this.host.element);
  }

  /** Strings, settings and icons of the dialog. */
  private dialogContext(): LinkDialogContext {
    return { i18n: this.host.config.i18n, settings: this.settings, templates: this.host.templates };
  }

  /** The composer's "Attach link" button (next to the quick replies): inserts a link card token. */
  private addComposerButton(): void {
    const tools = this.host.element.querySelector<HTMLElement>('[data-part="composer-tools"]');
    if (tools === null) {
      return;
    }
    const label = t(this.host.config.i18n, 'links_attach');
    const button = h(
      'button',
      { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-composer__tool', 'data-part': 'attach-link', 'data-testid': 'nowo-chat-attach-link', 'aria-label': label, title: label, 'aria-haspopup': 'dialog' },
      this.host.templates.icon('link', 16),
    );
    button.addEventListener('click', () => {
      new LinkDialog(
        this.dialogContext(),
        { mode: 'message', typedDuringClose: (text) => insertAtCaret(this.host.input, text), submit: (url, title) => insertLinkToken(this.host.input, linkToken(title, url, this.settings.titleMaxLength)) },
        button,
      ).open(this.host.element);
    });
    const after = tools.querySelector('[data-part="quick-replies"]') ?? tools.querySelector('[data-part="format-toggle"]');
    if (after !== null) {
      after.after(button);
    } else {
      tools.prepend(button);
    }
  }

  /** The header's "Links" button and its panel over the window's main area. */
  private addPanel(): void {
    const i18n = this.host.config.i18n;
    const actions = this.host.element.querySelector<HTMLElement>('.nowo-chat-window__actions');
    if (actions === null) {
      return;
    }
    const label = t(i18n, 'links_panel');
    const button = h(
      'button',
      { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-window__links', 'data-part': 'links', 'data-testid': 'nowo-chat-links-open', 'aria-label': label, title: label, 'aria-expanded': 'false' },
      this.host.templates.icon('links'),
    );
    const panel = new LinksPanel(this.host, this.settings, button, (link, opener) => this.openPinnedDialog('edit', link, opener));
    button.setAttribute('aria-controls', panel.element.id);
    button.addEventListener('click', () => (panel.isOpen ? panel.close() : panel.open()));
    actions.insertBefore(button, actions.querySelector('[data-part="popout"]') ?? actions.firstChild);
    (this.host.element.querySelector('.nowo-chat-window__main') ?? this.host.element).append(panel.element);
    this.panel = panel;
  }
}

/**
 * The icon of a link's provider (the application's, else its built-in fallback), or the generic link icon.
 *
 * @param templates Icon source.
 * @param provider The provider, or null.
 * @param size Icon size.
 * @returns The icon (aria-hidden).
 */
function providerIcon(templates: Templates, provider: LinkProviderRef | null, size: number): Element {
  return provider === null ? templates.icon('link', size) : templates.icon(provider.icon, size, provider.fallbackIcon);
}

/**
 * The "Links" panel of a window: search, provider filter, the pinned links and the links shared in messages (newest
 * first, paged), each with Open, Copy link and — for a shared one — Go to message.
 */
class LinksPanel {
  readonly element: HTMLElement;
  private readonly search: HTMLInputElement;
  private readonly provider: HTMLSelectElement;
  private readonly state: HTMLElement;
  private readonly pinnedSection: HTMLElement;
  private readonly pinnedList: HTMLElement;
  private readonly sharedSection: HTMLElement;
  private readonly sharedList: HTMLElement;
  private readonly more: HTMLButtonElement;
  private next: number | null = null;
  private sequence = 0;
  private searchTimer: number | undefined;
  private canManage = false;
  private pinned: PinnedLink[] = [];

  /**
   * Builds the (closed) panel.
   *
   * @param host The window.
   * @param settings Links settings (providers of the filter).
   * @param toggle The header button.
   * @param edit Opens the edit dialog of a pinned link.
   */
  constructor(
    private readonly host: LinksHost,
    settings: LinksSettings,
    private readonly toggle: HTMLButtonElement,
    private readonly edit: (link: PinnedLink, opener: HTMLElement) => void,
  ) {
    const i18n = host.config.i18n;
    serial += 1;
    const id = `nowo-chat-links-${serial}`;
    const back = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-links__close', 'aria-label': t(i18n, 'close'), title: t(i18n, 'close'), 'data-part': 'links-close' }, host.templates.icon('back'));
    back.addEventListener('click', () => this.close());
    this.search = h('input', { type: 'search', id: `${id}-q`, class: 'nowo-chat-links__search', placeholder: t(i18n, 'links_search'), autocomplete: 'off', 'data-part': 'links-search' });
    this.search.addEventListener('input', () => {
      window.clearTimeout(this.searchTimer);
      this.searchTimer = window.setTimeout(() => void this.refresh(), SEARCH_DELAY_MS);
    });
    this.provider = h('select', { id: `${id}-provider`, class: 'nowo-chat-links__provider', 'data-part': 'links-provider' });
    this.fillProviders(settings.providers);
    this.provider.addEventListener('change', () => void this.refresh());
    this.state = h('p', { class: 'nowo-chat-links__state', 'data-part': 'links-state', hidden: true });
    this.pinnedList = h('ul', { class: 'nowo-chat-links__list', 'data-part': 'links-pinned' });
    this.pinnedSection = h('section', { class: 'nowo-chat-links__section', 'aria-labelledby': `${id}-pinned`, hidden: true }, h('h3', { class: 'nowo-chat-links__heading', id: `${id}-pinned`, text: t(i18n, 'links_pinned') }), this.pinnedList);
    this.sharedList = h('ul', { class: 'nowo-chat-links__list', 'data-part': 'links-shared' });
    this.more = h('button', { type: 'button', class: 'nowo-chat-links__more', 'data-part': 'links-more', text: t(i18n, 'links_load_more'), hidden: true });
    this.more.addEventListener('click', () => void this.loadMore());
    this.sharedSection = h(
      'section',
      { class: 'nowo-chat-links__section', 'aria-labelledby': `${id}-shared`, hidden: true },
      h('h3', { class: 'nowo-chat-links__heading', id: `${id}-shared`, text: t(i18n, 'links_shared') }),
      this.sharedList,
      this.more,
    );
    this.element = h(
      'section',
      { class: 'nowo-chat-links', id, role: 'region', 'aria-labelledby': `${id}-title`, 'data-testid': 'nowo-chat-links-panel', hidden: true },
      h('header', { class: 'nowo-chat-links__header' }, back, h('h2', { class: 'nowo-chat-links__title', id: `${id}-title`, text: t(i18n, 'links_panel') })),
      h(
        'div',
        { class: 'nowo-chat-links__filters' },
        h('label', { class: 'nowo-chat-sr-only', for: this.search.id, text: t(i18n, 'links_search') }),
        this.search,
        h('label', { class: 'nowo-chat-sr-only', for: this.provider.id, text: t(i18n, 'links_filter') }),
        this.provider,
      ),
      h('div', { class: 'nowo-chat-links__body' }, this.state, this.pinnedSection, this.sharedSection),
    );
    this.element.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.close();
      }
    });
  }

  /**
   * Whether the panel shows.
   *
   * @returns True while open.
   */
  get isOpen(): boolean {
    return !this.element.hidden;
  }

  /** Opens the panel, focuses the search and loads the first page. */
  async open(): Promise<void> {
    this.element.hidden = false;
    this.toggle.setAttribute('aria-expanded', 'true');
    this.search.focus();
    await this.refresh();
  }

  /** Closes the panel and returns focus to the header button. */
  close(): void {
    this.element.hidden = true;
    this.toggle.setAttribute('aria-expanded', 'false');
    window.clearTimeout(this.searchTimer);
    this.toggle.focus();
  }

  /** Loads the first page with the current search and filter (stale answers are dropped). */
  async refresh(): Promise<void> {
    const sequence = ++this.sequence;
    this.element.setAttribute('aria-busy', 'true');
    try {
      const page = await this.host.api.links(this.host.uuid(), { q: this.search.value, provider: this.provider.value });
      if (sequence !== this.sequence) {
        return;
      }
      this.render(page, false);
    } catch {
      if (sequence === this.sequence) {
        this.showState(t(this.host.config.i18n, 'error'));
      }
    } finally {
      if (sequence === this.sequence) {
        this.element.removeAttribute('aria-busy');
      }
    }
  }

  /** Appends the next page of shared links. */
  private async loadMore(): Promise<void> {
    if (this.next === null) {
      return;
    }
    const sequence = ++this.sequence;
    this.more.disabled = true;
    try {
      const page = await this.host.api.links(this.host.uuid(), { q: this.search.value, provider: this.provider.value, before: this.next });
      if (sequence === this.sequence) {
        this.render(page, true);
      }
    } catch {
      this.host.notice(t(this.host.config.i18n, 'error'));
    } finally {
      this.more.disabled = false;
    }
  }

  /**
   * Paints a page.
   *
   * @param page Panel data.
   * @param append Adds shared links to the list (next page) instead of replacing it.
   */
  private render(page: LinksPage, append: boolean): void {
    const i18n = this.host.config.i18n;
    this.canManage = page.canManage;
    if (page.providers.length > 0) {
      this.fillProviders(page.providers);
    }
    if (!append) {
      this.pinned = page.pinned ?? [];
      this.pinnedList.replaceChildren(...this.pinned.map((link) => this.item(link, null)));
      this.sharedList.replaceChildren();
    }
    this.sharedList.append(...page.links.map((link) => this.item(link, link.messageId)));
    this.next = page.hasMore ? page.next : null;
    this.more.hidden = this.next === null;
    this.pinnedSection.hidden = this.pinned.length === 0;
    const shared = this.sharedList.childElementCount;
    this.sharedSection.hidden = shared === 0;
    if (this.pinned.length === 0 && shared === 0) {
      this.showState(t(i18n, this.search.value.trim() !== '' || this.provider.value !== '' ? 'links_no_results' : 'links_empty'));
    } else {
      this.state.hidden = true;
    }
  }

  /**
   * Shows the empty / no results / error line instead of the sections.
   *
   * @param message Text.
   */
  private showState(message: string): void {
    this.state.hidden = false;
    this.state.textContent = message;
    this.pinnedSection.hidden = true;
    this.sharedSection.hidden = true;
  }

  /**
   * The provider filter: All, each provider, Other (the selection is kept).
   *
   * @param providers Providers to offer.
   */
  private fillProviders(providers: LinkProviderRef[]): void {
    const i18n = this.host.config.i18n;
    const current = this.provider.value;
    const options = [h('option', { value: '', text: t(i18n, 'links_filter_all') }), ...providers.map((provider) => h('option', { value: provider.key, text: provider.label })), h('option', { value: 'other', text: t(i18n, 'links_filter_other') })];
    this.provider.replaceChildren(...options);
    this.provider.value = options.some((option) => option.value === current) ? current : '';
  }

  /**
   * One link: icon, label, host, who and when, and its actions.
   *
   * @param link A pinned or shared link.
   * @param messageId The message that shared it (null for a pinned link).
   * @returns The list item.
   */
  private item(link: PinnedLink | SharedLink, messageId: number | null): HTMLElement {
    const i18n = this.host.config.i18n;
    const person: LinkPerson | null = 'createdBy' in link ? link.createdBy : link.author;
    const meta = t(i18n, 'links_shared_by', { name: person?.name ?? t(i18n, 'deleted_user'), date: listTimeLabel(link.createdAt, this.host.config.locale) });
    const named = (key: string): string => `${t(i18n, key)} — ${link.label}`;
    const open = h('a', { class: 'nowo-chat-icon-btn nowo-chat-links__action', href: link.url, target: '_blank', rel: 'noopener noreferrer', 'aria-label': named('links_open'), title: t(i18n, 'links_open'), 'data-part': 'link-open' }, this.host.templates.icon('external', 16));
    const copy = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-links__action', 'aria-label': named('links_copy'), title: t(i18n, 'links_copy'), 'data-part': 'link-copy' }, this.host.templates.icon('copy', 16));
    copy.addEventListener('click', () => {
      void copyText(link.url).then((copied) => (copied ? this.host.announce(t(i18n, 'links_copied')) : this.host.notice(t(i18n, 'error'))));
    });
    const actions = h('span', { class: 'nowo-chat-links__actions' }, open, copy);
    if (messageId !== null) {
      const go = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-links__action', 'aria-label': named('links_goto'), title: t(i18n, 'links_goto'), 'data-part': 'link-goto' }, this.host.templates.icon('goto', 16));
      go.addEventListener('click', () => {
        this.close();
        void this.host.jumpTo(messageId);
      });
      actions.append(go);
    } else if (this.canManage && 'uuid' in link) {
      const edit = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-links__action', 'aria-label': t(i18n, 'links_edit_named', { label: link.label }), title: t(i18n, 'links_edit'), 'data-part': 'link-edit' }, this.host.templates.icon('edit', 16));
      edit.addEventListener('click', () => this.edit(link, edit));
      actions.append(edit);
    }
    return h(
      'li',
      { class: 'nowo-chat-links__item', 'data-provider': link.provider?.key ?? null },
      h('span', { class: 'nowo-chat-links__icon', 'aria-hidden': 'true' }, providerIcon(this.host.templates, link.provider, 18)),
      h('span', { class: 'nowo-chat-links__text' }, h('span', { class: 'nowo-chat-links__label', text: link.label }), h('span', { class: 'nowo-chat-links__host', text: link.host }), h('span', { class: 'nowo-chat-links__meta', text: meta })),
      actions,
    );
  }
}

