/**
 * Nowo Chat Kit — browser entry point. The IIFE build (`nowo-chat.js`) mounts the dock on every
 * `[data-nowo-chat-config]` element rendered by `@NowoChatKitBundle/dock.html.twig`, after first paint, and again
 * after Turbo page swaps. Applications with their own bundler can import `ChatDock` / `mount` from the source.
 */
import { ChatDock } from './dock';
import type { ChatConfig } from './types';

export { ChatDock } from './dock';
export type { ChatConfig } from './types';

/** Package version of the browser bundle. */
export const VERSION = '1.0.0-dev';

/** Prefix of every CSS custom property (theme token) of the dock. */
export const CSS_TOKEN_PREFIX = '--nowo-chat-';

const mounted = new WeakMap<HTMLElement, ChatDock>();

/**
 * Mounts the dock on one root (idempotent); returns null when the root has no valid configuration.
 *
 * @param root Element carrying the JSON `data-nowo-chat-config`.
 * @returns The dock of that root, or null when the configuration is missing or invalid.
 */
export function mount(root: HTMLElement): ChatDock | null {
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
  const dock = new ChatDock(root, config);
  mounted.set(root, dock);
  void dock.start();
  return dock;
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
 * Mounts every dock root in the document, when the browser is idle (the page paints first).
 *
 * @param scope Where to look for `[data-nowo-chat-config]` roots.
 */
export function mountAll(scope: ParentNode = document): void {
  /** Mounts every root found in the scope. */
  const run = (): void => scope.querySelectorAll<HTMLElement>('[data-nowo-chat-config]').forEach((root) => mount(root));
  if (typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 1500 });
  } else {
    window.setTimeout(run, 200);
  }
}

/** Auto start for the IIFE build: document ready, Turbo visits, and cleanup before Turbo swaps the body. */
export function autoStart(): void {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => mountAll(), { once: true });
  } else {
    mountAll();
  }
  document.addEventListener('turbo:load', () => mountAll());
  document.addEventListener('turbo:before-render', () => document.querySelectorAll<HTMLElement>('[data-nowo-chat-config]').forEach(unmount));
}
