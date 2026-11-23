import { test, expect, type Page, type Locator } from '@playwright/test';

/**
 * 1.36 — forward, threads and edit history on the desktop (1280 × 720): Forward picks a conversation in the dialog
 * (Escape closes it), Reply in thread opens the thread panel and the root counts the reply, and message info lists the
 * edits with the previous text for the author. The demo enables the three items.
 */
test.describe.configure({ timeout: 60_000 });

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-platform')).toContainText(username);
}

const menu = (page: Page) => page.locator('.nowo-ui-context-menu:not(.nowo-ui-context-menu--sub)');

async function mine(page: Page, prefix: string): Promise<Locator> {
  await page.route('**/.well-known/mercure**', (route) => route.abort());
  // The Symfony debug toolbar sits over the bottom of the page and intercepts the click on the thread summary of the
  // last message ("subtree intercepts pointer events"): the phone specs drop it too.
  await page.route('**/_wdt/**', (route) => route.abort());
  await signIn(page, 'alice');
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Bob Lee' }).first().click();
  const input = page.locator('.nowo-chat-composer__input');
  const text = `${prefix} ${Date.now()}`;
  await input.fill(text);
  await input.press('Enter');
  const message = page.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text });
  await expect(message.locator('[data-part="menu"]')).toBeAttached();
  return message;
}

async function open(page: Page, message: Locator, item: string): Promise<void> {
  await message.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await message.locator('.nowo-chat-bubble').click({ button: 'right' });
  await expect(menu(page)).toBeVisible();
  await menu(page).getByRole('menuitem', { name: item, exact: true }).click();
}

test.describe('message menu (1.36)', () => {
  test('Forward sends the message to another conversation, all at once', async ({ page }) => {
    const message = await mine(page, 'Forward me');
    const text = (await message.locator('.nowo-chat-bubble').innerText()).trim();
    await open(page, message, 'Forward');
    const dialog = page.getByRole('dialog', { name: 'Forward message' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('searchbox', { name: 'Search conversations or people' })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);

    await open(page, message, 'Forward');
    await expect(dialog).toBeVisible();
    // To Carol (other specs read the General channel's previews).
    await dialog.getByRole('searchbox', { name: 'Search conversations or people' }).fill('Carol');
    await dialog.getByLabel('Carol Diaz').check();
    await dialog.getByRole('button', { name: 'Forward' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('[data-part="notice"]')).toContainText('Forwarded to 1 conversation');
    // The new direct conversation with Carol (realtime is off in this spec: reload the list).
    await page.goto('/chat/');
    await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Carol Diaz', hasNotText: 'General' }).first().click();
    const copy = page.locator('.nowo-chat-msg', { hasText: text, has: page.locator('[data-part="forwarded"]') }).last();
    await expect(copy.locator('[data-part="forwarded"]')).toHaveText('Forwarded from a direct conversation');
  });

  test('Reply in thread opens the panel; the root counts the reply', async ({ page }) => {
    const message = await mine(page, 'Thread root');
    await open(page, message, 'Reply in thread');
    const panel = page.getByRole('dialog', { name: 'Thread' });
    await expect(panel).toBeVisible();
    const input = panel.getByRole('textbox', { name: 'Reply in thread' });
    await expect(input).toBeFocused();
    await input.fill('First reply');
    await panel.getByRole('button', { name: 'Send' }).click();
    await expect(panel.locator('[data-part="thread-replies"]')).toContainText('First reply');
    await page.keyboard.press('Escape');
    await expect(panel).toHaveCount(0);
    // The reply stays out of the timeline; the root shows "1 reply".
    await expect(page.locator('.nowo-chat-msg', { hasText: 'First reply' })).toHaveCount(0);
    const summary = message.locator('[data-part="thread-summary"]');
    await expect(summary).toContainText('1 reply');
    await summary.click();
    await expect(panel).toBeVisible();
    await expect(panel.locator('[data-part="thread-reply"]', { hasText: 'First reply' })).toBeVisible();
  });

  test('message info lists the edits and the previous text', async ({ page }) => {
    const shown = await mine(page, 'Before edit');
    // By id: while it is edited, the message shows a form instead of its text.
    const message = page.locator(`.nowo-chat-msg[data-id="${await shown.getAttribute('data-id')}"]`);
    await open(page, message, 'Edit');
    const editor = message.locator('textarea');
    await editor.fill('After edit');
    await editor.press('Enter');
    await expect(message.locator('.nowo-chat-bubble')).toContainText('After edit');
    await open(page, message, 'Message info');
    const info = page.getByRole('dialog', { name: 'Message info' });
    await expect(info.locator('[data-part="info-edit"]')).toHaveCount(1);
    await info.getByRole('button', { name: 'Show previous texts' }).click();
    await expect(info.locator('[data-part="info-revision"]')).toContainText('Before edit');
  });
});
