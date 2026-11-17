/**
 * 1.33: the "More actions" menu of a message (UiKit kebab: Reply, Edit, Copy link, Pin / Unpin, Delete) and its
 * context menu (UiKit 2.54 `context-menu` runtime: right-click, Shift+F10, the ContextMenu key, long press). The kebab
 * is the only item list: the context menu mirrors it and forwards each click to it, so it can show and run nothing the
 * kebab does not. Every action is checked again by the server.
 */
import { sameOriginPath } from './sidebar';

/** UiKit's context-menu / card attributes: never in a message body (UiKit 2.54 SECURITY). */
const RESERVED = /^data-nowo-ui-(?:context-menu|card$)/i;

/** Longest excerpt of a quoted message (characters). */
export const QUOTE_MAX = 200;

/** Symbol UiKit's context-menu runtime sets once it has bound its listeners (one copy per page). */
const BOUND_KEY = Symbol.for('nowo-ui.context-menu.bound');

/** URL of UiKit's context-menu runtime the dock loaded itself (one per page, whatever the docks). */
let runtimeRequested: string | null = null;

/** What the viewer may do with a message (the server decides again on each endpoint). */
export interface MessageMenuRules {
  /** Not deleted and a plain text message (no system, pin, buzz or bot line). */
  usable: boolean;
  /** The viewer wrote it. */
  mine: boolean;
  /** Still within the edit / delete window (15 minutes). */
  inWindow: boolean;
  /** The viewer may write in the conversation. */
  canSend: boolean;
  /** The viewer may pin in the conversation. */
  canPin: boolean;
  /** The message is pinned (the item says "Unpin"). */
  pinned: boolean;
  /** The server gives message links (`endpoints.messageLink`). */
  canLink: boolean;
}

/** The items of the menu, by `data-part`. */
export type MessageMenuItem = 'reply' | 'edit' | 'copy-link' | 'pin' | 'delete';

/**
 * Which items the viewer sees: Reply to whoever may write; Edit for the author within the window (who may still
 * write); Copy link for any shown message; Pin / Unpin to who may pin; Delete for the author within the window.
 *
 * @param rules What the viewer may do with the message.
 * @returns Whether each item is shown.
 */
export function visibleItems(rules: MessageMenuRules): Record<MessageMenuItem, boolean> {
  const own = rules.usable && rules.mine && rules.inWindow;
  return {
    reply: rules.usable && rules.canSend,
    edit: own && rules.canSend,
    'copy-link': rules.usable && rules.canLink,
    pin: rules.usable && rules.canPin,
    delete: own,
  };
}

/**
 * Removes UiKit's context-menu / card attributes from every element of a rendered message body (the server strips them
 * already; this keeps a stale cache or a host's own HTML from opening another element's kebab items).
 *
 * @param root The message bubble.
 */
export function stripReservedAttributes(root: Element): void {
  for (const element of [root, ...root.querySelectorAll('*')]) {
    for (const name of element.getAttributeNames()) {
      if (RESERVED.test(name)) {
        element.removeAttribute(name);
      }
    }
  }
}

/**
 * The Markdown quote a reply starts with: `> Name: excerpt` (whitespace collapsed, at most QUOTE_MAX characters).
 *
 * @param author Name of the author of the quoted message.
 * @param text Plain text of the quoted message.
 * @returns The quote line and an empty line after it, or '' when there is no text.
 */
export function quoteOf(author: string, text: string): string {
  const flat = text.replace(/\s+/g, ' ').trim();
  if (flat === '') {
    return '';
  }
  const excerpt = flat.length > QUOTE_MAX ? `${flat.slice(0, QUOTE_MAX - 1).trimEnd()}…` : flat;
  const name = author.replace(/\s+/g, ' ').trim();
  return `> ${name === '' ? '' : `${name}: `}${excerpt}\n\n`;
}

/**
 * `web_ui.message_context_menu: true`: loads UiKit's context-menu runtime (js/nowo-ui-context-menu.js) once. Nothing
 * happens when it already runs (its own bound symbol), when the page has a `<script>` for it (the host's, maybe not run
 * yet), when this page asked for it already, or for a URL of another origin. The script takes the CSP nonce of the
 * page's own scripts.
 *
 * @param url Address of js/nowo-ui-context-menu.js (UiKit's asset package).
 * @returns True when a script element was added.
 */
export function loadContextMenuRuntime(url: string): boolean {
  const bound = (window as unknown as Record<symbol, unknown>)[BOUND_KEY] === true;
  const present = [...document.querySelectorAll<HTMLScriptElement>('script[src]')].some((script) => /(?:^|\/)nowo-ui-context-menu\.js(?:[?#]|$)/.test(script.getAttribute('src') ?? ''));
  if (bound || present || runtimeRequested !== null || sameOriginPath(url) === null) {
    return false;
  }
  runtimeRequested = url;
  const script = document.createElement('script');
  script.src = url;
  script.defer = true;
  script.dataset.nowoChatContextMenuRuntime = '';
  const nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce ?? '';
  if (nonce !== '') {
    script.nonce = nonce;
  }
  document.head.append(script);
  return true;
}

/** Test hook: forgets that the context-menu runtime was requested. */
export function resetContextMenuRuntime(): void {
  runtimeRequested = null;
}

/**
 * `?message=<uuid>` of the address (a copied message link), dropped from the address once read.
 *
 * @returns The message uuid, or null.
 */
export function takeMessageFromUrl(): string | null {
  const url = new URL(window.location.href);
  const uuid = url.searchParams.get('message');
  if (uuid === null) {
    return null;
  }
  url.searchParams.delete('message');
  window.history.replaceState(window.history.state, '', url.toString());
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(uuid) ? uuid : null;
}
