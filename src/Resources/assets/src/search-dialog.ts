/**
 * 1.40: the search modal of the conversation header (`search.conversation_entry`). The markup is the Twig template
 * `search-dialog` (UiKit confirm_dialog anatomy: header with the title and ×, a body of sections that alone scrolls, a
 * footer with Close then the primary "Search"). The form goes to `GET /chat/search/results` (the same search, access
 * rules and budget as the /chat/search page; reads only, no CSRF token) and the HTML fragment it answers is put in the
 * results section: the server escapes every excerpt and marks the words, nothing from a message is ever parsed as
 * markup here. A result in this conversation closes the modal and shows the message (scrolled to, highlighted); one in
 * another conversation opens that conversation at the message (`/chat/{uuid}?message=<uuid>`). Closing aborts the
 * pending request; focus stays in the modal (Tab cycles) and returns to the header button. No inline style (CSP).
 */
import { closeDialog, presentDialog } from './message-info';
import type { Templates } from './templates';

/** What the modal needs from the dock. */
export interface SearchDialogOptions {
  templates: Templates;
  /** `GET /chat/search/results`. */
  endpoint: string;
  /** The open conversation (the default scope). */
  conversationUuid: string;
  /** Focused again when the modal closes. */
  returnTo: HTMLElement | null;
  /**
   * A result was picked (the modal is closed by then).
   *
   * @param conversationUuid Conversation of the message.
   * @param messageUuid The message.
   * @param link Its address (`/chat/{uuid}?message=<uuid>`).
   */
  pick(conversationUuid: string, messageUuid: string, link: string): void;
  /** `fetch` (tests). */
  fetcher?: typeof fetch;
}

/** The open modal and its controls. */
export interface SearchDialog {
  dialog: HTMLDialogElement;
  /** Runs the search with the form's values (what "Search" does). */
  search(): Promise<void>;
  /** Closes the modal (aborts the pending request). */
  close(): void;
}

/** Elements that take focus with Tab inside the modal. */
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/**
 * The query parameters of the form: `nowo_chat_msearch[q]` → `q`; unchecked boxes and empty values are left out.
 *
 * @param form The modal's form.
 * @param conversationUuid The open conversation (sent unless the scope is "all").
 * @returns The parameters.
 */
export function searchParams(form: HTMLFormElement, conversationUuid: string): URLSearchParams {
  const params = new URLSearchParams();
  new FormData(form).forEach((value, name) => {
    const key = /\[([^\]]+)\]$/.exec(name)?.[1] ?? name;
    if (typeof value === 'string' && value !== '' && key !== 'conversation' && key !== 'search') {
      params.set(key, value);
    }
  });
  if (params.get('scope') !== 'all') {
    params.set('scope', 'current');
    params.set('conversation', conversationUuid);
  }
  return params;
}

/** Response header that marks the results fragment (`ChatSearchController::FRAGMENT_HEADER`). */
export const FRAGMENT_HEADER = 'X-Nowo-Chat-Fragment';

/** Attributes holding an address: kept only for a relative path or this origin (http / https). */
const URL_ATTRIBUTES = ['href', 'src', 'srcset', 'action', 'formaction', 'xlink:href', 'poster', 'data'];

/**
 * Whether an address stays on this site: a relative path, or an absolute http(s) URL of this origin.
 *
 * @param value The attribute value.
 * @returns True when it may be kept.
 */
export function sameOriginUrl(value: string): boolean {
  try {
    const url = new URL(value, window.location.href);
    return (url.protocol === 'http:' || url.protocol === 'https:') && url.origin === window.location.origin;
  } catch {
    return false;
  }
}

/**
 * Parses the server's fragment into nodes of this document. The fragment is the bundle's escaped Twig output; it is
 * still cleaned here: no script, style, frame, object or form element, no `on*` attribute, and no address that leaves
 * the site (`href`, `src` … must be a relative path or this origin).
 *
 * @param html The fragment.
 * @returns Its nodes.
 */
export function fragment(html: string): Node[] {
  const parsed = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  parsed.querySelectorAll('script, style, iframe, frame, object, embed, base, meta, link, form, template').forEach((node) => node.remove());
  parsed.body.querySelectorAll('*').forEach((element) => {
    [...element.attributes].forEach((attribute) => {
      const name = attribute.name.toLowerCase();
      if (name.startsWith('on') || (URL_ATTRIBUTES.includes(name) && !sameOriginUrl(attribute.value))) {
        element.removeAttribute(attribute.name);
      }
    });
  });
  return [...parsed.body.childNodes].map((node) => document.importNode(node, true));
}

/**
 * The "session expired" notice (UiKit notice class and the texts from the results section's data attributes) with a
 * button that reloads the page.
 *
 * @param results The results section.
 * @returns The notice.
 */
