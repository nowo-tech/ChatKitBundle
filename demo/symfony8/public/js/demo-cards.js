/**
 * ChatKit 2.6 demo (spec 259): the host side of the card dialogs. A vault item button dispatches
 * `nowo-chat:insert-card` from inside the dialog (it bubbles to the dock root) and closes the dialog; the "Save to vault"
 * dialog is filled from `nowo-chat:slot-payload` (the selection never travels in a URL or to the chat server).
 */
(() => {
  const close = (element) => {
    const dialog = element.closest('dialog');
    if (dialog !== null && typeof dialog.close === 'function') {
      dialog.close();
    }
  };
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const insert = target?.closest('[data-demo-insert-card]');
    if (insert) {
      const [type, id] = (insert.getAttribute('data-demo-insert-card') ?? '').split(':');
      insert.dispatchEvent(new CustomEvent('nowo-chat:insert-card', { detail: { type, id }, bubbles: true }));
      close(insert);
      return;
    }
    const done = target?.closest('[data-demo-close]');
    if (done) {
      close(done);
    }
  });
  document.addEventListener(
    'nowo-chat:slot-payload',
    (event) => {
      const field = event.target instanceof Element ? event.target.querySelector('[data-demo-selection]') : null;
      if (field !== null && event.detail && typeof event.detail.selection === 'string') {
        field.value = event.detail.selection;
      }
    },
    true,
  );
})();
