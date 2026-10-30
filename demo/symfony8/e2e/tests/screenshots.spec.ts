import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — the chat dock in situ: navbar, the demo page, the dock with the list and an open conversation,
 * and the Symfony WebProfiler toolbar (full demo frame; the dock sits above the toolbar); the full-page chat in the
 * demo's UiKit host layout (REQ-UI-003).
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.use({ viewport: { width: 1280, height: 800 } });

test.describe('feature screenshots', () => {
  test('overview — dock with an open conversation', async ({ page }) => {
    await page.goto('/en/login');
    await page.getByLabel('Username').fill('alice');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.locator('.nowo-chat-launcher').click();
    await page.locator('.nowo-chat-list .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    await expect(page.locator('.nowo-chat-window', { hasText: 'Bob Lee' })).toBeVisible();
    await page.waitForTimeout(400); // window entrance animation
    await page.screenshot({ path: resolve(outDir, 'overview.png') });
  });

  test('page — full-page chat in the UiKit host layout', async ({ page }) => {
    await page.goto('/en/login');
    await page.getByLabel('Username').fill('alice');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.goto('/chat/');
    // 1.13 page mode: the dock's list and the conversation inside the page.
    await page.locator('.nowo-chat-pagemode .nowo-chat-row', { hasText: 'Bob Lee' }).click();
    await expect(page.locator('.nowo-chat-pagemode .nowo-chat-window__title')).toHaveText('Bob Lee');
    await page.waitForTimeout(400);
    await page.screenshot({ path: resolve(outDir, 'page.png') });
  });
});
