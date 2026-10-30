/**
 * Nowo Chat Kit — browser entry point. The IIFE build (`nowo-chat.js`) mounts the dock on every
 * `[data-nowo-chat-config]` element rendered by `@NowoChatKitBundle/dock.html.twig`, after first paint, and again
 * after Turbo page swaps — except roots marked `data-nowo-chat-manual`, which the host mounts itself. Applications with their own bundler can import `ChatDock` / `mount` from the source.
 *
 * Page mode (1.13): on the full-page chat (`[data-nowo-chat-page-mode]`, page.html.twig) mount() renders the dock's
 * list and one conversation inside the page instead of floating — automatically, or with `mount(root, { page })`.
 */
import { ChatDock, type ChatDockOptions } from './dock';
import { markPageRead } from './page';
import type { ChatConfig } from './types';

export { ChatDock } from './dock';
export type { ChatDockOptions } from './dock';
export { markPageRead } from './page';
export { PREFS_EVENT, saveChatPreference } from './prefs';
export { playSound, SOUND_NAMES } from './sounds';
export type { ChatConfig, ChatPrefs, ChatStatus, OwnStatus, SoundName } from './types';

/** Package version of the browser bundle. */
export const VERSION = '1.13.0';

/** Prefix of every CSS custom property (theme token) of the dock. */
export const CSS_TOKEN_PREFIX = '--nowo-chat-';

const mounted = new WeakMap<HTMLElement, ChatDock>();

/** The full-page chat element page mode renders into (page.html.twig, 1.13). */
export const PAGE_SELECTOR = '[data-nowo-chat-page-mode]';

/**
 * The full-page chat element of the document, when the page is the chat page.
 *
 * @param scope Where to look.
 * @returns The element, or null on every other page.
 */
export function findChatPage(scope: ParentNode = document): HTMLElement | null {
  return scope.querySelector<HTMLElement>(PAGE_SELECTOR);
}

/**
 * Mounts the dock on one root (idempotent); returns null when the root has no valid configuration. On the full-page
 * chat the dock starts in page mode (1.13): its list and one conversation fill the page, the floating dock hides.
 *
 * @param root Element carrying the JSON `data-nowo-chat-config`.
 * @param options `page`: the full-page chat element for page mode; omitted = found in the document
 *   (`[data-nowo-chat-page-mode]`); null = always the floating dock.
 * @returns The dock of that root, or null when the configuration is missing or invalid.
 */
export function mount(root: HTMLElement, options: ChatDockOptions = {}): ChatDock | null {
  const existing = mounted.get(root);
  if (existing !== undefined) {
    return existing;
  }
  let config: ChatConfig;
  try {
    config = JSON.parse(root.dataset.nowoChatConfig ?? '') as ChatConfig;
  } catch {
    return null;
  }
  const dock = new ChatDock(root, config, { page: options.page === undefined ? findChatPage(root.ownerDocument) : options.page });
  mounted.set(root, dock);
  void dock.start();
  return dock;
}

/**
 * Mounts the dock of a root in page mode on a full-page chat element (1.13) — the explicit form of mount() for hosts
 * that render the page element themselves.
 *
 * @param root Element carrying the JSON `data-nowo-chat-config`.
 * @param page The full-page chat element (two columns: list, conversation).
 * @returns The dock, or null when the configuration is missing or invalid.
 */
export function mountPage(root: HTMLElement, page: HTMLElement): ChatDock | null {
  return mount(root, { page });
}

/**
 * Stops the dock of a root (connection, timers, listeners).
 *
 * @param root Root element previously passed to mount().
 */
export function unmount(root: HTMLElement): void {
  mounted.get(root)?.destroy();
  mounted.delete(root);
}

/**
 * Roots the automatic start handles: every `[data-nowo-chat-config]` except the ones marked
 * `data-nowo-chat-manual` (a dock the host mounts itself, e.g. with its own bundler or a Stimulus controller).
 */
export const AUTO_ROOT_SELECTOR = '[data-nowo-chat-config]:not([data-nowo-chat-manual])';

/**
 * Mounts every dock root in the document, when the browser is idle (the page paints first). Roots marked
 * `data-nowo-chat-manual` are left to the host (mount() still mounts them when called explicitly).
 *
 * @param scope Where to look for `[data-nowo-chat-config]` roots.
 */
export function mountAll(scope: ParentNode = document): void {
  /** Mounts every automatic root found in the scope. */
  const run = (): void => scope.querySelectorAll<HTMLElement>(AUTO_ROOT_SELECTOR).forEach((root) => mount(root));
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 1500 });
  } else {
    window.setTimeout(run, 200);
  }
}

/**
 * Auto start for the IIFE build: document ready, Turbo visits, and cleanup before Turbo swaps the body. Also marks
 * read the conversation of a full-page chat (see markPageRead).
 */
export function autoStart(): void {
  /** Mounts the docks and marks the open full-page conversation read. */
  const start = (): void => {
    markPageRead();
    mountAll();
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
  document.addEventListener('turbo:load', start);
  document.addEventListener('turbo:before-render', () => document.querySelectorAll<HTMLElement>(AUTO_ROOT_SELECTOR).forEach(unmount));
}
