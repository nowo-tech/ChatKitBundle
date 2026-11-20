import { test, expect, type Page } from '@playwright/test';

/**
 * 1.36 — on a phone (390 × 844): the forward dialog and the thread panel are full-height sheets.
 */
test.describe.configure({ timeout: 60_000 });

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-platform')).toContainText(username);
}

test('the forward dialog and the thread panel fill the phone screen', async ({ page }) => {
  await page.route('**/.well-known/mercure**', (route) => route.abort());
  await page.route('**/_wdt/**', (route) => route.abort());
  await signIn(page, 'alice');
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Bob Lee' }).first().click();
  const input = page.locator('.nowo-chat-composer__input');
  const text = `Phone 1.36 ${Date.now()}`;
  await input.fill(text);
  await input.press('Enter');
  const mine = page.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text });
  await expect(mine.locator('[data-part="menu"]')).toBeAttached();
  const viewport = page.viewportSize()!;
  for (const [item, name] of [['Forward', 'Forward message'], ['Reply in thread', 'Thread']] as const) {
    await mine.scrollIntoViewIfNeeded();
    await page.waitForTimeout(1000);
    await mine.locator('.nowo-chat-bubble').click({ button: 'right' });
    const sheet = page.locator('.nowo-ui-context-menu--sheet');
    await expect(sheet).toBeVisible();
    await sheet.getByRole('menuitem', { name: item, exact: true }).click();
    const dialog = page.getByRole('dialog', { name });
    await expect(dialog).toBeVisible();
    await page.evaluate(() => Promise.all(document.getAnimations().map((a) => a.finished.catch(() => undefined))));
    const box = (await dialog.boundingBox())!;
    expect(Math.round(box.width)).toBe(viewport.width);
    expect(Math.round(box.height)).toBe(viewport.height);
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
  }
});
