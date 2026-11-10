import { test, expect, type Page } from '@playwright/test';

/**
 * 1.25 (audit KJ-M1): a UiKit dialog inside the dock root — where the sidebar's action dialogs live — takes mouse and
 * touch input although the root is `pointer-events: none`, and the floating dock does not hide under it. Real clicks in
 * a real browser: jsdom cannot tell.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

test('a dialog in the dock root is clickable and keeps the dock shown', async ({ page }) => {
  await signIn(page, 'alice');
  await page.goto('/en');
  const root = page.getByTestId('chat-dock');
  await expect(root).toBeAttached();
  await page.waitForFunction(() => typeof (window as unknown as { nowoUiOpenDialog?: unknown }).nowoUiOpenDialog === 'function');

  // The markup of the `dialog` partial the sidebar's remote form dialogs render (header ×, body, footer).
  await page.evaluate(() => {
    const host = document.querySelector('[data-testid="chat-dock"]') as HTMLElement;
    host.insertAdjacentHTML(
      'beforeend',
      '<dialog id="kj-dialog" class="nowo-ui-dialog" data-nowo-ui-dialog aria-labelledby="kj-dialog-title">' +
        '<header class="nowo-ui-dialog__header"><h2 class="nowo-ui-dialog__title" id="kj-dialog-title">Create channel</h2>' +
        '<button type="button" class="nowo-ui-dialog__close" data-nowo-ui-dialog-close aria-label="Close">×</button></header>' +
        '<div class="nowo-ui-dialog__body" data-nowo-ui-dialog-body><label>Name <input name="kj-name"></label></div>' +
        '<footer class="nowo-ui-dialog__footer"><button type="button" id="kj-save">Create</button></footer></dialog>',
    );
    document.getElementById('kj-save')?.addEventListener('click', () => document.body.setAttribute('data-kj-saved', '1'));
    (window as unknown as { nowoUiOpenDialog: (id: string) => void }).nowoUiOpenDialog('kj-dialog');
  });

  const dialog = page.locator('#kj-dialog');
  await expect(dialog).toBeVisible();
  // Real pointer input (Playwright refuses an element another one covers, or one that takes no pointer events).
  await dialog.getByRole('textbox').click();
  await dialog.getByRole('textbox').fill('design');
  await dialog.getByRole('button', { name: 'Create' }).click();
  await expect(page.locator('body')).toHaveAttribute('data-kj-saved', '1');
  const hit = await page.evaluate(() => {
    const save = document.getElementById('kj-save') as HTMLElement;
    const box = save.getBoundingClientRect();
    return document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)?.id ?? null;
  });
  expect(hit).toBe('kj-save');
  // Its own dialog never suppresses the floating dock.
  await expect(root).not.toHaveAttribute('data-overlay', /.+/);
  await expect(root).not.toHaveAttribute('inert', /.*/);

  await dialog.getByRole('button', { name: 'Close' }).click();
  await expect(dialog).toBeHidden();
});
