import { test, expect, type Page } from '@playwright/test';

/**
 * 1.25 (audit KJ-M1) / 1.25.1 (audit KJ-I13): the real sidebar action of the demo's section provider
 * (App\Chat\DemoSidebarProvider, "Demo rooms" → "Create room") opens the bundle's UiKit remote form dialog in the dock
 * root, which takes mouse and keyboard input although the root is `pointer-events: none`; an invalid post re-renders
 * the fragment (422), a valid one follows `204` + `X-Nowo-Redirect`. Real clicks in a real browser: jsdom cannot tell.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

test('the sidebar action opens a clickable remote form dialog that posts and follows the redirect', async ({ page }) => {
  await signIn(page, 'alice');
  await page.goto('/chat/');
  const app = page.getByTestId('nowo-chat-page-mode');
  const section = app.getByTestId('chat-sidebar-section-demo-rooms');
  await expect(section).toBeVisible();
  await expect(section.getByTestId('chat-sidebar-item-demo-rooms-home')).toContainText('Home');
  const action = section.getByTestId('chat-sidebar-action-demo-rooms-create');
  await expect(action).toHaveAttribute('data-nowo-ui-dialog-src', '/en/demo/rooms/new');
  await expect(action).toHaveAttribute('aria-haspopup', 'dialog');

  // The bundle's medium dialog (size md), filled by UiKit from the action's same-origin fragment.
  const posts: string[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url().includes('/demo/rooms/new')) {
      posts.push(request.headers()['x-nowo-dialog'] ?? '');
    }
  });
  await action.click();
  const dialog = page.getByTestId('chat-sidebar-dialog-md');
  await expect(dialog).toBeVisible();
  const name = dialog.getByLabel('Room name');
  await expect(name).toBeVisible();
  const root = page.getByTestId('chat-dock');
  await expect(root).not.toHaveAttribute('data-overlay', /.+/);
  await expect(root).not.toHaveAttribute('inert', /.*/);

  // Real pointer input (Playwright refuses an element another one covers, or one that takes no pointer events).
  const hit = await name.evaluate((input) => {
    const box = input.getBoundingClientRect();
    return document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2) === input;
  });
  expect(hit).toBe(true);

  // Empty: the server answers 422 and the fragment is re-rendered inside the dialog.
  await dialog.getByRole('button', { name: 'Create' }).click();
  await expect(dialog.getByText(/should not be blank/)).toBeVisible();
  await expect(dialog).toBeVisible();
  expect(posts).toEqual(['1']);

  // Escape closes; the action opens it again with a fresh form.
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await action.click();
  await expect(dialog.getByLabel('Room name')).toBeVisible();

  await dialog.getByLabel('Room name').click();
  await dialog.getByLabel('Room name').fill('design');
  await dialog.getByRole('button', { name: 'Create' }).click();
  await expect(page).toHaveURL(/\/chat\/\?room=design$/);
  expect(posts).toEqual(['1', '1']);
});
