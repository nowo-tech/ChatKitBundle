/**
 * Chat status (1.10): the user's own status menu in the dock (available, busy, away, do not disturb, invisible, a
 * custom text with an emoji and when it clears), the shared activity clock behind auto-away, and the helpers that
 * show someone's status (dot class, label, custom text).
 *
 * The menu is a popover (`role="dialog"`) holding an ARIA menu of `menuitemradio` options (↑ ↓ Home End move,
 * Enter / Space pick, Escape closes and returns focus to the trigger) and the custom-status form.
 */
import type { ChatApi } from './api';
import { h } from './dom';
import { emojiPicker } from './emoji';
import { t } from './format';
import { part } from './templates';
import type { ChatConfig, ChatStatus, OwnStatus, VisibleStatus } from './types';

/** Statuses a user picks, in menu order. */
export const STATUSES: readonly ChatStatus[] = ['available', 'busy', 'away', 'dnd', 'invisible'];

/** Ends an idle stretch: any of these in any tab of the browser. */
const ACTIVITY_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart'] as const;

/** Tabs write the shared activity time at most this often. */
const WRITE_EVERY_MS = 15_000;

/**
 * The user's own status as it stands now: an expired one is back to available, without text.
 *
 * @param status The stored status.
 * @param now Current time (ms).
 * @returns The status in force.
 */
export function currentStatus(status: OwnStatus, now = Date.now()): OwnStatus {
  if (status.expiresAt !== null && Date.parse(status.expiresAt) <= now) {
    return { status: 'available', text: null, emoji: null, expiresAt: null };
  }
  return status;
}

/**
 * How the user's own status looks on their own avatar (invisible shows the offline ring).
 *
 * @param status The user's own status.
 * @returns The visible status.
 */
export function ownVisible(status: ChatStatus): VisibleStatus {
  return status === 'invisible' ? 'offline' : status;
}

/**
 * Translated name of a status.
 *
 * @param i18n Dock strings.
 * @param status A picked or visible status.
 * @returns The label ("Busy", "Do not disturb" …).
 */
export function statusLabel(i18n: Record<string, string>, status: ChatStatus | VisibleStatus): string {
  return t(i18n, `status_${status}`);
}

/**
 * The custom status as one line ("🌴 On holiday"), empty when there is none.
 *
 * @param text Custom text.
 * @param emoji Custom emoji.
 * @returns The line.
 */
export function customStatus(text: string | null | undefined, emoji: string | null | undefined): string {
  return [emoji ?? '', text ?? ''].filter((value) => value !== '').join(' ');
}

/**
 * Activity shared by every tab of the browser (localStorage): the user is idle once no tab saw input for the
 * auto-away delay.
 */
export class ActivityTracker {
  private last = Date.now();
  private written = 0;
  private readonly key: string;

  /**
   * Starts listening for input in this tab.
   *
   * @param userUuid Owner of the shared clock.
   * @param signal Stops listening.
   */
  constructor(userUuid: string, signal: AbortSignal) {
    this.key = `nowo-chat-activity:${userUuid}`;
    for (const name of ACTIVITY_EVENTS) {
      document.addEventListener(name, () => this.touch(), { signal, passive: true, capture: true });
    }
    this.touch();
  }

  /**
   * Records input now (the shared clock is written at most every 15 s).
   *
   * @param now Current time (ms).
   */
  touch(now = Date.now()): void {
    this.last = now;
    if (now - this.written < WRITE_EVERY_MS) {
      return;
    }
    this.written = now;
    try {
      window.localStorage.setItem(this.key, String(now));
    } catch {
      // No storage: this tab's own activity still counts.
    }
  }

  /**
   * Whether no tab saw input for the delay.
   *
   * @param minutes Auto-away delay; 0 or less = never idle.
   * @param now Current time (ms).
   * @returns True when idle.
   */
  isIdle(minutes: number, now = Date.now()): boolean {
    if (minutes <= 0) {
      return false;
    }
    return now - Math.max(this.last, this.shared()) >= minutes * 60_000;
  }

  /** Last input any tab wrote (0 without storage). */
  private shared(): number {
    try {
      return Number(window.localStorage.getItem(this.key)) || 0;
    } catch {
      return 0;
    }
  }
}

/** What the status menu needs from the dock. */
export interface StatusHost {
  readonly config: ChatConfig;
  readonly api: ChatApi;
}

/**
 * The user's own status: the trigger (avatar with the status dot) and its popover menu.
 */
export class StatusMenu {
  readonly popover: HTMLElement;
  private status: OwnStatus;
  private readonly options: HTMLButtonElement[];
  private readonly form: HTMLFormElement;
  private readonly textInput: HTMLInputElement;
  private readonly emojiInput: HTMLInputElement;
  private readonly clearAfter: HTMLSelectElement;
  private readonly error: HTMLElement;

