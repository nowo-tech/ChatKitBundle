import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — crop to the demo use-case panel that hosts the product UI
 * (title / form / widget in situ). Not a naked control; not full-page chrome
 * (site nav, “back to demos”, profiler).
 *
 * Prefer locator.screenshot() on that panel.
 * Skip this file entirely for packages with no distinctive UI.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('feature screenshots', () => {
  test('overview — use-case panel', async ({ page }) => {
    await page.goto('/');
    // Chat Kit skeleton: the planned-features card. Switch to the dock once it ships.
    const panel = page.locator('[data-screenshot-target], .demo-page-card, main .card, form').first();
    await expect(panel).toBeVisible();
    await panel.screenshot({ path: resolve(outDir, 'overview.png') });
  });
});
