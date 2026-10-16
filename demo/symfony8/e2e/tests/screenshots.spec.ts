import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — the chat dock in situ: navbar, the demo page, the dock with the list and an open conversation,
 * and the Symfony WebProfiler toolbar (full demo frame; the dock sits above the toolbar).
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
});