  /**
   * Binds the trigger and the popover (cloned from the `status-menu` template) and paints the current status.
   *
   * @param host The dock (configuration and API).
   * @param trigger The button that opens the menu.
   * @param popover The menu popover.
   * @param onChange Called with the stored status after every change.
   * @param signal Removes the document listeners.
   */
  constructor(
    private readonly host: StatusHost,
    readonly trigger: HTMLButtonElement,
    popover: HTMLElement,
    private readonly onChange: (status: OwnStatus) => void,
    signal: AbortSignal,
  ) {
    this.popover = popover;
    this.status = currentStatus(host.config.status as OwnStatus);
    this.options = [...popover.querySelectorAll<HTMLButtonElement>('[data-status]')];
    this.form = part<HTMLFormElement>(popover, 'status-form');
    this.textInput = part<HTMLInputElement>(popover, 'status-text');
    this.emojiInput = part<HTMLInputElement>(popover, 'status-emoji');
    this.clearAfter = part<HTMLSelectElement>(popover, 'status-clear-after');
    this.error = part(popover, 'status-error');
    trigger.hidden = false;
    trigger.addEventListener('click', () => (this.isOpen ? this.close(true) : this.open()));
    this.options.forEach((option, index) => {
      option.addEventListener('click', () => void this.save({ status: option.dataset.status as ChatStatus }));
      option.addEventListener('keydown', (event) => this.onOptionKey(event, index));
    });
    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.save({});
    });
    // 1.10.1: suggested statuses (emoji, text, when it clears) save in one click; the emoji button opens the chat's emoji
    // picker inside the menu (never clipped by it).
    popover.querySelectorAll<HTMLButtonElement>('[data-preset-emoji]').forEach((preset) => {
      preset.addEventListener('click', () => {
        this.emojiInput.value = preset.dataset.presetEmoji ?? '';
        this.textInput.value = preset.dataset.presetText ?? '';
        this.clearAfter.value = preset.dataset.presetClearAfter ?? 'never';
        void this.save({});
      });
    });
    const pick = popover.querySelector<HTMLButtonElement>('[data-part="status-emoji-pick"]');
    const holder = popover.querySelector<HTMLElement>('[data-part="status-emoji-panel"]');
    if (pick !== null && holder !== null) {
      /** Closes the picker and returns focus to its button. */
      const closePicker = (): void => {
        holder.replaceChildren();
        pick.setAttribute('aria-expanded', 'false');
        pick.focus();
      };
      pick.addEventListener('click', () => {
        if (holder.childElementCount > 0) {
          closePicker();
          return;
        }
        const panel = emojiPicker(
          host.config.i18n,
          (emoji) => {
            this.emojiInput.value = emoji;
            closePicker();
          },
          closePicker,
        );
        holder.append(panel);
        pick.setAttribute('aria-expanded', 'true');
        panel.querySelector<HTMLButtonElement>('button')?.focus();
      });
    }
    this.bindAutoReply(popover);
    part(popover, 'status-clear').addEventListener('click', () => {
      this.textInput.value = '';
      this.emojiInput.value = '';
      this.clearAfter.value = 'never';
      void this.save({ text: null, emoji: null });
    });
    popover.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        this.close(true);
      }
    });
    document.addEventListener(
      'click',
      (event) => {
        const target = event.target as Node;
        if (this.isOpen && !popover.contains(target) && !trigger.contains(target)) {
          this.close(false);
        }
      },
      { signal },
    );
    this.paint();
  }

  /**
   * Whether the menu is open.
   *
   * @returns True when open.
   */
  get isOpen(): boolean {
    return !this.popover.hidden;
  }

  /**
   * The user's own status in force now.
   *
   * @returns The status.
   */
  get current(): OwnStatus {
    return currentStatus(this.status);
  }

  /** Opens the menu with focus on the status in force. */
  open(): void {
    this.popover.hidden = false;
    this.trigger.setAttribute('aria-expanded', 'true');
    this.error.hidden = true;
    const checked = this.options.find((option) => option.getAttribute('aria-checked') === 'true') ?? this.options[0];
    checked?.focus();
  }

  /**
   * Closes the menu.
   *
   * @param focusTrigger Moves focus back to the trigger (keyboard close).
   */
  close(focusTrigger: boolean): void {
    this.popover.hidden = true;
    this.trigger.setAttribute('aria-expanded', 'false');
    if (focusTrigger) {
      this.trigger.focus();
    }
  }

  /**
   * Shows a status changed elsewhere (another tab, the presence answer, an expiry).
   *
   * @param status The stored status.
   */
  apply(status: OwnStatus): void {
    this.status = status;
    this.paint();
  }

  /**
   * Stores a change: the picked status (or the one in force) with the form's text, emoji and "clear after".
   *
   * @param change Status and / or text and emoji to set.
   */
  private async save(change: { status?: ChatStatus; text?: string | null; emoji?: string | null }): Promise<void> {
    const i18n = this.host.config.i18n;
    try {
      const stored = await this.host.api.setStatus({
        status: change.status ?? this.current.status,
        text: change.text === undefined ? this.textInput.value.trim() || null : change.text,
        emoji: change.emoji === undefined ? this.emojiInput.value.trim() || null : change.emoji,
        clearAfter: this.clearAfter.value || 'never',
      });
      this.apply(stored);
      this.onChange(stored);
      this.close(true);
    } catch {
      this.error.textContent = t(i18n, 'status_error');
      this.error.hidden = false;
    }
  }

  /**
   * The automatic reply section (1.11), when the template and the configuration have it: filled from the
   * configuration, the date range shown only for "between two dates", saved on its own.
   */
  private bindAutoReply(popover: HTMLElement): void {
    const section = popover.querySelector<HTMLElement>('[data-part="auto-reply"]');
    const current = this.host.config.autoReply;
    if (section === null || current === undefined || this.host.config.endpoints.autoReply === undefined) {
      section?.remove();
      return;
    }
    const field = <T extends HTMLElement>(name: string): T => part<T>(section, name);
    const enabled = field<HTMLInputElement>('auto-reply-enabled');
    const text = field<HTMLTextAreaElement>('auto-reply-text');
    const when = field<HTMLSelectElement>('auto-reply-when');
    const from = field<HTMLInputElement>('auto-reply-from');
    const until = field<HTMLInputElement>('auto-reply-until');
    const mentions = field<HTMLInputElement>('auto-reply-mentions');
    const range = field('auto-reply-range');
    const error = field('auto-reply-error');
    /** `datetime-local` value of an ISO date, in the browser's time zone. */
    const local = (iso: string | null): string => {
      if (iso === null) {
        return '';
      }
      const date = new Date(iso);
      return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
    };
    const iso = (value: string): string | null => (value === '' ? null : new Date(value).toISOString());
    enabled.checked = current.enabled;
    text.value = current.text;
    when.value = current.when;
    from.value = local(current.from);
    until.value = local(current.until);
    mentions.checked = current.mentions;
    const showRange = (): void => {
      range.hidden = when.value !== 'range';
    };
    showRange();
    when.addEventListener('change', showRange);
    part(section, 'auto-reply-save').addEventListener('click', () => {
      void this.host.api
        .saveAutoReply({ enabled: enabled.checked, text: text.value, when: when.value === 'range' ? 'range' : 'status', from: iso(from.value), until: iso(until.value), mentions: mentions.checked })
        .then((saved) => {
          this.host.config.autoReply = saved;
          error.hidden = true;
          this.close(true);
        })
        .catch(() => {
          error.textContent = t(this.host.config.i18n, 'auto_reply_invalid');
          error.hidden = false;
        });
    });
  }

  /** Arrow keys, Home and End move between the options (roving focus). */
  private onOptionKey(event: KeyboardEvent, index: number): void {
    const last = this.options.length - 1;
    const next = { ArrowDown: index === last ? 0 : index + 1, ArrowUp: index === 0 ? last : index - 1, Home: 0, End: last }[event.key];
    if (next === undefined) {
      return;
    }
    event.preventDefault();
    this.options[next]?.focus();
  }

  /** Trigger (dot, accessible name) and menu (checked option, form values) from the status in force. */
  private paint(): void {
    const i18n = this.host.config.i18n;
    const status = this.current;
    const custom = customStatus(status.text, status.emoji);
    const label = t(i18n, 'status_yours', { status: statusLabel(i18n, status.status) }) + (custom === '' ? '' : ` — ${custom}`);
    this.trigger.dataset.status = status.status;
    // 1.11.1 (WCAG 2.5.3, axe label-content-name-mismatch): the accessible name starts with the visible text — the
    // initials shown on the button — as a word of its own.
    const visible = (this.trigger.textContent ?? '').trim();
    this.trigger.setAttribute('aria-label', visible === '' ? label : `${visible} — ${label}`);
    this.trigger.title = label;
    const dot = this.trigger.querySelector<HTMLElement>('[data-part="me-dot"]');
    if (dot !== null) {
      dot.className = `nowo-chat-status-dot nowo-chat-status-dot--${ownVisible(status.status)}`;
    }
    this.options.forEach((option) => {
      const checked = option.dataset.status === status.status;
      option.setAttribute('aria-checked', checked ? 'true' : 'false');
      option.tabIndex = checked ? 0 : -1;
    });
    this.textInput.value = status.text ?? '';
    this.emojiInput.value = status.emoji ?? '';
  }
}

/**
 * The status dot next to someone's avatar or name.
 *
 * @param status What the viewer sees of the status.
 * @returns The dot (aria-hidden).
 */
export function statusDot(status: VisibleStatus): HTMLElement {
  return h('span', { class: `nowo-chat-status-dot nowo-chat-status-dot--${status}`, 'aria-hidden': 'true' });
}
