/**
 * Full-page chat (`/chat/{uuid}`): opening a conversation is a plain GET that changes nothing (REQ-SEC-005). The page
 * carries `data-nowo-chat-read-url` / `-id` / `-csrf`; this script moves the read cursor through the same
 * CSRF-protected `POST …/read` API call the dock uses, once the page is visible.
 */

/**
 * Marks read the conversation of every full-page chat in the scope (once per page element).
 *
 * @param scope Where to look for `[data-nowo-chat-read-url]` elements.
 */
export function markPageRead(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-nowo-chat-read-url]').forEach((page) => {
    const url = page.dataset.nowoChatReadUrl as string;
    const id = Number(page.dataset.nowoChatReadId);
    const csrf = page.dataset.nowoChatReadCsrf ?? '';
    delete page.dataset.nowoChatReadUrl;
    /** Sends the read cursor; a failure leaves the conversation unread (the next visit tries again). */
    const send = (): void => {
      void fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ messageId: id }),
      }).catch(() => undefined);
    };
    if (document.visibilityState === 'visible') {
      send();
      return;
    }
    /** Waits until the tab is shown (a page opened in the background is not read yet). */
    const onVisible = (): void => {
      if (document.visibilityState === 'visible') {
        document.removeEventListener('visibilitychange', onVisible);
        send();
      }
    };
    document.addEventListener('visibilitychange', onVisible);
  });
}
