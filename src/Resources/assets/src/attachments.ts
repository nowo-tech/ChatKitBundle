/**
 * 2.7 (spec 259 block B): files in the dock.
 *
 * - The composer's attach button (a native `<button>` that opens a hidden `<input type="file" multiple>`) uploads
 *   each file as a draft. Only its uploader sees a draft; the server scans it before it can be sent.
 * - The tray lists the drafts with their state (`<progress>` while uploading, then "Scanning…", "Ready", "Blocked",
 *   "Could not be checked"); each chip has a remove button. The send waits until every file is clean
 *   ("send when ready"), and refuses blocked or failed files.
 * - A message's files are cards (name, size, kind) with a Download button: the download URL is asked for at click
 *   time (signed for this reader, 5 minutes), never kept in the page. File names are always text nodes.
 */
import { h } from './dom';
import { t } from './format';
import type { AttachmentSettings, AttachmentState, AttachmentStub } from './types';

/** The calls the tray needs (ChatApi). */
export interface AttachmentApi {
  uploadAttachment(conversationUuid: string, file: File, key: string): Promise<AttachmentStub>;
  attachmentDrafts(conversationUuid: string): Promise<AttachmentStub[]>;
  attachment(uuid: string): Promise<{ attachment: AttachmentStub; url: string | null }>;
  removeAttachment(uuid: string): Promise<void>;
}

/** What the tray is given by its window. */
export interface AttachmentTrayHost {
  i18n: Record<string, string>;
  settings: AttachmentSettings;
  api: AttachmentApi;
  conversationUuid: () => string;
  icon: (name: string, size?: number) => Element;
  /** The composer re-checks its send button. */
  changed: () => void;
  /** Where focus goes when the last chip is removed. */
  focusAfterRemove?: () => void;
  /** A new idempotency key (tests pass a fixed one). */
  key?: () => string;
}

/** A chip of the tray: a file being uploaded, or a draft the server knows. */
interface Chip {
  key: string;
  name: string;
  size: number;
  state: AttachmentState | 'uploading' | 'error';
  uuid: string | null;
  error: string;
  element: HTMLElement;
}

/** States that still wait for the scan. */
const WAITING: ReadonlyArray<Chip['state']> = ['uploading', 'uploaded', 'scanning'];

/**
 * A human size (B, KB, MB).
 *
 * @param bytes Size in bytes.
 * @returns The label.
 */
