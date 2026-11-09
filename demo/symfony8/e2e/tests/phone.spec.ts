import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';

/**
 * 1.23 — the dock on a phone (390 × 844), run by the `phone-chromium` and `phone-webkit` projects: the round launcher
 * and the space it reserves (MUX-02), the "Reconnecting…" notice (MUX-08), the list rows (MUX-25) and the open sheet's
 * title and close button (MUX-91). Screenshots go to the test output for a visual check.
 */
async function signIn(page: Page, username: string): Promise<void> {
  await page.goto('/en/login');
  await page.getByLabel('Username').fill(username);
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('nav')).toContainText(username);
}

test.describe('chat dock on a phone (1.23)', () => {
  test.beforeEach(async ({ page }) => {
    await signIn(page, 'alice');
    await expect(page.locator('.nowo-chat.is-mobile')).toHaveCount(1);
  });

  test('MUX-02: one round launcher of at least 44px, and the page knows how much space it covers', async ({ page }, testInfo) => {
    const launcher = page.getByRole('button', { name: /^Messaging/ });
    await expect(launcher).toBeVisible();
    await expect(page.locator('.nowo-chat-me')).toBeHidden();
    const box = await page.locator('.nowo-chat-panel').boundingBox();
    const button = await launcher.boundingBox();
    expect(button?.width ?? 0).toBeGreaterThanOrEqual(44);
    expect(button?.height ?? 0).toBeGreaterThanOrEqual(44);
    // The whole folded dock is the launcher (and its border): no status button, no label, no banner beside it.
    expect(box?.width ?? 999).toBeLessThanOrEqual(56);
    expect(box?.height ?? 999).toBeLessThanOrEqual(56);

    const reserve = await page.evaluate(() => ({
      shown: document.body.classList.contains('nowo-chat-dock-shown'),
      value: parseFloat(document.documentElement.style.getPropertyValue('--nowo-chat-dock-reserve')),
      top: (document.querySelector('.nowo-chat-panel') as HTMLElement).getBoundingClientRect().top,
      height: window.innerHeight,
    }));
    expect(reserve.shown).toBe(true);
    // Viewport bottom minus the launcher's top, plus the 8px gap.
    expect(reserve.value).toBeGreaterThanOrEqual(Math.round(reserve.height - reserve.top));
    expect(reserve.value).toBeLessThanOrEqual(Math.round(reserve.height - reserve.top) + 9);

    // A host scroller marked data-nowo-chat-dock-reserve is padded by the token.
    const padding = await page.evaluate(() => {
      const el = document.createElement('div');
      el.setAttribute('data-nowo-chat-dock-reserve', '');
      document.body.append(el);
      const value = getComputedStyle(el).paddingBottom;
      el.remove();
      return parseFloat(value);
    });
    expect(padding).toBe(reserve.value);

    const axe = await new AxeBuilder({ page }).include('.nowo-chat-panel').withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    expect(axe.violations.map((violation) => `${violation.id}: ${violation.help}`)).toEqual([]);
    await page.screenshot({ path: testInfo.outputPath('phone-folded.png') });
  });

  test('MUX-91 / MUX-25: the sheet has its title and a close button above everything; rows keep their time and badge', async ({ page }, testInfo) => {
    const launcher = page.getByRole('button', { name: /^Messaging/ });
    await launcher.click();
    const panel = page.locator('.nowo-chat-panel');
    await expect(panel).toHaveClass(/is-open/);
    await expect(page.locator('.nowo-chat.is-sheet-open')).toHaveCount(1);
    await expect(page.locator('.nowo-chat-launcher__label')).toBeVisible();
    const close = page.locator('.nowo-chat-panel__close');
    await expect(close).toBeVisible();
    await expect(close).toHaveAttribute('aria-label', 'Close');
    // Nothing of the page covers the header: the close button is what a tap at its centre reaches.
    const onTop = await close.evaluate((element) => {
      const rect = element.getBoundingClientRect();
      const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
      return hit !== null && element.contains(hit);
    });
    expect(onTop).toBe(true);
    const closeBox = await close.boundingBox();
    expect(closeBox?.width ?? 0).toBeGreaterThanOrEqual(44);

    await expect(page.locator('.nowo-chat-list .nowo-chat-row').first()).toBeVisible();
    const rows = await page.locator('.nowo-chat-list > li:has(.nowo-chat-row)').evaluateAll((items) =>
      items.map((item) => {
        const width = document.documentElement.clientWidth;
        const time = item.querySelector('.nowo-chat-row__time') as HTMLElement;
        const archive = item.querySelector('.nowo-chat-row__archive') as HTMLElement;
        const row = item.querySelector('.nowo-chat-row') as HTMLElement;
        const t = time.getBoundingClientRect();
        const a = archive.getBoundingClientRect();
        return { width, rowRight: row.getBoundingClientRect().right, timeRight: t.right, archiveLeft: a.left, archiveRight: a.right, timeWidth: t.width };
      }),
    );
    expect(rows.length).toBeGreaterThan(0);
    for (const row of rows) {
      expect(row.timeWidth).toBeGreaterThan(0);
      expect(row.rowRight).toBeLessThanOrEqual(row.width);
      expect(row.archiveRight).toBeLessThanOrEqual(row.width);
      // The Close / Reopen button has its own column: it never covers the time.
      expect(row.timeRight).toBeLessThanOrEqual(row.archiveLeft);
    }
    await page.screenshot({ path: testInfo.outputPath('phone-list.png') });

    await close.click();
    await expect(panel).not.toHaveClass(/is-open/);
    await expect(page.locator('.nowo-chat.is-sheet-open')).toHaveCount(0);
    await expect(launcher).toBeFocused();
  });

  test('MUX-08: the reconnecting notice leaves the bottom corner for the top of the screen', async ({ page }, testInfo) => {
    // The live connection cannot be dropped from here: show the notice as the dock does, and measure it in the same
    // task (a realtime state change of the demo may hide it again right after).
    const measure = () =>
      page.evaluate(() => {
        const status = document.querySelector('.nowo-chat-status') as HTMLElement;
        status.textContent = 'Reconnecting…';
        status.hidden = false;
        document.querySelector('.nowo-chat')?.classList.add('is-reconnecting');
        const box = status.getBoundingClientRect();
        const launcher = (document.querySelector('.nowo-chat-launcher') as HTMLElement).getBoundingClientRect();
        return { top: box.top, bottom: box.bottom, height: box.height, launcherTop: launcher.top, role: status.getAttribute('role'), animation: getComputedStyle(status).animationName };
      });
    const placed = await measure();
    expect(placed.height).toBeGreaterThan(0);
    expect(placed.top).toBeLessThan(80);
    expect(placed.bottom).toBeLessThan(placed.launcherTop);
    expect(placed.role).toBe('status');
    expect(placed.animation).toBe('nowo-chat-status-drop');
    await page.screenshot({ path: testInfo.outputPath('phone-reconnecting.png') });

    await page.emulateMedia({ reducedMotion: 'reduce' });
    expect((await measure()).animation).toBe('none');
  });
});
