import { test, expect, type Page } from '@playwright/test';

/**
 * 1.35 — the message menu on a phone (390 × 844; projects phone-chromium and phone-webkit): the kebab of a message and
 * its context menu open as a bottom sheet (UiKit 2.58) with the quick reactions on top; a tap on the backdrop closes it.
 */
test.describe.configure({ timeout: 60_000 });

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByTestId('demo-platform')).toContainText(username);
}

test('the message menu is a bottom sheet; a quick reaction reacts; the backdrop closes it', async ({ page }) => {
  await page.route('**/.well-known/mercure**', (route) => route.abort());
  await page.route('**/_wdt/**', (route) => route.abort());
  await signIn(page, 'alice');
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Bob Lee' }).first().click();
  const input = page.locator('.nowo-chat-composer__input');
  const text = `Phone ${Date.now()}`;
  await input.fill(text);
  await input.press('Enter');
  const mine = page.locator('.nowo-chat-msg--mine:not(.nowo-chat-msg--pending)', { hasText: text });
  await expect(mine.locator('[data-part="menu"]')).toBeAttached();
  await mine.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  await mine.locator('.nowo-chat-bubble').click({ button: 'right' });

  const sheet = page.locator('.nowo-ui-context-menu--sheet');
  await expect(sheet).toBeVisible();
  await page.evaluate(() => Promise.all(document.getAnimations().map((a) => a.finished.catch(() => undefined))));
  const box = (await sheet.boundingBox())!;
  const viewport = page.viewportSize()!;
  expect(Math.round(box.width)).toBe(viewport.width);
  expect(Math.abs(box.y + box.height - viewport.height)).toBeLessThan(2);
  await expect(sheet.getByRole('group', { name: 'Quick reactions' })).toBeVisible();
  expect((await sheet.getByRole('menuitem', { name: 'Reply', exact: true }).boundingBox())!.height).toBeGreaterThanOrEqual(43);

  // The backdrop closes it without reaching the page.
  await page.mouse.click(viewport.width / 2, 10);
  await expect(sheet).toHaveCount(0);

  // A quick reaction from the sheet (UiKit swallows the browser's menu for a moment after the closing tap).
  await page.waitForTimeout(1000);
  await mine.locator('.nowo-chat-bubble').click({ button: 'right' });
  await expect(sheet).toBeVisible();
  await sheet.getByRole('menuitem', { name: 'React with 👍' }).click();
  await expect(mine.locator('.nowo-chat-reaction', { hasText: '👍' })).toBeVisible();
});