function expiredNotice(results: HTMLElement): HTMLElement {
  const notice = document.createElement('div');
  notice.className = `${results.dataset.noticeClass ?? ''} nowo-chat-notice nowo-chat-msearch__expired`.trim();
  notice.setAttribute('role', 'alert');
  notice.dataset.testid = 'chat-msearch-expired';
  const text = document.createElement('p');
  text.textContent = results.dataset.expired ?? '';
  const reload = document.createElement('button');
  reload.type = 'button';
  reload.className = results.dataset.reloadClass ?? '';
  reload.textContent = results.dataset.reload ?? '';
  reload.addEventListener('click', () => window.location.reload());
  notice.append(text, reload);
  return notice;
}

/**
 * Builds and opens the modal.
 *
 * @param options What the modal needs.
 * @returns The open modal.
 */
export function openSearchDialog(options: SearchDialogOptions): SearchDialog {
  const dialog = options.templates.clone<HTMLDialogElement>('search-dialog');
  const form = dialog.querySelector<HTMLFormElement>('[data-part="search-form"]');
  const results = dialog.querySelector<HTMLElement>('[data-part="search-results"]');
  const input = dialog.querySelector<HTMLInputElement>('input[type="search"]');
  if (form === null || results === null || input === null) {
    throw new Error('NowoChat: the search-dialog template needs the form, the results section and the text field.');
  }
  const fetcher = options.fetcher ?? window.fetch.bind(window);
  let controller: AbortController | null = null;

  const close = (): void => {
    controller?.abort();
    controller = null;
    closeDialog(dialog);
  };

  const load = async (url: string, append: boolean): Promise<void> => {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    results.setAttribute('aria-busy', 'true');
    if (!append) {
      const status = document.createElement('p');
      status.className = 'nowo-chat-msearch__status';
      status.textContent = results.dataset.loading ?? '';
      results.replaceChildren(status);
    }
    try {
      const response = await fetcher(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin', signal: current.signal });
      const html = await response.text();
      if (current.signal.aborted) {
        return;
      }
      // Signed out (401, or a redirect to the login page answered 200 without the fragment header): never show that
      // page inside the modal; say the session expired and offer a reload.
      if (response.status === 401 || response.redirected || (response.ok && response.headers.get(FRAGMENT_HEADER) !== 'search-results')) {
        results.replaceChildren(expiredNotice(results));
        return;
      }
      // 400 / 429 answer the fragment with the notice; any other error is a failure.
      if (!response.ok && response.status !== 400 && response.status !== 429) {
        throw new Error(`HTTP ${response.status}`);
      }
      results.querySelector('[data-nowo-chat-search-more]')?.remove();
      if (append) {
        const nodes = fragment(html);
        const list = results.querySelector('ol');
        nodes.forEach((node) => {
          if (list !== null && node instanceof HTMLOListElement) {
            list.append(...node.children);
          } else {
            results.append(node);
          }
        });
      } else {
        results.replaceChildren(...fragment(html));
      }
    } catch (error) {
      if (current.signal.aborted) {
        return;
      }
      const failed = document.createElement('p');
      failed.className = `${results.dataset.noticeClass ?? ''} nowo-chat-notice`.trim();
      failed.setAttribute('role', 'alert');
      failed.textContent = results.dataset.failed ?? String(error);
      results.replaceChildren(failed);
    } finally {
      if (controller === current) {
        controller = null;
        results.setAttribute('aria-busy', 'false');
      }
    }
  };

  const search = (): Promise<void> => {
    if (input.value.trim() === '') {
      input.focus();
      return Promise.resolve();
    }
    return load(`${options.endpoint}?${searchParams(form, options.conversationUuid).toString()}`, false);
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void search();
  });
  dialog.querySelectorAll<HTMLElement>('[data-confirm-dialog-close], [data-part="search-close"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      close();
    });
  });
  // Escape: the native dialog closes itself; abort the request too.
  dialog.addEventListener('cancel', () => {
    controller?.abort();
    controller = null;
  });
  dialog.addEventListener('close', () => {
    controller?.abort();
    controller = null;
  });
  results.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const more = target?.closest<HTMLAnchorElement>('a[data-nowo-chat-search-more]');
    if (more) {
      event.preventDefault();
      void load(more.href, true);
      return;
    }
    const hit = target?.closest<HTMLAnchorElement>('a[data-nowo-chat-search-hit]');
    if (hit && !(event instanceof MouseEvent && (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1))) {
      event.preventDefault();
      const conversation = hit.dataset.conversation ?? '';
      const message = hit.dataset.message ?? '';
      close();
      options.pick(conversation, message, hit.href);
    }
  });
  // Focus stays inside: Tab from the last control goes to the first, Shift+Tab from the first to the last.
  dialog.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab') {
      return;
    }
    const focusable = [...dialog.querySelectorAll<HTMLElement>(FOCUSABLE)].filter((element) => element.getClientRects().length > 0);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (first === undefined || last === undefined) {
      return;
    }
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  presentDialog(dialog, input, options.returnTo);
  return { dialog, search, close };
}