export function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${Math.ceil(bytes / 1024)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * The lower-case extension of a file name ('' when none).
 *
 * @param name File name.
 * @returns The extension.
 */
export function extensionOf(name: string): string {
  const dot = name.lastIndexOf('.');
  return dot < 0 ? '' : name.slice(dot + 1).toLowerCase();
}

/** The composer's attachments. */
export class AttachmentTray {
  readonly element: HTMLElement;
  readonly button: HTMLButtonElement;
  readonly input: HTMLInputElement;
  private readonly host: AttachmentTrayHost;
  private readonly list: HTMLElement;
  private readonly note: HTMLElement;
  private chips: Chip[] = [];

  /**
   * @param host Strings, settings, calls and icons.
   */
  constructor(host: AttachmentTrayHost) {
    this.host = host;
    const i18n = host.i18n;
    const label = t(i18n, 'attachment.attach');
    this.input = h('input', { type: 'file', multiple: true, hidden: true, accept: host.settings.extensions.map((ext) => `.${ext}`).join(','), 'data-part': 'attach-input', tabindex: '-1' });
    this.button = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-composer__tool', 'data-part': 'attach-file', 'data-testid': 'nowo-chat-attach-file', 'aria-label': label, title: t(i18n, 'attachment.attach_hint', { max: formatSize(host.settings.maxBytes) }) }, host.icon('paperclip', 16));
    this.list = h('ul', { class: 'nowo-chat-files nowo-chat-files--draft', role: 'list' });
    // Errors and the "send when ready" note: a polite live region (never on every state change).
    this.note = h('p', { class: 'nowo-chat-files__note', role: 'status', 'aria-live': 'polite' });
    this.element = h('div', { class: 'nowo-chat-attachtray', role: 'group', 'aria-label': t(i18n, 'attachment.files'), 'data-testid': 'nowo-chat-attachtray', hidden: true }, this.list, this.note);
    this.button.addEventListener('click', () => this.input.click());
    this.input.addEventListener('change', () => {
      const files = Array.from(this.input.files ?? []);
      this.input.value = '';
      void this.addFiles(files);
    });
  }

  /**
   * Uploads files (each checked here first against the size limit, the allowed extensions and the per-message cap).
   *
   * @param files The files picked or dropped.
   */
  async addFiles(files: File[]): Promise<void> {
    const i18n = this.host.i18n;
    const settings = this.host.settings;
    const uploads: Promise<void>[] = [];
    for (const file of files) {
      if (this.chips.length >= settings.maxPerMessage) {
        this.say(t(i18n, 'attachment.too_many', { max: settings.maxPerMessage }));
        break;
      }
      if (file.size > settings.maxBytes) {
        this.say(t(i18n, 'attachment.too_large', { max: formatSize(settings.maxBytes) }));
        continue;
      }
      if (!settings.extensions.includes(extensionOf(file.name))) {
        this.say(t(i18n, 'attachment.not_allowed'));
        continue;
      }
      uploads.push(this.upload(file));
    }
    await Promise.all(uploads);
  }

  /**
   * Applies a state the server announced (realtime `attachment` event of this person).
   *
   * @param uuid Attachment uuid.
   * @param state New state.
   */
  setState(uuid: string, state: AttachmentState): void {
    const chip = this.chips.find((item) => item.uuid === uuid);
    if (chip !== undefined) {
      chip.state = state;
      this.paint(chip);
      this.host.changed();
    }
  }

  /** Reads the drafts' states again from the server (a reconnect, a poll while waiting). */
  async refresh(): Promise<void> {
    if (!this.chips.some((chip) => chip.uuid !== null)) {
      return;
    }
    const drafts = await this.host.api.attachmentDrafts(this.host.conversationUuid());
    for (const chip of this.chips) {
      const draft = drafts.find((item) => item.uuid === chip.uuid);
      if (chip.uuid !== null && draft !== undefined && draft.state !== chip.state) {
        chip.state = draft.state;
        this.paint(chip);
      }
    }
    this.host.changed();
  }

  /** @returns The uuids of the drafts to send, in order. */
  get uuids(): string[] {
    return this.chips.flatMap((chip) => (chip.uuid === null ? [] : [chip.uuid]));
  }

  /** @returns Whether there are files in the tray. */
  get hasFiles(): boolean {
    return this.chips.length > 0;
  }

  /** @returns Whether every file is clean. */
  get ready(): boolean {
    return this.chips.length > 0 && this.chips.every((chip) => chip.state === 'clean');
  }

  /** @returns Whether a file can never be sent (blocked, failed, or its upload failed): the user removes it. */
  get rejected(): boolean {
    return this.chips.some((chip) => chip.state === 'blocked' || chip.state === 'failed' || chip.state === 'error');
  }

  /**
   * Send when ready: resolves true once every file is clean, false when one is rejected or the wait times out. Polls
   * the drafts every `intervalMs` (realtime events make it sooner).
   *
   * @param timeoutMs Longest wait.
   * @param intervalMs Poll interval.
   * @returns Whether the files may be sent.
   */
  async waitUntilReady(timeoutMs = 120_000, intervalMs = 2_000): Promise<boolean> {
    const until = Date.now() + timeoutMs;
    this.say(t(this.host.i18n, 'attachment.waiting'));
    while (!this.ready && !this.rejected && Date.now() < until) {
      await new Promise((resolve) => window.setTimeout(resolve, intervalMs));
      try {
        await this.refresh();
      } catch {
        // A failed poll is retried at the next interval.
      }
    }
    this.say('');
    return this.ready;
  }

  /** Empties the tray after a send (the files went with the message). */
  reset(): void {
    this.chips = [];
    this.list.replaceChildren();
    this.say('');
    this.refreshVisibility();
  }

  private async upload(file: File): Promise<void> {
    const chip: Chip = { key: this.host.key?.() ?? crypto.randomUUID(), name: file.name, size: file.size, state: 'uploading', uuid: null, error: '', element: h('li', { class: 'nowo-chat-file nowo-chat-file--draft' }) };
    this.chips.push(chip);
    this.list.append(chip.element);
    this.paint(chip);
    this.refreshVisibility();
    this.host.changed();
    try {
      const draft = await this.host.api.uploadAttachment(this.host.conversationUuid(), file, chip.key);
      chip.uuid = draft.uuid;
      chip.name = draft.name;
      chip.state = draft.state;
    } catch (error) {
      chip.state = 'error';
      const code = typeof error === 'object' && error !== null && 'code' in error ? String((error as { code: unknown }).code) : '';
      chip.error = t(this.host.i18n, code === 'file_too_large' ? 'attachment.too_large' : code === 'file_type_not_allowed' || code === 'type_mismatch' ? 'attachment.not_allowed' : 'attachment.error', { max: formatSize(this.host.settings.maxBytes) });
      this.say(chip.error);
    }
    this.paint(chip);
    this.host.changed();
  }

  /** Draws one chip: name (text), size, state, remove button. */
  private paint(chip: Chip): void {
    const i18n = this.host.i18n;
    const states: Record<Chip['state'], string> = {
      uploading: t(i18n, 'attachment.uploading'),
      uploaded: t(i18n, 'attachment.scanning'),
      scanning: t(i18n, 'attachment.scanning'),
      clean: t(i18n, 'attachment.clean'),
      blocked: t(i18n, 'attachment.blocked'),
      failed: t(i18n, 'attachment.failed'),
      error: chip.error,
    };
    const remove = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-file__remove', 'aria-label': `${t(i18n, 'attachment.remove')}: ${chip.name}`, title: t(i18n, 'attachment.remove') }, h('span', { 'aria-hidden': 'true', text: '×' }));
    remove.addEventListener('click', () => void this.remove(chip));
    chip.element.dataset.state = chip.state;
    chip.element.replaceChildren(
      this.host.icon('file', 14),
      h('span', { class: 'nowo-chat-file__name', text: chip.name }),
      h('span', { class: 'nowo-chat-file__size', text: formatSize(chip.size) }),
      // An in-progress state is a progress bar, not a live announcement (Modern Web Guidance).
      WAITING.includes(chip.state) ? h('progress', { class: 'nowo-chat-file__progress', 'aria-label': states[chip.state] }) : h('span', { class: 'nowo-chat-file__state', text: states[chip.state] }),
      remove,
    );
  }

  private async remove(chip: Chip): Promise<void> {
    const index = this.chips.indexOf(chip);
    const next = (this.chips[index + 1] ?? this.chips[index - 1])?.element.querySelector<HTMLButtonElement>('.nowo-chat-file__remove') ?? null;
    this.chips = this.chips.filter((item) => item !== chip);
    chip.element.remove();
    this.refreshVisibility();
    this.host.changed();
    if (next !== null) {
      next.focus();
    } else {
      this.host.focusAfterRemove?.();
    }
    if (chip.uuid !== null) {
      try {
        await this.host.api.removeAttachment(chip.uuid);
      } catch {
        // Unsent drafts are removed by the server after pending_ttl_hours anyway.
      }
    }
  }

  private refreshVisibility(): void {
    this.element.hidden = this.chips.length === 0 && this.note.textContent === '';
  }

  private say(message: string): void {
    this.note.textContent = message;
    this.refreshVisibility();
  }
}

/**
 * The file cards of a message: name and size as text, a Download button that asks for a URL signed for this reader
 * at click time.
 *
 * @param files The message's files.
 * @param i18n Strings.
 * @param icon Icon factory.
 * @param download Opens one file.
 * @returns The list.
 */
export function renderFiles(files: AttachmentStub[], i18n: Record<string, string>, icon: (name: string, size?: number) => Element, download: (uuid: string) => void): HTMLElement {
  const list = h('ul', { class: 'nowo-chat-files', role: 'list', 'data-testid': 'nowo-chat-files' });
  for (const file of files) {
    const button = h('button', { type: 'button', class: 'nowo-chat-icon-btn nowo-chat-file__download', 'aria-label': `${t(i18n, 'attachment.download')}: ${file.name}`, title: t(i18n, 'attachment.download') }, icon('download', 16));
    button.addEventListener('click', () => download(file.uuid));
    list.append(h('li', { class: 'nowo-chat-file', 'data-kind': file.kind }, icon('file', 16), h('span', { class: 'nowo-chat-file__name', text: file.name }), h('span', { class: 'nowo-chat-file__size', text: formatSize(file.size) }), button));
  }
  return list;
}

/**
 * Downloads one file: asks for a fresh URL signed for this reader, then follows it as a download (never shown inline:
 * the server answers with `Content-Disposition: attachment`, `nosniff` and a sandbox CSP).
 *
 * @param api The calls.
 * @param uuid Attachment uuid.
 * @param follow Opens the URL (tests replace it).
 * @returns Whether a URL was given.
 */
export async function downloadFile(api: AttachmentApi, uuid: string, follow: (url: string) => void = followDownload): Promise<boolean> {
  const { url } = await api.attachment(uuid);
  if (url === null || !url.startsWith('/')) {
    return false;
  }
  follow(url);
  return true;
}

/**
 * Follows a same-origin download URL through a temporary `<a download>`.
 *
 * @param url Root-relative URL.
 */
export function followDownload(url: string): void {
  const link = h('a', { href: url, download: '', rel: 'noopener noreferrer', hidden: true });
  document.body.append(link);
  link.click();
  link.remove();
}
