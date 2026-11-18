/**
 * 1.35: the Saved page (`/chat/saved`, `[data-nowo-chat-saved-page]`): without JavaScript each entry only links to its
 * message; with it, "Remove" unsaves the entry through the CSRF-protected `DELETE …/save` API and takes it off the list.
 */

/**
 * Binds the "Remove" buttons of every Saved page in the scope (once per page element).
 *
 * @param scope Where to look for `[data-nowo-chat-saved-page]` elements.
 */
export function bindSavedPage(scope: ParentNode = document): void {
  scope.querySelectorAll<HTMLElement>('[data-nowo-chat-saved-page]:not([data-nowo-chat-saved-bound])').forEach((page) => {
    page.dataset.nowoChatSavedBound = '';
    const csrf = page.dataset.nowoChatCsrf ?? '';
    const live = page.querySelector<HTMLElement>('[data-nowo-chat-saved-live]');
    page.querySelectorAll<HTMLButtonElement>('[data-nowo-chat-unsave]').forEach((button) => {
      button.hidden = false;
      button.addEventListener('click', () => {
        const url = button.dataset.nowoChatUnsave ?? '';
        if (!/^\/(?![/\\])/.test(url)) {
          return;
        }
        button.disabled = true;
        void fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf } })
          .then((response) => {
            if (!response.ok) {
              throw new Error(String(response.status));
            }
            const item = button.closest('[data-nowo-chat-saved-item]');
            const next = item?.nextElementSibling ?? item?.previousElementSibling ?? null;
            item?.remove();
            if (live !== null) {
              live.textContent = page.dataset.nowoChatRemoved ?? '';
            }
            next?.querySelector<HTMLElement>('a, button:not([hidden])')?.focus();
          })
          .catch(() => {
            button.disabled = false;
          });
      });
    });
  });
}
