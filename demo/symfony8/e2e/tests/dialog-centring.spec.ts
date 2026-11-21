import { test, expect, type Page, type Locator } from '@playwright/test';

/**
 * 1.36.1 — the message info and forward dialogs stay centred under a host CSS reset that zeroes every margin (Tailwind
 * preflight: `* { margin: 0 }`), which is what centres a modal <dialog> in the browser's own stylesheet. Desktop
 * 1280 × 720; the dialogs also carry the standard anatomy (title and close button in a header).
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

/** The dialog's box is centred in the viewport, horizontally within a couple of pixels, and well inside the left edge. */
async function expectCentred(page: Page, dialog: Locator): Promise<void> {
  const box = await dialog.boundingBox();
  const viewport = page.viewportSize();
  expect(box).not.toBeNull();
  expect(viewport).not.toBeNull();
  const centre = (box?.x ?? 0) + (box?.width ?? 0) / 2;
  expect(Math.abs(centre - (viewport?.width ?? 0) / 2)).toBeLessThanOrEqual(2);
  expect(box?.x ?? 0).toBeGreaterThan(100);
  expect((box?.width ?? 0) + 100).toBeLessThan(viewport?.width ?? 0);
}

test.describe('dialog centring under a margin reset (1.36.1)', () => {
  test('message info and forward stay centred when the host zeroes every margin', async ({ page }) => {
    const message = await mine(page, 'Centre me');
    await page.addStyleTag({ content: '*, ::before, ::after, ::backdrop { margin: 0; }' });

    await open(page, message, 'Message info');
    const info = page.getByRole('dialog', { name: 'Message info' });
    await expect(info).toBeVisible();
    await expect(info.locator('header').getByRole('button', { name: 'Close' })).toBeVisible();
    await expectCentred(page, info);
    await page.keyboard.press('Escape');
    await expect(info).toHaveCount(0);

    await open(page, message, 'Forward');
    const forward = page.getByRole('dialog', { name: 'Forward message' });
    await expect(forward).toBeVisible();
    await expect(forward.locator('header').getByRole('button', { name: 'Close' })).toBeVisible();
    await expectCentred(page, forward);
    // The two dialogs share the medium width.
    const width = (await forward.boundingBox())?.width ?? 0;
    await forward.locator('header').getByRole('button', { name: 'Close' }).click();
    await expect(forward).toHaveCount(0);
    expect(width).toBeGreaterThan(300);
    // Escape still closes it and the focus goes back (the message menu's opener or the message).
    await open(page, message, 'Forward');
    await expect(forward).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(forward).toHaveCount(0);
  });
});
