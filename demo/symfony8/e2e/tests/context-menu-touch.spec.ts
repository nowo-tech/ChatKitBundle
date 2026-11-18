import { test, expect, type Page, type CDPSession, type Locator } from '@playwright/test';

/**
 * 1.33 — a long press on a message opens its menu items (UiKit 2.54 context menu, touch). Chromium with Pixel 7
 * emulation (`pointer: coarse`, project `context-menu-touch`); CDP touch events go through Blink's input pipeline.
 * iOS Safari's own long-press callout is not emulated: check it on a device.
 */
test.describe.configure({ timeout: 60_000 });

type Point = { x: number; y: number };

async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

async function grip(locator: Locator): Promise<Point> {
  await locator.scrollIntoViewIfNeeded();
  // A scroll of the thread closes the menu: let it settle first.
  await locator.page().waitForTimeout(300);
  const box = (await locator.boundingBox())!;
  return { x: box.x + Math.min(12, box.width / 2), y: box.y + box.height / 2 };
}

test('a long press on a message opens its items; a tap runs one', async ({ page, browser }) => {
  // Bob writes from his own browser, so the message is on the first page alice loads.
  const text = `Touch from Bob ${Date.now()}`;
  const bob = await browser.newContext();
  const bobPage = await bob.newPage();
  await signIn(bobPage, 'bob');
  await bobPage.goto('/chat/');
  await bobPage.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Alice Martin' }).first().click();
  await bobPage.locator('.nowo-chat-composer__input').fill(text);
  await bobPage.locator('.nowo-chat-composer__input').press('Enter');
  await expect(bobPage.locator('.nowo-chat-msg:not(.nowo-chat-msg--pending)', { hasText: text })).toBeVisible();
  await bob.close();

  await signIn(page, 'alice');
  expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);
  // Phone layout: the full-page chat shows one conversation at a time.
  await page.goto('/chat/');
  await page.locator('.nowo-chat-row, .nowo-chat-sidebar__item', { hasText: 'Bob Lee' }).first().click();
  const theirs = page.locator('.nowo-chat-msg:not(.nowo-chat-msg--mine)', { hasText: text }).last();
  await expect(theirs).toBeVisible();

  const cdp: CDPSession = await page.context().newCDPSession(page);
  const point = await grip(theirs.locator('.nowo-chat-bubble'));
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: point.x, y: point.y, id: 1 }] });
  await page.waitForTimeout(800);
  const menu = page.locator('.nowo-ui-context-menu:not(.nowo-ui-context-menu--sub)');
  await expect(menu).toBeVisible();
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await page.waitForTimeout(200);
  await expect(menu).toBeVisible();
  await expect(menu.getByRole('menuitem')).toHaveText(['👍', '❤️', '😂', '😮', '😢', '🙏', '+', 'Reply', 'Quote', 'Copy text', 'Copy link', /^(Save for later|Remove from saved)$/, 'Mark unread from here', /^(Pin|Unpin)$/]);
  // 1.35 (UiKit 2.58): on a phone the menu is a bottom sheet, full width at the bottom of the screen.
  await expect(menu).toHaveClass(/nowo-ui-context-menu--sheet/);
  const sheet = (await menu.boundingBox())!;
  const viewport = page.viewportSize()!;
  expect(Math.round(sheet.width)).toBe(viewport.width);
  expect(Math.abs(sheet.y + sheet.height - viewport.height)).toBeLessThan(2);
  const reply = menu.getByRole('menuitem', { name: 'Reply' });
  expect((await reply.boundingBox())!.height).toBeGreaterThanOrEqual(43);
  await reply.tap();
  await expect(page.locator('.nowo-chat-composer__input')).toHaveValue(new RegExp(`^> Bob Lee: ${text}\\n\\n`));
});
